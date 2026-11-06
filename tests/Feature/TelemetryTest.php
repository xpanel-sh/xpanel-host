<?php

namespace Tests\Feature;

use App\Services\TelemetryReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_standalone_host_sends_only_selected_categories(): void
    {
        config()->set('xpanel.management_mode', 'standalone');
        $reporter = app(TelemetryReporter::class);
        $reporter->save(true, ['deployment', 'workload']);
        Http::fake(['xpanel.sh/*' => Http::response(['accepted' => true], 202)]);

        $this->assertTrue($reporter->send());
        Http::assertSent(fn ($request) => $request['product'] === 'xpanel-host'
            && $request['deployment']['mode'] === 'standalone'
            && isset($request['workload']) && ! isset($request['compute'], $request['gpu']));
    }

    public function test_managed_host_does_not_duplicate_the_vps_heartbeat(): void
    {
        config()->set('xpanel.management_mode', 'vps-instance');
        Http::fake();

        $this->assertFalse(app(TelemetryReporter::class)->send());
        Http::assertNothingSent();
    }
}
