<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ControlPlaneSsoController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(config('xpanel.management_mode') === 'vps-instance' && config('xpanel.sso_enabled'), 404);
        $parts = explode('.', (string) $request->query('token'), 2);
        abort_unless(count($parts) === 2, 403, 'Acceso SSO inválido.');

        [$encoded, $signature] = $parts;
        $expected = $this->encode(hash_hmac('sha256', $encoded, (string) config('xpanel.broker_secret'), true));
        abort_unless(hash_equals($expected, $signature), 403, 'Acceso SSO inválido.');

        $payload = json_decode($this->decode($encoded), true, 16, JSON_THROW_ON_ERROR);
        $now = now()->timestamp;
        abort_unless(
            is_array($payload)
            && ($payload['aud'] ?? null) === config('xpanel.instance_id')
            && is_string($payload['jti'] ?? null)
            && is_string($payload['email'] ?? null)
            && (int) ($payload['iat'] ?? 0) <= $now + 10
            && (int) ($payload['exp'] ?? 0) >= $now,
            403,
            'El acceso SSO expiró o pertenece a otra instancia.',
        );
        abort_unless(Cache::add('control-plane-sso:'.$payload['jti'], true, max(60, (int) $payload['exp'] - $now + 60)), 403, 'Este acceso SSO ya fue utilizado.');

        $user = User::query()->where('email', $payload['email'])->first()
            ?? User::query()->whereHas('role', fn ($query) => $query->where('slug', 'owner'))->first();
        abort_unless($user, 403, 'La cuenta todavía no está sincronizada con esta instancia.');

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        abort_if($decoded === false, 403, 'Acceso SSO inválido.');

        return $decoded;
    }
}
