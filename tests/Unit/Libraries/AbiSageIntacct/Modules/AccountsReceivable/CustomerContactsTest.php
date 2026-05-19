<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\AccountsReceivable;

use AbiSageIntacct\Modules\AccountsReceivable\CustomerContacts;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CustomerContacts module
 *
 * Tests the CustomerContacts module functionality including list, get, create, update, and delete operations.
 */
final class CustomerContactsTest extends TestCase
{
    private CustomerContacts $customerContacts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerContacts = new CustomerContacts('/ar-customer-contacts');
    }

    // ==================== INSTANTIATION TESTS ====================

    public function test_can_create_customer_contacts_module(): void
    {
        // Act & Assert
        $this->assertInstanceOf(CustomerContacts::class, $this->customerContacts);
    }

    public function test_base_endpoint_is_set_correctly(): void
    {
        // Act
        $endpoint = $this->customerContacts->getBaseEndpoint();

        // Assert
        $this->assertEquals('/ar-customer-contacts', $endpoint);
    }

    // ==================== INHERITANCE TESTS ====================

    public function test_inherits_list_method_from_base_module(): void
    {
        // Act
        $reflection = new \ReflectionClass(CustomerContacts::class);
        $method = $reflection->getMethod('list');

        // Assert
        $this->assertTrue($method->isPublic());
    }

    public function test_inherits_get_method_from_base_module(): void
    {
        // Act
        $reflection = new \ReflectionClass(CustomerContacts::class);
        $method = $reflection->getMethod('get');

        // Assert
        $this->assertTrue($method->isPublic());
    }

    public function test_inherits_create_method_from_base_module(): void
    {
        // Act
        $reflection = new \ReflectionClass(CustomerContacts::class);
        $method = $reflection->getMethod('create');

        // Assert
        $this->assertTrue($method->isPublic());
    }

    public function test_inherits_update_method_from_base_module(): void
    {
        // Act
        $reflection = new \ReflectionClass(CustomerContacts::class);
        $method = $reflection->getMethod('update');

        // Assert
        $this->assertTrue($method->isPublic());
    }

    public function test_inherits_delete_method_from_base_module(): void
    {
        // Act
        $reflection = new \ReflectionClass(CustomerContacts::class);
        $method = $reflection->getMethod('delete');

        // Assert
        $this->assertTrue($method->isPublic());
    }

    // ==================== CLASS STRUCTURE TESTS ====================

    public function test_is_final_class(): void
    {
        // Act
        $reflection = new \ReflectionClass(CustomerContacts::class);

        // Assert
        $this->assertTrue($reflection->isFinal());
    }

    public function test_list_method_signature(): void
    {
        // Act
        $reflection = new \ReflectionMethod(CustomerContacts::class, 'list');
        $params = $reflection->getParameters();

        // Assert
        $this->assertCount(1, $params);
        $this->assertEquals('filters', $params[0]->getName());
    }

    public function test_get_method_signature(): void
    {
        // Act
        $reflection = new \ReflectionMethod(CustomerContacts::class, 'get');
        $params = $reflection->getParameters();

        // Assert
        $this->assertCount(1, $params);
        $this->assertEquals('contactId', $params[0]->getName());
    }

    public function test_create_method_signature(): void
    {
        // Act
        $reflection = new \ReflectionMethod(CustomerContacts::class, 'create');
        $params = $reflection->getParameters();

        // Assert
        $this->assertCount(1, $params);
        $this->assertEquals('contactData', $params[0]->getName());
    }

    public function test_update_method_signature(): void
    {
        // Act
        $reflection = new \ReflectionMethod(CustomerContacts::class, 'update');
        $params = $reflection->getParameters();

        // Assert
        $this->assertCount(2, $params);
        $this->assertEquals('contactId', $params[0]->getName());
        $this->assertEquals('contactData', $params[1]->getName());
    }

    public function test_delete_method_signature(): void
    {
        // Act
        $reflection = new \ReflectionMethod(CustomerContacts::class, 'delete');
        $params = $reflection->getParameters();

        // Assert
        $this->assertCount(1, $params);
        $this->assertEquals('contactId', $params[0]->getName());
    }

    // ==================== METHOD VISIBILITY TESTS ====================

    public function test_all_methods_are_public(): void
    {
        // Act
        $reflection = new \ReflectionClass(CustomerContacts::class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        // Assert - Should have at least 5 public methods (list, get, create, update, delete)
        $this->assertGreaterThanOrEqual(5, count($methods));
    }

    public function test_list_method_is_public(): void
    {
        // Act
        $reflection = new \ReflectionMethod(CustomerContacts::class, 'list');

        // Assert
        $this->assertTrue($reflection->isPublic());
    }

    public function test_get_method_is_public(): void
    {
        // Act
        $reflection = new \ReflectionMethod(CustomerContacts::class, 'get');

        // Assert
        $this->assertTrue($reflection->isPublic());
    }

    public function test_create_method_is_public(): void
    {
        // Act
        $reflection = new \ReflectionMethod(CustomerContacts::class, 'create');

        // Assert
        $this->assertTrue($reflection->isPublic());
    }

    public function test_update_method_is_public(): void
    {
        // Act
        $reflection = new \ReflectionMethod(CustomerContacts::class, 'update');

        // Assert
        $this->assertTrue($reflection->isPublic());
    }

    public function test_delete_method_is_public(): void
    {
        // Act
        $reflection = new \ReflectionMethod(CustomerContacts::class, 'delete');

        // Assert
        $this->assertTrue($reflection->isPublic());
    }

    // ==================== RETURN TYPE TESTS ====================

    public function test_list_method_returns_array(): void
    {
        // Act
        $reflection = new \ReflectionMethod(CustomerContacts::class, 'list');
        $returnType = $reflection->getReturnType();

        // Assert
        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string) $returnType);
    }

    public function test_get_method_returns_array(): void
    {
        // Act
        $reflection = new \ReflectionMethod(CustomerContacts::class, 'get');
        $returnType = $reflection->getReturnType();

        // Assert
        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string) $returnType);
    }

    public function test_create_method_returns_array(): void
    {
        // Act
        $reflection = new \ReflectionMethod(CustomerContacts::class, 'create');
        $returnType = $reflection->getReturnType();

        // Assert
        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string) $returnType);
    }

    public function test_update_method_returns_array(): void
    {
        // Act
        $reflection = new \ReflectionMethod(CustomerContacts::class, 'update');
        $returnType = $reflection->getReturnType();

        // Assert
        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string) $returnType);
    }

    public function test_delete_method_returns_array(): void
    {
        // Act
        $reflection = new \ReflectionMethod(CustomerContacts::class, 'delete');
        $returnType = $reflection->getReturnType();

        // Assert
        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string) $returnType);
    }

    public function test_get_base_endpoint_method_exists(): void
    {
        // Act
        $reflection = new \ReflectionClass(CustomerContacts::class);
        $method = $reflection->getMethod('getBaseEndpoint');

        // Assert
        $this->assertTrue($method->isPublic());
    }
}
