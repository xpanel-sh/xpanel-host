<?php

namespace Tests\Unit;

use App\Services\ServerCommandRunner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServerCommandRunnerTest extends TestCase
{
    public function test_commands_always_run_from_the_accessible_project_directory(): void
    {
        $output = app(ServerCommandRunner::class)->run([
            PHP_BINARY, '-r', 'echo getcwd();',
        ]);

        $this->assertSame(realpath(base_path()), realpath($output));
    }

    public function test_instance_helper_commands_are_signed_and_sent_to_the_vps_broker(): void
    {
        config()->set('xpanel.management_mode', 'vps-instance');
        config()->set('xpanel.instance_id', '01234567-89ab-cdef-8123-456789abcdef');
        config()->set('xpanel.broker_secret', str_repeat('a', 64));
        config()->set('xpanel.broker_url', 'https://control.test/api/internal/host-broker');
        config()->set('xpanel.site_helper', '/opt/xpanel-host/scripts/xpanel-site-helper.sh');
        Http::fake(function (Request $request) {
            $payload = $request->data();
            $expected = hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), str_repeat('a', 64));
            $this->assertSame($expected, $request->header('X-XPanel-Signature')[0]);
            $this->assertSame('database-create', $payload['action']);
            $this->assertSame(['xp_012345_demo', 'xp_012345_user'], $payload['arguments']);
            $this->assertSame(base64_encode("secret-password\n"), $payload['input']);

            return Http::response(['ok' => true, 'output' => 'created'], 200);
        });

        $output = app(ServerCommandRunner::class)->run([
            'sudo', '-n', config('xpanel.site_helper'), 'database-create',
            'xp_012345_demo', 'xp_012345_user',
        ], "secret-password\n");

        $this->assertSame('created', $output);
        Http::assertSentCount(1);
    }
}
