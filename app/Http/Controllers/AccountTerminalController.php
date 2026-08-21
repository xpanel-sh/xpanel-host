<?php

namespace App\Http\Controllers;

use App\Services\HostingAccountWorkspace;
use App\Services\TerminalTokenIssuer;
use Illuminate\Http\JsonResponse;

class AccountTerminalController extends Controller
{
    public function token(HostingAccountWorkspace $workspace, TerminalTokenIssuer $issuer): JsonResponse
    {
        abort_unless(config('xpanel.terminal_enabled'), 404);

        return response()->json([
            'path' => '/terminal-ws',
            'system_user' => $workspace->user(),
            'home' => $workspace->systemRoot(),
            ...$issuer->issueAccount($workspace->user(), $workspace->systemRoot()),
        ]);
    }
}
