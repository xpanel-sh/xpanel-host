<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;

#[Fillable(['parent_site_id', 'domain', 'document_root', 'public_path', 'system_user', 'php_version', 'node_version', 'runtime_port', 'node_start_command', 'type', 'tenancy_mode', 'wildcard_domain', 'wildcard_ssl_status', 'web_server', 'status', 'ssl_status', 'ssl_expires_at', 'ssl_issuer', 'https_redirect'])]
class Site extends Model
{
    protected static function booted(): void
    {
        static::created(function (Site $site): void {
            if ($site->system_user === null) {
                $site->forceFill(['system_user' => static::identityFor($site)])->saveQuietly();
            }
        });
    }

    public function systemUser(): string
    {
        $user = $this->system_user;
        if ($user === null && is_string($this->domain) && $this->domain !== '') {
            $user = static::identityFor($this);
        }
        if (! is_string($user) || ! preg_match('/^xps[a-z0-9]{9,29}$/', $user)) {
            throw new \RuntimeException('El sitio no tiene una identidad Unix válida.');
        }

        return $user;
    }

    private static function identityFor(Site $site): string
    {
        $instance = config('xpanel.management_mode') === 'vps-instance'
            ? substr(str_replace('-', '', (string) config('xpanel.instance_id')), 0, 6)
            : '';

        return 'xps'.$instance.base_convert((string) ($site->id ?? 0), 10, 36).substr(hash('sha256', $site->domain), 0, 8);
    }

    protected function casts(): array
    {
        return [
            'ssl_expires_at' => 'datetime',
            'https_redirect' => 'boolean',
            'wildcard_domain' => 'boolean',
            'runtime_port' => 'integer',
        ];
    }

    public static function phpVersions(): array
    {
        return config('xpanel.php_versions', ['8.1', '8.2', '8.3', '8.4']);
    }

    public static function nodeVersions(): array
    {
        return config('xpanel.node_versions', ['22']);
    }

    public function wildcardName(): string
    {
        return '*.'.$this->domain;
    }

    public static function availableRuntimePort(?int $exceptId = null): int
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $port = random_int(20000, 49999);
            if (! static::query()->where('runtime_port', $port)->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))->exists()) {
                return $port;
            }
        }
        throw new \RuntimeException('No se pudo reservar un puerto interno para la aplicación Node.js.');
    }

    public static function webServers(): array
    {
        if (! Schema::hasTable('web_server_engines')) {
            return ['nginx'];
        }

        return WebServerEngine::query()
            ->where('status', 'installed')
            ->orderBy('id')
            ->pluck('slug')
            ->all();
    }

    public function getRouteKeyName(): string
    {
        return 'domain';
    }

    /**
     * On a real install (standalone server or provisioned by xpanel-core),
     * document_root (e.g. /var/www/{domain}) is a real, already-existing
     * path. In local dev that path doesn't exist on this machine, so the
     * file manager falls back to a sandboxed folder under storage/ for
     * this site.
     */
    public function localRoot(): string
    {
        if (is_dir($this->document_root)) {
            return $this->document_root;
        }

        $fallback = storage_path('app/sites/'.$this->domain);
        if (! is_dir($fallback)) {
            mkdir($fallback, 0755, true);
        }

        return $fallback;
    }

    /**
     * The path the web server actually serves from: document_root itself,
     * or a subdirectory within it (e.g. "public" for a Laravel app whose
     * project root holds .env/storage/vendor above the served folder).
     * File manager, SSH/SFTP, ownership and backups keep using document_root
     * directly — only nginx/Apache/OpenLiteSpeed, the PHP-FPM pool, the ACME
     * webroot challenge and error pages should ever read this value.
     */
    public function webRoot(): string
    {
        $publicPath = trim((string) $this->public_path, '/');

        return $publicPath === '' ? $this->document_root : rtrim($this->document_root, '/').'/'.$publicPath;
    }

    public function databases(): HasMany
    {
        return $this->hasMany(SiteDatabase::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(SiteBackup::class)->latest();
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class)->latest('created_at');
    }

    public function backupPolicy(): HasOne
    {
        return $this->hasOne(BackupPolicy::class);
    }

    public function phpSettings(): HasOne
    {
        return $this->hasOne(SitePhpSetting::class);
    }

    public function webSettings(): HasOne
    {
        return $this->hasOne(SiteWebSetting::class);
    }

    public function accessSettings(): HasOne
    {
        return $this->hasOne(SiteAccessSetting::class);
    }

    public function sshKeys(): HasMany
    {
        return $this->hasMany(SiteSshKey::class)->orderBy('name');
    }

    public function malwareScans(): HasMany
    {
        return $this->hasMany(SiteMalwareScan::class)->latest();
    }

    public function applications(): HasMany
    {
        return $this->hasMany(SiteApplication::class)->orderBy('type');
    }

    public function migrations(): HasMany
    {
        return $this->hasMany(SiteMigration::class)->latest();
    }

    public function pageSpeedScans(): HasMany
    {
        return $this->hasMany(PageSpeedScan::class)->latest();
    }

    public function diagnostics(): HasMany
    {
        return $this->hasMany(SiteDiagnostic::class)->latest();
    }

    public function resourceSamples(): HasMany
    {
        return $this->hasMany(SiteResourceSample::class)->latest('sampled_at');
    }

    public function dnsConnection(): HasOne
    {
        return $this->hasOne(DnsProviderConnection::class);
    }

    public function gitRepository(): HasOne
    {
        return $this->hasOne(SiteGitRepository::class);
    }

    public function cronJobs(): HasMany
    {
        return $this->hasMany(SiteCronJob::class)->orderBy('id');
    }

    public function redirects(): HasMany
    {
        return $this->hasMany(SiteRedirect::class)->orderBy('source_path');
    }

    public function errorPages(): HasMany
    {
        return $this->hasMany(SiteErrorPage::class)->orderBy('status_code');
    }

    public function ipRules(): HasMany
    {
        return $this->hasMany(SiteIpRule::class)->orderBy('address');
    }

    public function parkedDomains(): HasMany
    {
        return $this->hasMany(Domain::class)->where('type', 'alias')->orderBy('domain');
    }

    public function protectedDirectories(): HasMany
    {
        return $this->hasMany(ProtectedDirectory::class)->orderBy('path');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_site_id');
    }

    public function subdomains(): HasMany
    {
        return $this->hasMany(self::class, 'parent_site_id')->orderBy('domain');
    }
}
