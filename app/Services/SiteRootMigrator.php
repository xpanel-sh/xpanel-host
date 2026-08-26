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

        $knownLegacyRoots = $this->knownLegacyRoots($site, $canonicalRoot);
        $configuredIsRetiredAccountRoot = $this->isExactLegacyAccountRoot($site, $configuredRoot, $canonicalRoot);
        $legacyRoot = $configuredRoot !== $canonicalRoot && (
            ! config('xpanel.apply_system_changes')
            || is_dir($configuredRoot)
            || ! $configuredIsRetiredAccountRoot
        )
            ? $configuredRoot
            : $this->recoverablePhysicalRoot($knownLegacyRoots, $canonicalRoot);

        // The database can still reference an old account home that no longer
        // exists. Once every exact known source has been checked, normalize the
        // record so provisioning creates the missing FQDN root in the current
        // account instead of recreating the retired home hierarchy.
        if ($legacyRoot === null && $configuredRoot !== $canonicalRoot && $configuredIsRetiredAccountRoot && in_array($configuredRoot, $knownLegacyRoots, true)) {
            $site->forceFill(['document_root' => $canonicalRoot])->save();

            return true;
        }

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
    private function knownLegacyRoots(Site $site, string $canonicalRoot): array
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

        if ($this->isExactLegacyAccountRoot($site, rtrim($site->document_root, '/'), $canonicalRoot)) {
            $roots[] = rtrim($site->document_root, '/');
        }

        // Older builds silently used this local-development sandbox when a
        // configured production root was absent. Recover it as a last resort
        // instead of continuing to show a virtual folder whose real FQDN root
        // does not exist below public_html.
        $roots[] = storage_path('app/sites/'.$site->domain);

        return array_values(array_unique($roots));
    }

    private function isExactLegacyAccountRoot(Site $site, string $path, string $canonicalRoot): bool
    {
        $identity = '(?:xpa[a-z0-9]{8,24}|xhi[a-f0-9]{12})';
        preg_match('#^(/home/'.$identity.')/public_html/#', $path, $legacyAccount);
        preg_match('#^(/home/'.$identity.')/public_html/#', $canonicalRoot, $currentAccount);
        if (($legacyAccount[1] ?? null) === null || ($legacyAccount[1] ?? null) === ($currentAccount[1] ?? null)) {
            return false;
        }

        $domain = preg_quote($site->domain, '#');
        if (preg_match('#^/home/'.$identity.'/public_html/'.$domain.'$#', $path) === 1) {
            return true;
        }

        if ($site->parent_site_id === null) {
            return false;
        }

        $parent = $site->parent()->first();
        $label = $parent ? $this->subdomainLabel($site, $parent) : null;

        return $parent && $label && preg_match(
            '#^/home/'.$identity.'/public_html/'.preg_quote($parent->domain, '#').'/subdomains/'.preg_quote($label, '#').'$#',
            $path
        ) === 1;
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

        $existing = array_values(array_filter(
            $knownLegacyRoots,
            fn (string $candidate): bool => $candidate !== $canonicalRoot && is_dir($candidate)
        ));

        foreach ($existing as $candidate) {
            if (! $this->directoryIsEmpty($candidate)) {
                return $candidate;
            }
        }

        return $existing[0] ?? null;
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
