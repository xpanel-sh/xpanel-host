<?php

namespace Tests\Unit;

use App\Services\XMailService;
use ReflectionMethod;
use Tests\TestCase;

class XMailServiceSecurityTest extends TestCase
{
    public function test_message_html_removes_active_and_remote_content(): void
    {
        $method = new ReflectionMethod(XMailService::class, 'sanitizeHtml');
        $html = $method->invoke(new XMailService, <<<'HTML'
            <p onclick="steal()">Hello</p>
            <script>alert(1)</script>
            <img src="https://tracker.example/pixel.gif" onerror="steal()">
            <a href="javascript:steal()">bad</a>
            <a href="https://example.com/path">good</a>
            HTML);

        $this->assertStringNotContainsStringIgnoringCase('<script', $html);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $html);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $html);
        $this->assertStringNotContainsString('tracker.example', $html);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function test_folder_names_cannot_escape_the_imap_mailbox_specification(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new XMailService)->createFolder('user@example.com', 'secret', "bad}\r\nINBOX");
    }
}
