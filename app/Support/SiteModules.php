<?php

namespace App\Support;

use App\Models\Site;

class SiteModules
{
    /**
     * Matches the real xpanel client site menu: same sections, same order,
     * same items. 'flat' sections render as a single link instead of an
     * accordion. Disabled items render but don't get a route/page.
     *
     * @return array<string, array{label: string, icon: string, flat?: bool, items: array<string, array{label: string, icon: string, description: string, disabled?: bool}>}>
     */
    public static function catalog(): array
    {
        return [
            'server' => [
                'label' => 'Servidor y recursos',
                'icon' => 'ki-external-drive',
                'items' => [
                    'summary' => ['label' => 'Resumen del servidor', 'icon' => 'ki-external-drive', 'description' => 'Modo de administracion y capacidad disponible para todos tus sitios.'],
                    'usage' => ['label' => 'Uso de recursos', 'icon' => 'ki-chart-simple', 'description' => 'CPU, RAM y disco disponibles en el servidor o MicroVM.'],
                    'external' => ['label' => 'Administracion externa', 'icon' => 'ki-arrow-up-right', 'description' => 'En modo VM, abre el servicio para renovar o cambiar recursos.'],
                ],
            ],
            'performance' => [
                'label' => 'Rendimiento',
                'icon' => 'ki-chart-line-up',
                'items' => [
                    'ai-troubleshooter' => ['label' => 'Diagnóstico del sitio', 'icon' => 'ki-message-programming', 'description' => 'Comprueba servicios, rutas, DNS, HTTP, HTTPS, backups y seguridad.'],
                    'page-speed' => ['label' => 'Page Speed', 'icon' => 'ki-chart-simple', 'description' => 'Diagnostico de velocidad de carga del sitio.'],
                    'cdn' => ['label' => 'CDN', 'icon' => 'ki-world', 'description' => 'Sirve contenido estatico desde una red de distribucion.'],
                ],
            ],
            'analytics' => [
                'label' => 'Analisis',
                'icon' => 'ki-chart-simple',
                'flat' => true,
                'items' => [
                    'analytics' => ['label' => 'Analisis', 'icon' => 'ki-chart-simple', 'description' => 'Visitas, trafico y paginas mas vistas.'],
                ],
            ],
            'security' => [
                'label' => 'Seguridad',
                'icon' => 'ki-shield-tick',
                'items' => [
                    'malware-scanner' => ['label' => 'Escaner de malware', 'icon' => 'ki-shield-search', 'description' => 'Analiza los archivos del sitio en busca de codigo malicioso.'],
                    'ssl' => ['label' => 'SSL', 'icon' => 'ki-lock', 'description' => 'Certificados SSL y renovacion automatica.'],
                ],
            ],
            'domains' => [
                'label' => 'Dominios',
                'icon' => 'ki-click',
                'items' => [
                    'subdomains' => ['label' => 'Subdominios', 'icon' => 'ki-abstract-26', 'description' => 'Crea subdominios que apunten a carpetas dentro de este sitio.'],
                    'parked-domains' => ['label' => 'Dominios aparcados', 'icon' => 'ki-parcel', 'description' => 'Sirve el mismo contenido bajo dominios adicionales.'],
                    'redirects' => ['label' => 'Redirecciones', 'icon' => 'ki-exit-right-corner', 'description' => 'Redirige rutas o dominios completos hacia otra URL.'],
                ],
            ],
            'website' => [
                'label' => 'Sitio web',
                'icon' => 'ki-screen',
                'items' => [
                    'wordpress' => ['label' => 'Instalar WordPress', 'icon' => 'ki-abstract-6', 'description' => 'Gestion avanzada para sitios WordPress.'],
                    'auto-installer' => ['label' => 'Instalador automatico', 'icon' => 'ki-package', 'description' => 'Instala apps populares en un clic.'],
                    'migration' => ['label' => 'Migrar sitio web', 'icon' => 'ki-arrow-up-right', 'description' => 'Importa un sitio existente desde otro proveedor.'],
                    'error-pages' => ['label' => 'Paginas de error', 'icon' => 'ki-cross-circle', 'description' => 'Personaliza las paginas 404/500 de este sitio.'],
                ],
            ],
            'files' => [
                'label' => 'Archivos',
                'icon' => 'ki-folder',
                'items' => [
                    'file-manager' => ['label' => 'Gestor de archivos', 'icon' => 'ki-folder', 'description' => 'Explora, sube y edita archivos del sitio desde el navegador.'],
                    'backups' => ['label' => 'Backups', 'icon' => 'ki-cloud-change', 'description' => 'Copias de seguridad del sitio, programables y descargables.'],
                    'ftp' => ['label' => 'Cuentas FTP', 'icon' => 'ki-abstract-14', 'description' => 'Crea cuentas FTP/SFTP con acceso limitado a carpetas.'],
                ],
            ],
            'database' => [
                'label' => 'Bases de datos',
                'icon' => 'ki-data',
                'items' => [
                    'mysql-databases' => ['label' => 'Administracion', 'icon' => 'ki-data', 'description' => 'Crea bases de datos y usuarios con privilegios por base.'],
                    'phpmyadmin' => ['label' => 'phpMyAdmin', 'icon' => 'ki-code', 'description' => 'Administra tus bases de datos desde una interfaz visual.'],
                    'remote-mysql' => ['label' => 'MySQL remoto', 'icon' => 'ki-abstract-41', 'description' => 'Autoriza IPs externas para conectarse a tus bases de datos.'],
                ],
            ],
            'advanced' => [
                'label' => 'Avanzado',
                'icon' => 'ki-setting-2',
                'items' => [
                    'ssh-access' => ['label' => 'Acceso SSH', 'icon' => 'ki-terminal', 'description' => 'Llaves SSH y acceso remoto por terminal a este sitio.'],
                    'php-configuration' => ['label' => 'Configuracion PHP', 'icon' => 'ki-code', 'description' => 'Version de PHP, extensiones y limites por sitio.'],
                    'dns-zone-editor' => ['label' => 'Editor DNS', 'icon' => 'ki-abstract-45', 'description' => 'Edita registros DNS (A, CNAME, MX, TXT) de este sitio.'],
                    'cron-jobs' => ['label' => 'Cron Jobs', 'icon' => 'ki-timer', 'description' => 'Programa tareas periodicas para este sitio.'],
                    'php-info' => ['label' => 'PHP info', 'icon' => 'ki-information-2', 'description' => 'Detalle de la configuracion activa de PHP.'],
                    'web-settings' => ['label' => 'Configuración web', 'icon' => 'ki-setting-3', 'description' => 'Listado de carpetas, Hotlink y mantenimiento de caché.'],
                    'git' => ['label' => 'Git', 'icon' => 'ki-abstract-22', 'description' => 'Despliega el sitio automaticamente desde un repositorio Git.'],
                    'password-protect-directories' => ['label' => 'Directorios protegidos', 'icon' => 'ki-lock-3', 'description' => 'Protege carpetas del sitio con usuario y contrasena.'],
                    'ip-manager' => ['label' => 'Administrador de IP', 'icon' => 'ki-security-user', 'description' => 'Bloquea o permite el acceso segun direccion IP.'],
                    'activity-log' => ['label' => 'Registro de actividad', 'icon' => 'ki-time', 'description' => 'Historial de cambios realizados en este sitio.'],
                ],
            ],
        ];
    }

