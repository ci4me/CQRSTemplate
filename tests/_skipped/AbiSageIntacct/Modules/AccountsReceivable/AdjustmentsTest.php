<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\AccountsReceivable;

use AbiSageIntacct\Modules\AccountsReceivable\Adjustments;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Adjustments module
 *
 * Tests the Adjustments module functionality including list, get, create, update, delete, and getLines operations.
 */
final class AdjustmentsTest extends TestCase
{
    private Adjustments $adjustments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adjustments = new Adjustments('/ar-adjustments');
    }

    // ==================== INSTANTIATION TESTS ====================

    public function test_can_create_adjustments_module(): void
    {
        // Act & Assert
        $this->assertInstanceOf(Adjustments::class, $this->adjustments);
    }

    public function test_base_endpoint_is_set_correctly(): void
    {
        // Act
        $endpoint = $this->adjustments->getBaseEndpoint();

        // Assert
        $this->assertEquals('/ar-adjustments', $endpoint);
    }

    // ==================== INHERITANCE TESTS ====================

    public function test_inherits_list_method_from_base_module(): void
    {
        // Act
        $reflection = new \ReflectionClass(Adjustments::class);
        $method = $reflection->getMethod('list');

        // Assert
        $this->assertTrue($method->isPublic());
    }

    public function test_inherits_get_method_from_base_module(): void
    {
        // Act
        $reflection = new \ReflectionClass(Adjustments::class);
        $method = $reflection->getMethod('get');

        // Assert
        $this->assertTrue($method->isPublic());
    }

    public function test_inherits_create_method_from_base_module(): void
    {
        // Act
        $reflection = new \ReflectionClass(Adjustments::class);
        $method = $reflection->getMethod('create');

        // Assert
        $this->assertTrue($method->isPublic());
    }

    public function test_inherits_update_method_from_base_module(): void
    {
        // Act
        $reflection = new \ReflectionClass(Adjustments::class);
        $method = $reflection->getMethod('update');

        // Assert
        $this->assertTrue($method->isPublic());
    }

    public function test_inherits_delete_method_from_base_module(): void
    {
        // Act
        $reflection = new \ReflectionClass(Adjustments::class);
        $method = $reflection->getMethod('delete');

        // Assert
        $this->assertTrue($method->isPublic());
    }

    public function test_has_get_lines_method(): void
    {
        // Act
        $reflection = new \ReflectionClass(Adjustments::class);
        $method = $reflection->getMethod('getLines');

        // Assert
        $this->assertTrue($method->isPublic());
    }

    // ==================== CLASS STRUCTURE TESTS ====================

    public function test_is_final_class(): void
    {
        // Act
        $reflection = new \ReflectionClass(Adjustments::class);

        // Assert
        $this->assertTrue($reflection->isFinal());
    }

    public function test_list_method_signature(): void
    {
        // Act
        $reflection = new \ReflectionMethod(Adjustments::class, 'list');
        $params = $reflection->getParameters();

        // Assert
        $this->assertCount(1, $params);
        $this->assertEquals('filters', $params[0]->getName());
    }

    public function test_get_method_signature(): void
    {
        // Act
        $reflection = new \ReflectionMethod(Adjustments::class, 'get');
        $params = $reflection->getParameters();

        // Assert
        $this->assertCount(1, $params);
        $this->assertEquals('adjustmentId', $params[0]->getName());
    }

    public function test_create_method_signature(): void
    {
        // Act
        $reflection = new \ReflectionMethod(Adjustments::class, 'create');
        $params = $reflection->getParameters();

        // Assert
        $this->assertCount(1, $params);
        $this->assertEquals('adjustmentData', $params[0]->getName());
    }

    public function test_update_method_signature(): void
    {
        // Act
        $reflection = new \ReflectionMethod(Adjustments::class, 'update');
        $params = $reflection->getParameters();

        // Assert
        $this->assertCount(2, $params);
        $this->assertEquals('adjustmentId', $params[0]->getName());
        $this->assertEquals('adjustmentData', $params[1]->getName());
    }

    public function test_delete_method_signature(): void
    {
        // Act
        $reflection = new \ReflectionMethod(Adjustments::class, 'delete');
        $params = $reflection->getParameters();

        // Assert
        $this->assertCount(1, $params);
        $this->assertEquals('adjustmentId', $params[0]->getName());
    }

    public function test_get_lines_method_signature(): void
    {
        // Act
        $reflection = new \ReflectionMethod(Adjustments::class, 'getLines');
        $params = $reflection->getParameters();

        // Assert
        $this->assertCount(1, $params);
        $this->assertEquals('adjustmentId', $params[0]->getName());
    }

    public function test_get_lines_returns_array(): void
    {
        // Act
        $reflection = new \ReflectionMethod(Adjustments::class, 'getLines');
        $returnType = $reflection->getReturnType();

        // Assert
        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string) $returnType);
    }

    // ==================== METHOD VISIBILITY TESTS ====================

    public function test_all_methods_are_public(): void
    {
        // Act
        $reflection = new \ReflectionClass(Adjustments::class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        // Assert - Should have at least 6 public methods (list, get, create, update, delete, getLines)
        $this->assertGreaterThanOrEqual(6, count($methods));
    }

    public function test_list_method_is_public(): void
    {
        // Act
        $reflection = new \ReflectionMethod(Adjustments::class, 'list');

        // Assert
        $this->assertTrue($reflection->isPublic());
    }

    public function test_getLines_method_is_public(): void
    {
        // Act
        $reflection = new \ReflectionMethod(Adjustments::class, 'getLines');

        // Assert
        $this->assertTrue($reflection->isPublic());
    }

    // ==================== RETURN TYPE TESTS ====================

    public function test_list_method_returns_array(): void
    {
        // Act
        $reflection = new \ReflectionMethod(Adjustments::class, 'list');
        $returnType = $reflection->getReturnType();

        // Assert
        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string) $returnType);
    }

    public function test_get_method_returns_array(): void
    {
        // Act
        $reflection = new \ReflectionMethod(Adjustments::class, 'get');
        $returnType = $reflection->getReturnType();

        // Assert
        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string) $returnType);
    }

    public function test_create_method_returns_array(): void
    {
        // Act
        $reflection = new \ReflectionMethod(Adjustments::class, 'create');
        $returnType = $reflection->getReturnType();

        // Assert
        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string) $returnType);
    }

    public function test_update_method_returns_array(): void
    {
        // Act
        $reflection = new \ReflectionMethod(Adjustments::class, 'update');
        $returnType = $reflection->getReturnType();

        // Assert
        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string) $returnType);
    }

    public function test_delete_method_returns_array(): void
    {
        // Act
        $reflection = new \ReflectionMethod(Adjustments::class, 'delete');
        $returnType = $reflection->getReturnType();

        // Assert
        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string) $returnType);
    }

    public function test_get_base_endpoint_method_exists(): void
    {
        // Act
        $reflection = new \ReflectionClass(Adjustments::class);
        $method = $reflection->getMethod('getBaseEndpoint');

        // Assert
        $this->assertTrue($method->isPublic());
    }
}
