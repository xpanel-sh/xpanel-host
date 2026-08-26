<?php

namespace Tests\Unit;

use App\Services\HostingAccountWorkspace;
use Tests\TestCase;

class HostingAccountWorkspaceTest extends TestCase
{
    public function test_it_builds_site_and_subdomain_paths_inside_account_home(): void
    {
        config([
            'xpanel.account_user' => 'xpa0123456789',
            'xpanel.account_home' => '/home/xpa0123456789',
        ]);

        $workspace = app(HostingAccountWorkspace::class);

        $this->assertSame('/home/xpa0123456789/public_html/example.com', $workspace->siteRoot('example.com'));
        $this->assertSame('/home/xpa0123456789/public_html/blog.example.com', $workspace->subdomainRoot('example.com', 'blog'));
        $this->assertTrue($workspace->acceptsDocumentRoot('/home/xpa0123456789/public_html/example.com/public'));
        $this->assertFalse($workspace->acceptsDocumentRoot('/home/other/public_html/example.com'));
        $this->assertFalse($workspace->acceptsDocumentRoot('/home/xpa0123456789/public_html/../private'));
    }

    public function test_it_derives_a_safe_account_when_an_old_config_cache_lacks_the_new_keys(): void
    {
        config([
            'app.key' => 'base64:test-account-fallback',
            'xpanel.account_user' => null,
            'xpanel.account_home' => null,
        ]);

        $workspace = app(HostingAccountWorkspace::class);

        $this->assertMatchesRegularExpression('/^xpa[a-f0-9]{10}$/', $workspace->user());
        $this->assertSame('/home/'.$workspace->user(), $workspace->systemRoot());
    }
}
