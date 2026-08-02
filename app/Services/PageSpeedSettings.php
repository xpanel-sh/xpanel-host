<?php

namespace App\Services;

class PageSpeedSettings
{
    public function __construct(private readonly ServerCommandRunner $commands) {}

    public function update(?string $key): void
    {
        $key = trim((string) $key);
        if (config('xpanel.apply_system_changes')) {
            $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'pagespeed-key-set',
            ], $key."\n");
        }

        config(['services.pagespeed.key' => $key === '' ? null : $key]);
    }
}
