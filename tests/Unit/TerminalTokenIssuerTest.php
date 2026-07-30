<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Services\TerminalTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TerminalTokenIssuerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['xpanel.terminal_signing_key' => 'test-signing-key-not-app-key']);
    }

    private function site(): Site
    {
        return Site::create([
            'domain' => 'terminal.example.com',
            'document_root' => '/var/www/nonexistent-terminal.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ]);
    }

    public function test_a_freshly_issued_token_can_be_consumed_exactly_once(): void
    {
        $issuer = new TerminalTokenIssuer;
        $site = $this->site();
        ['token' => $token] = $issuer->issue($site);

        $first = $issuer->verifyAndConsume($token);
        $this->assertNotNull($first);
        $this->assertSame($site->systemUser(), $first['system_user']);

        $second = $issuer->verifyAndConsume($token);
        $this->assertNull($second, 'A token must not be usable twice.');
    }

    public function test_a_tampered_token_is_rejected(): void
    {
        $issuer = new TerminalTokenIssuer;
        ['token' => $token] = $issuer->issue($this->site());
        [$body, $signature] = explode('.', $token, 2);
        $tampered = $body.'x.'.$signature;

        $this->assertNull($issuer->verifyAndConsume($tampered));
    }

    public function test_a_token_signed_with_a_different_key_is_rejected(): void
    {
        config(['xpanel.terminal_signing_key' => 'key-one']);
        ['token' => $token] = (new TerminalTokenIssuer)->issue($this->site());

        config(['xpanel.terminal_signing_key' => 'key-two']);
        $this->assertNull((new TerminalTokenIssuer)->verifyAndConsume($token));
    }

    public function test_an_expired_token_is_rejected(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');
        ['token' => $token] = (new TerminalTokenIssuer)->issue($this->site());

        Carbon::setTestNow('2026-01-01 00:01:00');
        $this->assertNull((new TerminalTokenIssuer)->verifyAndConsume($token));
        Carbon::setTestNow();
    }
}
