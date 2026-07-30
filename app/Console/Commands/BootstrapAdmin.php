<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class BootstrapAdmin extends Command
{
    protected $signature = 'xpanel:admin-bootstrap
        {--name=Administrador}
        {--email=admin@xpanel.local}
        {--password-stdin : Read the initial password from standard input}
        {--status-only : Only report whether an account is missing}';

    protected $description = 'Create the first XPanel Host owner without exposing its password in process arguments';

    public function handle(): int
    {
        if ($this->option('status-only')) {
            $this->line(User::query()->exists() ? 'configured' : 'missing');

            return self::SUCCESS;
        }

        if (User::query()->exists()) {
            $this->error('An XPanel Host account already exists.');

            return self::FAILURE;
        }

        $password = $this->option('password-stdin') ? rtrim((string) stream_get_contents(STDIN), "\r\n") : '';
        $data = [
            'name' => (string) $this->option('name'),
            'email' => strtolower((string) $this->option('email')),
            'password' => $password,
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:16', 'max:128'],
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $owner = Role::query()->where('slug', 'owner')->firstOrFail();
        User::query()->create($data + ['role_id' => $owner->id]);
        $this->line('created');

        return self::SUCCESS;
    }
}
