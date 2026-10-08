<?php

namespace App\Services;

use App\Models\Site;

class IkodeConsoleData
{
    /** @return array<string, mixed> */
    public function forSite(Site $site, string $kind): array
    {
        $family = $site->parent_site_id === null
            ? collect([$site])->concat($site->subdomains()->get())
            : collect([$site]);
        if ($kind === 'ports') {
            return ['ports' => $family->flatMap(fn (Site $member) => $this->ports($member))->values()->all()];
        }

        abort_unless($kind === 'logs', 422, 'Panel desconocido.');

        $domains = $family->pluck('domain')->all();
        return ['logs' => $this->logs($domains), 'limited' => count($domains) > 30];
    }

    /** @return array<string, mixed> */
    public function forAccount(string $kind): array
    {
        if ($kind === 'ports') {
            return ['ports' => Site::query()->orderBy('domain')->get()->flatMap(fn (Site $site) => $this->ports($site))->values()->all()];
        }

        abort_unless($kind === 'logs', 422, 'Panel desconocido.');

        $domains = Site::query()->orderBy('domain')->pluck('domain')->all();
        return ['logs' => $this->logs($domains), 'limited' => count($domains) > 30];
    }

    /** @return list<array<string, string|int|null>> */
    private function ports(Site $site): array
    {
        $ports = [
            ['domain' => $site->domain, 'port' => 80, 'protocol' => 'HTTP', 'scope' => 'Público', 'status' => $site->status],
        ];
        if ($site->ssl_status === 'active') {
            $ports[] = ['domain' => $site->domain, 'port' => 443, 'protocol' => 'HTTPS', 'scope' => 'Público', 'status' => $site->status];
        }
        if ($site->type === 'node' && $site->runtime_port) {
            $ports[] = ['domain' => $site->domain, 'port' => $site->runtime_port, 'protocol' => 'Node.js', 'scope' => 'Interno · 127.0.0.1', 'status' => $site->status];
        }

        return $ports;
    }

    /** @param list<string> $domains
     *  @return list<array{domain: string, type: string, lines: list<string>}>
     */
    private function logs(array $domains): array
    {
        $accountRoot = realpath(app(HostingAccountWorkspace::class)->localRoot());
        if (! is_string($accountRoot)) return [];
        $root = $accountRoot.'/logs';
        $resolvedRoot = realpath($root);
        if (! is_string($resolvedRoot) || ! str_starts_with($resolvedRoot, $accountRoot.DIRECTORY_SEPARATOR)) return [];
        $result = [];
        foreach (array_slice($domains, 0, 30) as $domain) {
            // Domain names originate in our own Site records, not in the request.
            if (! preg_match('/^[a-z0-9.-]+$/', $domain)) {
                continue;
            }
            foreach (['error', 'access'] as $type) {
                $path = $root.'/'.$domain.'/'.$type.'.log';
                $resolvedPath = realpath($path);
                if (! is_string($resolvedPath)
                    || ! str_starts_with($resolvedPath, $resolvedRoot.DIRECTORY_SEPARATOR)
                    || ! is_file($path) || is_link($path) || ! is_readable($path)) {
                    continue;
                }
                $result[] = ['domain' => $domain, 'type' => $type, 'lines' => $this->tail($path, 40)];
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function tail(string $path, int $maxLines): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) return [];
        try {
            $size = (int) (fstat($handle)['size'] ?? 0);
            $length = min($size, 65536);
            if ($length === 0) return [];
            fseek($handle, -$length, SEEK_END);
            $chunk = (string) fread($handle, $length);
            $lines = explode("\n", $chunk);
            if ($size > $length) array_shift($lines); // The first line may be partial.
            return array_values(array_filter(array_map(fn (string $line) => mb_substr(trim($line), 0, 1000), array_slice($lines, -$maxLines)), fn (string $line) => $line !== ''));
        } finally {
            fclose($handle);
        }
    }
}
