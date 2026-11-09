<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteDatabase;
use App\Services\DbGateManager;
use App\Services\ServerCommandRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DbGateManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stages_isolated_connections_without_database_passwords(): void
    {
        $site = Site::create([
            'domain' => 'dbgate.example.com',
            'document_root' => '/var/www/dbgate.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);
        SiteDatabase::create([
            'site_id' => $site->id,
            'engine' => 'postgresql',
            'name' => 'xp_test_application',
            'username' => 'xp_test_user',
            'status' => 'active',
        ]);

        $manager = new DbGateManager(Mockery::mock(ServerCommandRunner::class), app(\App\Services\SiteSqliteDatabaseManager::class));
        $credentials = $manager->synchronize();
        $environment = file_get_contents(storage_path('app/dbgate/runtime.env'));

        $this->assertSame('xpanel', $credentials['login']);
        $this->assertSame(48, strlen($credentials['password']));
        $this->assertStringContainsString('WEB_ROOT=/dbgate', $environment);
        $this->assertStringContainsString('ENGINE_db1=postgres@dbgate-plugin-postgres', $environment);
        $this->assertStringContainsString('PASSWORD_MODE_db1=askPassword', $environment);
        $this->assertStringNotContainsString('PASSWORD_db1=', $environment);
        $this->assertFileExists(storage_path('app/dbgate/access.json'));
    }
}
