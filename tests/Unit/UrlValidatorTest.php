<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\UrlValidator;

class UrlValidatorTest extends TestCase
{
    /** @test T020 — reject loopback */
    public function rejects_loopback_ip()
    {
        $result = UrlValidator::validate('http://127.0.0.1/secret');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('private or reserved', $result['reason']);
    }

    /** @test T020 — reject 10.x range */
    public function rejects_rfc1918_10_range()
    {
        $result = UrlValidator::validate('http://10.0.0.1/admin');
        $this->assertFalse($result['valid']);
    }

    /** @test T020 — reject 172.16.x range */
    public function rejects_rfc1918_172_range()
    {
        $result = UrlValidator::validate('http://172.16.0.1/admin');
        $this->assertFalse($result['valid']);
    }

    /** @test T020 — reject 192.168.x range */
    public function rejects_rfc1918_192_range()
    {
        $result = UrlValidator::validate('http://192.168.1.1/router');
        $this->assertFalse($result['valid']);
    }

    /** @test T020 — reject link-local */
    public function rejects_link_local_169_254()
    {
        $result = UrlValidator::validate('http://169.254.169.254/latest/meta-data/');
        $this->assertFalse($result['valid']);
    }

    /** @test T020 — reject file:// scheme */
    public function rejects_file_scheme()
    {
        $result = UrlValidator::validate('file:///etc/passwd');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('HTTP and HTTPS', $result['reason']);
    }

    /** @test T020 — reject ftp:// scheme */
    public function rejects_ftp_scheme()
    {
        $result = UrlValidator::validate('ftp://evil.com/payload');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('HTTP and HTTPS', $result['reason']);
    }

    /** @test T020 — accept valid public URL */
    public function accepts_valid_public_http_url()
    {
        $result = UrlValidator::validate('https://example.com/page');
        $this->assertTrue($result['valid']);
    }

    /** @test T020 — reject 0.0.0.0 */
    public function rejects_zero_ip()
    {
        $result = UrlValidator::validate('http://0.0.0.0/');
        $this->assertFalse($result['valid']);
    }

    /** @test T021 — enforce max redirect hops */
    public function rejects_when_max_redirect_hops_exceeded()
    {
        $maxRedirects = config('llm-client.ssrf.max_redirects', 5);
        $result = UrlValidator::validateRedirect('https://example.com', $maxRedirects);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('redirect hops', $result['reason']);
    }

    /** @test T021 — redirect to private IP is rejected */
    public function rejects_redirect_to_private_ip()
    {
        $result = UrlValidator::validateRedirect('http://127.0.0.1/internal', 0);
        $this->assertFalse($result['valid']);
    }

    /** @test T021 — redirect within limit to public URL is accepted */
    public function accepts_redirect_within_limit_to_public_url()
    {
        $result = UrlValidator::validateRedirect('https://example.com/redirected', 0);
        $this->assertTrue($result['valid']);
    }
}
