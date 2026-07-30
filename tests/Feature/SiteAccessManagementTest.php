<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteAccessManagementTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $domain = 'access.example.com'): Site
    {
        return Site::create(['domain' => $domain, 'document_root' => '/var/www/'.$domain, 'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active']);
    }

    private function owner(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
    }

    private function publicKey(): string
    {
        $type = 'ssh-ed25519';
        $blob = pack('N', strlen($type)).$type.pack('N', 32).str_repeat("\x01", 32);

        return $type.' '.base64_encode($blob).' test@example';
    }

    public function test_enabling_sftp_requires_a_password_and_never_persists_it(): void
    {
        $site = $this->site();
        $actor = $this->actingAs($this->owner());
        $actor->put(route('sites.access.update', $site), ['sftp_enabled' => '1'])->assertSessionHasErrors('password');
        $actor->put(route('sites.access.update', $site), ['sftp_enabled' => '1', 'password' => 'Strong-Access_2026!'])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('site_access_settings', ['site_id' => $site->id, 'sftp_enabled' => true]);
        $this->assertStringNotContainsString('Strong-Access_2026!', json_encode($site->accessSettings->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_ssh_requires_a_valid_public_key_and_stages_authorized_keys(): void
    {
        $site = $this->site();
        $actor = $this->actingAs($this->owner());
        $actor->put(route('sites.access.update', $site), ['ssh_enabled' => '1'])->assertSessionHasErrors('ssh_enabled');
        $actor->post(route('sites.access.keys.store', $site), ['name' => 'Laptop', 'public_key' => $this->publicKey()])
            ->assertSessionHas('status');
        $key = $site->sshKeys()->firstOrFail();
        $this->assertStringStartsWith('SHA256:', $key->fingerprint);
        $this->assertSame($this->publicKey()."\n", file_get_contents(storage_path('app/access/'.$site->systemUser().'/authorized_keys')));
        $actor->put(route('sites.access.update', $site), ['ssh_enabled' => '1'])->assertSessionHas('status');
        $this->assertTrue($site->accessSettings->fresh()->ssh_enabled);
    }

    public function test_key_cannot_be_removed_through_another_site(): void
    {
        $site = $this->site();
        $other = $this->site('other-access.example.com');
        $key = $other->sshKeys()->create(['name' => 'Other', 'public_key' => $this->publicKey()]);

        $this->actingAs($this->owner())->delete(route('sites.access.keys.destroy', [$site, $key]))->assertNotFound();
    }

    public function test_web_terminal_toggle_requires_the_server_wide_feature_flag(): void
    {
        $site = $this->site();
        $actor = $this->actingAs($this->owner());

        $actor->put(route('sites.access.update', $site), ['web_terminal_enabled' => '1'])
            ->assertSessionHasErrors('web_terminal_enabled');
        $this->assertFalse($site->accessSettings()->first()?->web_terminal_enabled ?? false);

        config(['xpanel.terminal_enabled' => true]);
        $actor->put(route('sites.access.update', $site), ['web_terminal_enabled' => '1'])
            ->assertSessionHas('status');
        $this->assertTrue($site->accessSettings->fresh()->web_terminal_enabled);
    }

    public function test_access_pages_show_the_distinct_system_user(): void
    {
        $site = $this->site();
        $actor = $this->actingAs($this->owner());
        $actor->get(route('sites.access.files', $site))->assertOk()->assertSee($site->systemUser());
        $actor->get(route('sites.access.ssh', $site))->assertOk()->assertSee($site->systemUser());
    }
}
