<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Short-lived, single-use capability tokens for the real per-site terminal.
 *
 * Tokens are opaque random capabilities whose payload exists only in Laravel's
 * cache. The Go agent cannot mint authorization for another Unix identity;
 * sshd's forced-command gate must atomically consume a Laravel-issued token.
 */
class TerminalTokenIssuer
{
    private const TTL_SECONDS = 20;

    public function issue(Site $site): array
    {
        $token = Str::random(64);
        $payload = [
            'site_id' => $site->id,
            'system_user' => $site->systemUser(),
        ];
        Cache::put($this->payloadKey($token), $payload, self::TTL_SECONDS);

        return ['token' => $token, 'expires_in' => self::TTL_SECONDS];
    }

    /**
     * Verifies the signature/expiry and atomically marks the token as used.
     * Returns the decoded payload on first (and only) success, null otherwise.
     */
    public function verifyAndConsume(string $token): ?array
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            return null;
        }

        $fingerprint = hash('sha256', $token);
        if (! Cache::add('terminal-token-claim:'.$fingerprint, true, self::TTL_SECONDS)) {
            return null;
        }
        $payload = Cache::pull($this->payloadKey($token));
        if (! is_array($payload)
            || ! array_key_exists('site_id', $payload) || ! is_int($payload['site_id'])
            || ! array_key_exists('system_user', $payload) || ! is_string($payload['system_user']) || $payload['system_user'] === ''
        ) {
            return null;
        }

        return $payload;
    }

    private function payloadKey(string $token): string
    {
        return 'terminal-token-payload:'.hash('sha256', $token);
    }
}
