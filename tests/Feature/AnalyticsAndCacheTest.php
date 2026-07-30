<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ServerCommandRunner;
use App\Services\SiteProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AnalyticsAndCacheTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
    }

    private function site(): Site
    {
        return Site::create([
            'domain' => 'stats.example.com', 'document_root' => '/var/www/stats.example.com',
            'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active',
        ]);
    }

    public function test_analytics_parses_combined_and_openlitespeed_logs(): void
    {
        $site = $this->site();
        $path = storage_path('app/logs/'.$site->domain.'-access.log');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode("\n", [
            '203.0.113.10 - - [29/Jul/2026:10:00:00 +0000] "GET / HTTP/1.1" 200 1200 "-" "Browser"',
            '203.0.113.10 - - [29/Jul/2026:10:01:00 +0000] "GET /missing HTTP/1.1" 404 300 "-" "Browser"',
            '198.51.100.2 - - [29/Jul/2026:10:02:00 +0000] "POST /login HTTP/1.1" 302 50',
        ]));

        $this->actingAs($this->owner())->get(route('sites.analytics', $site))
            ->assertOk()->assertSee('3')->assertSee('2')->assertSee('/missing');
    }

    public function test_local_cache_purge_only_cleans_known_directories(): void
    {
        $site = $this->site();
        $root = $site->localRoot();
        File::ensureDirectoryExists($root.'/storage/framework/views');
        File::put($root.'/storage/framework/views/cached.php', 'cache');
        File::put($root.'/keep.txt', 'important');

        $this->actingAs($this->owner())->post(route('sites.cache.purge', $site))
            ->assertRedirect()->assertSessionHas('status');

        $this->assertFileDoesNotExist($root.'/storage/framework/views/cached.php');
        $this->assertFileExists($root.'/keep.txt');
    }

    public function test_production_cache_purge_uses_narrow_helper(): void
    {
        $site = $this->site();
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $this->mock(ServerCommandRunner::class, function ($mock) use ($site): void {
            $mock->shouldReceive('run')->once()->with([
                'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'cache-purge',
                $site->domain, $site->document_root,
            ], null, 1800)->andReturn("files=4\nbytes=1024");
        });

        $this->actingAs($this->owner())->post(route('sites.cache.purge', $site))
            ->assertSessionHas('status', fn ($message) => str_contains($message, '4 archivos'));
    }

    public function test_production_analytics_requests_only_the_selected_site_log(): void
    {
        $site = $this->site();
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $this->mock(ServerCommandRunner::class, function ($mock) use ($site): void {
            $mock->shouldReceive('run')->once()->with([
                'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'access-log-read',
                $site->domain, $site->web_server,
            ])->andReturn('203.0.113.10 - - [29/Jul/2026:10:00:00 +0000] "GET / HTTP/1.1" 200 1200 "-" "Browser"');
        });

        $this->actingAs($this->owner())->get(route('sites.analytics', $site))->assertOk()->assertSee('203.0.113.10');
    }

    public function test_directory_listing_is_disabled_by_default_and_can_be_enabled(): void
    {
        $site = $this->site();
        app(SiteProvisioner::class)->provision($site);
        $this->assertStringContainsString('autoindex off;', file_get_contents(storage_path('app/gateways/'.$site->domain.'.conf')));

        $this->actingAs($this->owner())->put(route('sites.folder-index.update', $site), [
            'directory_listing' => '1',
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('site_web_settings', ['site_id' => $site->id, 'directory_listing' => true]);
        $this->assertStringContainsString('autoindex on;', file_get_contents(storage_path('app/gateways/'.$site->domain.'.conf')));
    }
}
