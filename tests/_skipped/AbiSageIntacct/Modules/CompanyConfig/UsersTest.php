<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\CompanyConfig;

use AbiSageIntacct\Modules\CompanyConfig\Users;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class UsersTest extends TestCase
{
    private Users $users;

    protected function setUp(): void
    {
        $this->users = new Users('/company/users');
    }

    // ========================================
    // Endpoint configuration tests
    // ========================================

    public function testUsersModuleHasCorrectEndpoint(): void
    {
        $this->assertEquals('/company/users', $this->users->getBaseEndpoint());
    }

    public function testUsersIsClassInstance(): void
    {
        $this->assertInstanceOf(Users::class, $this->users);
    }

    public function testUsersExtendsBaseModule(): void
    {
        $reflection = new \ReflectionClass($this->users);
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
        $this->assertTrue(method_exists($this->users, 'list'));
    }

    public function testGetMethodExists(): void
    {
        $this->assertTrue(method_exists($this->users, 'get'));
    }

    public function testCreateMethodExists(): void
    {
        $this->assertTrue(method_exists($this->users, 'create'));
    }

    public function testUpdateMethodExists(): void
    {
        $this->assertTrue(method_exists($this->users, 'update'));
    }

    public function testDeleteMethodExists(): void
    {
        $this->assertTrue(method_exists($this->users, 'delete'));
    }

    public function testGetRolesMethodExists(): void
    {
        $this->assertTrue(method_exists($this->users, 'getRoles'));
    }

    public function testGetPermissionsMethodExists(): void
    {
        $this->assertTrue(method_exists($this->users, 'getPermissions'));
    }

    // ========================================
    // Method signature tests
    // ========================================

    public function testListMethodIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('list');
        $this->assertTrue($method->isPublic());
    }

    public function testGetMethodIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('get');
        $this->assertTrue($method->isPublic());
    }

    public function testCreateMethodIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('create');
        $this->assertTrue($method->isPublic());
    }

    public function testUpdateMethodIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('update');
        $this->assertTrue($method->isPublic());
    }

    public function testDeleteMethodIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('delete');
        $this->assertTrue($method->isPublic());
    }

    public function testGetRolesMethodIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('getRoles');
        $this->assertTrue($method->isPublic());
    }

    public function testGetPermissionsMethodIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('getPermissions');
        $this->assertTrue($method->isPublic());
    }

    // ========================================
    // Method parameter tests
    // ========================================

    public function testListMethodAcceptsFilterArray(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('list');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('filters', $params[0]->getName());
    }

    public function testGetMethodAcceptsUserId(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('get');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('userId', $params[0]->getName());
    }

    public function testCreateMethodAcceptsUserData(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('create');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('userData', $params[0]->getName());
    }

    public function testUpdateMethodAcceptsUserIdAndData(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('update');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('userId', $params[0]->getName());
        $this->assertEquals('userData', $params[1]->getName());
    }

    public function testDeleteMethodAcceptsUserId(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('delete');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('userId', $params[0]->getName());
    }

    public function testGetRolesMethodAcceptsUserId(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('getRoles');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('userId', $params[0]->getName());
    }

    public function testGetPermissionsMethodAcceptsUserId(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('getPermissions');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('userId', $params[0]->getName());
    }

    // ========================================
    // Return type tests
    // ========================================

    public function testListMethodReturnsArray(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('list');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string)$returnType);
    }

    public function testGetMethodReturnsArray(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('get');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string)$returnType);
    }

    public function testCreateMethodReturnsArray(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('create');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string)$returnType);
    }

    public function testUpdateMethodReturnsArray(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('update');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string)$returnType);
    }

    public function testDeleteMethodReturnsArray(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('delete');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string)$returnType);
    }

    public function testGetRolesMethodReturnsArray(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('getRoles');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string)$returnType);
    }

    public function testGetPermissionsMethodReturnsArray(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $method = $reflection->getMethod('getPermissions');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('array', (string)$returnType);
    }

    // ========================================
    // Class structure tests
    // ========================================

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $this->assertTrue($reflection->isFinal());
    }

    public function testClassHasStrictTypes(): void
    {
        $filename = (new \ReflectionClass($this->users))->getFileName();
        $content = file_get_contents($filename);

        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    public function testClassIsInCorrectNamespace(): void
    {
        $reflection = new \ReflectionClass($this->users);
        $this->assertEquals('AbiSageIntacct\Modules\CompanyConfig', $reflection->getNamespaceName());
    }
}
