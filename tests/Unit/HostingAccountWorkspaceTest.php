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
        $this->assertSame('/home/xpa0123456789/public_html/example.com/subdomains/blog', $workspace->subdomainRoot('example.com', 'blog'));
        $this->assertTrue($workspace->acceptsDocumentRoot('/home/xpa0123456789/public_html/example.com/public'));
        $this->assertFalse($workspace->acceptsDocumentRoot('/home/other/public_html/example.com'));
        $this->assertFalse($workspace->acceptsDocumentRoot('/home/xpa0123456789/public_html/../private'));
    }
}
