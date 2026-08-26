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
        $configuredRoot = rtrim($site->document_root, '/');
        $canonicalRoot = $this->canonicalRoot($site);

        if ($canonicalRoot === null) {
            return false;
        }

        $knownLegacyRoots = $this->knownLegacyRoots($site);
        $legacyRoot = $configuredRoot !== $canonicalRoot
            ? $configuredRoot
            : $this->recoverablePhysicalRoot($knownLegacyRoots, $canonicalRoot);

        if ($legacyRoot === null || $legacyRoot === $canonicalRoot || ! in_array($legacyRoot, $knownLegacyRoots, true)) {
            return false;
        }

        if (config('xpanel.apply_system_changes')) {
            $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'site-root-migrate',
                $legacyRoot, $canonicalRoot, $site->systemUser(),
            ]);
        }

        $site->forceFill(['document_root' => $canonicalRoot])->save();

        // Moving a legacy primary root also moves its old nested subdomain
        // directories. Point the children at that temporary location so the
        // child migration can move each one to its flat FQDN root afterwards.
        if ($site->parent_site_id === null) {
            $site->subdomains()->get()->each(function (Site $child) use ($canonicalRoot, $legacyRoot, $site): void {
                $label = $this->subdomainLabel($child, $site);
                if (! $label) {
                    return;
                }

                $oldChildRoot = $legacyRoot.'/subdomains/'.$label;
                if (rtrim($child->document_root, '/') === $oldChildRoot) {
                    $child->forceFill([
                        'document_root' => $canonicalRoot.'/subdomains/'.$label,
                    ])->save();
                }
            });
        }

        return true;
    }

    /** @return list<string> */
    private function knownLegacyRoots(Site $site): array
    {
        $roots = [
            '/var/www/'.$site->domain,
            '/srv/www/'.$site->domain,
        ];

        if ($site->parent_site_id !== null) {
            $parent = $site->parent()->first();
            $label = $parent ? $this->subdomainLabel($site, $parent) : null;
            if ($parent && $label) {
                $roots[] = rtrim($parent->document_root, '/').'/subdomains/'.$label;
                $roots[] = $this->workspace->siteRoot($parent->domain).'/subdomains/'.$label;
                $roots[] = '/var/www/'.$parent->domain.'/subdomains/'.$label;
                $roots[] = '/srv/www/'.$parent->domain.'/subdomains/'.$label;
            }
        }

        return array_values(array_unique($roots));
    }

    private function recoverablePhysicalRoot(array $knownLegacyRoots, string $canonicalRoot): ?string
    {
        if (! config('xpanel.apply_system_changes')) {
            return null;
        }

        $canonicalIsMissingOrEmpty = ! is_dir($canonicalRoot) || $this->directoryIsEmpty($canonicalRoot);
        if (! $canonicalIsMissingOrEmpty) {
            return null;
        }

        foreach ($knownLegacyRoots as $candidate) {
            if ($candidate !== $canonicalRoot && is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function directoryIsEmpty(string $path): bool
    {
        $entries = @scandir($path);

        return is_array($entries) && count($entries) === 2;
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
