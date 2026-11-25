<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\CodexSessionManager;
use App\Services\HostingAccountWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IkodeAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_codex_state_uses_account_login_and_rejects_api_keys(): void
    {
        $this->mock(CodexSessionManager::class)
            ->shouldReceive('authStatus')->once()->andReturn(['status' => 'connected']);
        $user = $this->developer();

        $this->actingAs($user)->getJson(route('sites.ikode.api.agents.state'))
            ->assertOk()->assertJsonPath('auth.status', 'connected')
            ->assertJsonPath('connections.0.provider', 'codex')
            ->assertJsonMissingPath('connections.0.api_key');
        $this->actingAs($user)->postJson(route('sites.ikode.api.agents.connections.store'), [
            'provider' => 'openai', 'name' => 'API', 'model' => 'example', 'api_key' => 'secret',
        ])->assertStatus(410);
    }

    public function test_site_chat_starts_a_job_in_the_selected_site_and_keeps_histories_separate(): void
    {
        $user = $this->developer();
        $parent = $this->site('parent-'.uniqid().'.example.com');
        $child = $this->site('child.'.$parent->domain);
        $child->update(['parent_site_id' => $parent->id]);
        $other = $this->site('other-'.uniqid().'.example.com');
        $codex = $this->mock(CodexSessionManager::class);
        $codex->shouldReceive('startJob')->once()->withArgs(function ($directory, $job, $thread, $prompt) use ($child): bool {
            return str_ends_with($directory, '/public_html/'.$child->domain)
                && strlen($job) === 36 && $thread === null
                && str_contains($prompt, 'Trabaja únicamente en el sitio '.$child->domain)
                && str_contains($prompt, 'Revisa el proyecto');
        });
        $codex->shouldReceive('authStatus')->andReturn(['status' => 'connected']);

        $response = $this->actingAs($user)->postJson(route('sites.files.api.agents.chat', $parent), [
            'target_site_id' => $child->id, 'message' => 'Revisa el proyecto',
        ])->assertStatus(202);
        $this->assertDatabaseHas('ai_conversations', [
            'site_id' => $child->id, 'scope_key' => 'site:'.$child->id,
            'codex_job_id' => $response->json('job'),
        ]);
        $codex->shouldReceive('jobStatus')->once()->with($response->json('job'))->andReturn([
            'state' => 'done', 'thread_id' => 'e7e5f159-0d95-43de-af8c-d194e37420c2',
            'answer' => 'Proyecto revisado.', 'error' => null,
        ]);
        $this->actingAs($user)->getJson(route('sites.files.api.agents.codex.jobs.status', [
            'site' => $parent, 'job' => $response->json('job'), 'target_site_id' => $child->id,
        ]))->assertOk()->assertJsonPath('status.state', 'done');
        $this->assertDatabaseHas('ai_messages', ['role' => 'assistant', 'content' => 'Proyecto revisado.']);
        $this->actingAs($user)->getJson(route('sites.files.api.agents.state', [$parent, 'target_site_id' => $other->id]))
            ->assertStatus(422);
        $this->actingAs($user)->getJson(route('sites.files.api.agents.state', $parent))
            ->assertOk()->assertJsonCount(0, 'conversations');
        $this->actingAs($user)->getJson(route('sites.files.api.agents.state', [$parent, 'target_site_id' => $child->id]))
            ->assertOk()->assertJsonCount(1, 'conversations');
    }

    private function developer(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'developer')->firstOrFail()->id]);
    }

    private function site(string $domain): Site
    {
        return Site::create([
            'domain' => $domain,
            'document_root' => app(HostingAccountWorkspace::class)->siteRoot($domain),
            'php_version' => '8.3',
            'type' => 'php',
            'status' => 'active',
        ]);
    }
}
