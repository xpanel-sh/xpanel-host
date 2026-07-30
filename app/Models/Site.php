<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;

#[Fillable(['parent_site_id', 'domain', 'document_root', 'system_user', 'php_version', 'type', 'web_server', 'status', 'ssl_status', 'ssl_expires_at', 'ssl_issuer', 'https_redirect'])]
class Site extends Model
{
    protected static function booted(): void
    {
        static::created(function (Site $site): void {
            if ($site->system_user === null) {
                $site->forceFill(['system_user' => 'xps'.base_convert((string) $site->id, 10, 36).substr(hash('sha256', $site->domain), 0, 8)])->saveQuietly();
            }
        });
    }

    public function systemUser(): string
    {
        $user = $this->system_user;
        if ($user === null && is_string($this->domain) && $this->domain !== '') {
            $user = 'xps'.base_convert((string) ($this->id ?? 0), 10, 36).substr(hash('sha256', $this->domain), 0, 8);
        }
        if (! is_string($user) || ! preg_match('/^xps[a-z0-9]{9,29}$/', $user)) {
            throw new \RuntimeException('El sitio no tiene una identidad Unix válida.');
        }

        return $user;
    }

    protected function casts(): array
    {
        return [
            'ssl_expires_at' => 'datetime',
            'https_redirect' => 'boolean',
        ];
    }

    public static function phpVersions(): array
    {
        return config('xpanel.php_versions', ['8.1', '8.2', '8.3', '8.4']);
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
