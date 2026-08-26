<?php

use App\Http\Controllers\AccountTerminalController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CacheController;
use App\Http\Controllers\CdnController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\CronJobController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DnsController;
use App\Http\Controllers\DnsZoneController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\DomainMailSettingsController;
use App\Http\Controllers\ErrorPageController;
use App\Http\Controllers\FileManagerController;
use App\Http\Controllers\FolderIndexController;
use App\Http\Controllers\GitDeploymentController;
use App\Http\Controllers\GlobalFileManagerController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\HotlinkController;
use App\Http\Controllers\IkodeAgentController;
use App\Http\Controllers\IpRuleController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\MailDnsController;
use App\Http\Controllers\MalwareScanController;
use App\Http\Controllers\PageSpeedController;
use App\Http\Controllers\PanelAccessController;
use App\Http\Controllers\PanelNotificationController;
use App\Http\Controllers\ParkedDomainController;
use App\Http\Controllers\PhpConfigurationController;
use App\Http\Controllers\PhpMyAdminController;
use App\Http\Controllers\ProtectedDirectoryController;
use App\Http\Controllers\RedirectController;
use App\Http\Controllers\RemoteMysqlController;
use App\Http\Controllers\ResourceMetricsController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ServerIpAddressController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SiteAccessController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteDiagnosticController;
use App\Http\Controllers\SiteMigrationController;
use App\Http\Controllers\SiteOperationController;
use App\Http\Controllers\SiteTerminalController;
use App\Http\Controllers\SiteWebSettingsController;
use App\Http\Controllers\SubdomainController;
use App\Http\Controllers\TeamChatController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\WebServerEngineController;
use App\Http\Controllers\WordPressController;
use App\Http\Controllers\XflowController;
use App\Http\Controllers\XflowWebhookController;
use App\Http\Controllers\XMailController;
use App\Models\ActivityLog;
use App\Models\Domain;
use App\Models\MailAccount;
use App\Models\Site;
use App\Models\SiteBackup;
use App\Models\SiteDatabase;
use App\Models\User;
use App\Services\ServerContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

Route::get('/setup', [SetupController::class, 'create'])->name('setup');
Route::post('/setup', [SetupController::class, 'store']);

