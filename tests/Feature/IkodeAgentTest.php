<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IkodeAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_connections_are_encrypted_and_only_return_safe_metadata(): void
    {
        $user = $this->developer();

        $response = $this->actingAs($user)->postJson(route('sites.ikode.api.agents.connections.store'), [
            'provider' => 'openai',
            'name' => 'Codex principal',
            'model' => 'gpt-5-mini',
            'api_key' => 'sk-test-secret-that-must-be-encrypted',
        ])->assertCreated()->assertJsonMissing(['api_key' => 'sk-test-secret-that-must-be-encrypted']);

        $connectionId = $response->json('connection.id');
        $encrypted = DB::table('ai_connections')->find($connectionId)->api_key;
        $this->assertNotSame('sk-test-secret-that-must-be-encrypted', $encrypted);

        $this->actingAs($user)->putJson(route('sites.ikode.api.agents.connections.update', $connectionId), [
            'name' => 'Codex ajustado', 'model' => 'gpt-5-mini', 'api_key' => '',
        ])->assertOk()->assertJsonPath('connection.name', 'Codex ajustado');
        $this->assertSame($encrypted, DB::table('ai_connections')->find($connectionId)->api_key);

        $this->actingAs($user)->getJson(route('sites.ikode.api.agents.state'))
            ->assertOk()
            ->assertJsonPath('connections.0.name', 'Codex ajustado')
            ->assertJsonMissing(['api_key' => 'sk-test-secret-that-must-be-encrypted']);
    }

    public function test_site_chat_sends_only_the_site_context_and_excludes_secrets(): void
    {
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output' => [['content' => [['type' => 'output_text', 'text' => 'El archivo está correcto.']]]],
            ]),
        ]);
        $user = $this->developer();
        $site = $this->site('agent-'.uniqid().'.example.com');
        file_put_contents($site->localRoot().'/index.js', 'console.log("scope-ok")');
        file_put_contents($site->localRoot().'/.env', 'SECRET_MUST_NOT_LEAK=yes');
        $connection = AiConnection::create([
            'user_id' => $user->id,
            'provider' => 'openai',
            'name' => 'Codex',
            'model' => 'gpt-5-mini',
            'api_key' => 'sk-test-secret-that-must-be-encrypted',
            'status' => 'configured',
        ]);

        $this->actingAs($user)->postJson(route('sites.files.api.agents.chat', $site), [
            'connection_id' => $connection->id,
            'message' => 'Revisa el archivo activo',
            'active_path' => '/index.js',
        ])->assertOk()->assertJsonPath('message.content', 'El archivo está correcto.');

        Http::assertSent(function ($request): bool {
            $instructions = (string) $request['instructions'];

            return str_contains($instructions, 'scope-ok')
                && str_contains($instructions, 'Ámbito autorizado: sitio')
                && ! str_contains($instructions, 'SECRET_MUST_NOT_LEAK')
                && ! str_contains($instructions, '.env');
        });
        $this->assertDatabaseHas('ai_conversations', ['site_id' => $site->id, 'scope_key' => 'site:'.$site->id]);
        $this->assertSame('connected', $connection->fresh()->status);
    }

    public function test_account_and_site_histories_are_independent(): void
    {
        $user = $this->developer();
        $site = $this->site('scope-'.uniqid().'.example.com');
        $connection = AiConnection::create([
            'user_id' => $user->id, 'provider' => 'anthropic', 'name' => 'Claude',
            'model' => 'claude-sonnet-4-20250514', 'api_key' => 'anthropic-test-secret-key',
        ]);
        $connection->conversations()->create([
            'user_id' => $user->id, 'scope_key' => 'account', 'title' => 'Cuenta completa',
        ])->messages()->create(['role' => 'user', 'content' => 'Mensaje global']);
        $connection->conversations()->create([
            'user_id' => $user->id, 'site_id' => $site->id, 'scope_key' => 'site:'.$site->id, 'title' => $site->domain,
        ])->messages()->create(['role' => 'user', 'content' => 'Mensaje del sitio']);

        $this->actingAs($user)->getJson(route('sites.ikode.api.agents.state', ['connection_id' => $connection->id]))
            ->assertOk()->assertJsonFragment(['content' => 'Mensaje global'])->assertJsonMissing(['content' => 'Mensaje del sitio']);
        $this->actingAs($user)->getJson(route('sites.files.api.agents.state', [$site, 'connection_id' => $connection->id]))
            ->assertOk()->assertJsonFragment(['content' => 'Mensaje del sitio'])->assertJsonMissing(['content' => 'Mensaje global']);
    }

    public function test_anthropic_connections_use_the_messages_api_without_exposing_the_key(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Respuesta de Claude']],
            ]),
        ]);
        $user = $this->developer();
        $connection = AiConnection::create([
            'user_id' => $user->id, 'provider' => 'anthropic', 'name' => 'Claude',
            'model' => 'claude-sonnet-4-20250514', 'api_key' => 'anthropic-test-secret-key',
        ]);

        $this->actingAs($user)->postJson(route('sites.ikode.api.agents.chat'), [
            'connection_id' => $connection->id,
            'message' => 'Hola Claude',
        ])->assertOk()->assertJsonPath('message.content', 'Respuesta de Claude');

        Http::assertSent(fn ($request): bool => $request->hasHeader('x-api-key', 'anthropic-test-secret-key')
            && $request['model'] === 'claude-sonnet-4-20250514'
            && str_contains($request['system'], 'cuenta completa'));
    }

    public function test_one_agent_can_keep_multiple_independent_chats_in_the_same_scope(): void
    {
        $user = $this->developer();
        $connection = AiConnection::create([
            'user_id' => $user->id, 'provider' => 'openai', 'name' => 'Codex',
            'model' => 'gpt-5-mini', 'api_key' => 'openai-test-secret-key',
        ]);

        $first = $this->actingAs($user)->postJson(route('sites.ikode.api.agents.conversations.store'), [
            'connection_id' => $connection->id,
        ])->assertCreated()->json('conversation.id');
        $second = $this->actingAs($user)->postJson(route('sites.ikode.api.agents.conversations.store'), [
            'connection_id' => $connection->id,
        ])->assertCreated()->json('conversation.id');
        $connection->conversations()->findOrFail($first)->messages()->create(['role' => 'user', 'content' => 'Chat uno']);
        $connection->conversations()->findOrFail($second)->messages()->create(['role' => 'user', 'content' => 'Chat dos']);

        $this->actingAs($user)->getJson(route('sites.ikode.api.agents.state', [
            'connection_id' => $connection->id,
            'conversation_id' => $first,
        ]))->assertOk()
            ->assertJsonCount(2, 'conversations')
            ->assertJsonPath('active_conversation_id', $first)
            ->assertJsonFragment(['content' => 'Chat uno'])
            ->assertJsonMissing(['content' => 'Chat dos']);
    }

    private function developer(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'developer')->firstOrFail()->id]);
    }

    private function site(string $domain): Site
    {
        return Site::create([
            'domain' => $domain,
            'document_root' => '/var/www/nonexistent-in-tests/'.$domain,
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ]);
    }
}
