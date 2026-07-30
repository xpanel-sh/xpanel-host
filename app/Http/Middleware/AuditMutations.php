<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuditMutations
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user() !== null && ! $request->isMethodSafe() && $response->getStatusCode() < 500) {
            try {
                $routeName = $request->route()?->getName() ?? 'request';
                $site = $request->route('site');
                $siteExists = $site instanceof Site && Site::whereKey($site->id)->exists();
                ActivityLog::create([
                    'user_id' => $request->user()->id,
                    'site_id' => $siteExists ? $site->id : null,
                    'event' => $routeName,
                    'description' => $this->description($routeName),
                    'ip_address' => $request->ip(),
                    'metadata' => [
                        'method' => $request->method(),
                        'status' => $response->getStatusCode(),
                        'site' => $site instanceof Site ? $site->domain : null,
                    ],
                ]);
            } catch (\Throwable $exception) {
                Log::warning('No se pudo registrar una acción administrativa.', ['exception' => $exception->getMessage()]);
            }
        }

        return $response;
    }

    private function description(string $routeName): string
    {
        return [
            'sites.backups.store' => 'Solicitó crear un backup del sitio.',
            'sites.backups.restore' => 'Solicitó restaurar un backup del sitio.',
            'sites.backups.destroy' => 'Eliminó un backup del sitio.',
            'sites.backups.policy' => 'Actualizó la política de backups.',
            'sites.store' => 'Creó un sitio.',
            'sites.update' => 'Actualizó un sitio.',
            'sites.destroy' => 'Eliminó un sitio.',
            'sites.restart' => 'Reinició los servicios del sitio.',
            'sites.php.configuration.update' => 'Actualizó la configuración PHP del sitio.',
            'sites.cron.store' => 'Creó una tarea cron.',
            'sites.cron.update' => 'Actualizó una tarea cron.',
            'sites.cron.destroy' => 'Eliminó una tarea cron.',
            'sites.parked-domains.store' => 'Aparcó un dominio en el sitio.',
            'sites.parked-domains.destroy' => 'Retiró un dominio aparcado.',
            'sites.redirects.store' => 'Creó una redirección.',
            'sites.redirects.update' => 'Actualizó una redirección.',
            'sites.redirects.destroy' => 'Eliminó una redirección.',
            'sites.error-pages.update' => 'Actualizó una página de error.',
            'sites.ownership.repair' => 'Reparó propietarios y permisos del sitio.',
            'sites.cache.purge' => 'Purgó las cachés conocidas del sitio.',
            'sites.folder-index.update' => 'Cambió la política de listado de carpetas.',
            'sites.hotlink.update' => 'Actualizó la protección Hotlink.',
            'sites.ip-rules.store' => 'Creó una regla de acceso IP.',
            'sites.ip-rules.destroy' => 'Eliminó una regla de acceso IP.',
            'sites.git.store' => 'Conectó y desplegó un repositorio Git.',
            'sites.git.deploy' => 'Desplegó la rama configurada del repositorio Git.',
            'sites.git.destroy' => 'Desconectó el repositorio Git.',
            'sites.protected-directories.store' => 'Protegió un directorio con autenticación HTTP.',
            'sites.protected-directories.destroy' => 'Eliminó la protección HTTP de un directorio.',
            'sites.ssl.issue' => 'Solicitó un certificado SSL.',
            'sites.ssl.destroy' => 'Eliminó un certificado SSL.',
            'sites.malware.store' => 'Ejecutó un escaneo de malware.',
            'sites.malware.quarantine' => 'Movió un hallazgo de malware a cuarentena.',
            'sites.wordpress.store' => 'Instaló WordPress en el sitio.',
            'sites.migrations.store' => 'Importó una migración de archivos y base de datos.',
            'sites.pagespeed.store' => 'Ejecutó una medición PageSpeed.',
            'sites.diagnostics.store' => 'Ejecutó el diagnóstico técnico del sitio.',
            'sites.dns.connect' => 'Conectó el proveedor DNS del sitio.',
            'sites.dns.disconnect' => 'Desconectó el proveedor DNS del sitio.',
            'sites.dns.records.store' => 'Creó un registro DNS mediante el proveedor.',
            'sites.dns.records.update' => 'Actualizó un registro DNS mediante el proveedor.',
            'sites.dns.records.destroy' => 'Eliminó un registro DNS mediante el proveedor.',
            'sites.cdn.update' => 'Cambió el estado del CDN de Cloudflare.',
            'sites.cdn.purge' => 'Purgó la caché CDN de Cloudflare.',
            'sites.databases.store' => 'Creó una base de datos.',
            'sites.databases.password' => 'Rotó la contraseña de una base de datos.',
            'sites.databases.destroy' => 'Eliminó una base de datos.',
            'sites.subdomains.store' => 'Creó un subdominio.',
            'sites.subdomains.destroy' => 'Eliminó un subdominio.',
            'sites.access.terminal.token' => 'Abrió una terminal SSH real del sitio.',
        ][$routeName] ?? 'Ejecutó una acción administrativa: '.$routeName.'.';
    }
}
