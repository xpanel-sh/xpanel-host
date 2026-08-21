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

    public function issueAccount(string $systemUser, string $home): array
    {
        $token = Str::random(64);
        Cache::put($this->payloadKey($token), [
            'scope' => 'account',
            'site_id' => null,
            'system_user' => $systemUser,
            'home' => $home,
        ], self::TTL_SECONDS);

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
            || ! array_key_exists('site_id', $payload) || (! is_int($payload['site_id']) && $payload['site_id'] !== null)
            || ! array_key_exists('system_user', $payload) || ! is_string($payload['system_user']) || $payload['system_user'] === ''
            || (isset($payload['home']) && (! is_string($payload['home']) || ! str_starts_with($payload['home'], '/home/')))
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
