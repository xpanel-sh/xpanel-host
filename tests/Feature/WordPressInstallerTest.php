<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteApplication;
use App\Models\SiteBackup;
use App\Models\User;
use App\Services\DatabaseProvisioner;
use App\Services\ServerCommandRunner;
use App\Services\SiteBackupManager;
use App\Services\WordPressInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class WordPressInstallerTest extends TestCase
{
    use RefreshDatabase;

    public function test_privileged_installer_verifies_core_before_configuration_and_uses_a_noninteractive_terminal(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));
        $verify = strpos($helper, 'core verify-checksums --path="$staging" --version="$wordpress_version" --locale=en_US');
        $configure = strpos($helper, 'config create \\');

        $this->assertStringContainsString('TERM=dumb WP_CLI_COLOR=0 PAGER=cat', $helper);
        $this->assertStringContainsString('language core install "$locale"', $helper);
        $this->assertNotFalse($verify);
        $this->assertNotFalse($configure);
        $this->assertLessThan($configure, $verify);
    }

    private function site(): Site
    {
        return Site::create(['domain' => 'wp.example.com', 'document_root' => '/var/www/wp.example.com', 'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active', 'ssl_status' => 'active']);
    }

    private function owner(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return [
            'title' => 'Mi WordPress', 'admin_user' => 'ronald', 'admin_email' => 'ronald@example.com',
            'admin_password' => 'Admin-Password_2026!', 'locale' => 'es_PE',
            'database_name' => 'wordpress', 'database_username' => 'wpuser',
            'database_password' => 'Database-Pass_2026!', 'confirmation' => 'wp.example.com',
        ];
    }

    public function test_installer_passes_secrets_only_through_standard_input(): void
    {
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $site = $this->site();
        $user = $this->owner();
        $data = $this->payload();
        unset($data['confirmation']);

        $commands = Mockery::mock(ServerCommandRunner::class);
        $commands->shouldReceive('run')->once()->withArgs(function (array $command, ?string $input, int $timeout) use ($data): bool {
            $serialized = implode(' ', $command);
            $this->assertStringNotContainsString($data['admin_password'], $serialized);
            $this->assertStringNotContainsString($data['database_password'], $serialized);
            $this->assertSame($data['database_password']."\n".$data['admin_password']."\n", $input);
            $this->assertSame(1200, $timeout);

            return true;
        })->andReturn("version=6.8.2\n");
        $databases = Mockery::mock(DatabaseProvisioner::class);
        $databases->shouldReceive('create')->once()->andReturnUsing(fn ($database) => $database->update(['status' => 'active']));
        $backups = Mockery::mock(SiteBackupManager::class);
        $backups->shouldReceive('create')->once()->andReturn(new SiteBackup(['token' => '11111111-1111-4111-8111-111111111111']));

        $application = (new WordPressInstaller($commands, $databases, $backups))->install($site, $data, $user);

        $this->assertSame('installed', $application->status);
        $this->assertSame('6.8.2', $application->version);
        $this->assertStringNotContainsString($data['admin_password'], json_encode($application->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($data['database_password'], json_encode($application->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_controller_validates_confirmation_and_never_flashes_passwords(): void
    {
        $site = $this->site();
        $owner = $this->owner();
        $payload = $this->payload();
        $invalid = $payload;
        $invalid['confirmation'] = 'wrong.example.com';
        $this->actingAs($owner)->post(route('sites.wordpress.store', $site), $invalid)->assertSessionHasErrors('confirmation');

        $application = SiteApplication::create(['token' => (string) Str::uuid(), 'site_id' => $site->id, 'type' => 'wordpress', 'status' => 'installed', 'version' => '6.8.2']);
        $this->mock(WordPressInstaller::class, fn (MockInterface $mock) => $mock->shouldReceive('install')->once()->andReturn($application));
        $response = $this->actingAs($owner)->from(route('sites.wordpress.index', $site))->post(route('sites.wordpress.store', $site), $payload);
        $response->assertRedirect(route('sites.wordpress.index', $site))->assertSessionHas('status');
        $this->assertStringNotContainsString($payload['admin_password'], serialize(session()->all()));
        $this->assertStringNotContainsString($payload['database_password'], serialize(session()->all()));
    }
}