Route::middleware('setup.complete')->group(function () {
    Route::post('/xflow/hooks/{workflow}/{token}', XflowWebhookController::class)
        ->middleware('throttle:30,1')->where('token', '[a-f0-9]{64}')->name('xflow.webhook');
    Route::get('/xmail/login', [XMailController::class, 'login'])->name('xmail.login');
    Route::post('/xmail/login', [XMailController::class, 'authenticate'])->middleware('throttle:xmail-login')->name('xmail.authenticate');
    Route::middleware('xmail.auth')->group(function () {
        Route::get('/xmail', [XMailController::class, 'index'])->name('xmail.index');
        Route::post('/xmail/logout', [XMailController::class, 'logout'])->name('xmail.logout');
        Route::prefix('/xmail/api')->name('xmail.api.')->group(function () {
            Route::get('/folders', [XMailController::class, 'folders'])->name('folders');
            Route::post('/folders', [XMailController::class, 'createFolder'])->name('folders.create');
            Route::delete('/folders', [XMailController::class, 'deleteFolder'])->name('folders.destroy');
            Route::get('/messages', [XMailController::class, 'messages'])->name('messages');
            Route::get('/message', [XMailController::class, 'message'])->name('message');
            Route::post('/flag', [XMailController::class, 'flag'])->name('flag');
            Route::post('/move', [XMailController::class, 'move'])->name('move');
            Route::delete('/message', [XMailController::class, 'deleteMessage'])->name('message.destroy');
            Route::post('/send', [XMailController::class, 'send'])->middleware('throttle:30,1')->name('send');
            Route::get('/attachment', [XMailController::class, 'attachment'])->name('attachment');
        });
    });

    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store']);

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
        Route::get('/search', GlobalSearchController::class)->middleware('throttle:120,1')->name('search');

        Route::prefix('/team-chat')->name('team-chat.')->group(function () {
            Route::get('/conversations', [TeamChatController::class, 'conversations'])->middleware('throttle:120,1')->name('conversations');
            Route::post('/conversations', [TeamChatController::class, 'createConversation'])->middleware('throttle:20,1')->name('conversations.store');
            Route::get('/conversations/{conversation}/messages', [TeamChatController::class, 'messages'])->middleware('throttle:120,1')->name('messages');
            Route::post('/conversations/{conversation}/messages', [TeamChatController::class, 'storeMessage'])->middleware('throttle:30,1')->name('messages.store');
            Route::post('/conversations/{conversation}/read', [TeamChatController::class, 'read'])->middleware('throttle:60,1')->name('read');
        });
        Route::prefix('/notifications')->name('notifications.')->group(function () {
            Route::get('/', [PanelNotificationController::class, 'index'])->middleware('throttle:120,1')->name('index');
            Route::post('/read-all', [PanelNotificationController::class, 'readAll'])->middleware('throttle:60,1')->name('read-all');
            Route::post('/{notification}/read', [PanelNotificationController::class, 'read'])->middleware('throttle:60,1')->name('read');
        });

        Route::get('/', function () {
            $user = auth()->user();

            return view('dashboard', [
                'server' => app(ServerContext::class)->snapshot(),
                'stats' => [
                    'sites' => Site::whereNull('parent_site_id')->count(),
                    'subdomains' => Site::whereNotNull('parent_site_id')->count(),
                    'domains' => Domain::count(),
                    'mail_accounts' => MailAccount::count(),
                    'databases' => SiteDatabase::count(),
                    'backups' => SiteBackup::where('status', 'completed')->count(),
                ],
                'recentSites' => Site::whereNull('parent_site_id')
                    ->withCount('subdomains')
                    ->latest()
                    ->limit(6)
                    ->get(),
                'recentActivity' => ActivityLog::with(['site', 'user'])
                    ->latest('created_at')
                    ->limit(6)
                    ->get(),
                'teamCount' => $user->hasPermission(Permissions::TEAM_MANAGE) ? User::count() : null,
            ]);
        });

        Route::get('/api/resources/live', [ResourceMetricsController::class, 'account'])
            ->middleware('throttle:60,1')
            ->name('resources.live');

        Route::middleware('permission:'.Permissions::SITES_VIEW)->group(function () {
            Route::get('/xflow', [XflowController::class, 'index'])->name('xflow.index');
            Route::get('/xflow/runs/{run}', [XflowController::class, 'runShow'])->name('xflow.runs.show');
            Route::get('/xflow/{workflow}/builder', [XflowController::class, 'builder'])->name('xflow.builder');
            Route::resource('sites', SiteController::class)->only(['index']);
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
            Route::get('/xflow/create', [XflowController::class, 'create'])->name('xflow.create');
            Route::post('/xflow', [XflowController::class, 'store'])->name('xflow.store');
            Route::put('/xflow/{workflow}', [XflowController::class, 'update'])->name('xflow.update');
            Route::post('/xflow/{workflow}/run', [XflowController::class, 'run'])->middleware('throttle:10,1')->name('xflow.run');
            Route::patch('/xflow/{workflow}/toggle', [XflowController::class, 'toggle'])->name('xflow.toggle');
            Route::delete('/xflow/{workflow}', [XflowController::class, 'destroy'])->name('xflow.destroy');
            Route::post('/sites/{site}/restart', [SiteOperationController::class, 'restart'])->name('sites.restart');
            Route::resource('sites', SiteController::class)->except(['index', 'show']);

            Route::get('/domains/create', [DomainController::class, 'create'])->name('domains.create');
            Route::post('/domains', [DomainController::class, 'store'])->name('domains.store');
            Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');

            Route::get('/mail/create', [MailController::class, 'create'])->name('mail.create');
            Route::post('/mail', [MailController::class, 'store'])->name('mail.store');
            Route::delete('/mail/{mailAccount}', [MailController::class, 'destroy'])->name('mail.destroy');
            Route::post('/mail/{mailAccount}/password', [MailController::class, 'resetPassword'])->name('mail.reset-password');
            Route::put('/mail/{mailAccount}/limits', [MailController::class, 'updateLimits'])->name('mail.update-limits');
            Route::put('/mail/domains/{domain}/settings', [DomainMailSettingsController::class, 'update'])->name('mail.domains.settings');

            Route::post('/server-ip-addresses', [ServerIpAddressController::class, 'store'])->name('server-ip-addresses.store');
            Route::delete('/server-ip-addresses/{serverIpAddress}', [ServerIpAddressController::class, 'destroy'])->name('server-ip-addresses.destroy');
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
                Route::get('/agents', [IkodeAgentController::class, 'state'])->name('agents.state');
            });

            Route::get('/sites/{site}', [SiteController::class, 'show'])->name('sites.show');
            Route::get('/api/sites/{site}/resources/live', [ResourceMetricsController::class, 'site'])
                ->middleware('throttle:30,1')
                ->name('sites.resources.live');

            // Flat "Analisis" section: a single page, no sub-module segment.
            Route::get('/sites/{site}/analytics', AnalyticsController::class)->name('sites.analytics');
            Route::get('/sites/{site}/advanced/web-settings', SiteWebSettingsController::class)->name('sites.web-settings.index');
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
            Route::get('/sites/{site}/domains/{subdomainLabel}', [SubdomainController::class, 'edit'])
                ->where('subdomainLabel', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')
                ->middleware('permission:'.Permissions::SITES_MANAGE)
                ->name('sites.subdomains.edit');
            Route::get('/sites/{site}/website/error-pages', [ErrorPageController::class, 'index'])->name('sites.error-pages.index');
            Route::get('/sites/{site}/website/wordpress', [WordPressController::class, 'index'])->name('sites.wordpress.index');
            Route::get('/sites/{site}/website/auto-installer', [WordPressController::class, 'catalog'])->name('sites.installer.index');
            Route::get('/sites/{site}/website/migration', [SiteMigrationController::class, 'index'])->name('sites.migrations.index');

            // File manager chooser + real editor: registered before the generic
            // {section}/{module} wildcard below (same segment shape) so they win the match.
            Route::get('/sites/{site}/files/manager', [FileManagerController::class, 'index'])->name('sites.files.index');
            Route::get('/sites/{site}/files/manager/ikode', [FileManagerController::class, 'ikode'])->name('sites.files.ikode');
            Route::get('/sites/{site}/files/backups', [BackupController::class, 'index'])->name('sites.backups.index');
            Route::get('/sites/{site}/files/ftp', [SiteAccessController::class, 'files'])->name('sites.access.files');
            Route::get('/sites/{site}/advanced/ssh-access', [SiteAccessController::class, 'ssh'])->name('sites.access.ssh');
            Route::get('/sites/{site}/advanced/activity-log', [ActivityLogController::class, 'index'])->name('sites.activity.index');
            Route::get('/sites/{site}/advanced/xflow', [XflowController::class, 'index'])->name('sites.xflow.index');
            Route::get('/sites/{site}/advanced/xflow/{workflow}/builder', [XflowController::class, 'siteBuilder'])->name('sites.xflow.builder');
            Route::get('/sites/{site}/advanced/php-configuration', [PhpConfigurationController::class, 'index'])->name('sites.php.configuration');
            Route::get('/sites/{site}/advanced/php-info', [PhpConfigurationController::class, 'info'])->name('sites.php.info');
            Route::get('/sites/{site}/advanced/cron-jobs', [CronJobController::class, 'index'])->name('sites.cron.index');
            Route::get('/sites/{site}/security/malware-scanner', [MalwareScanController::class, 'index'])->name('sites.malware.index');
            Route::get('/sites/{site}/performance/page-speed', [PageSpeedController::class, 'index'])->name('sites.pagespeed.index');
            Route::get('/sites/{site}/performance/ai-troubleshooter', [SiteDiagnosticController::class, 'index'])->name('sites.diagnostics.index');
            Route::get('/sites/{site}/performance/cdn', [CdnController::class, 'index'])->name('sites.cdn.index');
            Route::get('/sites/{site}/advanced/dns-zone-editor', [DnsZoneController::class, 'index'])->name('sites.dns.index');
            Route::prefix('/sites/{site}/files/manager/api')->name('sites.files.api.')->group(function () {
                Route::get('/list', [FileManagerController::class, 'list'])->name('list');
                Route::get('/read', [FileManagerController::class, 'read'])->name('read');
                Route::get('/download', [FileManagerController::class, 'download'])->name('download');
                Route::post('/search', [FileManagerController::class, 'search'])->name('search');
                Route::get('/agents', [IkodeAgentController::class, 'state'])->name('agents.state');
            });

            // Every other module, grouped by its SiteModules section
            // (e.g. /sites/{site}/domains/subdomains, /sites/{site}/files/backups).
            Route::get('/sites/{site}/{section}/{module}', [SiteController::class, 'module'])->name('sites.module');
        });
        Route::middleware('permission:'.Permissions::SITES_MANAGE)->group(function () {
            Route::post('/sites/{site}/files/backups', [BackupController::class, 'store'])->name('sites.backups.store');
            Route::put('/sites/{site}/access', [SiteAccessController::class, 'update'])->name('sites.access.update');
            Route::post('/sites/{site}/access/ssh-keys', [SiteAccessController::class, 'storeKey'])->name('sites.access.keys.store');
            Route::delete('/sites/{site}/access/ssh-keys/{sshKey}', [SiteAccessController::class, 'destroyKey'])->name('sites.access.keys.destroy');
            Route::post('/sites/{site}/access/terminal-token', [SiteTerminalController::class, 'token'])->middleware('throttle:10,1')->name('sites.access.terminal.token');
            Route::post('/sites/ikode/terminal-token', [AccountTerminalController::class, 'token'])->middleware('throttle:10,1')->name('sites.ikode.terminal.token');
            Route::put('/sites/{site}/advanced/php-configuration', [PhpConfigurationController::class, 'update'])->name('sites.php.configuration.update');
            Route::put('/sites/{site}/advanced/php-configuration/profile', [PhpConfigurationController::class, 'assignProfile'])->name('sites.php.profile.assign');
            Route::post('/sites/{site}/advanced/php-configuration/profiles', [PhpConfigurationController::class, 'storeProfile'])->name('sites.php.profiles.store');
            Route::put('/sites/{site}/advanced/php-configuration/profiles/{phpProfile}', [PhpConfigurationController::class, 'updateProfile'])->name('sites.php.profiles.update');
            Route::delete('/sites/{site}/advanced/php-configuration/profiles/{phpProfile}', [PhpConfigurationController::class, 'destroyProfile'])->name('sites.php.profiles.destroy');
            Route::post('/sites/{site}/advanced/php-configuration/extensions/{extension}', [PhpConfigurationController::class, 'installExtension'])->where('extension', '[a-z0-9]+')->name('sites.php.extensions.install');
            Route::post('/sites/{site}/advanced/cron-jobs', [CronJobController::class, 'store'])->name('sites.cron.store');
            Route::put('/sites/{site}/advanced/cron-jobs/{cronJob}', [CronJobController::class, 'update'])->name('sites.cron.update');
            Route::delete('/sites/{site}/advanced/cron-jobs/{cronJob}', [CronJobController::class, 'destroy'])->name('sites.cron.destroy');
            Route::post('/sites/{site}/domains/parked-domains', [ParkedDomainController::class, 'store'])->name('sites.parked-domains.store');
            Route::delete('/sites/{site}/domains/parked-domains/{parkedDomain}', [ParkedDomainController::class, 'destroy'])->name('sites.parked-domains.destroy');
            Route::post('/sites/{site}/domains/redirects', [RedirectController::class, 'store'])->name('sites.redirects.store');
            Route::put('/sites/{site}/domains/redirects/{redirect}', [RedirectController::class, 'update'])->name('sites.redirects.update');
            Route::delete('/sites/{site}/domains/redirects/{redirect}', [RedirectController::class, 'destroy'])->name('sites.redirects.destroy');
            Route::put('/sites/{site}/website/error-pages', [ErrorPageController::class, 'update'])->name('sites.error-pages.update');
            Route::post('/sites/{site}/website/wordpress', [WordPressController::class, 'store'])->middleware('throttle:3,1')->name('sites.wordpress.store');
            Route::post('/sites/{site}/website/migration', [SiteMigrationController::class, 'store'])->middleware('throttle:3,1')->name('sites.migrations.store');
            Route::post('/sites/{site}/performance/page-speed', [PageSpeedController::class, 'store'])->middleware('throttle:4,10')->name('sites.pagespeed.store');
            Route::post('/sites/{site}/performance/ai-troubleshooter', [SiteDiagnosticController::class, 'store'])->middleware('throttle:10,1')->name('sites.diagnostics.store');
            Route::put('/sites/{site}/performance/cdn', [CdnController::class, 'update'])->name('sites.cdn.update');
            Route::post('/sites/{site}/performance/cdn/purge', [CdnController::class, 'purge'])->middleware('throttle:5,1')->name('sites.cdn.purge');
            Route::post('/sites/{site}/advanced/dns-zone-editor/connect', [DnsZoneController::class, 'connect'])->middleware('throttle:5,1')->name('sites.dns.connect');
            Route::delete('/sites/{site}/advanced/dns-zone-editor/connect', [DnsZoneController::class, 'disconnect'])->name('sites.dns.disconnect');
            Route::post('/sites/{site}/advanced/dns-zone-editor/records', [DnsZoneController::class, 'store'])->name('sites.dns.records.store');
            Route::put('/sites/{site}/advanced/dns-zone-editor/records/{record}', [DnsZoneController::class, 'update'])->where('record', '[a-f0-9]{32}')->name('sites.dns.records.update');
            Route::delete('/sites/{site}/advanced/dns-zone-editor/records/{record}', [DnsZoneController::class, 'destroy'])->where('record', '[a-f0-9]{32}')->name('sites.dns.records.destroy');
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
            Route::post('/sites/{site}/security/ssl/all', [CertificateController::class, 'issueAll'])->name('sites.ssl.issue-all');
            Route::post('/sites/{site}/security/malware-scanner', [MalwareScanController::class, 'store'])->middleware('throttle:3,1')->name('sites.malware.store');
            Route::post('/sites/{site}/security/malware-scanner/{malwareScan}/findings/{finding}/quarantine', [MalwareScanController::class, 'quarantine'])->whereNumber('finding')->name('sites.malware.quarantine');
            Route::delete('/sites/{site}/security/ssl', [CertificateController::class, 'destroy'])->name('sites.ssl.destroy');
            Route::post('/sites/{site}/database/mysql-databases', [DatabaseController::class, 'store'])->name('sites.databases.store');
            Route::post('/sites/{site}/domains/subdomains', [SubdomainController::class, 'store'])->name('sites.subdomains.store');
            Route::put('/sites/{site}/domains/{subdomainLabel}', [SubdomainController::class, 'update'])
                ->where('subdomainLabel', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')
                ->name('sites.subdomains.update');
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
                Route::post('/agents/connections', [IkodeAgentController::class, 'storeConnection'])->name('agents.connections.store');
                Route::put('/agents/connections/{connection}', [IkodeAgentController::class, 'updateConnection'])->name('agents.connections.update');
                Route::delete('/agents/connections/{connection}', [IkodeAgentController::class, 'destroyConnection'])->name('agents.connections.destroy');
                Route::post('/agents/conversations', [IkodeAgentController::class, 'storeConversation'])->name('agents.conversations.store');
                Route::delete('/agents/conversations/{conversation}', [IkodeAgentController::class, 'destroyConversation'])->name('agents.conversations.destroy');
                Route::post('/agents/chat', [IkodeAgentController::class, 'chat'])->middleware('throttle:20,1')->name('agents.chat');
            });
            Route::prefix('/sites/{site}/files/manager/api')->name('sites.files.api.')->group(function () {
                Route::post('/write', [FileManagerController::class, 'write'])->name('write');
                Route::post('/create', [FileManagerController::class, 'create'])->name('create');
                Route::post('/mkdir', [FileManagerController::class, 'mkdir'])->name('mkdir');
                Route::post('/rename', [FileManagerController::class, 'rename'])->name('rename');
                Route::post('/delete', [FileManagerController::class, 'destroy'])->name('delete');
                Route::post('/upload', [FileManagerController::class, 'upload'])->name('upload');
                Route::post('/extract', [FileManagerController::class, 'extract'])->name('extract');
                Route::post('/agents/connections', [IkodeAgentController::class, 'storeConnection'])->name('agents.connections.store');
                Route::put('/agents/connections/{connection}', [IkodeAgentController::class, 'updateConnection'])->name('agents.connections.update');
                Route::delete('/agents/connections/{connection}', [IkodeAgentController::class, 'destroyConnection'])->name('agents.connections.destroy');
                Route::post('/agents/conversations', [IkodeAgentController::class, 'storeConversation'])->name('agents.conversations.store');
                Route::delete('/agents/conversations/{conversation}', [IkodeAgentController::class, 'destroyConversation'])->name('agents.conversations.destroy');
                Route::post('/agents/chat', [IkodeAgentController::class, 'chat'])->middleware('throttle:20,1')->name('agents.chat');
            });
        });

        Route::middleware('permission:'.Permissions::TEAM_MANAGE)->group(function () {
            Route::resource('team', TeamController::class)->except(['show'])->parameters(['team' => 'user']);
            Route::resource('roles', RoleController::class)->except(['show']);
        });
        Route::middleware('permission:'.Permissions::SERVER_MANAGE)->group(function () {
            Route::put('/sites/{site}/performance/page-speed/api-key', [PageSpeedController::class, 'updateApiKey'])->name('sites.pagespeed.api-key');
            Route::get('/settings/panel-access', [PanelAccessController::class, 'index'])->name('settings.panel-access.index');
            Route::put('/settings/panel-access/domain', [PanelAccessController::class, 'domain'])->name('settings.panel-access.domain');
            Route::put('/settings/panel-access/ip', [PanelAccessController::class, 'ip'])->name('settings.panel-access.ip');
            Route::post('/settings/panel-access/ssl', [PanelAccessController::class, 'ssl'])->name('settings.panel-access.ssl');
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

// Called only by the loopback sshd forced-command gate, never by a browser
// session — see SiteTerminalController::consume() and TerminalTokenIssuer.
Route::post('/internal/terminal/consume', [SiteTerminalController::class, 'consume']);
