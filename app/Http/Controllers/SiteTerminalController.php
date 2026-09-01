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

        return response()->json([
            'path' => '/terminal-ws',
            'system_user' => $site->systemUser(),
            ...$issuer->issue($site),
        ]);
    }

    /**
     * Called by the sshd forced-command gate to burn the token immediately
     * before it opens the real shell. Never touched by the browser directly.
     */
    public function consume(Request $request, TerminalTokenIssuer $issuer): JsonResponse
    {
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);

        $token = (string) $request->input('token', '');
        $payload = $token === '' ? null : $issuer->verifyAndConsume($token);
        abort_if($payload === null, 403, 'Token invalido o ya usado.');

        return response()->json(array_filter([
            'ok' => true,
            'site_id' => $payload['site_id'],
            'system_user' => $payload['system_user'],
            'home' => $payload['home'] ?? null,
            'runtime_token' => $issuer->issueRuntime($payload),
        ], fn ($value) => $value !== null));
    }
}
