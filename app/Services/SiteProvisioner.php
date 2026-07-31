<?php

namespace App\Services;

use App\Models\Site;

class SiteProvisioner
{
    public function __construct(
        private readonly VirtualHostGenerator $vhosts,
        private readonly ServerCommandRunner $commands,
    ) {}

    /**
     * Stage configuration on every environment and apply it only on an
     * installation whose Linux helper was explicitly enabled.
     */
    public function provision(Site $site, ?Site $previous = null): void
    {
        if ($previous && (
            $previous->domain !== $site->domain
            || $previous->web_server !== $site->web_server
            || $previous->type !== $site->type
            || $previous->php_version !== $site->php_version
        )) {
            $this->remove($previous);
        }

        $this->vhosts->write($site);
        $this->vhosts->writeGateway($site);
        $this->vhosts->writePhpPool($site);
        $this->vhosts->writeOpenLiteSpeedRegistry();

        if (! config('xpanel.apply_system_changes')) {
            return;
        }

        $this->commands->run([
            'sudo',
            '-n',
            (string) config('xpanel.site_helper'),
            'apply',
            $site->domain,
            $site->web_server,
            $site->type,
            $site->php_version,
            $site->document_root,
            $site->systemUser(),
            $site->webRoot(),
        ]);
    }

    public function remove(Site $site): void
    {
        $this->vhosts->remove($site);
        $this->vhosts->writeOpenLiteSpeedRegistry($site->exists ? $site->id : null);

        if (! config('xpanel.apply_system_changes')) {
            return;
        }

        $this->commands->run([
            'sudo',
            '-n',
            (string) config('xpanel.site_helper'),
            'remove',
            $site->domain,
            $site->web_server,
            $site->type,
            $site->php_version,
            $site->document_root,
            $site->systemUser(),
        ]);
    }

    public function discardStaged(Site $site): void
    {
        $this->vhosts->remove($site);
        $this->vhosts->writeOpenLiteSpeedRegistry($site->id);
    }

    public function restart(Site $site): void
    {
        if (! config('xpanel.apply_system_changes')) {
            return;
        }

        $this->commands->run([
            'sudo', '-n', (string) config('xpanel.site_helper'), 'site-restart',
            $site->domain, $site->web_server, $site->type, $site->php_version, $site->document_root, $site->systemUser(),
        ]);
    }
}
