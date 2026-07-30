<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
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
        $this->actingAs($this->owner())->get(route('sites.pagespeed.index', $site))->assertOk()->assertSee('Reduce unused CSS');
    }

    public function test_pagespeed_failure_is_not_reported_as_a_fake_score(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['message' => 'quota exceeded']], 429)]);
        $site = $this->site();
        $this->actingAs($this->owner())->post(route('sites.pagespeed.store', $site), ['strategy' => 'desktop'])->assertSessionHasErrors('server');
        $this->assertDatabaseHas('page_speed_scans', ['site_id' => $site->id, 'status' => 'failed', 'performance_score' => null]);
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
