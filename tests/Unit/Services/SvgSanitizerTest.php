<?php

namespace Tests\Unit\Services;

use App\Services\Media\SvgSanitizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SvgSanitizerTest extends TestCase
{
    private SvgSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new SvgSanitizer();
    }

    public function test_it_allows_clean_svg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="red"/></svg>';
        $cleaned = $this->sanitizer->sanitize($svg);

        $this->assertStringContainsString('<circle', $cleaned);
        $this->assertStringContainsString('fill="red"', $cleaned);
    }

    public function test_it_removes_script_tags(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("xss")</script><circle cx="50" cy="50" r="40"/></svg>';
        $cleaned = $this->sanitizer->sanitize($svg);

        $this->assertStringNotContainsString('<script', $cleaned);
        $this->assertStringNotContainsString('alert', $cleaned);
        $this->assertStringContainsString('<circle', $cleaned);
    }

    public function test_it_removes_inline_event_handlers(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><circle cx="50" cy="50" r="40" onclick="steal()"/></svg>';
        $cleaned = $this->sanitizer->sanitize($svg);

        $this->assertStringNotContainsString('onload', $cleaned);
        $this->assertStringNotContainsString('onclick', $cleaned);
        $this->assertStringContainsString('<circle', $cleaned);
    }

    public function test_it_removes_javascript_hrefs(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><text>Click</text></a></svg>';
        $cleaned = $this->sanitizer->sanitize($svg);

        $this->assertStringNotContainsString('javascript:', $cleaned);
    }

    public function test_it_rejects_xxe_entity_injection(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $svg = '<!DOCTYPE svg [ <!ENTITY xxe SYSTEM "file:///etc/passwd"> ]><svg>&xxe;</svg>';
        $this->sanitizer->sanitize($svg);
    }

    public function test_it_rejects_empty_content(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->sanitizer->sanitize('');
    }

    public function test_it_rejects_non_svg_xml(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->sanitizer->sanitize('<html><body>Not an SVG</body></html>');
    }
}
