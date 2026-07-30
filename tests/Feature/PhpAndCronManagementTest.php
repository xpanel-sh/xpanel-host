<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ServerCommandRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhpAndCronManagementTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
    }

    private function site(string $domain = 'app.example.com'): Site
    {
        return Site::create([
            'domain' => $domain,
            'document_root' => '/var/www/'.$domain,
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);
    }

    public function test_php_limits_are_persisted_and_staged_in_the_site_pool(): void
    {
        $site = $this->site();

        $this->actingAs($this->owner())->put(route('sites.php.configuration.update', $site), [
            'memory_limit' => '512M',
            'upload_max_filesize' => '128M',
            'post_max_size' => '256M',
            'max_execution_time' => 120,
            'display_errors' => '1',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('site_php_settings', ['site_id' => $site->id, 'memory_limit' => '512M']);
        $pool = file_get_contents(storage_path('app/php-fpm/'.$site->domain.'.conf'));
        $this->assertStringContainsString('php_admin_value[memory_limit] = 512M', $pool);
        $this->assertStringContainsString('php_admin_flag[display_errors] = on', $pool);
    }

    public function test_post_limit_cannot_be_smaller_than_upload_limit(): void
    {
        $this->actingAs($this->owner())->put(route('sites.php.configuration.update', $this->site()), [
            'memory_limit' => '256M',
            'upload_max_filesize' => '256M',
            'post_max_size' => '64M',
            'max_execution_time' => 60,
        ])->assertSessionHasErrors('post_max_size');
    }

    public function test_cron_job_is_staged_as_the_limited_site_user(): void
    {
        $site = $this->site();

        $this->actingAs($this->owner())->post(route('sites.cron.store', $site), [
            'expression' => '*/5 * * * *',
            'command' => 'php artisan schedule:run',
            'enabled' => '1',
        ])->assertRedirect()->assertSessionHas('status');

        $contents = file_get_contents(storage_path('app/cron/'.$site->domain));
        $this->assertStringContainsString('*/5 * * * * '.$site->systemUser().' cd --', $contents);
        $this->assertStringContainsString('php artisan schedule:run', $contents);
    }

    public function test_cron_rejects_line_breaks_and_percent_expansion(): void
    {
        $site = $this->site();
        $owner = $this->actingAs($this->owner());

        $owner->post(route('sites.cron.store', $site), ['expression' => '* * * * *', 'command' => "whoami\nreboot"])
            ->assertSessionHasErrors('command');
        $owner->post(route('sites.cron.store', $site), ['expression' => '* * * * *', 'command' => 'date +%s'])
            ->assertSessionHasErrors('command');
        $this->assertDatabaseCount('site_cron_jobs', 0);
    }

    public function test_real_cron_sync_uses_only_the_narrow_helper(): void
    {
        $site = $this->site();
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $this->mock(ServerCommandRunner::class, function ($mock) use ($site): void {
            $mock->shouldReceive('run')->once()->with([
                'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'cron-sync',
                $site->domain, $site->document_root, $site->systemUser(),
            ]);
        });

        $this->actingAs($this->owner())->post(route('sites.cron.store', $site), [
            'expression' => '0 2 * * *', 'command' => 'php artisan backup:run', 'enabled' => '1',
        ])->assertSessionHas('status');
    }
}
