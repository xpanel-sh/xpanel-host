<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteGatewayRulesTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
    }

    private function site(string $engine = 'nginx'): Site
    {
        return Site::create([
            'domain' => 'protected.example.com', 'document_root' => '/var/www/protected.example.com',
            'php_version' => '8.3', 'type' => 'php', 'web_server' => $engine, 'status' => 'active',
        ]);
    }

    public function test_hotlink_protection_is_rendered_for_direct_nginx_site(): void
    {
        $site = $this->site();

        $this->actingAs($this->owner())->put(route('sites.hotlink.update', $site), [
            'enabled' => '1', 'extensions' => ['jpg', 'webp'],
            'allowed_referrers' => "cdn.example.net\n*.trusted.example",
        ])->assertSessionHas('status');

        $gateway = file_get_contents(storage_path('app/gateways/'.$site->domain.'.conf'));
        $this->assertStringContainsString('location ~* \.(jpg|webp)$', $gateway);
        $this->assertStringContainsString('valid_referers none blocked server_names cdn.example.net *.trusted.example;', $gateway);
        $this->assertStringContainsString('if ($invalid_referer) { return 403; }', $gateway);
    }

    public function test_hotlink_assets_are_proxied_for_apache_backend(): void
    {
        $site = $this->site('apache');

        $this->actingAs($this->owner())->put(route('sites.hotlink.update', $site), [
            'enabled' => '1', 'extensions' => ['png'], 'allowed_referrers' => '',
        ])->assertSessionHas('status');

        $gateway = file_get_contents(storage_path('app/gateways/'.$site->domain.'.conf'));
        $this->assertStringContainsString('location ~* \.(png)$', $gateway);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:8082;', $gateway);
    }

    public function test_allowlist_rules_are_rendered_and_acme_remains_allowed(): void
    {
        $site = $this->site();
        $owner = $this->actingAs($this->owner());

        $owner->post(route('sites.ip-rules.store', $site), ['action' => 'allow', 'address' => '203.0.113.0/24'])
            ->assertSessionHas('status');
        $owner->post(route('sites.ip-rules.store', $site), ['action' => 'allow', 'address' => '2001:db8::/32'])
            ->assertSessionHas('status');

        $gateway = file_get_contents(storage_path('app/gateways/'.$site->domain.'.conf'));
        $this->assertStringContainsString('allow 203.0.113.0/24;', $gateway);
        $this->assertStringContainsString('allow 2001:db8::/32;', $gateway);
        $this->assertStringContainsString('deny all;', $gateway);
        $this->assertMatchesRegularExpression('/location \^~ \/\.well-known\/acme-challenge\/ \{\s+allow all;/m', $gateway);
    }

    public function test_allowlist_and_blocklist_cannot_be_mixed(): void
    {
        $site = $this->site();
        $owner = $this->actingAs($this->owner());
        $owner->post(route('sites.ip-rules.store', $site), ['action' => 'deny', 'address' => '198.51.100.4'])
            ->assertSessionHas('status');

        $owner->post(route('sites.ip-rules.store', $site), ['action' => 'allow', 'address' => '203.0.113.4'])
            ->assertSessionHasErrors('action');

        $this->assertDatabaseCount('site_ip_rules', 1);
    }

    public function test_invalid_cidr_is_rejected(): void
    {
        $this->actingAs($this->owner())->post(route('sites.ip-rules.store', $this->site()), [
            'action' => 'deny', 'address' => '203.0.113.1/99',
        ])->assertSessionHasErrors('address');
    }
}
