<?php

namespace Tests\Unit;

use Tests\TestCase;

class DefaultSiteTemplateTest extends TestCase
{
    public function test_new_php_sites_use_the_bundled_welcome_page_without_replacing_existing_indexes(): void
    {
        $helper = file_get_contents(base_path('scripts/xpanel-site-helper.sh'));
        $template = file_get_contents(resource_path('site-templates/welcome.php'));

        $this->assertStringContainsString('resources/site-templates/welcome.php', $helper);
        $this->assertStringContainsString('! -e "$web_root/index.php" && ! -L "$web_root/index.php"', $helper);
        $this->assertStringContainsString('! -e "$web_root/index.html" && ! -L "$web_root/index.html"', $helper);
        $this->assertStringContainsString('mv -n -- "$welcome_temp" "$web_root/index.php"', $helper);
        $this->assertStringContainsString('<!DOCTYPE html>', $template);
        $this->assertStringContainsString('const domain = location.host;', $template);
        $this->assertStringContainsString('name="robots" content="noindex, nofollow"', $template);
        $this->assertStringContainsString('hosting-welcome-theme', $template);
    }
}
