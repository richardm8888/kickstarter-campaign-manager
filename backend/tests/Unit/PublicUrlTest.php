<?php

namespace Tests\Unit;

use App\Support\PublicUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The analyser fetches user-supplied URLs from the server, so these
 * guards are the difference between a feature and an SSRF hole.
 */
class PublicUrlTest extends TestCase
{
    public static function blockedUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/admin'],
            'loopback name' => ['http://localhost:8000'],
            'private class A' => ['http://10.0.0.5/'],
            'private class C' => ['http://192.168.1.1/'],
            'link local' => ['http://169.254.169.254/latest/meta-data/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://example.com/'],
            'no host' => ['not-a-url'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function test_it_blocks_unsafe_urls(string $url): void
    {
        $this->assertFalse(PublicUrl::isSafe($url), "{$url} should be rejected");
    }

    public function test_it_allows_public_http_urls(): void
    {
        $this->assertTrue(PublicUrl::isSafe('https://example.com/launch'));
        $this->assertTrue(PublicUrl::isSafe('http://example.com'));
    }

    public function test_the_error_explains_why_a_private_address_was_rejected(): void
    {
        $this->expectExceptionMessageMatches('/private address/');

        PublicUrl::assertSafe('http://192.168.0.10/');
    }
}
