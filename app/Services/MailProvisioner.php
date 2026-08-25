<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\DomainMailSettings;
use App\Models\MailAccount;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;

class MailProvisioner
{
    public function __construct(private readonly ServerCommandRunner $commands) {}

    public function apply(MailAccount $account): void
    {
        $this->sync();
        $account->update(['status' => config('xpanel.apply_system_changes') ? 'active' : 'staged']);
    }

    public function remove(MailAccount $account): void
    {
        $this->sync($account->id);
        if (config('xpanel.apply_system_changes')) {
            $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'mail-remove',
                $account->domain->domain, $account->local_part,
            ]);
        }
    }

    public function sync(?int $excludeID = null): void
    {
        $query = MailAccount::with('domain')->orderBy('domain_id')->orderBy('local_part');
        if ($excludeID !== null) {
            $query->whereKeyNot($excludeID);
        }
        $accounts = $query->get();
        $this->stage($accounts);

        if (config('xpanel.apply_system_changes')) {
            $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'mail-sync',
            ]);
        }
    }

    /**
     * Domains in "dedicated" outbound mode get their own Postfix transport
     * (own smtp_bind_address + smtp_helo_name), so their mail leaves through
     * a specific IP with a PTR that actually matches, instead of the one
     * shared server-wide hostname/IP every domain uses by default.
     */
    public function syncOutboundRouting(?int $excludeDomainId = null): void
    {
        $this->stageOutboundRouting($excludeDomainId);

        if (config('xpanel.apply_system_changes')) {
            $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'mail-outbound-sync',
            ]);
        }
    }

    private function stageOutboundRouting(?int $excludeDomainId = null): void
    {
        $query = DomainMailSettings::with(['domain', 'serverIpAddress'])
            ->where('outbound_mode', 'dedicated')
            ->whereHas('serverIpAddress');
        if ($excludeDomainId !== null) {
            $query->where('domain_id', '!=', $excludeDomainId);
        }
        $settings = $query->get();

        $directory = storage_path('app/mail');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('No se pudo preparar la configuración de correo.');
        }

        $senderTransport = [];
        $dedicatedIps = [];
        foreach ($settings as $setting) {
            $ip = $setting->serverIpAddress;
            $transport = 'xpanelout-'.str_replace(['.', ':'], '-', $ip->ip_address);
            $senderTransport[] = '@'.$setting->domain->domain.' '.$transport.':';
            $dedicatedIps[$ip->id] ??= $transport.' '.$ip->ip_address.' '.$ip->ptr_hostname;
        }

        $this->atomicWrite($directory.'/sender-transport', implode("\n", $senderTransport).($senderTransport ? "\n" : ''));
        $this->atomicWrite($directory.'/dedicated-ips', implode("\n", array_values($dedicatedIps)).($dedicatedIps ? "\n" : ''));
    }

    public function removeDomain(Domain $domain): void
    {
        $accounts = MailAccount::with('domain')
            ->where('domain_id', '!=', $domain->id)
            ->orderBy('domain_id')->orderBy('local_part')->get();
        $this->stage($accounts);
        $this->stageOutboundRouting($domain->id);
        if (config('xpanel.apply_system_changes')) {
            $this->commands->run(['sudo', '-n', (string) config('xpanel.site_helper'), 'mail-sync']);
            $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'mail-outbound-sync',
            ]);
            $this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'mail-remove-domain', $domain->domain,
            ]);
        }
        foreach (['private', 'txt'] as $extension) {
            $path = storage_path("app/mail/dkim/{$domain->domain}.{$extension}");
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** @param Collection<int, MailAccount> $accounts */
    private function stage(Collection $accounts): void
    {
        $uid = (int) config('xpanel.mail_uid', 5000);
        $gid = (int) config('xpanel.mail_gid', 5000);
        $domains = [];
        $users = [];
        $mailboxes = [];
        $senderLogin = [];
        $sendLimits = [];
        $firstAccountByDomain = [];

        foreach ($accounts as $account) {
            $domain = $account->domain->domain;
            $this->ensureDkimKey($domain);
            $email = $account->local_part.'@'.$domain;
            $home = rtrim((string) config('xpanel.mail_root'), '/').'/'.$domain.'/'.$account->local_part;
            $domains[$domain] = $domain.' OK';
            $mailboxes[] = $email.' OK';
            $senderLogin[] = $email.' '.$email;
            $sendLimits[] = $email.' '.$account->hourly_send_limit.' '.$account->daily_send_limit;
            $firstAccountByDomain[$domain] ??= $email;
            $users[] = implode(':', [
                $email,
                '{BLF-CRYPT}'.$account->getRawOriginal('password'),
                $uid,
                $gid,
                '',
                $home,
                '',
                'userdb_quota_rule=*:storage='.$account->quota_mb.'M',
            ]);
        }

        $directory = storage_path('app/mail');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('No se pudo preparar la configuración de correo.');
        }
        $this->atomicWrite($directory.'/domains', implode("\n", $domains).($domains ? "\n" : ''));
        $this->atomicWrite($directory.'/mailboxes', implode("\n", $mailboxes).($mailboxes ? "\n" : ''));
        $this->atomicWrite($directory.'/users', implode("\n", $users).($users ? "\n" : ''));
        $this->atomicWrite($directory.'/sender-login', implode("\n", $senderLogin).($senderLogin ? "\n" : ''));
        $this->atomicWrite($directory.'/send-limits', implode("\n", $sendLimits).($sendLimits ? "\n" : ''));
        $this->atomicWrite($directory.'/dkim-selector', (string) config('xpanel.dkim_selector', 'xpanel')."\n");
        $aliases = [];
        foreach ($firstAccountByDomain as $domain => $email) {
            $aliases[] = 'postmaster@'.$domain.' '.$email;
            $aliases[] = 'abuse@'.$domain.' '.$email;
        }
        $this->atomicWrite($directory.'/aliases', implode("\n", $aliases).($aliases ? "\n" : ''));
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temporary = tempnam(dirname($path), '.xpanel-mail-');
        if ($temporary === false) {
            throw new \RuntimeException("No se pudo preparar {$path}.");
        }
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false || ! rename($temporary, $path)) {
                throw new \RuntimeException("No se pudo escribir {$path}.");
            }
            chmod($path, 0600);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function ensureDkimKey(string $domain): void
    {
        $directory = storage_path('app/mail/dkim');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('No se pudo preparar el directorio DKIM.');
        }

        $privatePath = $directory.'/'.$domain.'.private';
        $recordPath = $directory.'/'.$domain.'.txt';
        if (is_file($privatePath) && is_file($recordPath)) {
            return;
        }

        $lock = fopen($directory.'/.generation.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new \RuntimeException('No se pudo bloquear la generación DKIM.');
        }

        try {
            if (is_file($privatePath) && is_file($recordPath)) {
                return;
            }

            [$privateKey, $publicPem] = $this->generateDkimKeyPair($directory, $domain);

            $publicKey = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $publicPem);
            if (! is_string($publicKey) || $publicKey === '') {
                throw new \RuntimeException("La clave pública DKIM de {$domain} no es válida.");
            }

            $this->atomicWrite($privatePath, $privateKey);
            $this->atomicWrite($recordPath, 'v=DKIM1; k=rsa; p='.$publicKey."\n");
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{string, string} */
    private function generateDkimKeyPair(string $directory, string $domain): array
    {
        $key = @openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($key !== false && @openssl_pkey_export($key, $privateKey)) {
            $details = openssl_pkey_get_details($key);
            if (is_array($details) && ! empty($details['key'])) {
                return [$privateKey, $details['key']];
            }
        }

        $temporary = tempnam($directory, '.xpanel-dkim-');
        if ($temporary === false) {
            throw new \RuntimeException("No se pudo preparar la clave DKIM de {$domain}.");
        }

        try {
            $generate = new Process([
                'openssl', 'genpkey', '-algorithm', 'RSA',
                '-pkeyopt', 'rsa_keygen_bits:2048', '-out', $temporary,
            ]);
            $generate->setTimeout(30)->mustRun();
            $privateKey = file_get_contents($temporary);

            $public = new Process(['openssl', 'pkey', '-in', $temporary, '-pubout']);
            $public->setTimeout(15)->mustRun();
            if (! is_string($privateKey) || $privateKey === '' || $public->getOutput() === '') {
                throw new \RuntimeException("No se pudo generar la clave DKIM de {$domain}.");
            }

            return [$privateKey, $public->getOutput()];
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                "No se pudo generar la clave DKIM de {$domain}. Verifica PHP OpenSSL o el binario openssl.",
                previous: $exception,
            );
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
