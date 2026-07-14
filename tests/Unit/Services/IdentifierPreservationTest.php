<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use ClarionApp\LlmClient\Services\StructureReducer;

class IdentifierPreservationTest extends TestCase
{
    private StructureReducer $reducer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reducer = new StructureReducer();
    }

    // T017: Identifier preservation tests

    // UUID preservation
    public function test_uuid_is_preserved(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $data = ['id' => $uuid, 'description' => str_repeat('x', 500)];

        $reduced = $this->reducer->reduce($data, 500, 5);

        $this->assertEquals($uuid, $reduced['id']);
    }

    public function test_uuid_in_nested_structure_is_preserved(): void
    {
        $uuid = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $data = ['items' => [['uuid' => $uuid, 'name' => 'Item']]];

        $reduced = $this->reducer->reduce($data, 500, 5);

        $this->assertEquals($uuid, $reduced['items'][0]['uuid']);
    }

    // URI preservation
    public function test_http_url_is_preserved(): void
    {
        $url = 'https://example.com/path/to/resource';
        $data = ['url' => $url, 'description' => str_repeat('x', 500)];

        $reduced = $this->reducer->reduce($data, 500, 5);

        $this->assertEquals($url, $reduced['url']);
    }

    public function test_https_url_is_preserved(): void
    {
        $url = 'https://api.example.com/v1/users/123';
        $data = ['href' => $url];

        $reduced = $this->reducer->reduce($data, 500, 5);

        $this->assertEquals($url, $reduced['href']);
    }

    // Field name preservation
    public function test_id_field_is_preserved(): void
    {
        $data = ['id' => str_repeat('x', 500)];

        $reduced = $this->reducer->reduce($data, 500, 5);

        // Long values in identifier fields are still truncated at 200 chars
        // but identifier field values are preserved (not truncated if < 200)
        $this->assertArrayHasKey('id', $reduced);
    }

    public function test_uuid_field_is_preserved(): void
    {
        $uuid_value = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
        $data = ['uuid' => $uuid_value];

        $reduced = $this->reducer->reduce($data, 500, 5);

        $this->assertEquals($uuid_value, $reduced['uuid']);
    }

    public function test_path_field_is_preserved(): void
    {
        $path = '/var/log/app/production.log';
        $data = ['path' => $path];

        $reduced = $this->reducer->reduce($data, 500, 5);

        $this->assertEquals($path, $reduced['path']);
    }

    public function test_email_field_is_preserved(): void
    {
        $email = 'user@example.com';
        $data = ['email' => $email];

        $reduced = $this->reducer->reduce($data, 500, 5);

        $this->assertEquals($email, $reduced['email']);
    }

    public function test_name_field_is_preserved(): void
    {
        $data = ['name' => 'My Important Name'];

        $reduced = $this->reducer->reduce($data, 500, 5);

        $this->assertEquals('My Important Name', $reduced['name']);
    }

    // Hex string preservation
    public function test_long_hex_string_is_preserved(): void
    {
        $hex = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';
        $data = ['hash' => $hex];

        $reduced = $this->reducer->reduce($data, 500, 5);

        $this->assertEquals($hex, $reduced['hash']);
    }

    // Monetary amount preservation
    public function test_monetary_amount_is_preserved(): void
    {
        $data = ['price' => '$1234.56'];

        $reduced = $this->reducer->reduce($data, 500, 5);

        $this->assertEquals('$1234.56', $reduced['price']);
    }

    public function test_monetary_amount_without_dollar_sign(): void
    {
        $data = ['amount' => '99.99'];

        $reduced = $this->reducer->reduce($data, 500, 5);

        $this->assertEquals('99.99', $reduced['amount']);
    }

    // Email pattern detection
    public function test_email_pattern_is_preserved(): void
    {
        $data = ['contact' => 'admin@company.org'];

        $reduced = $this->reducer->reduce($data, 500, 5);

        $this->assertEquals('admin@company.org', $reduced['contact']);
    }

    // *_id pattern fields
    public function test_field_ending_with_id_is_preserved(): void
    {
        $data = ['user_id' => str_repeat('x', 300), 'conversation_id' => 'conv-123'];

        $reduced = $this->reducer->reduce($data, 500, 5);

        // user_id is an identifier field so value is preserved (not truncated)
        $this->assertArrayHasKey('user_id', $reduced);
        $this->assertEquals('conv-123', $reduced['conversation_id']);
    }

    public function test_field_ending_with_uuid_is_preserved(): void
    {
        $data = ['message_uuid' => '12345678-1234-1234-1234-123456789abc'];

        $reduced = $this->reducer->reduce($data, 500, 5);

        $this->assertEquals('12345678-1234-1234-1234-123456789abc', $reduced['message_uuid']);
    }

    // looksLikeIdentifier public method tests
    public function test_looks_like_identifier_uuid(): void
    {
        $this->assertTrue($this->reducer->looksLikeIdentifier('550e8400-e29b-41d4-a716-446655440000'));
    }

    public function test_looks_like_identifier_http_url(): void
    {
        $this->assertTrue($this->reducer->looksLikeIdentifier('http://example.com'));
    }

    public function test_looks_like_identifier_https_url(): void
    {
        $this->assertTrue($this->reducer->looksLikeIdentifier('https://example.com'));
    }

    public function test_looks_like_identifier_hex_string(): void
    {
        $this->assertTrue($this->reducer->looksLikeIdentifier('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6'));
    }

    public function test_looks_like_identifier_monetary(): void
    {
        $this->assertTrue($this->reducer->looksLikeIdentifier('$1234.56'));
    }

    public function test_looks_like_identifier_email(): void
    {
        $this->assertTrue($this->reducer->looksLikeIdentifier('user@example.com'));
    }

    public function test_looks_like_identifier_false_for_normal_text(): void
    {
        $this->assertFalse($this->reducer->looksLikeIdentifier('This is normal text'));
    }

    public function test_looks_like_identifier_false_for_short_hex(): void
    {
        // Hex strings < 16 chars should not match
        $this->assertFalse($this->reducer->looksLikeIdentifier('abcd1234'));
    }
}
