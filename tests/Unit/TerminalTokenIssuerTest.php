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

    public function test_a_token_that_laravel_never_issued_is_rejected(): void
    {
        $issuer = new TerminalTokenIssuer;
        $this->assertNull($issuer->verifyAndConsume(str_repeat('a', 64)));
    }

    public function test_issued_tokens_are_opaque_and_contain_no_site_identity(): void
    {
        ['token' => $token] = (new TerminalTokenIssuer)->issue($this->site());

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $token);
        $this->assertStringNotContainsString('.', $token);
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
