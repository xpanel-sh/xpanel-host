<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Schema;

class VirtualHostGenerator
{
    public function render(Site $site): string
    {
        return match ($site->web_server) {
            'nginx' => $this->renderNginx($site),
            'apache' => $this->renderApache($site),
            default => $this->renderOpenLiteSpeed($site),
        };
    }

    public function write(Site $site): string
    {
        $path = $this->vhostPath($site);
        $this->atomicWrite($path, $this->render($site));

        return $path;
    }

    public function writeGateway(Site $site): string
    {
        $path = $this->gatewayPath($site);
        $this->atomicWrite($path, $this->renderGateway($site));

        return $path;
    }

    public function writeOpenLiteSpeedRegistry(?int $excludeSiteId = null): string
    {
        $sites = Schema::hasTable('sites')
            ? Site::query()
                ->where('web_server', 'openlitespeed')
                ->when($excludeSiteId, fn ($query) => $query->whereKeyNot($excludeSiteId))
                ->orderBy('id')
                ->get()
            : collect();
        $maps = $sites->map(fn (Site $site) => "    map                     xpanel_{$site->id} ".implode(',', $this->domainNames($site)))->implode("\n");
        $vhosts = $sites->map(fn (Site $site) => <<<CONF
virtualhost xpanel_{$site->id} {
    vhRoot                   {$site->document_root}
    configFile               /usr/local/lsws/conf/vhosts/xpanel-{$site->domain}/vhconf.conf
    allowSymbolLink          1
    enableScript             1
    restrained               1
}
CONF)->implode("\n\n");
        $contents = trim($vhosts."\n\n".<<<CONF
listener xpanel_backend {
    address                  127.0.0.1:8083
    secure                   0
{$maps}
}
CONF);
        $path = storage_path('app/openlitespeed/registry.conf');
        $this->atomicWrite($path, $contents);

        return $path;
    }

    public function writePhpPool(Site $site): ?string
    {
        $path = $this->phpPoolPath($site);
        if ($site->type !== 'php' || $site->web_server === 'openlitespeed') {
            if (is_file($path)) {
                unlink($path);
            }

            return null;
        }

        $this->atomicWrite($path, $this->renderPhpPool($site));

        return $path;
    }

