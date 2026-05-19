<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\CompanyConfig;

use AbiSageIntacct\Modules\CompanyConfig\Departments;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class DepartmentsTest extends TestCase
{
    private Departments $departments;

    protected function setUp(): void
    {
        $this->departments = new Departments('/company/departments');
    }

    // ========================================
    // Endpoint configuration tests
    // ========================================

    public function testDepartmentsModuleHasCorrectEndpoint(): void
    {
        $this->assertEquals('/company/departments', $this->departments->getBaseEndpoint());
    }

    public function testDepartmentsIsClassInstance(): void
    {
        $this->assertInstanceOf(Departments::class, $this->departments);
    }

    public function testDepartmentsExtendsBaseModule(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $this->assertTrue($reflection->hasMethod('list'));
        $this->assertTrue($reflection->hasMethod('get'));
        $this->assertTrue($reflection->hasMethod('create'));
        $this->assertTrue($reflection->hasMethod('update'));
        $this->assertTrue($reflection->hasMethod('delete'));
    }

    // ========================================
    // Method existence tests
    // ========================================

    public function testListMethodExists(): void
    {
        $this->assertTrue(method_exists($this->departments, 'list'));
    }

    public function testGetMethodExists(): void
    {
        $this->assertTrue(method_exists($this->departments, 'get'));
    }

    public function testCreateMethodExists(): void
    {
        $this->assertTrue(method_exists($this->departments, 'create'));
    }

    public function testUpdateMethodExists(): void
    {
        $this->assertTrue(method_exists($this->departments, 'update'));
    }

    public function testDeleteMethodExists(): void
    {
        $this->assertTrue(method_exists($this->departments, 'delete'));
    }

    // ========================================
    // Method signature tests
    // ========================================

    public function testListMethodIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $method = $reflection->getMethod('list');
        $this->assertTrue($method->isPublic());
    }

    public function testGetMethodIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $method = $reflection->getMethod('get');
        $this->assertTrue($method->isPublic());
    }

    public function testCreateMethodIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $method = $reflection->getMethod('create');
        $this->assertTrue($method->isPublic());
    }

    public function testUpdateMethodIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $method = $reflection->getMethod('update');
        $this->assertTrue($method->isPublic());
    }

    public function testDeleteMethodIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $method = $reflection->getMethod('delete');
        $this->assertTrue($method->isPublic());
    }

    // ========================================
    // Method parameter tests
    // ========================================

    public function testListMethodAcceptsFilterArray(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $method = $reflection->getMethod('list');
        $params = $method->getParameters();
        
        $this->assertCount(1, $params);
        $this->assertEquals('filters', $params[0]->getName());
    }

    public function testGetMethodAcceptsDepartmentId(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $method = $reflection->getMethod('get');
        $params = $method->getParameters();
        
        $this->assertCount(1, $params);
        $this->assertEquals('departmentId', $params[0]->getName());
    }

    public function testCreateMethodAcceptsDepartmentData(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $method = $reflection->getMethod('create');
        $params = $method->getParameters();
        
        $this->assertCount(1, $params);
        $this->assertEquals('departmentData', $params[0]->getName());
    }

    public function testUpdateMethodAcceptsDepartmentIdAndData(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $method = $reflection->getMethod('update');
        $params = $method->getParameters();
        
        $this->assertCount(2, $params);
        $this->assertEquals('departmentId', $params[0]->getName());
        $this->assertEquals('departmentData', $params[1]->getName());
    }

    public function testDeleteMethodAcceptsDepartmentId(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $method = $reflection->getMethod('delete');
        $params = $method->getParameters();
        
        $this->assertCount(1, $params);
        $this->assertEquals('departmentId', $params[0]->getName());
    }

    // ========================================
    // Return type tests
    // ========================================

    public function testListMethodReturnsArray(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $method = $reflection->getMethod('list');
        $returnType = $method->getReturnType();
        
        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string)$returnType);
    }

    public function testGetMethodReturnsArray(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $method = $reflection->getMethod('get');
        $returnType = $method->getReturnType();
        
        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string)$returnType);
    }

    public function testCreateMethodReturnsArray(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $method = $reflection->getMethod('create');
        $returnType = $method->getReturnType();
        
        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string)$returnType);
    }

    public function testUpdateMethodReturnsArray(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $method = $reflection->getMethod('update');
        $returnType = $method->getReturnType();
        
        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string)$returnType);
    }

    public function testDeleteMethodReturnsArray(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $method = $reflection->getMethod('delete');
        $returnType = $method->getReturnType();
        
        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string)$returnType);
    }

    // ========================================
    // Class structure tests
    // ========================================

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $this->assertTrue($reflection->isFinal());
    }

    public function testClassHasStrictTypes(): void
    {
        $filename = (new \ReflectionClass($this->departments))->getFileName();
        $content = file_get_contents($filename);
        
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    public function testClassIsInCorrectNamespace(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        $this->assertEquals('AbiSageIntacct\Modules\CompanyConfig', $reflection->getNamespaceName());
    }

    // ========================================
    // CRUD pattern tests
    // ========================================

    public function testDepartmentsImplementsCrudInterface(): void
    {
        $this->assertTrue(method_exists($this->departments, 'list'));
        $this->assertTrue(method_exists($this->departments, 'get'));
        $this->assertTrue(method_exists($this->departments, 'create'));
        $this->assertTrue(method_exists($this->departments, 'update'));
        $this->assertTrue(method_exists($this->departments, 'delete'));
    }

    public function testAllCrudMethodsArePublic(): void
    {
        $reflection = new \ReflectionClass($this->departments);
        
        foreach (['list', 'get', 'create', 'update', 'delete'] as $methodName) {
            $method = $reflection->getMethod($methodName);
            $this->assertTrue(
                $method->isPublic(),
                "Method {$methodName} should be public"
            );
        }
    }
}
