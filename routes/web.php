<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CacheController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\CronJobController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DnsController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\ErrorPageController;
use App\Http\Controllers\FileManagerController;
use App\Http\Controllers\FolderIndexController;
use App\Http\Controllers\GitDeploymentController;
use App\Http\Controllers\GlobalFileManagerController;
use App\Http\Controllers\HotlinkController;
use App\Http\Controllers\IpRuleController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\MailDnsController;
use App\Http\Controllers\OwnershipController;
use App\Http\Controllers\ParkedDomainController;
use App\Http\Controllers\PhpConfigurationController;
use App\Http\Controllers\PhpMyAdminController;
use App\Http\Controllers\ProtectedDirectoryController;
use App\Http\Controllers\RedirectController;
use App\Http\Controllers\RemoteMysqlController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteOperationController;
use App\Http\Controllers\SubdomainController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\WebServerEngineController;
use App\Models\Site;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

Route::get('/setup', [SetupController::class, 'create'])->name('setup');
Route::post('/setup', [SetupController::class, 'store']);

Route::middleware('setup.complete')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store']);

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

        Route::get('/', function () {
            $user = auth()->user();

            return view('dashboard', [
                'modules' => array_values(array_filter([
                    ['label' => 'Sitios', 'description' => 'Dominios, vhosts y despliegues web', 'href' => route('sites.index'), 'count' => Site::whereNull('parent_site_id')->count()],
                    ['label' => 'Dominios', 'description' => 'Subdominios, redirecciones y DNS', 'href' => route('domains.index')],
                    ['label' => 'Correos', 'description' => 'Cuentas, SMTP, IMAP y dominios mail', 'href' => route('mail.index')],
                    ['label' => 'PHP', 'description' => 'Versiones, extensiones y configuracion'],
                    ['label' => 'Bases de datos', 'description' => 'MariaDB, MySQL y PostgreSQL'],
                    ['label' => 'SSL', 'description' => 'Certificados y renovacion'],
                    ['label' => 'Backups', 'description' => 'Copias internas del hosting'],
                    $user->hasPermission(Permissions::TEAM_MANAGE)
                        ? ['label' => 'Equipo', 'description' => 'Usuarios, roles y permisos', 'href' => route('team.index'), 'count' => User::count()]
                        : null,
                ])),
            ]);
        });

        Route::middleware('permission:'.Permissions::SITES_VIEW)->group(function () {
            Route::resource('sites', SiteController::class)->only(['index']);
            Route::get('/builder', fn () => view('builder.index'))->name('builder.index');

            // Registered before /domains/{domain} (destroy) below so the literal
            // segments don't get swallowed by the wildcard.
            Route::get('/domains', [DomainController::class, 'index'])->name('domains.index');
            Route::get('/domains/search', [DomainController::class, 'search'])->name('domains.search');
            Route::get('/domains/transfers', [DomainController::class, 'transfers'])->name('domains.transfers');
            Route::get('/dns', [DnsController::class, 'index'])->name('dns.index');

            Route::get('/mail', [MailController::class, 'index'])->name('mail.index');
            Route::get('/mail/domains/{domain}/dns-status', MailDnsController::class)
                ->middleware('throttle:12,1')
                ->name('mail.dns-status');
        });
        Route::middleware('permission:'.Permissions::SITES_MANAGE)->group(function () {
            Route::post('/sites/{site}/restart', [SiteOperationController::class, 'restart'])->name('sites.restart');
            Route::resource('sites', SiteController::class)->except(['index', 'show']);

            Route::get('/domains/create', [DomainController::class, 'create'])->name('domains.create');
            Route::post('/domains', [DomainController::class, 'store'])->name('domains.store');
            Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');

            Route::get('/mail/create', [MailController::class, 'create'])->name('mail.create');
            Route::post('/mail', [MailController::class, 'store'])->name('mail.store');
            Route::delete('/mail/{mailAccount}', [MailController::class, 'destroy'])->name('mail.destroy');
            Route::post('/mail/{mailAccount}/password', [MailController::class, 'resetPassword'])->name('mail.reset-password');
        });
        // Registered after the literal /sites/create, /sites/{site}/edit routes above so
        // those don't get swallowed by the {site} wildcard here.
        Route::middleware('permission:'.Permissions::SITES_VIEW)->group(function () {
            // Global ikode ("todos los sitios"): registered before /sites/{site} below
            // since both are 2-segment routes under /sites/*, and route matching is by
            // registration order for same-shape routes.
            Route::get('/sites/ikode', [GlobalFileManagerController::class, 'ikode'])->name('sites.ikode');
            Route::prefix('/sites/ikode/api')->name('sites.ikode.api.')->group(function () {
                Route::get('/list', [GlobalFileManagerController::class, 'list'])->name('list');
                Route::get('/read', [GlobalFileManagerController::class, 'read'])->name('read');
                Route::get('/download', [GlobalFileManagerController::class, 'download'])->name('download');
                Route::post('/search', [GlobalFileManagerController::class, 'search'])->name('search');
            });

            Route::get('/sites/{site}', [SiteController::class, 'show'])->name('sites.show');

            // Flat "Analisis" section: a single page, no sub-module segment.
            Route::get('/sites/{site}/analytics', AnalyticsController::class)->name('sites.analytics');
            Route::get('/sites/{site}/advanced/cache-manager', [CacheController::class, 'index'])->name('sites.cache.index');
            Route::get('/sites/{site}/advanced/folder-index-manager', [FolderIndexController::class, 'index'])->name('sites.folder-index.index');
            Route::get('/sites/{site}/advanced/hotlink-protection', [HotlinkController::class, 'index'])->name('sites.hotlink.index');
            Route::get('/sites/{site}/advanced/ip-manager', [IpRuleController::class, 'index'])->name('sites.ip-rules.index');
            Route::get('/sites/{site}/advanced/git', [GitDeploymentController::class, 'index'])->name('sites.git.index');
            Route::get('/sites/{site}/advanced/password-protect-directories', [ProtectedDirectoryController::class, 'index'])->name('sites.protected-directories.index');
            Route::get('/sites/{site}/database/mysql-databases', [DatabaseController::class, 'index'])->name('sites.databases.index');
            Route::get('/sites/{site}/database/phpmyadmin', PhpMyAdminController::class)->name('sites.phpmyadmin');
            Route::get('/sites/{site}/database/remote-mysql', [RemoteMysqlController::class, 'index'])->name('sites.remote-mysql.index');
            Route::get('/sites/{site}/domains/subdomains', [SubdomainController::class, 'index'])->name('sites.subdomains.index');
            Route::get('/sites/{site}/domains/parked-domains', [ParkedDomainController::class, 'index'])->name('sites.parked-domains.index');
            Route::get('/sites/{site}/domains/redirects', [RedirectController::class, 'index'])->name('sites.redirects.index');
            Route::get('/sites/{site}/website/error-pages', [ErrorPageController::class, 'index'])->name('sites.error-pages.index');

            // File manager chooser + real editor: registered before the generic
            // {section}/{module} wildcard below (same segment shape) so they win the match.
            Route::get('/sites/{site}/files/manager', [FileManagerController::class, 'index'])->name('sites.files.index');
            Route::get('/sites/{site}/files/manager/ikode', [FileManagerController::class, 'ikode'])->name('sites.files.ikode');
            Route::get('/sites/{site}/files/backups', [BackupController::class, 'index'])->name('sites.backups.index');
            Route::get('/sites/{site}/advanced/activity-log', [ActivityLogController::class, 'index'])->name('sites.activity.index');
            Route::get('/sites/{site}/advanced/php-configuration', [PhpConfigurationController::class, 'index'])->name('sites.php.configuration');
            Route::get('/sites/{site}/advanced/php-info', [PhpConfigurationController::class, 'info'])->name('sites.php.info');
            Route::get('/sites/{site}/advanced/cron-jobs', [CronJobController::class, 'index'])->name('sites.cron.index');
            Route::prefix('/sites/{site}/files/manager/api')->name('sites.files.api.')->group(function () {
                Route::get('/list', [FileManagerController::class, 'list'])->name('list');
                Route::get('/read', [FileManagerController::class, 'read'])->name('read');
                Route::get('/download', [FileManagerController::class, 'download'])->name('download');
                Route::post('/search', [FileManagerController::class, 'search'])->name('search');
            });

            // Every other module, grouped by its SiteModules section
            // (e.g. /sites/{site}/domains/subdomains, /sites/{site}/files/backups).
            Route::get('/sites/{site}/{section}/{module}', [SiteController::class, 'module'])->name('sites.module');
        });
        Route::middleware('permission:'.Permissions::SITES_MANAGE)->group(function () {
            Route::post('/sites/{site}/files/backups', [BackupController::class, 'store'])->name('sites.backups.store');
            Route::put('/sites/{site}/advanced/php-configuration', [PhpConfigurationController::class, 'update'])->name('sites.php.configuration.update');
            Route::post('/sites/{site}/advanced/cron-jobs', [CronJobController::class, 'store'])->name('sites.cron.store');
            Route::put('/sites/{site}/advanced/cron-jobs/{cronJob}', [CronJobController::class, 'update'])->name('sites.cron.update');
            Route::delete('/sites/{site}/advanced/cron-jobs/{cronJob}', [CronJobController::class, 'destroy'])->name('sites.cron.destroy');
            Route::post('/sites/{site}/domains/parked-domains', [ParkedDomainController::class, 'store'])->name('sites.parked-domains.store');
            Route::delete('/sites/{site}/domains/parked-domains/{parkedDomain}', [ParkedDomainController::class, 'destroy'])->name('sites.parked-domains.destroy');
            Route::post('/sites/{site}/domains/redirects', [RedirectController::class, 'store'])->name('sites.redirects.store');
            Route::put('/sites/{site}/domains/redirects/{redirect}', [RedirectController::class, 'update'])->name('sites.redirects.update');
            Route::delete('/sites/{site}/domains/redirects/{redirect}', [RedirectController::class, 'destroy'])->name('sites.redirects.destroy');
            Route::put('/sites/{site}/website/error-pages', [ErrorPageController::class, 'update'])->name('sites.error-pages.update');
            Route::post('/sites/{site}/advanced/fix-file-ownership', [OwnershipController::class, 'repair'])->name('sites.ownership.repair');
            Route::post('/sites/{site}/advanced/cache-manager/purge', [CacheController::class, 'purge'])->name('sites.cache.purge');
            Route::put('/sites/{site}/advanced/folder-index-manager', [FolderIndexController::class, 'update'])->name('sites.folder-index.update');
            Route::put('/sites/{site}/advanced/hotlink-protection', [HotlinkController::class, 'update'])->name('sites.hotlink.update');
            Route::post('/sites/{site}/advanced/ip-manager', [IpRuleController::class, 'store'])->name('sites.ip-rules.store');
            Route::delete('/sites/{site}/advanced/ip-manager/{ipRule}', [IpRuleController::class, 'destroy'])->name('sites.ip-rules.destroy');
            Route::post('/sites/{site}/advanced/git', [GitDeploymentController::class, 'store'])->name('sites.git.store');
            Route::post('/sites/{site}/advanced/git/deploy', [GitDeploymentController::class, 'deploy'])->name('sites.git.deploy');
            Route::delete('/sites/{site}/advanced/git', [GitDeploymentController::class, 'destroy'])->name('sites.git.destroy');
            Route::post('/sites/{site}/advanced/password-protect-directories', [ProtectedDirectoryController::class, 'store'])->name('sites.protected-directories.store');
            Route::delete('/sites/{site}/advanced/password-protect-directories/{protectedDirectory}', [ProtectedDirectoryController::class, 'destroy'])->name('sites.protected-directories.destroy');
            Route::put('/sites/{site}/files/backups/policy', [BackupController::class, 'policy'])->name('sites.backups.policy');
            Route::get('/sites/{site}/files/backups/{siteBackup}/download', [BackupController::class, 'download'])->name('sites.backups.download');
            Route::post('/sites/{site}/files/backups/{siteBackup}/restore', [BackupController::class, 'restore'])->name('sites.backups.restore');
            Route::delete('/sites/{site}/files/backups/{siteBackup}', [BackupController::class, 'destroy'])->name('sites.backups.destroy');
            Route::post('/sites/{site}/security/ssl', [CertificateController::class, 'issue'])->name('sites.ssl.issue');
            Route::delete('/sites/{site}/security/ssl', [CertificateController::class, 'destroy'])->name('sites.ssl.destroy');
            Route::post('/sites/{site}/database/mysql-databases', [DatabaseController::class, 'store'])->name('sites.databases.store');
            Route::post('/sites/{site}/domains/subdomains', [SubdomainController::class, 'store'])->name('sites.subdomains.store');
            Route::delete('/sites/{site}/domains/subdomains/{subdomain}', [SubdomainController::class, 'destroy'])->name('sites.subdomains.destroy');
            Route::post('/sites/{site}/database/mysql-databases/{siteDatabase}/password', [DatabaseController::class, 'password'])->name('sites.databases.password');
            Route::delete('/sites/{site}/database/mysql-databases/{siteDatabase}', [DatabaseController::class, 'destroy'])->name('sites.databases.destroy');
            Route::post('/sites/{site}/database/remote-mysql', [RemoteMysqlController::class, 'store'])->name('sites.remote-mysql.store');
            Route::delete('/sites/{site}/database/remote-mysql/{remoteHost}', [RemoteMysqlController::class, 'destroy'])->name('sites.remote-mysql.destroy');
            Route::prefix('/sites/ikode/api')->name('sites.ikode.api.')->group(function () {
                Route::post('/write', [GlobalFileManagerController::class, 'write'])->name('write');
                Route::post('/create', [GlobalFileManagerController::class, 'create'])->name('create');
                Route::post('/mkdir', [GlobalFileManagerController::class, 'mkdir'])->name('mkdir');
                Route::post('/rename', [GlobalFileManagerController::class, 'rename'])->name('rename');
                Route::post('/delete', [GlobalFileManagerController::class, 'destroy'])->name('delete');
                Route::post('/upload', [GlobalFileManagerController::class, 'upload'])->name('upload');
                Route::post('/extract', [GlobalFileManagerController::class, 'extract'])->name('extract');
            });
            Route::prefix('/sites/{site}/files/manager/api')->name('sites.files.api.')->group(function () {
                Route::post('/write', [FileManagerController::class, 'write'])->name('write');
                Route::post('/create', [FileManagerController::class, 'create'])->name('create');
                Route::post('/mkdir', [FileManagerController::class, 'mkdir'])->name('mkdir');
                Route::post('/rename', [FileManagerController::class, 'rename'])->name('rename');
                Route::post('/delete', [FileManagerController::class, 'destroy'])->name('delete');
                Route::post('/upload', [FileManagerController::class, 'upload'])->name('upload');
                Route::post('/extract', [FileManagerController::class, 'extract'])->name('extract');
            });
        });

        Route::middleware('permission:'.Permissions::TEAM_MANAGE)->group(function () {
            Route::resource('team', TeamController::class)->except(['show'])->parameters(['team' => 'user']);
            Route::resource('roles', RoleController::class)->except(['show']);
        });
        Route::middleware('permission:'.Permissions::SERVER_MANAGE)->group(function () {
            Route::get('/settings/web-servers', [WebServerEngineController::class, 'index'])->name('settings.web-servers.index');
            Route::post('/settings/web-servers/{engine}/install', [WebServerEngineController::class, 'install'])->name('settings.web-servers.install');
        });
    });
});

Route::get('/healthz', fn () => response()->json([
    'status' => 'ok',
    'service' => 'xpanel-host',
]));

Route::get('/version', fn () => response()->json([
    'service' => 'xpanel-host',
    'version' => '0.1.0-dev',
]));
