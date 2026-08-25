<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class XflowGraphValidator
{
    public function __construct(private readonly XflowCatalog $catalog) {}

    /** @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, string>>} */
    public function validate(mixed $nodes, mixed $edges, string $triggerType): array
    {
        if (! is_array($nodes) || ! is_array($edges) || count($nodes) < 1 || count($nodes) > 50 || count($edges) > 100) {
            throw ValidationException::withMessages(['graph' => 'XFlow admite entre 1 y 50 nodos y hasta 100 conexiones.']);
        }
        $catalog = $this->catalog->nodes();
        $ids = [];
        $cleanNodes = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/', (string) ($node['id'] ?? '')) || isset($ids[$node['id']])) {
                throw ValidationException::withMessages(['graph' => 'El grafo contiene identificadores de nodo inválidos o repetidos.']);
            }
            $handler = (string) ($node['handler'] ?? '');
            if (! isset($catalog[$handler])) {
                throw ValidationException::withMessages(['graph' => 'XFlow contiene un tipo de nodo no permitido.']);
            }
            $definition = $catalog[$handler];
            $ids[$node['id']] = true;
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $cleanNodes[] = [
                'id' => $node['id'], 'type' => $definition['type'], 'handler' => $handler,
                'label' => mb_substr(trim((string) ($node['label'] ?? $definition['label'])), 0, 120),
                'x' => max(0, min(4000, (int) ($node['x'] ?? 80))),
                'y' => max(0, min(2400, (int) ($node['y'] ?? 80))),
                'config' => $this->cleanConfig($handler, $config),
            ];
        }
        $triggers = array_values(array_filter($cleanNodes, fn (array $node): bool => $node['type'] === 'trigger'));
        if (count($triggers) !== 1 || $triggers[0]['handler'] !== 'trigger.'.$triggerType) {
            throw ValidationException::withMessages(['graph' => 'El builder debe contener exactamente un disparador que coincida con el workflow.']);
        }

        $cleanEdges = [];
        $adjacency = [];
        $nodeTypes = collect($cleanNodes)->pluck('type', 'id');
        foreach ($edges as $edge) {
            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            $branch = (string) ($edge['branch'] ?? 'always');
            if ($from === $to || ! isset($ids[$from], $ids[$to]) || ! in_array($branch, ['always', 'success', 'failure', 'true', 'false'], true)) {
                throw ValidationException::withMessages(['graph' => 'XFlow contiene una conexión inválida.']);
            }
            $allowedBranches = match ($nodeTypes->get($from)) {
                'condition' => ['true', 'false'],
                'action' => ['always', 'success', 'failure'],
                default => ['always'],
            };
            if (! in_array($branch, $allowedBranches, true)) {
                throw ValidationException::withMessages(['graph' => 'La salida elegida no corresponde al tipo de nodo de origen.']);
            }
            $key = $from.'>'.$to.'>'.$branch;
            $cleanEdges[$key] = compact('from', 'to', 'branch');
            $adjacency[$from][] = $to;
        }
        $this->assertAcyclic(array_keys($ids), $adjacency);

        return ['nodes' => array_values($cleanNodes), 'edges' => array_values($cleanEdges)];
    }

    /** @return array<string, mixed> */
    private function cleanConfig(string $handler, array $config): array
    {
        $clean = [];
        if (str_starts_with($handler, 'action.') || str_starts_with($handler, 'condition.')) {
            $target = (string) ($config['target'] ?? 'workflow');
            if (! in_array($target, ['workflow', 'all', 'site'], true)) {
                $target = 'workflow';
            }
            $clean['target'] = $target;
            $clean['site_id'] = $target === 'site' ? max(0, (int) ($config['site_id'] ?? 0)) : null;
        }
        if (str_starts_with($handler, 'condition.')) {
            $clean['operator'] = in_array(($config['operator'] ?? ''), ['equals', 'not_equals'], true) ? $config['operator'] : 'equals';
            $clean['value'] = mb_substr((string) ($config['value'] ?? ''), 0, 80);
        }
        if ($handler === 'action.notify') {
            $clean['title'] = mb_substr(trim((string) ($config['title'] ?? 'XFlow completó una acción')), 0, 120);
            $clean['message'] = mb_substr(trim((string) ($config['message'] ?? 'El workflow terminó correctamente.')), 0, 500);
            $clean['level'] = in_array(($config['level'] ?? ''), ['info', 'success', 'warning', 'danger'], true) ? $config['level'] : 'info';
        }
        $clean['retries'] = max(0, min(2, (int) ($config['retries'] ?? 0)));

        return $clean;
    }

    private function assertAcyclic(array $ids, array $adjacency): void
    {
        $state = [];
        $visit = function (string $id) use (&$visit, &$state, $adjacency): void {
            if (($state[$id] ?? 0) === 1) {
                throw ValidationException::withMessages(['graph' => 'Los ciclos no están permitidos; usa programación o eventos para repetir un flujo.']);
            }
            if (($state[$id] ?? 0) === 2) {
                return;
            }
            $state[$id] = 1;
            foreach ($adjacency[$id] ?? [] as $next) {
                $visit($next);
            }
            $state[$id] = 2;
        };
        foreach ($ids as $id) {
            $visit($id);
        }
    }
}
