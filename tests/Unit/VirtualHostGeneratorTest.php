<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Services\VirtualHostGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VirtualHostGeneratorTest extends TestCase
{
    private function site(string $webServer, string $type = 'php'): Site
    {
        return new Site([
            'domain' => 'engine-test.example.com',
            'document_root' => '/var/www/engine-test.example.com',
            'php_version' => '8.3',
            'type' => $type,
            'web_server' => $webServer,
            'status' => 'active',
        ]);
    }

    public static function engines(): array
    {
        return [
            'nginx' => ['nginx'],
            'apache' => ['apache'],
        ];
    }

    #[DataProvider('engines')]
    public function test_renders_php_vhost_for_each_engine(string $engine): void
    {
        $output = (new VirtualHostGenerator)->render($this->site($engine));

        $this->assertStringContainsString('engine-test.example.com', $output);
        $this->assertStringContainsString('8.3-fpm-engine-test.example.com.sock', $output);
    }

    public function test_nginx_output_uses_nginx_server_block_syntax(): void
    {
        $output = (new VirtualHostGenerator)->render($this->site('nginx'));

        $this->assertStringContainsString('server {', $output);
        $this->assertStringContainsString('fastcgi_pass unix:', $output);
        $this->assertStringNotContainsString('<VirtualHost', $output);
    }

    public function test_apache_output_uses_apache_virtualhost_syntax(): void
    {
        $output = (new VirtualHostGenerator)->render($this->site('apache'));

        $this->assertStringContainsString('<VirtualHost 127.0.0.1:8082>', $output);
        $this->assertStringContainsString('SetHandler "proxy:unix:', $output);
        $this->assertStringNotContainsString('server {', $output);
    }

    #[DataProvider('engines')]
    public function test_static_sites_skip_the_php_handler(string $engine): void
    {
        $output = (new VirtualHostGenerator)->render($this->site($engine, 'static'));

        $this->assertStringNotContainsString('fastcgi_pass', $output);
        $this->assertStringNotContainsString('SetHandler', $output);
    }

    #[DataProvider('engines')]
    public function test_suspended_site_returns_a_server_level_error(string $engine): void
    {
        $site = $this->site($engine);
        $site->status = 'suspended';

        $output = (new VirtualHostGenerator)->renderGateway($site);

        $this->assertStringContainsString('503', $output);
        $this->assertStringContainsString('Sitio suspendido', $output);
        $this->assertStringNotContainsString('fastcgi_pass', $output);
        $this->assertStringNotContainsString('SetHandler', $output);
    }

    public function test_php_pool_matches_the_socket_used_by_the_vhost(): void
    {
        $site = $this->site('nginx');
        $generator = new VirtualHostGenerator;

        $poolPath = $generator->writePhpPool($site);

        $this->assertNotNull($poolPath);
        $this->assertFileExists($poolPath);
        $pool = file_get_contents($poolPath);
        $this->assertStringContainsString('/run/php/php8.3-fpm-engine-test.example.com.sock', $pool);
        $this->assertStringContainsString('pm = ondemand', $pool);
        $this->assertStringContainsString('env[HOME] = /var/www/engine-test.example.com', $pool);

        $generator->remove($site);
    }

    public function test_active_ssl_renders_https_and_preserves_acme_challenge(): void
    {
        $site = $this->site('nginx');
        $site->ssl_status = 'active';
        $site->https_redirect = true;

        $output = (new VirtualHostGenerator)->renderGateway($site);

        $this->assertStringContainsString('listen 443 ssl;', $output);
        $this->assertStringContainsString('/etc/letsencrypt/live/engine-test.example.com/fullchain.pem', $output);
        $this->assertStringContainsString('/.well-known/acme-challenge/', $output);
        $this->assertStringContainsString('return 301 https://$host$request_uri;', $output);
    }

    public function test_apache_site_uses_the_shared_gateway_certificate(): void
    {
        $site = $this->site('apache');
        $site->ssl_status = 'active';
        $site->https_redirect = false;

        $generator = new VirtualHostGenerator;
        $backend = $generator->render($site);
        $output = $generator->renderGateway($site);

        $this->assertStringContainsString('proxy_pass http://127.0.0.1:8082;', $output);
        $this->assertStringContainsString('ssl_certificate_key /etc/letsencrypt/live/engine-test.example.com/privkey.pem', $output);
        $this->assertStringNotContainsString('SSLEngine on', $backend);
    }

    public function test_nginx_site_is_served_directly_without_a_proxy_hop(): void
    {
        $site = $this->site('nginx');
        $generator = new VirtualHostGenerator;

        $this->assertStringContainsString('listen 80;', $generator->renderGateway($site));
        $this->assertStringContainsString('fastcgi_pass unix:', $generator->renderGateway($site));
        $this->assertStringNotContainsString('proxy_pass', $generator->renderGateway($site));
        $this->assertStringContainsString(
            config('xpanel.account_home').'/logs/engine-test.example.com/access.log',
            $generator->renderGateway($site),
        );
    }

    public function test_openlitespeed_site_uses_lsapi_and_internal_proxy(): void
    {
        $site = $this->site('openlitespeed');
        $site->id = 42;
        $generator = new VirtualHostGenerator;

        $this->assertStringContainsString('lsapi:lsphp83', $generator->render($site));
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:8083;', $generator->renderGateway($site));
    }

    public function test_node_site_proxies_websockets_and_stages_a_hardened_service(): void
    {
        $site = $this->site('nginx', 'node');
        $site->runtime_port = 32123;
        $site->node_version = '22';
        $site->node_start_command = 'npm run serve';
        $generator = new VirtualHostGenerator;

        $gateway = $generator->renderGateway($site);
        $servicePath = $generator->writeNodeService($site);
        $service = file_get_contents($servicePath);

        $this->assertStringContainsString('proxy_pass http://127.0.0.1:32123;', $gateway);
        $this->assertStringContainsString('proxy_set_header Upgrade $http_upgrade;', $gateway);
        $this->assertStringContainsString('Environment=PORT=32123', $service);
        $this->assertStringContainsString('Environment=HOME=/var/www/engine-test.example.com', $service);
        $this->assertStringContainsString('ExecStart=/usr/local/bin/npm run serve', $service);
        $this->assertStringContainsString('NoNewPrivileges=true', $service);
        $generator->remove($site);
    }

    public function test_vps_managed_node_service_joins_the_instance_slice(): void
    {
        putenv('XPANEL_SYSTEMD_SLICE=xpanel-instance-01234567-89ab-cdef-0123-456789abcdef.slice');
        try {
            $site = $this->site('nginx', 'node');
            $site->runtime_port = 32123;
            $site->node_version = '22';
            $site->node_start_command = 'npm start';
            $generator = new VirtualHostGenerator;
            $servicePath = $generator->writeNodeService($site);

            $this->assertStringContainsString('Slice=xpanel-instance-01234567-89ab-cdef-0123-456789abcdef.slice', file_get_contents($servicePath));
            $generator->remove($site);
        } finally {
            putenv('XPANEL_SYSTEMD_SLICE');
        }
    }

    public function test_subdomain_tenancy_adds_wildcard_but_not_to_tls_until_dns_certificate_is_active(): void
    {
        $site = $this->site('nginx');
        $site->wildcard_domain = true;
        $site->wildcard_ssl_status = 'pending';
        $site->ssl_status = 'active';

        $output = (new VirtualHostGenerator)->renderGateway($site);

        $this->assertStringContainsString('server_name engine-test.example.com *.engine-test.example.com;', $output);
        $https = substr($output, strpos($output, 'listen 443 ssl;'));
        $this->assertStringNotContainsString('server_name engine-test.example.com *.engine-test.example.com;', $https);
    }
}