    public static function find(string $section, string $key): ?array
    {
        $sectionData = self::catalog()[$section] ?? null;

        if ($sectionData === null || ! isset($sectionData['items'][$key])) {
            return null;
        }

        return $sectionData['items'][$key] + ['section' => $sectionData['label']];
    }

    public static function url(Site $site, string $section, string $key): string
    {
        return match (true) {
            $section === 'domains' && $key === 'subdomains' => route('sites.subdomains.index', $site->parent ?? $site),
            $section === 'domains' && $key === 'parked-domains' => route('sites.parked-domains.index', $site),
            $section === 'domains' && $key === 'redirects' => route('sites.redirects.index', $site),
            $section === 'website' && $key === 'error-pages' => route('sites.error-pages.index', $site),
            $section === 'website' && $key === 'wordpress' => route('sites.wordpress.index', $site),
            $section === 'website' && $key === 'auto-installer' => route('sites.installer.index', $site),
            $section === 'website' && $key === 'migration' => route('sites.migrations.index', $site),
            $section === 'analytics' => route('sites.analytics', $site),
            $section === 'files' && $key === 'file-manager' => route('sites.files.index', $site),
            $section === 'files' && $key === 'backups' => route('sites.backups.index', $site),
            $section === 'files' && $key === 'ftp' => route('sites.access.files', $site),
            $section === 'database' && $key === 'mysql-databases' => route('sites.databases.index', $site),
            $section === 'database' && $key === 'phpmyadmin' => route('sites.phpmyadmin', $site),
            $section === 'database' && $key === 'remote-mysql' => route('sites.remote-mysql.index', $site),
            $section === 'advanced' && $key === 'activity-log' => route('sites.activity.index', $site),
            $section === 'advanced' && $key === 'ssh-access' => route('sites.access.ssh', $site),
            $section === 'advanced' && $key === 'php-configuration' => route('sites.php.configuration', $site),
            $section === 'advanced' && $key === 'php-info' => route('sites.php.info', $site),
            $section === 'advanced' && $key === 'cron-jobs' => route('sites.cron.index', $site),
            $section === 'advanced' && $key === 'web-settings' => route('sites.web-settings.index', $site),
            $section === 'advanced' && $key === 'ip-manager' => route('sites.ip-rules.index', $site),
            $section === 'advanced' && $key === 'git' => route('sites.git.index', $site),
            $section === 'advanced' && $key === 'password-protect-directories' => route('sites.protected-directories.index', $site),
            $section === 'security' && $key === 'malware-scanner' => route('sites.malware.index', $site),
            $section === 'performance' && $key === 'page-speed' => route('sites.pagespeed.index', $site),
            $section === 'performance' && $key === 'ai-troubleshooter' => route('sites.diagnostics.index', $site),
            $section === 'performance' && $key === 'cdn' => route('sites.cdn.index', $site),
            $section === 'advanced' && $key === 'dns-zone-editor' => route('sites.dns.index', $site),
            default => route('sites.module', [$site, $section, $key]),
        };
    }

