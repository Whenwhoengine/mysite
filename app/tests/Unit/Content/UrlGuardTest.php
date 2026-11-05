<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Domain\Content\Http\UpstreamException;
use App\Domain\Content\Http\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** API-03／API-04：只允許固定 WP host；私網、metadata、非法 scheme 一律拒絕。 */
final class UrlGuardTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function rejected(): array
    {
        return [
            'metadata IPv4' => ['http://169.254.169.254/latest/meta-data/', 'metadata'],
            'metadata 十進位' => ['http://2852039166/latest/meta-data/', 'metadata'],
            'metadata 十六進位' => ['http://0xa9fea9fe/', 'metadata'],
            'metadata IPv6' => ['http://[fd00:ec2::254]/', 'metadata'],
            'GCP metadata' => ['http://metadata.google.internal/computeMetadata/v1/', 'metadata'],
            '私網 10/8' => ['http://10.0.0.5/wp-json/site-content/v1', 'private_network'],
            '私網 192.168' => ['http://192.168.1.10/', 'private_network'],
            'loopback' => ['http://127.0.0.1:8080/', 'private_network'],
            '非 allowlist 公網 host' => ['https://evil.example/wp-json/site-content/v1', 'host_not_allowed'],
            'allowlist 子網域假冒' => ['http://wp-web.evil.example/', 'host_not_allowed'],
            'file scheme' => ['file:///etc/passwd', 'scheme'],
            'gopher scheme' => ['gopher://wp-web:70/', 'scheme'],
            'userinfo 混淆' => ['http://wp-web@evil.example/', 'userinfo'],
            '帳密' => ['http://user:pass@wp-web/', 'userinfo'],
            '反斜線混淆' => ['http://wp-web\\@evil.example/', 'invalid_characters'],
            '空白' => ['http://wp-web /x', 'invalid_characters'],
            '缺 host' => ['http:///x', 'unparsable'],
        ];
    }

    #[Test]
    #[Group('API-04')]
    #[DataProvider('rejected')]
    public function it_rejects_unsafe_urls(string $url, string $reason): void
    {
        $guard = new UrlGuard(['wp-web']);
        $this->assertSame($reason, $guard->rejectionReason($url));

        $this->expectException(UpstreamException::class);
        $guard->assertAllowed($url);
    }

    #[Test]
    #[Group('API-04')]
    public function metadata_is_rejected_even_when_listed_in_allowed_hosts(): void
    {
        $guard = new UrlGuard(['169.254.169.254', 'metadata.google.internal']);

        $this->assertSame('metadata', $guard->rejectionReason('http://169.254.169.254/'));
        $this->assertSame('metadata', $guard->rejectionReason('http://metadata.google.internal/'));
    }

    #[Test]
    #[Group('API-04')]
    public function fixed_wp_hosts_are_allowed_as_private_network_exception(): void
    {
        $guard = new UrlGuard(['wp-web', 'WP-Internal']);

        $this->assertNull($guard->rejectionReason('http://wp-web:8080/wp-json/site-content/v1/posts'));
        $this->assertNull($guard->rejectionReason('https://wp-web:8443/wp-json/site-content/v1/preview/post/1'));
        $this->assertNull($guard->rejectionReason('http://wp-internal/x'));
        $this->assertTrue(UrlGuard::isPrivateOrReservedIp('172.30.20.4'));
        $this->assertFalse(UrlGuard::isPrivateOrReservedIp('wp-web'));
        $this->assertFalse(UrlGuard::isMetadataHost('wp-web'));
    }
}
