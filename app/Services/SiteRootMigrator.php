<?php

namespace App\Services;

use App\Models\Site;

class SiteRootMigrator
{
    public function __construct(
        private readonly ServerCommandRunner $commands,
        private readonly HostingAccountWorkspace $workspace,
    ) {}

    public function migrateLegacyRoot(Site $site): bool
    {
        $legacyRoot = rtrim($site->document_root, '/');
        $canonicalRoot = $this->canonicalRoot($site);

        if ($canonicalRoot === null || $legacyRoot === $canonicalRoot) {
            return false;
        }

        $knownLegacyRoots = [
            '/var/www/'.$site->domain,
            '/srv/www/'.$site->domain,
        ];

        if ($site->parent_site_id !== null) {
            $parent = $site->parent()->first();
            $label = $parent ? $this->subdomainLabel($site, $parent) : null;
            if ($parent && $label) {
                $knownLegacyRoots[] = rtrim($parent->document_root, '/').'/subdomains/'.$label;
            }
        }

        if (! in_array($legacyRoot, $knownLegacyRoots, true)) {
            return false;
        }

        if (config('xpanel.apply_system_changes')) {
            $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'site-root-migrate',
                $legacyRoot, $canonicalRoot, $site->systemUser(),
            ]);
        }

        $site->forceFill(['document_root' => $canonicalRoot])->save();

        // Moving a primary site also moves any legacy subdomain directories
        // nested below it. Keep those records aligned without moving them twice.
        if ($site->parent_site_id === null) {
            $site->subdomains()->get()->each(function (Site $child) use ($legacyRoot, $site): void {
                $label = $this->subdomainLabel($child, $site);
                if (! $label) {
                    return;
                }

                $oldChildRoot = $legacyRoot.'/subdomains/'.$label;
                if (rtrim($child->document_root, '/') === $oldChildRoot) {
                    $child->forceFill([
                        'document_root' => $this->workspace->subdomainRoot($site->domain, $label),
                    ])->save();
                }
            });
        }

        return true;
    }

    private function canonicalRoot(Site $site): ?string
    {
        if ($site->parent_site_id === null) {
            return $this->workspace->siteRoot($site->domain);
        }

        $parent = $site->parent()->first();
        $label = $parent ? $this->subdomainLabel($site, $parent) : null;

        return $parent && $label
            ? $this->workspace->subdomainRoot($parent->domain, $label)
            : null;
    }

    private function subdomainLabel(Site $site, Site $parent): ?string
    {
        $suffix = '.'.$parent->domain;
        if (! str_ends_with($site->domain, $suffix)) {
            return null;
        }

        $label = substr($site->domain, 0, -strlen($suffix));

        return $label !== '' && ! str_contains($label, '.') ? $label : null;
    }
}
