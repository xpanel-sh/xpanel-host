<?php

namespace Tests\Unit;

use App\Services\ServerCommandRunner;
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
}
