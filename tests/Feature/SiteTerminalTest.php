<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteTerminalTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::create([
            'domain' => 'terminal.example.com',
            'document_root' => '/var/www/nonexistent-terminal.example.com',
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
        ]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'owner')->firstOrFail()->id]);
    }

    public function test_token_endpoint_requires_the_server_wide_feature_flag(): void
    {
        $site = $this->site();
        $site->accessSettings()->create(['web_terminal_enabled' => true]);

        $this->actingAs($this->owner())
            ->postJson(route('sites.access.terminal.token', $site))
            ->assertNotFound();
    }

    public function test_token_endpoint_requires_the_site_toggle_to_be_on(): void
    {
        config(['xpanel.terminal_enabled' => true]);
        $site = $this->site();

        $this->actingAs($this->owner())
            ->postJson(route('sites.access.terminal.token', $site))
            ->assertUnprocessable();
    }

    public function test_token_endpoint_issues_a_usable_token_when_enabled(): void
    {
        config(['xpanel.terminal_enabled' => true, 'xpanel.terminal_signing_key' => 'test-signing-key']);
        $site = $this->site();
        $site->accessSettings()->create(['web_terminal_enabled' => true]);

        $response = $this->actingAs($this->owner())
            ->postJson(route('sites.access.terminal.token', $site))
            ->assertOk()
            ->assertJsonStructure(['path', 'token', 'expires_in']);

        $this->assertSame('/terminal-ws', $response->json('path'));
        $this->assertNotSame('', $response->json('token'));
    }

    public function test_consume_endpoint_rejects_non_loopback_callers(): void
    {
        config(['xpanel.terminal_signing_key' => 'test-signing-key']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post('/internal/terminal/consume', ['token' => 'irrelevant'])
            ->assertForbidden();
    }

    public function test_consume_endpoint_burns_the_token_exactly_once(): void
    {
        config(['xpanel.terminal_enabled' => true, 'xpanel.terminal_signing_key' => 'test-signing-key']);
        $site = $this->site();
        $site->accessSettings()->create(['web_terminal_enabled' => true]);

        $token = $this->actingAs($this->owner())
            ->postJson(route('sites.access.terminal.token', $site))
            ->json('token');

        $this->post('/internal/terminal/consume', ['token' => $token])
            ->assertOk()
            ->assertJson(['ok' => true, 'system_user' => $site->systemUser()]);

        $this->post('/internal/terminal/consume', ['token' => $token])
            ->assertForbidden();
    }
}
