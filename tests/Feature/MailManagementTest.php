<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\MailAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\SystemDnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MailManagementTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->firstOrFail()->id]);
    }

    private function domain(): Domain
    {
        return Domain::create(['domain' => 'mail-test-'.uniqid().'.example.com', 'type' => 'primary']);
    }

    public function test_guests_cannot_view_mail(): void
    {
        $this->get('/mail')->assertRedirect('/login');
    }

    public function test_developer_can_create_a_mail_account(): void
    {
        $domain = $this->domain();

        $response = $this->actingAs($this->userWithRole('developer'))->post('/mail', [
            'local_part' => 'ventas',
            'domain_id' => $domain->id,
            'password' => 'a-very-long-password',
            'quota_mb' => 1024,
        ]);

        $response->assertRedirect(route('mail.index'));
        $this->assertDatabaseHas('mail_accounts', ['domain_id' => $domain->id, 'local_part' => 'ventas']);
        $account = MailAccount::where('local_part', 'ventas')->firstOrFail();
        $this->assertSame('staged', $account->status);
        $this->assertSame(100, $account->hourly_send_limit);
        $this->assertSame(500, $account->daily_send_limit);
        $this->assertStringContainsString('ventas@'.$domain->domain, file_get_contents(storage_path('app/mail/users')));
    }

    public function test_mail_index_shows_the_composed_email_address(): void
    {
        $domain = $this->domain();
        MailAccount::create([
            'domain_id' => $domain->id,
            'local_part' => 'soporte',
            'password' => 'a-very-long-password',
            'quota_mb' => 2048,
        ]);

        $this->actingAs($this->userWithRole('owner'))->get('/mail')
            ->assertOk()
            ->assertSee("soporte@{$domain->domain}")
            ->assertSee('Cuentas de correo')
            ->assertSee('SMTP')
            ->assertSee('IMAP')
            ->assertSee('assets/css/styles.css', false);
    }

    public function test_mail_index_links_roundcube_and_xmail(): void
    {
        config()->set('xpanel.roundcube_enabled', true);
        config()->set('xpanel.webmail_hostname', 'mail.example.com');
        config()->set('xpanel.webmail_url', 'https://mail.example.com');
        config()->set('xpanel.xmail_enabled', true);

        $this->actingAs($this->userWithRole('owner'))->get('/mail')
            ->assertOk()
            ->assertSee('https://mail.example.com', false)
            ->assertSee('Abrir Roundcube')
            ->assertSee('Abrir XMail');
    }

    public function test_mail_dns_modal_shows_records_and_verifies_public_dns_and_tls(): void
    {
        config()->set('xpanel.mail_hostname', 'mail.example.com');
        config()->set('xpanel.webmail_url', 'https://mail.example.com');
        config()->set('xpanel.server_ipv4', '203.0.113.10');
        config()->set('xpanel.dkim_selector', 'xpanel');
        $domain = Domain::create(['domain' => 'example.com', 'type' => 'primary']);
        MailAccount::create([
            'domain_id' => $domain->id,
            'local_part' => 'soporte',
            'password' => 'a-very-long-password',
            'quota_mb' => 1024,
        ]);
        $directory = storage_path('app/mail/dkim');
        mkdir($directory, 0700, true);
        $dkim = 'v=DKIM1; k=rsa; p=PUBLICKEY';
        file_put_contents($directory.'/example.com.txt', $dkim);

        $resolver = Mockery::mock(SystemDnsResolver::class);
        $resolver->shouldReceive('records')->with('example.com', DNS_MX)->andReturn([
            ['target' => 'mail.example.com.', 'pri' => 10],
        ]);
        $resolver->shouldReceive('records')->with('mail.example.com', DNS_A)->andReturn([
            ['ip' => '203.0.113.10'],
        ]);
        $resolver->shouldReceive('records')->with('example.com', DNS_TXT)->andReturn([
            ['txt' => 'v=spf1 mx a:mail.example.com -all'],
        ]);
        $resolver->shouldReceive('records')->with('xpanel._domainkey.example.com', DNS_TXT)->andReturn([
            ['txt' => $dkim],
        ]);
        $resolver->shouldReceive('records')->with('_dmarc.example.com', DNS_TXT)->andReturn([
            ['txt' => 'v=DMARC1; p=quarantine; rua=mailto:postmaster@example.com'],
        ]);
        $resolver->shouldReceive('reverse')->with('203.0.113.10')->andReturn('mail.example.com');
        $resolver->shouldReceive('tlsCertificate')->with('mail.example.com')->andReturn([
            'subject' => 'mail.example.com',
            'valid_to' => time() + 86400,
        ]);
        $this->app->instance(SystemDnsResolver::class, $resolver);

        $user = $this->userWithRole('developer');
        $this->actingAs($user)->get('/mail')
            ->assertOk()
            ->assertSee('DNS del correo')
            ->assertSee('xpanel._domainkey')
            ->assertSee($dkim)
            ->assertSee('DNS only');

        $this->actingAs($user)->getJson(route('mail.dns-status', $domain))
            ->assertOk()
            ->assertJsonPath('domain', 'example.com')
            ->assertJsonPath('checks.mx.ok', true)
            ->assertJsonPath('checks.a.ok', true)
            ->assertJsonPath('checks.spf.ok', true)
            ->assertJsonPath('checks.dkim.ok', true)
            ->assertJsonPath('checks.dmarc.ok', true)
            ->assertJsonPath('checks.ptr.ok', true)
            ->assertJsonPath('checks.ssl.ok', true);
    }

    public function test_dns_status_is_unavailable_for_a_domain_without_mailboxes(): void
    {
        $domain = $this->domain();

        $this->actingAs($this->userWithRole('developer'))
            ->getJson(route('mail.dns-status', $domain))
            ->assertNotFound();
    }

    public function test_password_is_never_exposed_in_json(): void
    {
        $domain = $this->domain();
        $account = MailAccount::create([
            'domain_id' => $domain->id,
            'local_part' => 'hidden',
            'password' => 'a-very-long-password',
            'quota_mb' => 1024,
        ]);

        $this->assertArrayNotHasKey('password', $account->toArray());
    }

    public function test_viewer_can_list_but_not_create_mail_accounts(): void
    {
        $domain = $this->domain();
        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer)->get('/mail')->assertOk();
        $this->actingAs($viewer)->post('/mail', [
            'local_part' => 'blocked',
            'domain_id' => $domain->id,
            'password' => 'a-very-long-password',
            'quota_mb' => 1024,
        ])->assertForbidden();
    }

    public function test_mail_account_can_be_deleted(): void
    {
        $domain = $this->domain();
        $account = MailAccount::create([
            'domain_id' => $domain->id,
            'local_part' => 'delete-me',
            'password' => 'a-very-long-password',
            'quota_mb' => 1024,
        ]);

        $this->actingAs($this->userWithRole('developer'))->delete(route('mail.destroy', $account))
            ->assertRedirect(route('mail.index'));

        $this->assertDatabaseMissing('mail_accounts', ['id' => $account->id]);
    }

    public function test_password_can_be_reset(): void
    {
        $domain = $this->domain();
        $account = MailAccount::create([
            'domain_id' => $domain->id,
            'local_part' => 'reset-me',
            'password' => 'the-original-password',
            'quota_mb' => 1024,
        ]);
        $originalHash = $account->password;

        $this->actingAs($this->userWithRole('developer'))
            ->post(route('mail.reset-password', $account), ['password' => 'a-brand-new-password'])
            ->assertRedirect(route('mail.index'));

        $this->assertNotEquals($originalHash, $account->fresh()->password);
    }

    public function test_outbound_recipient_limits_can_be_updated_per_mailbox(): void
    {
        $domain = $this->domain();
        $account = MailAccount::create([
            'domain_id' => $domain->id,
            'local_part' => 'limited',
            'password' => 'a-very-long-password',
            'quota_mb' => 1024,
        ]);

        $this->actingAs($this->userWithRole('developer'))
            ->put(route('mail.update-limits', $account), [
                'hourly_send_limit' => 40,
                'daily_send_limit' => 180,
            ])
            ->assertRedirect(route('mail.index'));

        $account->refresh();
        $this->assertSame(40, $account->hourly_send_limit);
        $this->assertSame(180, $account->daily_send_limit);
        $this->assertSame('staged', $account->status);
        $this->assertStringContainsString(
            "limited@{$domain->domain} 40 180",
            file_get_contents(storage_path('app/mail/send-limits')),
        );
    }

    public function test_daily_limit_cannot_be_lower_than_hourly_limit(): void
    {
        $domain = $this->domain();
        $account = MailAccount::create([
            'domain_id' => $domain->id,
            'local_part' => 'safe',
            'password' => 'a-very-long-password',
            'quota_mb' => 1024,
        ]);

        $this->actingAs($this->userWithRole('developer'))
            ->from(route('mail.index'))
            ->put(route('mail.update-limits', $account), [
                'hourly_send_limit' => 500,
                'daily_send_limit' => 100,
            ])
            ->assertRedirect(route('mail.index'))
            ->assertSessionHasErrors('daily_send_limit');
    }

    public function test_duplicate_local_part_on_same_domain_is_rejected(): void
    {
        $domain = $this->domain();
        MailAccount::create([
            'domain_id' => $domain->id,
            'local_part' => 'dupe',
            'password' => 'a-very-long-password',
            'quota_mb' => 1024,
        ]);

        $this->actingAs($this->userWithRole('developer'))->post('/mail', [
            'local_part' => 'dupe',
            'domain_id' => $domain->id,
            'password' => 'another-long-password',
            'quota_mb' => 1024,
        ])->assertUnprocessable();
    }
}
