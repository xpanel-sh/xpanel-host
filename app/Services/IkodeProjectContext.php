<?php

namespace App\Services;

use App\Models\Site;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class IkodeProjectContext
{
    private const EXCLUDED_DIRECTORIES = ['.git', '.svn', 'node_modules', 'vendor', 'storage/logs', 'mail', 'ssl', '.ssh'];

    public function __construct(private readonly HostingAccountWorkspace $workspace) {}

    public function build(?Site $site, ?string $activePath): string
    {
        $root = rtrim(str_replace('\\', '/', $site?->localRoot() ?? $this->workspace->localRoot()), '/');
        $scope = $site ? "sitio {$site->domain}" : 'cuenta completa de alojamiento';
        $lines = ["Ámbito autorizado: {$scope}", 'Raíz lógica: /', 'Árbol parcial del proyecto:'];
        $count = 0;

        $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $filtered = new RecursiveCallbackFilterIterator($directory, function ($item) use ($root): bool {
            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($root))), '/');

            return ! $this->isExcluded($relative);
        });
        $iterator = new RecursiveIteratorIterator(
            $filtered,
            RecursiveIteratorIterator::SELF_FIRST,
        );
        $iterator->setMaxDepth(5);

        foreach ($iterator as $item) {
            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($root))), '/');
            $lines[] = ($item->isDir() ? '[dir] ' : '[file] ').$relative;
            if (++$count >= 300) {
                $lines[] = '[árbol truncado a 300 elementos]';
                break;
            }
        }

        $active = $this->activeFile($root, $activePath);
        if ($active !== null) {
            $lines[] = '';
            $lines[] = "Archivo activo: {$active['path']}";
            $lines[] = "--- contenido ---\n{$active['content']}\n--- fin ---";
        }

        return implode("\n", $lines);
    }

    private function activeFile(string $root, ?string $requested): ?array
    {
        if (! is_string($requested) || $requested === '') {
            return null;
        }
        $relative = ltrim(str_replace('\\', '/', $requested), '/');
        if ($this->isExcluded($relative) || preg_match('/(^|\/)(\.env(?:\..*)?|.*\.(?:pem|key|p12|pfx))$/i', $relative)) {
            return null;
        }
        $candidate = realpath($root.'/'.$relative);
        if ($candidate === false || ! is_file($candidate)) {
            return null;
        }
        $candidate = str_replace('\\', '/', $candidate);
        if (! str_starts_with($candidate, $root.'/') || filesize($candidate) > 96 * 1024) {
            return null;
        }
        $content = @file_get_contents($candidate);
        if (! is_string($content) || str_contains(substr($content, 0, 8192), "\0")) {
            return null;
        }

        return ['path' => '/'.$relative, 'content' => $content];
    }

    private function isExcluded(string $relative): bool
    {
        $relative = trim($relative, '/');
        $basename = basename($relative);
        if (preg_match('/^(\.env(?:\..*)?|auth\.json|\.npmrc|\.pypirc)$/i', $basename)
            || preg_match('/\.(?:pem|key|p12|pfx)$/i', $basename)) {
            return true;
        }

        foreach (self::EXCLUDED_DIRECTORIES as $excluded) {
            if ($relative === $excluded || str_starts_with($relative, $excluded.'/')) {
                return true;
            }
        }

        return false;
    }
}
