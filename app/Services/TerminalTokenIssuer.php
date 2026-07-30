<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Short-lived, single-use capability tokens for the real per-site terminal.
 *
 * The Go terminal agent never touches the Laravel database or holds APP_KEY:
 * it only trusts a token signed with a dedicated secret (XPANEL_TERMINAL_SIGNING_KEY),
 * and calls back to consume() once to burn it before opening the SSH session.
 * Even a replayed/stolen token still has to pass sshd's own Match-User block
 * for that site, so this is defense in depth, not the only gate.
 */
class TerminalTokenIssuer
{
    private const TTL_SECONDS = 20;

    public function issue(Site $site): array
    {
        $payload = [
            'jti' => Str::random(32),
            'site_id' => $site->id,
            'system_user' => $site->systemUser(),
            'exp' => now()->addSeconds(self::TTL_SECONDS)->timestamp,
        ];

        return ['token' => $this->encode($payload), 'expires_in' => self::TTL_SECONDS];
    }

    /**
     * Verifies the signature/expiry and atomically marks the token as used.
     * Returns the decoded payload on first (and only) success, null otherwise.
     */
    public function verifyAndConsume(string $token): ?array
    {
        $payload = $this->decode($token);
        if ($payload === null) {
            return null;
        }

        return Cache::add('terminal-token:'.$payload['jti'], true, self::TTL_SECONDS) ? $payload : null;
    }

    private function encode(array $payload): string
    {
        $body = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $body, $this->signingKey(), true));

        return $body.'.'.$signature;
    }

    private function decode(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $signature] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $body, $this->signingKey(), true));
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $json = $this->base64UrlDecode($body);
        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($payload)
            || ! array_key_exists('jti', $payload) || ! is_string($payload['jti']) || $payload['jti'] === ''
            || ! array_key_exists('system_user', $payload) || ! is_string($payload['system_user']) || $payload['system_user'] === ''
            || ! array_key_exists('exp', $payload)
        ) {
            return null;
        }
        if ((int) $payload['exp'] < now()->timestamp) {
            return null;
        }

        return $payload;
    }

    private function signingKey(): string
    {
        $key = (string) config('xpanel.terminal_signing_key');
        if ($key === '') {
            throw new \RuntimeException('XPANEL_TERMINAL_SIGNING_KEY no está configurada.');
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true) ?: '';
    }
}
