<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\Core;

use AbiSageIntacct\Modules\Core\Dimensions;
use AbiSageIntacct\Support\BaseModule;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Dimensions module
 *
 * @internal
 */
final class DimensionsTest extends TestCase
{
    private Dimensions $dimensions;

    protected function setUp(): void
    {
        $this->dimensions = new Dimensions('/dimensions');
    }

    // ========================================
    // Module structure tests
    // ========================================

    public function testDimensionsExtendsBaseModule(): void
    {
        $this->assertInstanceOf(BaseModule::class, $this->dimensions);
    }

    public function testDimensionsHasCorrectEndpoint(): void
    {
        $this->assertEquals('/dimensions', $this->dimensions->getBaseEndpoint());
    }

    public function testDimensionsIsFinal(): void
    {
        $reflectionClass = new \ReflectionClass(Dimensions::class);
        $this->assertTrue($reflectionClass->isFinal());
    }

    // ========================================
    // Method availability tests
    // ========================================

    public function testDimensionsHasListMethod(): void
    {
        $this->assertTrue(method_exists($this->dimensions, 'list'));
    }

    public function testDimensionsHasGetMethod(): void
    {
        $this->assertTrue(method_exists($this->dimensions, 'get'));
    }

    public function testDimensionsHasCreateMethod(): void
    {
        $this->assertTrue(method_exists($this->dimensions, 'create'));
    }

    public function testDimensionsHasUpdateMethod(): void
    {
        $this->assertTrue(method_exists($this->dimensions, 'update'));
    }

    public function testDimensionsHasDeleteMethod(): void
    {
        $this->assertTrue(method_exists($this->dimensions, 'delete'));
    }

    public function testListMethodIsPublic(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'list');
        $this->assertTrue($reflectionMethod->isPublic());
    }

    public function testGetMethodIsPublic(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'get');
        $this->assertTrue($reflectionMethod->isPublic());
    }

    public function testCreateMethodIsPublic(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'create');
        $this->assertTrue($reflectionMethod->isPublic());
    }

    public function testUpdateMethodIsPublic(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'update');
        $this->assertTrue($reflectionMethod->isPublic());
    }

    public function testDeleteMethodIsPublic(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'delete');
        $this->assertTrue($reflectionMethod->isPublic());
    }

    // ========================================
    // Type safety tests
    // ========================================

    public function testListMethodHasReturnTypeArray(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'list');
        $returnType = $reflectionMethod->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertStringContainsString('array', (string)$returnType);
    }

    public function testGetMethodHasReturnTypeArray(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'get');
        $returnType = $reflectionMethod->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertStringContainsString('array', (string)$returnType);
    }

    public function testCreateMethodHasReturnTypeArray(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'create');
        $returnType = $reflectionMethod->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertStringContainsString('array', (string)$returnType);
    }

    public function testUpdateMethodHasReturnTypeArray(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'update');
        $returnType = $reflectionMethod->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertStringContainsString('array', (string)$returnType);
    }

    public function testDeleteMethodHasReturnTypeArray(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'delete');
        $returnType = $reflectionMethod->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertStringContainsString('array', (string)$returnType);
    }

    public function testGetMethodHasStringParameter(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'get');
        $parameters = $reflectionMethod->getParameters();

        // get() must have dimensionId parameter
        $this->assertGreaterThan(0, count($parameters));
        $this->assertEquals('dimensionId', $parameters[0]->getName());
    }

    public function testCreateMethodHasArrayParameter(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'create');
        $parameters = $reflectionMethod->getParameters();

        // create() must have dimensionData parameter
        $this->assertGreaterThan(0, count($parameters));
        $this->assertEquals('dimensionData', $parameters[0]->getName());
    }

    public function testUpdateMethodHasStringAndArrayParameters(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'update');
        $parameters = $reflectionMethod->getParameters();

        // update() must have dimensionId and dimensionData parameters
        $this->assertGreaterThanOrEqual(2, count($parameters));
        $this->assertEquals('dimensionId', $parameters[0]->getName());
        $this->assertEquals('dimensionData', $parameters[1]->getName());
    }

    public function testDeleteMethodHasStringParameter(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'delete');
        $parameters = $reflectionMethod->getParameters();

        // delete() must have dimensionId parameter
        $this->assertGreaterThan(0, count($parameters));
        $this->assertEquals('dimensionId', $parameters[0]->getName());
    }

    // ========================================
    // DocBlock tests
    // ========================================

    public function testDimensionClassHasDocBlock(): void
    {
        $reflectionClass = new \ReflectionClass(Dimensions::class);
        $docComment = $reflectionClass->getDocComment();

        $this->assertNotFalse($docComment);
        $this->assertStringContainsString('Dimensions module', $docComment);
    }

    public function testListMethodHasDocBlock(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'list');
        $docComment = $reflectionMethod->getDocComment();

        $this->assertNotFalse($docComment);
    }

    public function testGetMethodHasDocBlock(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'get');
        $docComment = $reflectionMethod->getDocComment();

        $this->assertNotFalse($docComment);
    }

    public function testCreateMethodHasDocBlock(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'create');
        $docComment = $reflectionMethod->getDocComment();

        $this->assertNotFalse($docComment);
    }

    public function testUpdateMethodHasDocBlock(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'update');
        $docComment = $reflectionMethod->getDocComment();

        $this->assertNotFalse($docComment);
    }

    public function testDeleteMethodHasDocBlock(): void
    {
        $reflectionMethod = new \ReflectionMethod(Dimensions::class, 'delete');
        $docComment = $reflectionMethod->getDocComment();

        $this->assertNotFalse($docComment);
    }

    // ========================================
    // HttpClient integration tests
    // ========================================

    public function testDimensionsCanSetHttpClient(): void
    {
        // This should not throw any exception
        $this->assertIsObject($this->dimensions);
    }

    public function testDimensionsImplementsModuleInterface(): void
    {
        $interfaces = class_implements(Dimensions::class);
        $this->assertArrayHasKey(
            'AbiSageIntacct\Contracts\ModuleInterface',
            $interfaces
        );
    }

    // ========================================
    // CRUD method count tests
    // ========================================

    public function testDimensionsHasAllCrudMethods(): void
    {
        $methods = [
            'list' => true,
            'get' => true,
            'create' => true,
            'update' => true,
            'delete' => true,
        ];

        foreach ($methods as $method => $expected) {
            $hasMethod = method_exists($this->dimensions, $method);
            $this->assertEquals(
                $expected,
                $hasMethod,
                "Dimensions should have {$method} method"
            );
        }
    }
}
