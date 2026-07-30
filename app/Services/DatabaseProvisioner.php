<?php

namespace App\Services;

use App\Models\SiteDatabase;

class DatabaseProvisioner
{
    public function __construct(private readonly ServerCommandRunner $commands) {}

    public function create(SiteDatabase $database, string $password): void
    {
        if (! config('xpanel.apply_system_changes')) {
            $database->update(['status' => 'staged']);

            return;
        }

        $this->commands->run($this->command('database-create', $database), $password."\n");
        $database->update(['status' => 'active']);
    }

    public function rotatePassword(SiteDatabase $database, string $password): void
    {
        if (! config('xpanel.apply_system_changes')) {
            return;
        }

        $this->commands->run($this->command('database-password', $database), $password."\n");
    }

    public function remove(SiteDatabase $database): void
    {
        if (! config('xpanel.apply_system_changes')) {
            return;
        }

        $this->commands->run($this->command('database-remove', $database));
    }

    /** @return array<int, string> */
    private function command(string $action, SiteDatabase $database): array
    {
        return [
            'sudo', '-n', (string) config('xpanel.site_helper'), $action,
            $database->name, $database->username,
        ];
    }
}
