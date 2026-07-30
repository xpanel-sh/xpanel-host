<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\TerminalTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteTerminalController extends Controller
{
    public function token(Site $site, TerminalTokenIssuer $issuer): JsonResponse
    {
        abort_unless(config('xpanel.terminal_enabled'), 404);
        $settings = $site->accessSettings()->first();
        abort_unless($settings?->web_terminal_enabled, 422, 'Activa la terminal real en Acceso SSH antes de abrirla.');

        return response()->json(['path' => '/terminal-ws', ...$issuer->issue($site)]);
    }

    /**
     * Called by the loopback-only Go terminal agent to burn a token before it
     * opens the real SSH session. Never touched by the browser directly.
     */
    public function consume(Request $request, TerminalTokenIssuer $issuer): JsonResponse
    {
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);

        $token = (string) $request->input('token', '');
        $payload = $token === '' ? null : $issuer->verifyAndConsume($token);
        abort_if($payload === null, 403, 'Token invalido o ya usado.');

        return response()->json(['ok' => true, 'system_user' => $payload['system_user']]);
    }
}
