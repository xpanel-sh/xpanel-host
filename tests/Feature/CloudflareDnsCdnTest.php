<?php

namespace Tests\Feature;

use App\Models\DnsProviderConnection;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\CloudflareDnsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareDnsCdnTest extends TestCase
{
    use RefreshDatabase;

    private const ZONE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const RECORD = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private function site(): Site
    {
        return Site::create(['domain' => 'example.com', 'document_root' => '/var/www/example.com', 'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active']);
    }

    private function owner(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
    }

    private function connection(Site $site, string $token = 'cfut_test_token_abcdefghijklmnopqrstuvwxyz'): DnsProviderConnection
    {
        return $site->dnsConnection()->create([
            'provider' => 'cloudflare', 'zone_id' => self::ZONE, 'zone_name' => 'example.com',
            'credentials' => ['api_token' => $token], 'verified_at' => now(),
        ]);
    }

    public function test_connection_is_verified_and_token_is_encrypted_at_rest(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['success' => true, 'result' => ['status' => 'active']]),
            'api.cloudflare.com/client/v4/zones/'.self::ZONE => Http::response(['success' => true, 'result' => ['name' => 'example.com']]),
        ]);
        $site = $this->site();
        $token = 'cfut_secret_token_abcdefghijklmnopqrstuvwxyz';
        $this->actingAs($this->owner())->post(route('sites.dns.connect', $site), ['zone_id' => self::ZONE, 'api_token' => $token])->assertSessionHas('status');

        $stored = (string) DB::table('dns_provider_connections')->value('credentials');
        $this->assertStringNotContainsString($token, $stored);
        $this->assertSame($token, $site->dnsConnection()->firstOrFail()->token());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.$token));
    }

    public function test_editor_lists_only_supported_records_inside_site_and_creates_valid_record(): void
    {
        $site = $this->site();
        $this->connection($site);
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response(['success' => true, 'result' => [
                    ['id' => self::RECORD, 'type' => 'A', 'name' => 'example.com', 'content' => '192.0.2.10', 'ttl' => 300, 'proxiable' => true, 'proxied' => false],
                    ['id' => str_repeat('c', 32), 'type' => 'A', 'name' => 'outside.test', 'content' => '192.0.2.20', 'ttl' => 300],
                    ['id' => str_repeat('d', 32), 'type' => 'NS', 'name' => 'example.com', 'content' => 'ns.example.net', 'ttl' => 300],
                ]]);
            }

            return Http::response(['success' => true, 'result' => ['id' => str_repeat('e', 32)]]);
        });
        $actor = $this->actingAs($this->owner());
        $actor->get(route('sites.dns.index', $site))->assertOk()->assertSee('192.0.2.10')->assertDontSee('192.0.2.20')->assertDontSee('ns.example.net');
        $actor->post(route('sites.dns.records.store', $site), [
            'type' => 'A', 'name' => 'www', 'content' => '192.0.2.30', 'ttl' => 300, 'proxied' => '1',
        ])->assertSessionHas('status');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/dns_records')
            && $request['name'] === 'www.example.com' && $request['proxied'] === true && $request['ttl'] === 1);
        $this->assertSame('_dmarc.example.com', app(CloudflareDnsService::class)->normalizeName($site, '_dmarc'));
        $this->assertSame('selector._domainkey.example.com', app(CloudflareDnsService::class)->normalizeName($site, 'selector._domainkey'));
    }

    public function test_record_id_cannot_modify_another_domain(): void
    {
        $site = $this->site();
        $this->connection($site);
        Http::fake([
            'api.cloudflare.com/client/v4/zones/'.self::ZONE.'/dns_records/'.self::RECORD => Http::response(['success' => true, 'result' => ['id' => self::RECORD, 'type' => 'A', 'name' => 'other.test', 'content' => '192.0.2.1']]),
        ]);
        $this->actingAs($this->owner())->delete(route('sites.dns.records.destroy', [$site, self::RECORD]))->assertSessionHasErrors('provider');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_cdn_toggles_root_proxy_and_purges_zone_cache(): void
    {
        $site = $this->site();
        $this->connection($site);
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response(['success' => true, 'result' => [[
                    'id' => self::RECORD, 'type' => 'A', 'name' => 'example.com', 'content' => '192.0.2.10',
                    'ttl' => 300, 'proxiable' => true, 'proxied' => false,
                ]]]);
            }

            return Http::response(['success' => true, 'result' => ['id' => self::RECORD]]);
        });
        $actor = $this->actingAs($this->owner());
        $actor->get(route('sites.cdn.index', $site))->assertOk()->assertSee('DNS solamente');
        $actor->put(route('sites.cdn.update', $site), ['enabled' => '1'])->assertSessionHas('status');
        $actor->post(route('sites.cdn.purge', $site))->assertSessionHas('status');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && $request['proxied'] === true);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/purge_cache') && $request['purge_everything'] === true);
    }
}
