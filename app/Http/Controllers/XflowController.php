<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\XflowRun;
use App\Models\XflowWorkflow;
use App\Services\XflowCatalog;
use App\Services\XflowGraphValidator;
use App\Services\XflowRunner;
use App\Services\XflowSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class XflowController extends Controller
{
    public function index(?Site $site = null): View
    {
        $scope = XflowWorkflow::query();
        if ($site) {
            $scope->where('site_id', $site->id);
        }
        $workflowIds = (clone $scope)->select('id');
        $recentRuns = XflowRun::query()->with('workflow.site')->whereIn('workflow_id', $workflowIds)->latest()->limit(8)->get();

        return view('xflow.index', [
            'site' => $site,
            'workflows' => (clone $scope)->with(['site', 'creator'])->withCount('runs')->latest()->paginate(18),
            'recentRuns' => $recentRuns,
            'stats' => [
                'workflows' => (clone $scope)->count(),
                'active' => (clone $scope)->where('status', 'active')->count(),
                'runs' => XflowRun::query()->whereIn('workflow_id', $workflowIds)->count(),
                'failures' => XflowRun::query()->whereIn('workflow_id', $workflowIds)->where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count(),
            ],
        ]);
    }

    public function create(Request $request, XflowCatalog $catalog): View
    {
        $site = $request->filled('site') ? Site::where('domain', (string) $request->string('site'))->firstOrFail() : null;

        return view('xflow.create', ['site' => $site, 'sites' => Site::orderBy('domain')->get(), 'catalog' => $catalog]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'],
            'scope' => ['required', Rule::in(['account', 'site'])], 'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'trigger_type' => ['required', Rule::in(['manual', 'schedule', 'event', 'webhook'])],
        ]);
        if ($data['scope'] === 'site' && empty($data['site_id'])) {
            return back()->withInput()->withErrors(['site_id' => 'Selecciona el sitio que limitará este XFlow.']);
        }
        $trigger = 'trigger.'.$data['trigger_type'];
        $workflow = XflowWorkflow::create([
            'name' => trim($data['name']), 'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'scope' => $data['scope'], 'site_id' => $data['scope'] === 'site' ? $data['site_id'] : null,
            'status' => 'draft', 'trigger_type' => $data['trigger_type'], 'trigger_config' => $this->defaultTriggerConfig($data['trigger_type']),
            'nodes' => [['id' => 'trigger-1', 'type' => 'trigger', 'handler' => $trigger, 'label' => 'Inicio', 'x' => 100, 'y' => 160, 'config' => ['retries' => 0]]],
            'edges' => [], 'webhook_token' => $data['trigger_type'] === 'webhook' ? hash('sha256', Str::random(80)) : null,
            'created_by' => $request->user()->id,
        ]);

        $route = $workflow->site_id ? 'sites.xflow.builder' : 'xflow.builder';
        $parameters = $workflow->site_id ? ['site' => $workflow->site, 'workflow' => $workflow] : ['workflow' => $workflow];

        return redirect()->route($route, $parameters);
    }

    public function builder(XflowWorkflow $workflow, XflowCatalog $catalog): View
    {
        return $this->builderView($workflow, $catalog);
    }

    public function siteBuilder(Site $site, XflowWorkflow $workflow, XflowCatalog $catalog): View
    {
        abort_unless($workflow->site_id === $site->id, 404);

        return $this->builderView($workflow, $catalog);
    }

    private function builderView(XflowWorkflow $workflow, XflowCatalog $catalog): View
    {
        return view('xflow.builder', [
            'workflow' => $workflow->load(['site', 'creator']), 'sites' => Site::orderBy('domain')->get(),
            'catalog' => $catalog->nodes(), 'events' => $catalog->events(), 'schedules' => $catalog->schedules(),
        ]);
    }

    public function update(Request $request, XflowWorkflow $workflow, XflowGraphValidator $graphs, XflowSchedule $schedule): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(['draft', 'active', 'paused'])],
            'trigger_config' => ['nullable', 'array'], 'nodes_json' => ['required', 'json'], 'edges_json' => ['required', 'json'],
        ]);
        $graph = $graphs->validate(json_decode($data['nodes_json'], true), json_decode($data['edges_json'], true), $workflow->trigger_type);
        $triggerConfig = $this->triggerConfig($workflow->trigger_type, $data['trigger_config'] ?? []);
        $workflow->fill([
            'name' => trim($data['name']), 'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'status' => $data['status'], 'trigger_config' => $triggerConfig,
            'nodes' => $graph['nodes'], 'edges' => $graph['edges'],
        ]);
        if ($workflow->trigger_type === 'webhook' && ! $workflow->webhook_token) {
            $workflow->webhook_token = hash('sha256', Str::random(80));
        }
        $workflow->next_run_at = $schedule->next($workflow);
        $workflow->save();

        return back();
    }

    public function run(Request $request, XflowWorkflow $workflow, XflowRunner $runner): RedirectResponse
    {
        try {
            $run = $runner->run($workflow, 'manual', [], $request->user());
        } catch (\Throwable $exception) {
            return back()->withErrors(['xflow' => $exception->getMessage()]);
        }

        return redirect()->route('xflow.runs.show', $run)->with('status', 'Ejecución terminada.');
    }

    public function toggle(XflowWorkflow $workflow, XflowGraphValidator $graphs, XflowSchedule $schedule): RedirectResponse
    {
        if ($workflow->status !== 'active') {
            $graphs->validate($workflow->nodes, $workflow->edges, $workflow->trigger_type);
        }
        $workflow->status = $workflow->status === 'active' ? 'paused' : 'active';
        $workflow->next_run_at = $schedule->next($workflow);
        $workflow->save();

        return back()->with('status', $workflow->status === 'active' ? 'XFlow activado.' : 'XFlow pausado.');
    }

    public function destroy(XflowWorkflow $workflow): RedirectResponse
    {
        $site = $workflow->site;
        $workflow->delete();

        return redirect()->route($site ? 'sites.xflow.index' : 'xflow.index', $site ? ['site' => $site] : [])->with('status', 'XFlow eliminado con su historial.');
    }

    public function runShow(XflowRun $run): View
    {
        return view('xflow.run', ['run' => $run->load(['workflow.site', 'initiator', 'steps'])]);
    }

    /** @return array<string, mixed> */
    private function triggerConfig(string $type, array $config): array
    {
        return match ($type) {
            'schedule' => [
                'frequency' => in_array(($config['frequency'] ?? ''), ['every_five_minutes', 'hourly', 'daily', 'weekly'], true) ? $config['frequency'] : 'daily',
                'hour' => max(0, min(23, (int) ($config['hour'] ?? 2))), 'minute' => max(0, min(59, (int) ($config['minute'] ?? 0))),
                'weekday' => max(1, min(7, (int) ($config['weekday'] ?? 1))),
            ],
            'event' => ['event' => in_array(($config['event'] ?? ''), array_keys(app(XflowCatalog::class)->events()), true) ? $config['event'] : 'site.updated'],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function defaultTriggerConfig(string $type): array
    {
        return $this->triggerConfig($type, []);
    }
}
