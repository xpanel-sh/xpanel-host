<?php

namespace App\Console\Commands;

use App\Services\XMailService;
use Illuminate\Console\Command;

class SmokeXMail extends Command
{
    protected $signature = 'xpanel:xmail-smoke
        {email : Existing mailbox address}
        {--password-stdin : Read the mailbox password from standard input}
        {--send : Send a test message to the same mailbox through authenticated SMTP}';

    protected $description = 'Verify XMail authentication and IMAP/SMTP operations against the local mail stack';

    public function handle(XMailService $mail): int
    {
        $email = strtolower((string) $this->argument('email'));
        $password = $this->option('password-stdin')
            ? rtrim((string) stream_get_contents(STDIN), "\r\n")
            : (string) $this->secret('Mailbox password');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $this->error('A valid mailbox and a non-empty password are required.');

            return self::INVALID;
        }

        if (! $mail->authenticate($email, $password)) {
            $this->error('Dovecot rejected the mailbox credentials.');

            return self::FAILURE;
        }

        $folders = $mail->folders($email, $password);
        if (! collect($folders)->contains(fn (array $folder): bool => strtoupper($folder['name']) === 'INBOX')) {
            $this->error('IMAP did not return INBOX.');

            return self::FAILURE;
        }

        if ($this->option('send')) {
            $marker = 'XPanel XMail smoke '.now()->utc()->format('Ymd\THis\Z');
            $mail->send($email, $password, [$email], [], [], $marker, $marker);
        }

        $this->info('XMail IMAP'.($this->option('send') ? '/SMTP' : '').' smoke test passed.');

        return self::SUCCESS;
    }
}
