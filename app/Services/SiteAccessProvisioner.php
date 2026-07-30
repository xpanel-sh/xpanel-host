<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteAccessSetting;

class SiteAccessProvisioner
{
    public function __construct(private readonly ServerCommandRunner $commands) {}

    public function sync(Site $site, SiteAccessSetting $settings, ?string $password = null): void
    {
        $this->stageKeys($site);
        $this->stageJailRoots($site);
        if (! config('xpanel.apply_system_changes')) {
            return;
        }

        $this->commands->run([
            'sudo', '-n', (string) config('xpanel.site_helper'), 'access-sync',
            $site->systemUser(), $site->document_root,
            $settings->sftp_enabled ? '1' : '0',
            $settings->ftp_enabled ? '1' : '0',
            $settings->ssh_enabled ? '1' : '0',
            $settings->web_terminal_enabled ? '1' : '0',
        ], $password === null ? null : $password."\n");
    }

    public function stageKeys(Site $site): string
    {
        $directory = storage_path('app/access/'.$site->systemUser());
        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }
        $path = $directory.'/authorized_keys';
        $keys = $site->sshKeys()->pluck('public_key')->implode("\n");
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));
        file_put_contents($temporary, $keys.($keys === '' ? '' : "\n"), LOCK_EX);
        chmod($temporary, 0640);
        rename($temporary, $path);

        return $path;
    }

    /**
     * A shell (SSH or the browser terminal) belonging to one site must never
     * see another, unrelated site's files — but a site and its own
     * subdomains are the same owner's property, so the jail spans all of
     * them together, never just the single row that happens to own this
     * particular Unix identity.
     */
    public function stageJailRoots(Site $site): string
    {
        $family = $site->parent_site_id === null
            ? collect([$site])->concat($site->subdomains()->get())
            : collect([$site->parent])->concat($site->parent->subdomains()->get());

        $directory = storage_path('app/access/'.$site->systemUser());
        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }
        $path = $directory.'/jail-roots';
        $lines = $family->map(fn (Site $member): string => $member->systemUser().' '.$member->document_root)
            ->unique()
            ->implode("\n");
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));
        file_put_contents($temporary, $lines.($lines === '' ? '' : "\n"), LOCK_EX);
        chmod($temporary, 0640);
        rename($temporary, $path);

        return $path;
    }

    public function remove(Site $site): void
    {
        if (config('xpanel.apply_system_changes')) {
            $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'access-remove',
                $site->systemUser(), $site->document_root,
            ]);
        }
    }
}
