<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ServerCommandRunner;
use App\Services\SiteDiagnosticService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PerformanceDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::create(['domain' => 'speed.example.com', 'document_root' => '/var/www/speed.example.com', 'php_version' => '8.3', 'type' => 'php', 'web_server' => 'nginx', 'status' => 'active', 'ssl_status' => 'active']);
    }

    private function owner(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
    }

    /** @return array<string, mixed> */
    private function pageSpeedResponse(): array
    {
        return ['lighthouseResult' => [
            'categories' => [
                'performance' => ['score' => 0.91], 'accessibility' => ['score' => 0.88],
                'best-practices' => ['score' => 0.95], 'seo' => ['score' => 1],
            ],
            'audits' => [
                'first-contentful-paint' => ['displayValue' => '1.2 s', 'numericValue' => 1200],
                'largest-contentful-paint' => ['displayValue' => '2.1 s', 'numericValue' => 2100],
                'unused-css-rules' => ['title' => 'Reduce unused CSS', 'displayValue' => '20 KiB', 'details' => ['type' => 'opportunity']],
            ],
        ]];
    }

    public function test_pagespeed_uses_fixed_google_endpoint_and_persists_lighthouse_metrics(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response($this->pageSpeedResponse())]);
        $site = $this->site();
        $response = $this->actingAs($this->owner())->post(route('sites.pagespeed.store', $site), ['strategy' => 'mobile']);
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('page_speed_scans', ['site_id' => $site->id, 'strategy' => 'mobile', 'status' => 'completed', 'performance_score' => 91]);
        Http::assertSent(function ($request): bool {
            $url = $request->url();

            return str_starts_with($url, 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?')
                && str_contains($url, 'url=https%3A%2F%2Fspeed.example.com%2F')
                && substr_count($url, 'category=') === 4;
        });
        $this->actingAs($this->owner())->get(route('sites.pagespeed.index', $site))
            ->assertOk()
            ->assertSee('Resultado más reciente')
            ->assertSee('Métricas principales')
            ->assertSee('Oportunidades de mejora')
            ->assertSee('Reduce unused CSS');
    }

    public function test_pagespeed_failure_is_not_reported_as_a_fake_score(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['message' => 'quota exceeded']], 429)]);
        $site = $this->site();
        $this->actingAs($this->owner())->post(route('sites.pagespeed.store', $site), ['strategy' => 'desktop'])
            ->assertSessionHasErrors(['server' => 'Google agotó la cuota pública de PageSpeed para la IP de este servidor. Configura PAGESPEED_API_KEY para obtener una cuota propia o inténtalo cuando la cuota diaria se renueve.']);
        $this->assertDatabaseHas('page_speed_scans', ['site_id' => $site->id, 'status' => 'failed', 'performance_score' => null]);
        Http::assertSentCount(1);
        $site->pageSpeedScans()->firstOrFail()->update(['error' => 'HTTP request returned status code 429: Quota exceeded']);
        $this->get(route('sites.pagespeed.index', $site))
            ->assertOk()
            ->assertSee('Cuota de Google agotada. Configura PAGESPEED_API_KEY o espera su renovación.')
            ->assertDontSee('quota exceeded');
    }

    public function test_pagespeed_page_reports_whether_a_private_quota_is_configured(): void
    {
        $site = $this->site();
        $owner = $this->actingAs($this->owner());

        config(['services.pagespeed.key' => null]);
        $owner->get(route('sites.pagespeed.index', $site))->assertOk()->assertSee('PageSpeed usa la cuota pública de Google');

        config(['services.pagespeed.key' => 'private-test-key']);
        $owner->get(route('sites.pagespeed.index', $site))->assertOk()->assertSee('PageSpeed conectado con cuota propia')->assertDontSee('private-test-key');
    }

    public function test_owner_can_replace_pagespeed_key_without_exposing_it_in_the_command(): void
    {
        $site = $this->site();
        $key = 'AIzaSyExamplePrivateKey_1234567890';
        config(['xpanel.apply_system_changes' => true, 'xpanel.site_helper' => '/opt/xpanel-host/scripts/xpanel-site-helper.sh']);
        $this->mock(ServerCommandRunner::class, function ($mock) use ($key): void {
            $mock->shouldReceive('run')->once()->with([
                'sudo', '-n', '/opt/xpanel-host/scripts/xpanel-site-helper.sh', 'pagespeed-key-set',
            ], $key."\n");
        });

        $this->actingAs($this->owner())->put(route('sites.pagespeed.api-key', $site), [
            'action' => 'save', 'api_key' => $key,
        ])->assertRedirect()->assertSessionHas('status', 'Clave de PageSpeed actualizada y protegida.');

        $this->assertSame($key, config('services.pagespeed.key'));
    }

    public function test_invalid_pagespeed_key_is_never_flashed_back_to_the_session(): void
    {
        $site = $this->site();
        $secret = 'invalid secret with spaces';

        $this->actingAs($this->owner())->from(route('sites.pagespeed.index', $site))->put(route('sites.pagespeed.api-key', $site), [
            'action' => 'save', 'api_key' => $secret,
        ])->assertRedirect(route('sites.pagespeed.index', $site))->assertSessionHasErrors('api_key');

        $this->assertNull(session()->getOldInput('api_key'));
    }

    public function test_pagespeed_key_helper_uses_stdin_and_rolls_back_the_environment_on_failure(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));

        $this->assertStringContainsString('pagespeed_key_set()', $helper);
        $this->assertStringContainsString('IFS= read -r key', $helper);
        $this->assertStringContainsString('cp -a -- "$backup" "$ROOT/.env"', $helper);
        $this->assertStringContainsString('pagespeed-key-set) pagespeed_key_set', $helper);
    }

    public function test_diagnostic_parser_rejects_bad_protocol_and_local_run_persists_real_checks(): void
    {
        $service = app(SiteDiagnosticService::class);
        $message = base64_encode('Todo correcto.');
        $this->assertSame([['id' => 'http', 'status' => 'pass', 'message' => 'Todo correcto.']], $service->parse("check=http\tpass\t{$message}\n"));
        $this->expectException(\RuntimeException::class);
        $service->parse("check=http\tunknown\t{$message}\n");
    }

    public function test_diagnostic_controller_records_filesystem_security_and_backup_state(): void
    {
        config(['xpanel.apply_system_changes' => false]);
        $site = $this->site();
        $actor = $this->actingAs($this->owner());
        $actor->post(route('sites.diagnostics.store', $site))->assertSessionHas('status');
        $this->assertDatabaseHas('site_diagnostics', ['site_id' => $site->id, 'status' => 'completed']);
        $actor->get(route('sites.diagnostics.index', $site))->assertOk()->assertSee('document root local')->assertSee('No existe todavía un backup');
    }
}
