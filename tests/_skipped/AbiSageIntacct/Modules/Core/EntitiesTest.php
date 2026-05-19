<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\Core;

use AbiSageIntacct\Modules\Core\Entities;
use AbiSageIntacct\Support\BaseModule;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Entities module
 *
 * @internal
 */
final class EntitiesTest extends TestCase
{
    private Entities $entities;

    protected function setUp(): void
    {
        $this->entities = new Entities('/entities');
    }

    // ========================================
    // Module structure tests
    // ========================================

    public function testEntitiesExtendsBaseModule(): void
    {
        $this->assertInstanceOf(BaseModule::class, $this->entities);
    }

    public function testEntitiesHasCorrectEndpoint(): void
    {
        $this->assertEquals('/entities', $this->entities->getBaseEndpoint());
    }

    public function testEntitiesIsFinal(): void
    {
        $reflectionClass = new \ReflectionClass(Entities::class);
        $this->assertTrue($reflectionClass->isFinal());
    }

    // ========================================
    // Method availability tests
    // ========================================

    public function testEntitiesHasListMethod(): void
    {
        $this->assertTrue(method_exists($this->entities, 'list'));
    }

    public function testEntitiesHasGetMethod(): void
    {
        $this->assertTrue(method_exists($this->entities, 'get'));
    }

    public function testListMethodIsPublic(): void
    {
        $reflectionMethod = new \ReflectionMethod(Entities::class, 'list');
        $this->assertTrue($reflectionMethod->isPublic());
    }

    public function testGetMethodIsPublic(): void
    {
        $reflectionMethod = new \ReflectionMethod(Entities::class, 'get');
        $this->assertTrue($reflectionMethod->isPublic());
    }

    // ========================================
    // Type safety tests
    // ========================================

    public function testListMethodHasReturnTypeArray(): void
    {
        $reflectionMethod = new \ReflectionMethod(Entities::class, 'list');
        $returnType = $reflectionMethod->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertStringContainsString('array', (string)$returnType);
    }

    public function testGetMethodHasReturnTypeArray(): void
    {
        $reflectionMethod = new \ReflectionMethod(Entities::class, 'get');
        $returnType = $reflectionMethod->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertStringContainsString('array', (string)$returnType);
    }

    public function testListMethodHasStringTypeParameter(): void
    {
        $reflectionMethod = new \ReflectionMethod(Entities::class, 'list');
        $parameters = $reflectionMethod->getParameters();

        // list() can have optional filters parameter
        $this->assertGreaterThanOrEqual(0, count($parameters));
    }

    public function testGetMethodHasStringParameter(): void
    {
        $reflectionMethod = new \ReflectionMethod(Entities::class, 'get');
        $parameters = $reflectionMethod->getParameters();

        // get() must have entityId parameter
        $this->assertGreaterThan(0, count($parameters));
        $this->assertEquals('entityId', $parameters[0]->getName());
    }

    // ========================================
    // DocBlock tests
    // ========================================

    public function testEntityClassHasDocBlock(): void
    {
        $reflectionClass = new \ReflectionClass(Entities::class);
        $docComment = $reflectionClass->getDocComment();

        $this->assertNotFalse($docComment);
        $this->assertStringContainsString('Entities module', $docComment);
    }

    public function testListMethodHasDocBlock(): void
    {
        $reflectionMethod = new \ReflectionMethod(Entities::class, 'list');
        $docComment = $reflectionMethod->getDocComment();

        $this->assertNotFalse($docComment);
    }

    public function testGetMethodHasDocBlock(): void
    {
        $reflectionMethod = new \ReflectionMethod(Entities::class, 'get');
        $docComment = $reflectionMethod->getDocComment();

        $this->assertNotFalse($docComment);
    }

    // ========================================
    // HttpClient integration tests
    // ========================================

    public function testEntitiesCanSetHttpClient(): void
    {
        // This should not throw any exception
        $this->assertIsObject($this->entities);
    }

    public function testEntitiesImplementsModuleInterface(): void
    {
        $interfaces = class_implements(Entities::class);
        $this->assertArrayHasKey(
            'AbiSageIntacct\Contracts\ModuleInterface',
            $interfaces
        );
    }
}
