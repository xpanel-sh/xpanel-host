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
