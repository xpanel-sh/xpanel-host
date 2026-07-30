<?php

namespace App\Services;

use App\Models\Site;

class SubdomainRootMigrator
{
    public function __construct(private readonly ServerCommandRunner $commands) {}

    public function migrateLegacyRoot(Site $site): bool
    {
        if ($site->parent_site_id === null) {
            return false;
        }

        $parent = $site->parent()->first();
        if (! $parent) {
            return false;
        }

        $label = str_ends_with($site->domain, '.'.$parent->domain)
            ? substr($site->domain, 0, -strlen('.'.$parent->domain))
            : null;
        if (! $label || str_contains($label, '.')) {
            return false;
        }

        $legacyRoot = rtrim($parent->document_root, '/').'/subdomains/'.$label;
        if (rtrim($site->document_root, '/') !== $legacyRoot) {
            return false;
        }

        $documentRootBase = str_starts_with($parent->document_root, '/srv/www/') ? '/srv/www' : '/var/www';
        $canonicalRoot = $documentRootBase.'/'.$site->domain;
        if (config('xpanel.apply_system_changes')) {
            $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'subdomain-root-migrate',
                $legacyRoot, $canonicalRoot, $site->systemUser(),
            ]);
        }

        $site->forceFill(['document_root' => $canonicalRoot])->save();

        return true;
    }
}
