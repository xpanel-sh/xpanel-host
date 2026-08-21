<?php

namespace App\Services;

use App\Models\Site;
use RuntimeException;

class SiteDiagnosticService
{
    public function __construct(private readonly ServerCommandRunner $commands) {}

    /** @return array<int, array{id: string, status: string, message: string}> */
    public function run(Site $site): array
    {
        $checks = config('xpanel.apply_system_changes')
            ? $this->parse($this->commands->run([
                'sudo', '-n', (string) config('xpanel.site_helper'), 'site-diagnose',
                $site->domain, $site->document_root, $site->systemUser(), $site->web_server,
                $site->type, $site->php_version, (string) (config('xpanel.server_ipv4') ?: '-'),
                (string) ($site->runtime_port ?? '0'),
            ], timeout: 90))
            : $this->local($site);

        $checks[] = $site->ssl_status === 'active'
            ? ['id' => 'ssl-record', 'status' => 'pass', 'message' => 'Host registra el certificado SSL como activo'.($site->ssl_expires_at ? ' hasta '.$site->ssl_expires_at->format('Y-m-d') : '').'.']
            : ['id' => 'ssl-record', 'status' => 'warning', 'message' => 'El sitio todavía no tiene un certificado SSL activo.'];
        $malware = $site->malwareScans()->first();
        $checks[] = $malware === null
            ? ['id' => 'malware', 'status' => 'warning', 'message' => 'Todavía no se ha ejecutado un escaneo de malware.']
            : ['id' => 'malware', 'status' => $malware->status === 'completed' && $malware->infected_count === 0 ? 'pass' : 'fail', 'message' => "Último escaneo: {$malware->infected_count} amenaza(s), estado {$malware->status}."];
        $backup = $site->backups()->where('status', 'completed')->first();
        $checks[] = $backup
            ? ['id' => 'backup', 'status' => 'pass', 'message' => 'Existe un backup completado del '.$backup->created_at->format('Y-m-d H:i').'.']
            : ['id' => 'backup', 'status' => 'warning', 'message' => 'No existe todavía un backup completado del sitio.'];

        return $checks;
    }

    /** @return array<int, array{id: string, status: string, message: string}> */
    public function parse(string $output): array
    {
        $checks = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (! str_starts_with($line, 'check=')) {
                continue;
            }
            $parts = explode("\t", substr($line, 6), 3);
            $message = isset($parts[2]) ? base64_decode($parts[2], true) : false;
            if (count($parts) !== 3 || ! preg_match('/^[a-z0-9-]{2,40}$/', $parts[0]) || ! in_array($parts[1], ['pass', 'warning', 'fail'], true) || ! is_string($message)) {
                throw new RuntimeException('El helper devolvió un diagnóstico inválido.');
            }
            $checks[] = ['id' => $parts[0], 'status' => $parts[1], 'message' => $message];
        }
        if ($checks === []) {
            throw new RuntimeException('El helper no devolvió comprobaciones de diagnóstico.');
        }

        return $checks;
    }

    /** @return array<int, array{id: string, status: string, message: string}> */
    private function local(Site $site): array
    {
        $root = $site->localRoot();

        return [
            ['id' => 'document-root', 'status' => is_dir($root) ? 'pass' : 'fail', 'message' => is_dir($root) ? 'El document root local existe.' : 'El document root local no existe.'],
            ['id' => 'runtime', 'status' => 'warning', 'message' => 'La comprobación de servicios Linux requiere una instalación VDS.'],
        ];
    }
}
