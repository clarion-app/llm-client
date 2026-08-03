<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\ValueObjects\ModelRole;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for RoleAssignment model accessors/mutators.
 * These tests verify model configuration without requiring database connectivity,
 * mirroring the ServerProviderTypeTest pattern.
 */
class RoleAssignmentModelTest extends TestCase
{
    // ========== role accessor/mutator ==========

    #[Test]
    public function role_accessor_returns_inference_enum_for_inference_string(): void
    {
        $assignment = new RoleAssignment();
        $assignment->setRawAttributes(['role' => 'inference']);

        $this->assertInstanceOf(ModelRole::class, $assignment->role);
        $this->assertEquals(ModelRole::Inference, $assignment->role);
    }

    #[Test]
    public function role_accessor_returns_embedding_enum_for_embedding_string(): void
    {
        $assignment = new RoleAssignment();
        $assignment->setRawAttributes(['role' => 'embedding']);

        $this->assertInstanceOf(ModelRole::class, $assignment->role);
        $this->assertEquals(ModelRole::Embedding, $assignment->role);
    }

    #[Test]
    public function role_accessor_returns_image_enum_for_image_string(): void
    {
        $assignment = new RoleAssignment();
        $assignment->setRawAttributes(['role' => 'image']);

        $this->assertInstanceOf(ModelRole::class, $assignment->role);
        $this->assertEquals(ModelRole::Image, $assignment->role);
    }

    #[Test]
    public function role_mutator_stores_enum_value_as_string(): void
    {
        $assignment = new RoleAssignment();
        $assignment->role = ModelRole::Embedding;

        // getAttributeValue goes through the accessor and returns ModelRole enum.
        // Check the raw stored attribute instead.
        $this->assertEquals('embedding', $assignment->getAttributes()['role']);
    }

    #[Test]
    public function role_mutator_stores_string_value_as_string(): void
    {
        $assignment = new RoleAssignment();
        $assignment->role = 'image';

        // getAttributeValue goes through the accessor and returns ModelRole enum.
        // Check the raw stored attribute instead.
        $this->assertEquals('image', $assignment->getAttributes()['role']);
    }

    #[Test]
    public function role_accessor_returns_default_for_unrecognised_string(): void
    {
        $assignment = new RoleAssignment();
        $assignment->setRawAttributes(['role' => 'some-future-role']);

        // Should not fatal — should return a sensible default (Inference mirrors Server::provider_type).
        $this->assertInstanceOf(ModelRole::class, $assignment->role);
        $this->assertEquals(ModelRole::Inference, $assignment->role);
    }

    #[Test]
    public function role_accessor_returns_default_for_null(): void
    {
        $assignment = new RoleAssignment();
        $assignment->setRawAttributes(['role' => null]);

        $this->assertInstanceOf(ModelRole::class, $assignment->role);
        $this->assertEquals(ModelRole::Inference, $assignment->role);
    }

    #[Test]
    public function role_is_not_in_casts(): void
    {
        // The model uses an accessor/mutator pair, not a native enum cast.
        // A cast would fatal on unrecognised values; the accessor falls back gracefully.
        $assignment = new RoleAssignment();
        $this->assertArrayNotHasKey('role', $assignment->getCasts());
    }

    // ========== scope accessor ==========

    #[Test]
    public function scope_accessor_returns_installation_for_sentinel_user_id(): void
    {
        $assignment = new RoleAssignment();
        $assignment->setRawAttributes([
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
        ]);

        $this->assertEquals('installation', $assignment->scope);
    }

    #[Test]
    public function scope_accessor_returns_user_for_real_user_id(): void
    {
        $assignment = new RoleAssignment();
        $assignment->setRawAttributes([
            'user_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);

        $this->assertEquals('user', $assignment->scope);
    }

    #[Test]
    public function scope_accessor_returns_user_for_any_non_sentinel_uuid(): void
    {
        $assignment = new RoleAssignment();
        $assignment->setRawAttributes([
            'user_id' => '12345678-1234-1234-1234-123456789abc',
        ]);

        $this->assertEquals('user', $assignment->scope);
    }

    // ========== INSTALLATION_SCOPE_ID constant ==========

    #[Test]
    public function installation_scope_id_is_nil_uuid(): void
    {
        $this->assertEquals('00000000-0000-0000-0000-000000000000', RoleAssignment::INSTALLATION_SCOPE_ID);
    }
}
