<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\MailAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\XMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\TestCase;

class XMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
        config()->set('xpanel.xmail_enabled', true);
    }

    public function test_login_uses_mailbox_password_and_stores_only_encrypted_credential(): void
    {
        $account = $this->account('alice');
        $mail = Mockery::mock(XMailService::class);
        $mail->shouldReceive('authenticate')->once()->with($account->email, 'mailbox-secret-123')->andReturnTrue();
        $this->app->instance(XMailService::class, $mail);

        $response = $this->post(route('xmail.authenticate'), [
            'email' => strtoupper($account->email),
            'password' => 'mailbox-secret-123',
        ]);

        $response->assertRedirect(route('xmail.index'))
            ->assertSessionHas('xmail.account_id', $account->id)
            ->assertSessionHas('xmail.email', $account->email)
            ->assertSessionHas('xmail.credential', fn (string $value): bool => $value !== 'mailbox-secret-123' && Crypt::decryptString($value) === 'mailbox-secret-123')
            ->assertCookie('xpanel_xmail_session');
        $cookie = collect($response->headers->getCookies())->first(
            fn ($candidate): bool => $candidate->getName() === 'xpanel_xmail_session'
        );
        $this->assertSame('/xmail', $cookie?->getPath());
        $this->assertSame('strict', strtolower((string) $cookie?->getSameSite()));
    }

    public function test_invalid_credentials_are_rejected_without_revealing_which_value_failed(): void
    {
        $account = $this->account('alice');
        $mail = Mockery::mock(XMailService::class);
        $mail->shouldReceive('authenticate')->once()->with($account->email, 'wrong-password')->andReturnFalse();
        $this->app->instance(XMailService::class, $mail);

        $this->from(route('xmail.login'))->post(route('xmail.authenticate'), [
            'email' => $account->email,
            'password' => 'wrong-password',
        ])->assertRedirect(route('xmail.login'))->assertSessionHasErrors(['email']);

        $this->from(route('xmail.login'))->post(route('xmail.authenticate'), [
            'email' => 'unknown@example.com',
            'password' => 'wrong-password',
        ])->assertRedirect(route('xmail.login'))->assertSessionHasErrors(['email']);
    }

    public function test_api_requires_a_mailbox_session(): void
    {
        $this->getJson(route('xmail.api.folders'))->assertUnauthorized();
    }

    public function test_authenticated_mailbox_can_render_the_complete_xmail_client(): void
    {
        $account = $this->account('alice');

        $this->withSession($this->sessionFor($account, 'alice-password'))
            ->get(route('xmail.index'))
            ->assertOk()
            ->assertSee($account->email)
            ->assertSee('/xmail/api/messages', false)
            ->assertSee('/xmail/api/attachment', false)
            ->assertSee('xmail_folder_create', false);
    }

    public function test_api_is_bound_to_exactly_the_authenticated_mailbox(): void
    {
        $account = $this->account('alice');
        $other = $this->account('bob');
        $mail = Mockery::mock(XMailService::class);
        $mail->shouldReceive('folders')->once()->with($account->email, 'alice-password')->andReturn([
            ['name' => 'INBOX', 'unseen' => 2],
        ]);
        $this->app->instance(XMailService::class, $mail);

        $this->withSession($this->sessionFor($account, 'alice-password'))
            ->getJson(route('xmail.api.folders', ['account' => $other->id]))
            ->assertOk()
            ->assertJsonPath('folders.0.name', 'INBOX');
    }

    public function test_send_always_uses_authenticated_mailbox_as_sender(): void
    {
        $account = $this->account('alice');
        $mail = Mockery::mock(XMailService::class);
        $mail->shouldReceive('send')->once()->with(
            $account->email, 'alice-password', ['recipient@example.net'], [], [], 'Prueba', 'Contenido', null, null,
        );
        $this->app->instance(XMailService::class, $mail);

        $this->withSession($this->sessionFor($account, 'alice-password'))
            ->postJson(route('xmail.api.send'), [
                'from' => 'forged@example.com',
                'to' => ['recipient@example.net'],
                'subject' => 'Prueba',
                'text' => 'Contenido',
            ])->assertCreated();
    }

    public function test_deleted_account_revokes_the_session(): void
    {
        $account = $this->account('alice');
        $session = $this->sessionFor($account, 'alice-password');
        $account->delete();

        $this->withSession($session)->getJson(route('xmail.api.folders'))->assertUnauthorized();
    }

    public function test_xmail_can_be_disabled_without_affecting_roundcube(): void
    {
        config()->set('xpanel.xmail_enabled', false);
        $this->get(route('xmail.login'))->assertNotFound();
    }

    public function test_xmail_smoke_command_exercises_imap_and_authenticated_smtp(): void
    {
        $mail = Mockery::mock(XMailService::class);
        $mail->shouldReceive('authenticate')->once()->with('alice@example.com', 'mailbox-secret')->andReturnTrue();
        $mail->shouldReceive('folders')->once()->with('alice@example.com', 'mailbox-secret')->andReturn([
            ['name' => 'INBOX', 'unseen' => 0],
        ]);
        $mail->shouldReceive('send')->once()->withArgs(function (...$arguments): bool {
            return $arguments[0] === 'alice@example.com'
                && $arguments[1] === 'mailbox-secret'
                && $arguments[2] === ['alice@example.com'];
        });
        $this->app->instance(XMailService::class, $mail);

        $this->artisan('xpanel:xmail-smoke', ['email' => 'alice@example.com', '--send' => true])
            ->expectsQuestion('Mailbox password', 'mailbox-secret')
            ->expectsOutputToContain('smoke test passed')
            ->assertSuccessful();
    }

    /** @return array<string, mixed> */
    private function sessionFor(MailAccount $account, string $password): array
    {
        return ['xmail' => [
            'account_id' => $account->id,
            'email' => $account->email,
            'credential' => Crypt::encryptString($password),
        ]];
    }

    private function account(string $localPart): MailAccount
    {
        $domain = Domain::firstOrCreate(['domain' => 'example.com'], ['type' => 'primary']);

        return MailAccount::create([
            'domain_id' => $domain->id,
            'local_part' => $localPart,
            'password' => 'database-hash-source',
            'quota_mb' => 1024,
            'status' => 'active',
        ])->load('domain');
    }
}
