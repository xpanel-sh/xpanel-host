<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class ResetAdminPassword extends Command
{
    protected $signature = 'xpanel:admin-password
        {--generate : Generate and print a new secure password}
        {--password-stdin : Read the new password from standard input}';

    protected $description = 'Reset the XPanel Host owner password from the server console';

    public function handle(): int
    {
        if (PHP_OS_FAMILY === 'Linux' && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            $this->error('Run this recovery command as root.');

            return self::FAILURE;
        }

        $owner = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'owner'))->oldest()->first();
        if ($owner === null) {
            $this->error('No owner account exists yet. Run the installer first.');

            return self::FAILURE;
        }

        $password = $this->option('generate')
            ? bin2hex(random_bytes(16))
            : ($this->option('password-stdin') ? rtrim((string) stream_get_contents(STDIN), "\r\n") : '');
        $validator = Validator::make(['password' => $password], [
            'password' => ['required', 'string', 'min:16', 'max:128'],
        ]);
        if ($validator->fails()) {
            $this->error('Use --generate or provide a password of at least 16 characters through --password-stdin.');

            return self::FAILURE;
        }

        $owner->password = $password;
        $owner->save();

        $this->line('Correo: '.$owner->email);
        $this->line('Contraseña: '.$password);

        return self::SUCCESS;
    }
}
