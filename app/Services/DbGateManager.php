<?php

namespace App\Services;

use App\Models\SiteDatabase;
use App\Models\SiteSqliteDatabase;
use Illuminate\Support\Facades\File;
use Throwable;

class DbGateManager
{
    public function __construct(private readonly ServerCommandRunner $commands, private readonly SiteSqliteDatabaseManager $sqlite) {}

    /** @return array{login:string,password:string} */
    public function synchronize(): array
    {
        $credentials = $this->credentials();
        $databases = SiteDatabase::query()->where('status', 'active')->orderBy('id')->get();
        $ids = $databases->map(fn (SiteDatabase $database): string => 'db'.$database->id)->all();
        $sqliteFiles = [];
        foreach (SiteSqliteDatabase::query()->with('site')->orderBy('id')->get() as $database) {
            try {
                $sqliteFiles['sqlite'.$database->id] = [
                    'file' => $this->sqlite->resolve($database->site, $database->relative_path),
                    'label' => $database->site->domain.' · '.$database->relative_path,
                ];
            } catch (Throwable $exception) {
                // A moved or removed site file must never turn into an unrestricted connection.
                report($exception);
            }
        }
        $ids = array_merge($ids, array_keys($sqliteFiles));
        $lines = [
            'LOGIN='.$credentials['login'],
            'PASSWORD='.$credentials['password'],
            'LANGUAGE=es',
            'WEB_ROOT=/dbgate',
            'SHELL_CONNECTION=0',
            'SHELL_SCRIPTING=0',
            'USAGE_ANALYTICS=false',
            'CONNECTIONS='.implode(',', $ids),
        ];
        foreach ($databases as $database) {
            $id = 'db'.$database->id;
            $engine = match ($database->engine) {
                'postgresql' => 'postgres@dbgate-plugin-postgres',
                'mongodb' => 'mongo@dbgate-plugin-mongo',
                default => 'mariadb@dbgate-plugin-mysql',
            };
            array_push($lines,
                "LABEL_$id=".$database->engineLabel().' · '.$database->name,
                "SERVER_$id=127.0.0.1",
                "PORT_$id=".$database->defaultPort(),
                "USER_$id=".$database->username,
                "PASSWORD_MODE_$id=askPassword",
                "DATABASE_$id=".$database->name,
                "ALLOWED_DATABASES_$id=".$database->name,
                "ENGINE_$id=$engine",
            );
        }
        foreach ($sqliteFiles as $id => $sqliteFile) {
            array_push($lines,
                "LABEL_$id=SQLite · ".$sqliteFile['label'],
                "FILE_$id=".$sqliteFile['file'],
                "ENGINE_$id=sqlite@dbgate-plugin-sqlite",
            );
        }
        $directory = storage_path('app/dbgate');
        File::ensureDirectoryExists($directory, 0700, true);
        $source = $directory.'/runtime.env';
        if (is_link($source)) {
            throw new \RuntimeException('La configuración de DbGate no puede ser un enlace simbólico.');
        }
        // Replace atomically: older releases left runtime.env owned by the
        // account UID, which prevented the panel UID from opening it for write.
        $temporary = tempnam($directory, 'runtime-');
        if ($temporary === false) {
            throw new \RuntimeException('No se pudo preparar la configuración de DbGate.');
        }
        try {
            if (File::put($temporary, implode("\n", $lines)."\n", true) === false) {
                throw new \RuntimeException('No se pudo escribir la configuración de DbGate.');
            }
            @chmod($temporary, 0600);
            if (! rename($temporary, $source)) {
                throw new \RuntimeException('No se pudo activar la configuración de DbGate.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
        if (config('xpanel.apply_system_changes')) {
            $this->commands->run(['sudo', '-n', (string) config('xpanel.site_helper'), 'dbgate-sync'], null, 120);
        }

        return $credentials;
    }

    /** @return array{login:string,password:string} */
    private function credentials(): array
    {
        $path = storage_path('app/dbgate/access.json');
        if (File::isFile($path)) {
            $value = json_decode(File::get($path), true);
            if (is_array($value) && isset($value['login'], $value['password'])) {
                return $value;
            }
        }
        $value = ['login' => 'xpanel', 'password' => bin2hex(random_bytes(24))];
        File::ensureDirectoryExists(dirname($path), 0700, true);
        File::put($path, json_encode($value, JSON_THROW_ON_ERROR), true);
        @chmod($path, 0600);

        return $value;
    }
}
