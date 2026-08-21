<?php

namespace App\Services;

use App\Models\Site;

class SubdomainRootMigrator
{
    public function __construct(
        private readonly ServerCommandRunner $commands,
        private readonly HostingAccountWorkspace $workspace,
    ) {}

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

        $legacyRoot = rtrim($site->document_root, '/');
        $knownLegacyRoots = [
            '/var/www/'.$site->domain,
            '/srv/www/'.$site->domain,
            rtrim($parent->document_root, '/').'/subdomains/'.$label,
        ];
        $canonicalRoot = $this->workspace->subdomainRoot($parent->domain, $label);

        if ($legacyRoot === $canonicalRoot || ! in_array($legacyRoot, $knownLegacyRoots, true)) {
            return false;
        }

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
