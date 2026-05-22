<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\ServiceProvider;

use App\Infrastructure\ServiceProvider\ServiceProviderRegistry;
use Tests\Support\UnitTestCase;

/**
 * Direct unit tests for the tokenizer-based class-name extractor (C6).
 *
 * The previous regex pass mis-identified classes in some PHP files:
 *  - "class" mentioned in a docblock
 *  - return-typed anonymous classes
 *  - leading modifiers like `readonly final class Foo`
 *
 * These tests pin the new behaviour by feeding hand-crafted PHP files into
 * the registry's private extractor via reflection.
 */
final class ServiceProviderRegistryTokenizerTest extends UnitTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $tempDir = sys_get_temp_dir() . '/registry-tokenizer-' . uniqid('', true);
        mkdir($tempDir);
        $this->tempDir = $tempDir;
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir . '/*.php') ?: []);
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_extracts_simple_class(): void
    {
        $path = $this->writeFile('Simple', <<<'PHP'
<?php
namespace Foo\Bar;
final class Simple {}
PHP);

        $this->assertSame('Foo\\Bar\\Simple', $this->extract($path));
    }

    public function test_ignores_class_keyword_in_doc_comment(): void
    {
        $path = $this->writeFile('DocComment', <<<'PHP'
<?php
namespace Foo\Bar;
/**
 * @see SomeOtherClass for the canonical class layout.
 *
 * This class is the real one.
 */
final class DocComment {}
PHP);

        $this->assertSame('Foo\\Bar\\DocComment', $this->extract($path));
    }

    public function test_handles_readonly_final_modifier_combination(): void
    {
        $path = $this->writeFile('Combo', <<<'PHP'
<?php
namespace Foo;
final readonly class Combo {
    public function __construct(public int $x) {}
}
PHP);

        $this->assertSame('Foo\\Combo', $this->extract($path));
    }

    public function test_skips_anonymous_class_in_method_body(): void
    {
        $path = $this->writeFile('OuterWithAnon', <<<'PHP'
<?php
namespace App\Bag;

final class OuterWithAnon
{
    public function buildHandler(): object
    {
        return new class {
            public function handle(): bool { return true; }
        };
    }
}
PHP);

        $this->assertSame('App\\Bag\\OuterWithAnon', $this->extract($path));
    }

    public function test_returns_null_when_no_class(): void
    {
        $path = $this->writeFile('NoClass', <<<'PHP'
<?php
namespace App\Functions;
function helper(): void {}
PHP);

        $this->assertNull($this->extract($path));
    }

    public function test_returns_null_when_file_unreadable(): void
    {
        // file_get_contents() returns false for non-existent paths — the
        // tokenizer must short-circuit (line 255) rather than tokenizing
        // boolean false. Strict test mode promotes the warning to an
        // exception, so we silence it for this assertion only.
        set_error_handler(static fn () => true, E_WARNING);
        try {
            $this->assertNull($this->extract($this->tempDir . '/does-not-exist.php'));
        } finally {
            restore_error_handler();
        }
    }

    public function test_handles_braced_namespace_block(): void
    {
        // namespace Foo { class Bar {} } — the brace terminates the
        // namespace-name reader (line 308) before the dotted name is
        // accumulated, so the returned namespace is empty.
        $path = $this->writeFile('Braced', <<<'PHP'
<?php
namespace App\BracedNs {
    class BracedClass {}
}
PHP);

        $this->assertSame('App\\BracedNs\\BracedClass', $this->extract($path));
    }

    public function test_handles_class_keyword_as_constant_access(): void
    {
        // `SomeClass::class` is a constant-class expression. T_CLASS appears
        // after a T_DOUBLE_COLON (which the isAnonymousClass guard treats as
        // a non-modifier non-T_NEW token), so the extractor returns false
        // and continues. We then fall through to the real class declaration.
        $path = $this->writeFile('WithClassConst', <<<'PHP'
<?php
namespace App\ConstAccess;
final class WithClassConst
{
    public function name(): string
    {
        return \stdClass::class;
    }
}
PHP);

        $this->assertSame('App\\ConstAccess\\WithClassConst', $this->extract($path));
    }

    private function writeFile(string $name, string $contents): string
    {
        $path = $this->tempDir . '/' . $name . '.php';
        file_put_contents($path, $contents);
        return $path;
    }

    private function extract(string $path): ?string
    {
        $reflection = new \ReflectionClass(ServiceProviderRegistry::class);
        $method = $reflection->getMethod('getClassNameFromFile');
        $method->setAccessible(true);

        /** @var string|null $result */
        $result = $method->invoke(null, $path);
        return $result;
    }
}
