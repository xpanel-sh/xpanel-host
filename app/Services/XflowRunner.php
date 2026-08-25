<?php

namespace App\Services;

use App\Models\Site;
use App\Models\User;
use App\Models\XflowRun;
use App\Models\XflowWorkflow;
use App\Notifications\PanelActivityNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class XflowRunner
{
    public function __construct(
        private readonly SiteBackupManager $backups,
        private readonly GitDeploymentManager $deployments,
        private readonly SiteCacheManager $cache,
        private readonly SiteProvisioner $sites,
        private readonly MalwareScanner $malware,
        private readonly CertificateProvisioner $certificates,
    ) {}

    /** @param array<string, mixed> $input */
    public function run(XflowWorkflow $workflow, string $trigger = 'manual', array $input = [], ?User $user = null): XflowRun
    {
        if ($workflow->status !== 'active' && $trigger !== 'test') {
            throw new RuntimeException('Activa el workflow antes de ejecutarlo.');
        }
        $lock = Cache::lock('xflow:'.$workflow->uuid, 3600);
        if (! $lock->get()) {
            throw new RuntimeException('Este XFlow ya tiene una ejecución en curso.');
        }
        $run = $workflow->runs()->create([
            'initiated_by' => $user?->id, 'trigger' => $trigger, 'status' => 'running',
            'input' => $this->safePayload($input), 'started_at' => now(),
        ]);

        try {
            $this->execute($workflow->fresh(['site', 'creator']) ?? $workflow, $run);
        } finally {
            $lock->release();
        }

        return $run->fresh(['workflow.site', 'steps']) ?? $run;
    }

    private function execute(XflowWorkflow $workflow, XflowRun $run): void
    {
        $nodes = collect($workflow->nodes)->keyBy('id');
        $edges = collect($workflow->edges);
        $trigger = $nodes->firstWhere('type', 'trigger');
        if (! $trigger) {
            $this->failRun($run, 'El workflow no contiene un disparador válido.');

            return;
        }
        $this->recordStep($run, $trigger, 'completed', ['trigger' => $run->trigger], null, 0, 1);
        $queue = $edges->where('from', $trigger['id'])->map(fn ($edge) => $edge['to'])->values()->all();
        $visited = [$trigger['id'] => true];
        $results = [$trigger['id'] => ['status' => 'success', 'value' => true]];
        $outputs = [];
        $failed = false;

        while ($queue !== []) {
            $nodeId = array_shift($queue);
            if (isset($visited[$nodeId])) {
                continue;
            }
            $incoming = $edges->where('to', $nodeId);
            $eligible = $incoming->contains(function (array $edge) use ($results): bool {
                return isset($results[$edge['from']]) && $this->branchMatches($edge['branch'], $results[$edge['from']]);
            });
            if (! $eligible) {
                continue;
            }
            $node = $nodes->get($nodeId);
            if (! $node) {
                continue;
            }
            $visited[$nodeId] = true;
            $started = hrtime(true);
            $attempts = 1 + (int) ($node['config']['retries'] ?? 0);
            $result = null;
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                try {
                    $result = $this->executeNode($workflow, $run, $node);
                    $duration = (int) ((hrtime(true) - $started) / 1_000_000);
                    $this->recordStep($run, $node, 'completed', $result, null, $duration, $attempt);
                    break;
                } catch (\Throwable $exception) {
                    $duration = (int) ((hrtime(true) - $started) / 1_000_000);
                    $this->recordStep($run, $node, $attempt < $attempts ? 'retrying' : 'failed', null, $exception->getMessage(), $duration, $attempt);
                    if ($attempt === $attempts) {
                        $result = ['status' => 'failure', 'value' => false, 'error' => $exception->getMessage()];
                        $failed = true;
                    }
                }
            }
            $results[$nodeId] = $result ?? ['status' => 'failure', 'value' => false];
            $outputs[$nodeId] = $results[$nodeId];
            foreach ($edges->where('from', $nodeId) as $edge) {
                if ($this->branchMatches($edge['branch'], $results[$nodeId])) {
                    $queue[] = $edge['to'];
                }
            }
        }

        $run->update([
            'status' => $failed ? 'failed' : 'completed', 'output' => $outputs,
            'error' => $failed ? 'Uno o más nodos no pudieron completarse.' : null, 'finished_at' => now(),
        ]);
        $workflow->update(['last_run_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function executeNode(XflowWorkflow $workflow, XflowRun $run, array $node): array
    {
        $handler = $node['handler'];
        $targets = $this->targets($workflow, $node['config'] ?? [], $run->input ?? []);
        if (str_starts_with($handler, 'condition.')) {
            $value = $targets->isNotEmpty() && $targets->every(fn (Site $site): bool => $this->condition($handler, $site, $node['config'] ?? []));

            return ['status' => 'success', 'value' => $value, 'sites' => $targets->pluck('domain')->all()];
        }
        if ($targets->isEmpty() && $handler !== 'action.notify') {
            throw new RuntimeException('El nodo no tiene sitios válidos dentro de su alcance.');
        }
        $results = [];
        if ($handler === 'action.notify') {
            $config = $node['config'] ?? [];
            User::query()->each(fn (User $user) => $user->notify(new PanelActivityNotification(
                $config['title'] ?? 'XFlow', $config['message'] ?? 'El workflow completó una acción.',
                route('xflow.runs.show', $run), $config['level'] ?? 'info', 'ki-abstract-26',
            )));

            return ['status' => 'success', 'value' => true, 'notified' => User::count()];
        }
        foreach ($targets as $site) {
            $results[$site->domain] = $this->action($handler, $site, $workflow);
        }

        return ['status' => 'success', 'value' => true, 'sites' => $results];
    }

    private function action(string $handler, Site $site, XflowWorkflow $workflow): array|string|int|bool|null
    {
        return match ($handler) {
            'action.backup' => ['backup' => $this->backups->create($site, $workflow->creator, 'xflow')->token],
            'action.git_deploy' => $this->deploy($site),
            'action.cache_purge' => $this->cache->purge($site),
            'action.site_restart' => $this->restart($site),
            'action.malware_scan' => $this->scan($site, $workflow),
            'action.ssl_retry' => $this->issueCertificate($site, $workflow),
            default => throw new RuntimeException('La acción solicitada no está permitida por XFlow.'),
        };
    }

    private function deploy(Site $site): array
    {
        $repository = $site->gitRepository;
        if (! $repository) {
            throw new RuntimeException("{$site->domain} no tiene un repositorio Git conectado.");
        }
        $this->backups->create($site, null, 'xflow_pre_deploy');
        $this->deployments->deploy($site, $repository);

        return ['commit' => $repository->fresh()->last_commit, 'status' => $repository->fresh()->status];
    }

    private function restart(Site $site): array
    {
        $this->sites->restart($site);

        return ['restarted' => true];
    }

    private function issueCertificate(Site $site, XflowWorkflow $workflow): array
    {
        $this->certificates->issue($site, $workflow->creator?->email ?? 'admin@'.$site->domain, (bool) $site->https_redirect);

        return ['requested' => true];
    }

    private function scan(Site $site, XflowWorkflow $workflow): array
    {
        $scan = $site->malwareScans()->create(['token' => (string) Str::uuid(), 'user_id' => $workflow->created_by, 'status' => 'running']);
        try {
            $result = $this->malware->scan($site);
            $scan->update($result + ['status' => 'completed', 'completed_at' => now()]);

            return ['infected' => $result['infected_count'], 'files' => $result['files_scanned']];
        } catch (\Throwable $exception) {
            $scan->update(['status' => 'failed', 'error' => $exception->getMessage(), 'completed_at' => now()]);
            throw $exception;
        }
    }

    private function condition(string $handler, Site $site, array $config): bool
    {
        $actual = match ($handler) {
            'condition.site_status' => $site->status,
            'condition.ssl_status' => $site->ssl_status,
            'condition.site_type' => $site->type,
            default => throw new RuntimeException('La condición solicitada no está permitida por XFlow.'),
        };
        $matches = hash_equals((string) $actual, (string) ($config['value'] ?? ''));

        return ($config['operator'] ?? 'equals') === 'not_equals' ? ! $matches : $matches;
    }

    private function targets(XflowWorkflow $workflow, array $config, array $input)
    {
        if ($workflow->scope === 'site') {
            return $workflow->site ? collect([$workflow->site]) : collect();
        }
        $eventSite = isset($input['site_id']) ? Site::find((int) $input['site_id']) : null;
        if (($config['target'] ?? 'workflow') === 'site') {
            return Site::whereKey((int) ($config['site_id'] ?? 0))->get();
        }
        if (($config['target'] ?? 'workflow') === 'all') {
            return Site::orderBy('domain')->get();
        }

        return $eventSite ? collect([$eventSite]) : Site::whereNull('parent_site_id')->orderBy('domain')->get();
    }

    private function branchMatches(string $branch, array $result): bool
    {
        return match ($branch) {
            'always' => true, 'success' => ($result['status'] ?? '') === 'success',
            'failure' => ($result['status'] ?? '') === 'failure', 'true' => ($result['value'] ?? null) === true,
            'false' => ($result['value'] ?? null) === false, default => false,
        };
    }

    private function recordStep(XflowRun $run, array $node, string $status, ?array $output, ?string $error, int $duration, int $attempt): void
    {
        $run->steps()->create([
            'node_id' => $node['id'], 'node_type' => $node['type'], 'handler' => $node['handler'], 'label' => $node['label'],
            'status' => $status, 'attempt' => $attempt, 'input' => $run->input, 'output' => $output,
            'error' => $error ? Str::limit($error, 4000, '') : null, 'duration_ms' => $duration,
            'started_at' => now(), 'finished_at' => now(),
        ]);
    }

    private function failRun(XflowRun $run, string $message): void
    {
        $run->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
    }

    private function safePayload(array $input): array
    {
        return json_decode(json_encode($input, JSON_THROW_ON_ERROR), true, 16, JSON_THROW_ON_ERROR);
    }
}