    public function remove(Site $site): void
    {
        foreach ([$this->vhostPath($site), $this->gatewayPath($site), $this->phpPoolPath($site)] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function vhostPath(Site $site): string
    {
        return storage_path('app/vhosts/'.$site->domain.'.conf');
    }

    public function phpPoolPath(Site $site): string
    {
        return storage_path('app/php-fpm/'.$site->domain.'.conf');
    }

    public function gatewayPath(Site $site): string
    {
        return storage_path('app/gateways/'.$site->domain.'.conf');
    }

    private function renderApache(Site $site): string
    {
        if ($site->status !== 'active') {
            return $this->renderApacheSuspended($site);
        }

        return $site->type === 'static' ? $this->renderApacheStatic($site) : $this->renderApachePhp($site);
    }

    private function renderApachePhp(Site $site): string
    {
        $socket = '/run/php/php'.$site->php_version.'-fpm-'.$site->domain.'.sock';
        $aliases = $this->apacheAliases($site);

        return <<<CONF
<VirtualHost 127.0.0.1:8082>
    ServerName {$site->domain}
{$aliases}
    DocumentRoot {$site->document_root}

    <FilesMatch \.php$>
        SetHandler "proxy:unix:{$socket}|fcgi://localhost"
    </FilesMatch>

    <Directory {$site->document_root}>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/{$site->domain}-error.log
    CustomLog \${APACHE_LOG_DIR}/{$site->domain}-access.log combined
</VirtualHost>
CONF;
    }

    private function renderApacheStatic(Site $site): string
    {
        $aliases = $this->apacheAliases($site);

        return <<<CONF
<VirtualHost 127.0.0.1:8082>
    ServerName {$site->domain}
{$aliases}
    DocumentRoot {$site->document_root}

    <Directory {$site->document_root}>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/{$site->domain}-error.log
    CustomLog \${APACHE_LOG_DIR}/{$site->domain}-access.log combined
</VirtualHost>
CONF;
    }

    private function renderNginx(Site $site): string
    {
        if ($site->status !== 'active') {
            return $this->renderNginxSuspended($site);
        }

        return $site->type === 'static' ? $this->renderNginxStatic($site) : $this->renderNginxPhp($site);
    }

    private function renderNginxPhp(Site $site): string
    {
        $socket = '/run/php/php'.$site->php_version.'-fpm-'.$site->domain.'.sock';

        return <<<CONF
server {
    listen 127.0.0.1:8081;
    server_name {$site->domain};
    root {$site->document_root};
    index index.php index.html;
    set_real_ip_from 127.0.0.1;
    real_ip_header X-Forwarded-For;

    access_log /var/log/nginx/{$site->domain}-access.log;
    error_log /var/log/nginx/{$site->domain}-error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:{$socket};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }

    location ~ /\.ht {
        deny all;
    }
}
CONF;
    }

    private function renderNginxStatic(Site $site): string
    {
        return <<<CONF
server {
    listen 127.0.0.1:8081;
    server_name {$site->domain};
    root {$site->document_root};
    index index.html;
    set_real_ip_from 127.0.0.1;
    real_ip_header X-Forwarded-For;

    access_log /var/log/nginx/{$site->domain}-access.log;
    error_log /var/log/nginx/{$site->domain}-error.log;

    location / {
        try_files \$uri \$uri/ =404;
    }
}
CONF;
    }

    private function renderNginxSuspended(Site $site): string
    {
        return <<<CONF
server {
    listen 127.0.0.1:8081;
    server_name {$site->domain};
    root {$site->document_root};

    location ^~ /.well-known/acme-challenge/ {
        try_files \$uri =404;
    }

    default_type text/plain;
    return 503 "Sitio suspendido por el propietario de este servidor.\n";
}
CONF;
    }

    private function renderApacheSuspended(Site $site): string
    {
        return <<<CONF
<VirtualHost 127.0.0.1:8082>
    ServerName {$site->domain}
    DocumentRoot {$site->document_root}
    ErrorDocument 503 "Sitio suspendido por el propietario de este servidor."
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ - [R=503,L]
</VirtualHost>
CONF;
    }

    private function renderOpenLiteSpeed(Site $site): string
    {
        $php = str_replace('.', '', $site->php_version);
        $settings = Schema::hasTable('site_php_settings') ? $site->phpSettings : null;
        $memoryLimit = $settings?->memory_limit ?? '256M';
        $uploadLimit = $settings?->upload_max_filesize ?? '64M';
        $postLimit = $settings?->post_max_size ?? '64M';
        $executionTime = $settings?->max_execution_time ?? 60;
        $displayErrors = $settings?->display_errors ? 'On' : 'Off';
        $domainNames = implode(',', $this->domainNames($site));

        return <<<CONF
docRoot                   {$site->document_root}
vhDomain                  {$domainNames}
enableGzip                1

index  {
    useServer               0
    indexFiles              index.php, index.html
}

errorlog /var/log/xpanel-host/{$site->domain}-ols-error.log {
    useServer               0
    logLevel                WARN
    rollingSize             10M
}

accesslog /var/log/xpanel-host/{$site->domain}-ols-access.log {
    useServer               0
    logFormat               "%h %l %u %t \"%r\" %>s %b"
    rollingSize             10M
}

extprocessor lsphp{$php} {
    type                    lsapi
    address                 uds://tmp/lshttpd/xpanel-{$site->id}.sock
    maxConns                10
    env                     PHP_LSAPI_CHILDREN=10
    initTimeout             60
    retryTimeout            0
    persistConn             1
    respBuffer              0
    autoStart               1
    path                    /usr/local/lsws/lsphp{$php}/bin/lsphp
    backlog                 100
    instances               1
    extUser                 www-data
    extGroup                www-data
    runOnStartUp            1
}

scripthandler  {
    add                     lsapi:lsphp{$php} php
}

rewrite  {
    enable                  1
    autoLoadHtaccess        1
}

phpIniOverride  {
    php_admin_value         memory_limit {$memoryLimit}
    php_admin_value         upload_max_filesize {$uploadLimit}
    php_admin_value         post_max_size {$postLimit}
    php_admin_value         max_execution_time {$executionTime}
    php_admin_flag          display_errors {$displayErrors}
}
CONF;
    }

    public function renderGateway(Site $site): string
    {
        $http = $this->renderGatewayServer($site, false);
        if ($site->ssl_status !== 'active') {
            return $http;
        }

        $https = $this->renderGatewayServer($site, true, $this->domainNames($site, true));
        if ($site->https_redirect && $site->status === 'active') {
            $secureNames = $this->domainNames($site, true);
            $serverNames = implode(' ', $secureNames);
            $http = <<<CONF
server {
    listen 80;
    server_name {$serverNames};
    root {$site->document_root};

    location ^~ /.well-known/acme-challenge/ {
        try_files \$uri =404;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}
CONF;
            $pendingNames = array_values(array_diff($this->domainNames($site), $secureNames));
            if ($pendingNames !== []) {
                $http .= "\n\n".$this->renderGatewayServer($site, false, $pendingNames);
            }
        }

        return $http."\n\n".$https;
    }

    /** @param array<int, string>|null $domainNames */
    private function renderGatewayServer(Site $site, bool $tls, ?array $domainNames = null): string
    {
        $listen = $tls ? 'listen 443 ssl;' : 'listen 80;';
        $certificate = $tls
            ? "\n    ssl_certificate /etc/letsencrypt/live/{$site->domain}/fullchain.pem;\n    ssl_certificate_key /etc/letsencrypt/live/{$site->domain}/privkey.pem;\n    ssl_protocols TLSv1.2 TLSv1.3;"
            : '';
        $serverNames = implode(' ', $domainNames ?? $this->domainNames($site));

        if ($site->status !== 'active') {
            return <<<CONF
server {
    {$listen}{$certificate}
    server_name {$serverNames};
    root {$site->document_root};

    location ^~ /.well-known/acme-challenge/ {
        try_files \$uri =404;
    }

    default_type text/plain;
    return 503 "Sitio suspendido por el propietario de este servidor.\n";
}
CONF;
        }

        if ($site->web_server === 'nginx') {
            return $this->renderNginxGatewayServer($site, $listen, $certificate, $domainNames);
        }

        $port = $site->web_server === 'apache' ? 8082 : 8083;
        $redirects = $this->renderGatewayRedirects($site);
        $errorPages = $this->renderGatewayErrorPages($site);

        return <<<CONF
server {
    {$listen}{$certificate}
    server_name {$serverNames};
    root {$site->document_root};

    location ^~ /.well-known/acme-challenge/ {
        try_files \$uri =404;
    }

{$redirects}{$errorPages}

    location / {
        proxy_intercept_errors on;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_pass http://127.0.0.1:{$port};
    }
}
CONF;
    }

    /** @param array<int, string>|null $domainNames */
    private function renderNginxGatewayServer(Site $site, string $listen, string $certificate, ?array $domainNames = null): string
    {
        $redirects = $this->renderGatewayRedirects($site);
        $errorPages = $this->renderGatewayErrorPages($site);
        $serverNames = implode(' ', $domainNames ?? $this->domainNames($site));
        $handler = $site->type === 'static'
            ? <<<'CONF'
    location / {
        try_files $uri $uri/ =404;
    }
CONF
            : <<<CONF
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php{$site->php_version}-fpm-{$site->domain}.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_intercept_errors on;
    }
CONF;

        return <<<CONF
server {
    {$listen}{$certificate}
    server_name {$serverNames};
    root {$site->document_root};
    index index.php index.html;

    location ^~ /.well-known/acme-challenge/ {
        try_files \$uri =404;
    }

{$redirects}{$errorPages}
{$handler}
}
CONF;
    }

    /** @return array<int, string> */
    private function domainNames(Site $site, bool $onlySecureAliases = false): array
    {
        if (! Schema::hasTable('domains')) {
            return [$site->domain];
        }

        return array_values(array_unique(array_merge(
            [$site->domain],
            $site->parkedDomains()
                ->when($onlySecureAliases, fn ($query) => $query->where('ssl_status', 'active'))
                ->pluck('domain')->all(),
        )));
    }

    private function apacheAliases(Site $site): string
    {
        $aliases = array_slice($this->domainNames($site), 1);

        return $aliases === [] ? '' : '    ServerAlias '.implode(' ', $aliases);
    }

    private function renderGatewayRedirects(Site $site): string
    {
        if (! Schema::hasTable('site_redirects')) {
            return '';
        }

        return $site->redirects()->where('enabled', true)->orderByDesc('source_path')->get()->map(function ($redirect): string {
            $modifier = $redirect->match_type === 'exact' ? '= ' : '^~ ';

            return "    location {$modifier}{$redirect->source_path} {\n        return {$redirect->status_code} {$redirect->target_url};\n    }\n\n";
        })->implode('');
    }

    private function renderGatewayErrorPages(Site $site): string
    {
        if (! Schema::hasTable('site_error_pages')) {
            return '';
        }

        return $site->errorPages()->where('enabled', true)->get()->map(function ($page): string {
            $uri = '/.xpanel-errors/'.$page->status_code.'.html';

            return "    error_page {$page->status_code} {$uri};\n    location = {$uri} { internal; }\n\n";
        })->implode('');
    }

    private function renderPhpPool(Site $site): string
    {
        $socket = '/run/php/php'.$site->php_version.'-fpm-'.$site->domain.'.sock';
        $user = (string) config('xpanel.site_user', 'www-data');
        $group = (string) config('xpanel.site_group', 'www-data');
        $settings = Schema::hasTable('site_php_settings') ? $site->phpSettings : null;
        $memoryLimit = $settings?->memory_limit ?? '256M';
        $uploadLimit = $settings?->upload_max_filesize ?? '64M';
        $postLimit = $settings?->post_max_size ?? '64M';
        $executionTime = $settings?->max_execution_time ?? 60;
        $displayErrors = $settings?->display_errors ? 'on' : 'off';

        return <<<CONF
[xpanel-{$site->domain}]
user = {$user}
group = {$group}
listen = {$socket}
listen.owner = {$user}
listen.group = {$group}
listen.mode = 0660
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 10s
pm.max_requests = 500
chdir = {$site->document_root}
catch_workers_output = yes
php_admin_value[memory_limit] = {$memoryLimit}
php_admin_value[upload_max_filesize] = {$uploadLimit}
php_admin_value[post_max_size] = {$postLimit}
php_admin_value[max_execution_time] = {$executionTime}
php_admin_flag[display_errors] = {$displayErrors}
CONF;
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException("No se pudo crear {$directory}.");
        }

        $temporary = tempnam($directory, '.xpanel-');
        if ($temporary === false) {
            throw new \RuntimeException("No se pudo preparar {$path}.");
        }

        try {
            if (file_put_contents($temporary, $contents."\n", LOCK_EX) === false || ! rename($temporary, $path)) {
                throw new \RuntimeException("No se pudo escribir {$path}.");
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
