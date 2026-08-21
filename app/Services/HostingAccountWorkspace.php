<?php

namespace App\Services;

class HostingAccountWorkspace
{
    /** @return list<string> */
    public function directories(): array
    {
        return [
            '.trash', '.xpanel', 'etc', 'logs', 'mail', 'public_ftp',
            'public_ftp/incoming', 'public_html', 'ssl', 'ssl/certs',
            'ssl/csrs', 'tmp',
        ];
    }

    public function user(): string
    {
        $user = (string) config('xpanel.account_user');
        if (! preg_match('/^(?:xpa[a-z0-9]{8,24}|xhi[a-f0-9]{12})$/', $user)) {
            throw new \RuntimeException('La cuenta no tiene una identidad Unix válida.');
        }

        return $user;
    }

    public function systemRoot(): string
    {
        $root = rtrim((string) config('xpanel.account_home'), '/');
        if (! preg_match('#^/home/[a-z_][a-z0-9_-]{2,31}$#', $root) || str_contains($root, '..')) {
            throw new \RuntimeException('La raíz de la cuenta debe estar bajo /home.');
        }

        return $root;
    }

    public function localRoot(): string
    {
        $root = $this->systemRoot();
        if (! is_dir($root)) {
            $root = storage_path('app/account-home');
        }

        $this->ensureLayout($root);

        return $root;
    }

    public function siteRoot(string $domain): string
    {
        $this->assertDomain($domain);

        return $this->systemRoot().'/public_html/'.$domain;
    }

    public function subdomainRoot(string $parentDomain, string $label): string
    {
        $this->assertDomain($parentDomain);
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
            throw new \InvalidArgumentException('Etiqueta de subdominio inválida.');
        }

        return $this->siteRoot($parentDomain).'/subdomains/'.$label;
    }

    public function acceptsDocumentRoot(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        if (! str_contains($path, '..') && preg_match('#^/(?:var|srv)/www/[A-Za-z0-9._/-]+$#', $path)) {
            return true;
        }

        return str_starts_with($path, $this->systemRoot().'/public_html/')
            && ! str_contains($path, '..')
            && (bool) preg_match('#^/home/[a-z_][a-z0-9_-]{2,31}/public_html/[A-Za-z0-9._/-]+$#', $path);
    }

    private function ensureLayout(string $root): void
    {
        if (! is_dir($root) && ! mkdir($root, 0750, true) && ! is_dir($root)) {
            throw new \RuntimeException('No se pudo crear la raíz local de la cuenta.');
        }

        foreach ($this->directories() as $directory) {
            $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);
            $mode = str_starts_with($directory, '.xpanel') ? 0700 : 0750;
            if (! is_dir($path) && ! mkdir($path, $mode, true) && ! is_dir($path)) {
                throw new \RuntimeException("No se pudo preparar {$directory}.");
            }
        }

        $notice = $root.DIRECTORY_SEPARATOR.'.xpanel'.DIRECTORY_SEPARATOR.'README.txt';
        if (! file_exists($notice)) {
            file_put_contents($notice, "Datos auxiliares de XPanel. Ningún secreto vital debe guardarse aquí.\n");
        }
    }

    private function assertDomain(string $domain): void
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $domain) || ! str_contains($domain, '.') || str_contains($domain, '..')) {
            throw new \InvalidArgumentException('Dominio inválido para la cuenta.');
        }
    }
}