    public static function activeKey(): ?string
    {
        return match (true) {
            request()->routeIs('sites.module') => request()->route('module'),
            request()->routeIs('sites.files.*') => 'file-manager',
            request()->routeIs('sites.backups.*') => 'backups',
            request()->routeIs('sites.access.files') => 'ftp',
            request()->routeIs('sites.access.ssh') => 'ssh-access',
            request()->routeIs('sites.databases.*') => 'mysql-databases',
            request()->routeIs('sites.phpmyadmin') => 'phpmyadmin',
            request()->routeIs('sites.remote-mysql.*') => 'remote-mysql',
            request()->routeIs('sites.activity.*') => 'activity-log',
            request()->routeIs('sites.php.configuration*') => 'php-configuration',
            request()->routeIs('sites.php.info') => 'php-info',
            request()->routeIs('sites.cron.*') => 'cron-jobs',
            request()->routeIs('sites.analytics') => 'analytics',
            request()->routeIs('sites.subdomains.*') => 'subdomains',
            request()->routeIs('sites.parked-domains.*') => 'parked-domains',
            request()->routeIs('sites.redirects.*') => 'redirects',
            request()->routeIs('sites.error-pages.*') => 'error-pages',
            request()->routeIs('sites.wordpress.*') => 'wordpress',
            request()->routeIs('sites.installer.*') => 'auto-installer',
            request()->routeIs('sites.migrations.*') => 'migration',
            request()->routeIs('sites.web-settings.*', 'sites.cache.*', 'sites.folder-index.*', 'sites.hotlink.*') => 'web-settings',
            request()->routeIs('sites.ip-rules.*') => 'ip-manager',
            request()->routeIs('sites.git.*') => 'git',
            request()->routeIs('sites.protected-directories.*') => 'password-protect-directories',
            request()->routeIs('sites.malware.*') => 'malware-scanner',
            request()->routeIs('sites.pagespeed.*') => 'page-speed',
            request()->routeIs('sites.diagnostics.*') => 'ai-troubleshooter',
            request()->routeIs('sites.cdn.*') => 'cdn',
            request()->routeIs('sites.dns.*') => 'dns-zone-editor',
            default => null,
        };
    }

    public static function isReady(string $section, string $key): bool
    {
        return in_array($section.'.'.$key, [
            'server.summary', 'server.usage', 'server.external', 'analytics.analytics',
            'performance.ai-troubleshooter', 'performance.page-speed', 'performance.cdn',
            'security.ssl', 'security.malware-scanner', 'domains.subdomains', 'domains.parked-domains', 'domains.redirects',
            'website.wordpress', 'website.auto-installer', 'website.migration', 'website.error-pages', 'files.file-manager', 'files.backups', 'files.ftp', 'database.mysql-databases',
            'database.phpmyadmin', 'database.remote-mysql',
            'advanced.php-configuration', 'advanced.cron-jobs', 'advanced.php-info',
            'advanced.dns-zone-editor',
            'advanced.web-settings', 'advanced.ip-manager',
            'advanced.git', 'advanced.password-protect-directories',
            'advanced.activity-log', 'advanced.ssh-access',
        ], true);
    }
}
