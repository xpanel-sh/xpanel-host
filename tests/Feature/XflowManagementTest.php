<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\XflowWorkflow;
use App\Services\XflowGraphValidator;
use App\Services\XflowRunner;
use App\Services\XflowSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class XflowManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'owner'): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role)->firstOrFail()->id]);
    }

    private function site(string $domain = 'flow.example.com'): Site
    {
        return Site::create([
            'domain' => $domain,
            'document_root' => '/var/www/'.$domain,
            'php_version' => '8.3',
            'type' => 'php',
            'web_server' => 'nginx',
            'status' => 'active',
            'ssl_status' => 'inactive',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function nodes(string $trigger = 'manual'): array
    {
        return [
            ['id' => 'trigger-1', 'type' => 'trigger', 'handler' => 'trigger.'.$trigger, 'label' => 'Inicio', 'x' => 80, 'y' => 120, 'config' => []],
            ['id' => 'notify-1', 'type' => 'action', 'handler' => 'action.notify', 'label' => 'Avisar', 'x' => 360, 'y' => 120, 'config' => ['title' => 'Listo', 'message' => 'El flujo terminó.', 'level' => 'success']],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function workflow(User $user, array $overrides = []): XflowWorkflow
    {
        return XflowWorkflow::create($overrides + [
            'name' => 'Publicar sitio',
            'scope' => 'account',
            'status' => 'active',
            'trigger_type' => 'manual',
            'trigger_config' => [],
            'nodes' => $this->nodes(),
            'edges' => [['from' => 'trigger-1', 'to' => 'notify-1', 'branch' => 'always']],
            'created_by' => $user->id,
        ]);
    }

    public function test_xflow_is_available_globally_and_inside_a_site(): void
    {
        $owner = $this->user();
        $site = $this->site();
        $workflow = $this->workflow($owner, ['scope' => 'site', 'site_id' => $site->id]);

        $this->actingAs($owner)->get(route('xflow.index'))->assertOk()->assertSee($workflow->name);
        $this->actingAs($owner)->get(route('sites.xflow.index', $site))->assertOk()
            ->assertSee('Automatizaciones confinadas a este sitio.')
            ->assertSee($workflow->name)
            ->assertSee(route('sites.xflow.builder', [$site, $workflow]), false);
        $this->actingAs($owner)->get(route('sites.xflow.builder', [$site, $workflow]))->assertOk();
        $other = $this->site('other.example.com');
        $this->actingAs($owner)->get(route('sites.xflow.builder', [$other, $workflow]))->assertNotFound();
    }

    public function test_owner_can_create_and_save_a_site_scoped_workflow(): void
    {
        $owner = $this->user();
        $site = $this->site();

        $response = $this->actingAs($owner)->post(route('xflow.store'), [
            'name' => 'Backup nocturno',
            'description' => 'Protege la web.',
            'scope' => 'site',
            'site_id' => $site->id,
            'trigger_type' => 'schedule',
        ]);
        $workflow = XflowWorkflow::firstOrFail();
        $response->assertRedirect(route('sites.xflow.builder', [$site, $workflow]));

        $this->actingAs($owner)->put(route('xflow.update', $workflow), [
            'name' => $workflow->name,
            'description' => $workflow->description,
            'status' => 'active',
            'trigger_config' => ['frequency' => 'daily', 'hour' => 3, 'minute' => 15],
            'nodes_json' => json_encode($this->nodes('schedule')),
            'edges_json' => json_encode([['from' => 'trigger-1', 'to' => 'notify-1', 'branch' => 'always']]),
        ])->assertRedirect();

        $this->assertSame('active', $workflow->fresh()->status);
        $this->assertNotNull($workflow->fresh()->next_run_at);
    }

    public function test_graph_rejects_unknown_nodes_and_cycles(): void
    {
        $validator = app(XflowGraphValidator::class);
        $nodes = $this->nodes();
        $nodes[] = ['id' => 'shell-1', 'handler' => 'action.shell', 'label' => 'Shell'];

        try {
            $validator->validate($nodes, [], 'manual');
            $this->fail('An unsafe handler was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('graph', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        $validator->validate($this->nodes(), [
            ['from' => 'trigger-1', 'to' => 'notify-1', 'branch' => 'always'],
            ['from' => 'notify-1', 'to' => 'trigger-1', 'branch' => 'always'],
        ], 'manual');
    }

    public function test_manual_execution_records_trace_and_notifies_the_team(): void
    {
        $owner = $this->user();
        $workflow = $this->workflow($owner);

        $response = $this->actingAs($owner)->post(route('xflow.run', $workflow));
        $run = $workflow->runs()->firstOrFail();

        $response->assertRedirect(route('xflow.runs.show', $run));
        $this->assertSame('completed', $run->status);
        $this->assertCount(2, $run->steps);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $owner->id]);
    }

    public function test_webhook_requires_its_private_token_and_executes(): void
    {
        $owner = $this->user();
        $token = hash('sha256', 'private-xflow-token');
        $workflow = $this->workflow($owner, [
            'trigger_type' => 'webhook',
            'nodes' => $this->nodes('webhook'),
            'webhook_token' => $token,
        ]);

        $this->postJson(route('xflow.webhook', [$workflow, str_repeat('a', 64)]), ['release' => 'v1'])->assertNotFound();
        $this->postJson(route('xflow.webhook', [$workflow, $token]), ['release' => 'v1'])
            ->assertOk()->assertJson(['ok' => true, 'status' => 'completed']);
    }

    public function test_viewer_can_inspect_but_cannot_modify_xflow(): void
    {
        $owner = $this->user();
        $viewer = $this->user('viewer');
        $workflow = $this->workflow($owner);

        $this->actingAs($viewer)->get(route('xflow.builder', $workflow))->assertOk();
        $this->actingAs($viewer)->delete(route('xflow.destroy', $workflow))->assertForbidden();
    }

    public function test_site_scope_cannot_be_overridden_by_a_node_target(): void
    {
        $owner = $this->user();
        $allowed = $this->site('allowed.example.com');
        $foreign = $this->site('foreign.example.com');
        $nodes = [
            ['id' => 'trigger-1', 'type' => 'trigger', 'handler' => 'trigger.manual', 'label' => 'Inicio', 'x' => 80, 'y' => 120, 'config' => []],
            ['id' => 'check-1', 'type' => 'condition', 'handler' => 'condition.site_status', 'label' => 'Está activo', 'x' => 300, 'y' => 120, 'config' => ['target' => 'site', 'site_id' => $foreign->id, 'operator' => 'equals', 'value' => 'active']],
        ];
        $workflow = $this->workflow($owner, [
            'scope' => 'site', 'site_id' => $allowed->id, 'nodes' => $nodes,
            'edges' => [['from' => 'trigger-1', 'to' => 'check-1', 'branch' => 'always']],
        ]);

        $run = app(XflowRunner::class)->run($workflow);
        $output = $run->steps()->where('node_id', 'check-1')->firstOrFail()->output;

        $this->assertSame([$allowed->domain], $output['sites']);
    }

    public function test_due_scheduled_workflow_is_executed_and_rescheduled(): void
    {
        $owner = $this->user();
        $workflow = $this->workflow($owner, [
            'trigger_type' => 'schedule',
            'trigger_config' => ['frequency' => 'hourly'],
            'nodes' => $this->nodes('schedule'),
            'next_run_at' => now()->subMinute(),
        ]);

        $this->artisan('xpanel:xflow-run')->assertSuccessful();

        $this->assertDatabaseHas('xflow_runs', ['workflow_id' => $workflow->id, 'trigger' => 'schedule', 'status' => 'completed']);
        $this->assertTrue($workflow->fresh()->next_run_at->isFuture());
        $this->assertNotNull(app(XflowSchedule::class)->next($workflow->fresh()));
    }
}
