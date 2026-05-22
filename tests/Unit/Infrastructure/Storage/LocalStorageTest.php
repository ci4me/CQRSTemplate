<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Storage;

use App\Infrastructure\Storage\LocalStorage;
use App\Infrastructure\Storage\StorageException;
use Tests\Support\UnitTestCase;

final class LocalStorageTest extends UnitTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = sys_get_temp_dir() . '/local-storage-' . uniqid('', true);
        mkdir($dir);
        $this->tempDir = $dir;
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_put_get_round_trip(): void
    {
        $storage = new LocalStorage($this->tempDir);
        $storage->put('hello.txt', 'world');

        $this->assertSame('world', $storage->get('hello.txt'));
        $this->assertTrue($storage->exists('hello.txt'));
        $this->assertSame(5, $storage->sizeBytes('hello.txt'));
    }

    public function test_put_creates_nested_directories(): void
    {
        $storage = new LocalStorage($this->tempDir);
        $storage->put('a/b/c/d.txt', 'nested');

        $this->assertSame('nested', $storage->get('a/b/c/d.txt'));
    }

    public function test_overwriting_a_key_is_allowed(): void
    {
        $storage = new LocalStorage($this->tempDir);
        $storage->put('x.txt', 'one');
        $storage->put('x.txt', 'two');

        $this->assertSame('two', $storage->get('x.txt'));
    }

    public function test_get_missing_key_throws(): void
    {
        $storage = new LocalStorage($this->tempDir);

        $this->expectException(StorageException::class);
        $storage->get('nope.txt');
    }

    public function test_delete_is_idempotent(): void
    {
        $storage = new LocalStorage($this->tempDir);
        $storage->put('x.txt', 'one');
        $storage->delete('x.txt');
        $storage->delete('x.txt'); // no exception

        $this->assertFalse($storage->exists('x.txt'));
    }

    public function test_path_traversal_is_rejected(): void
    {
        $storage = new LocalStorage($this->tempDir);

        $this->expectException(StorageException::class);
        $storage->put('../escape.txt', 'no');
    }

    public function test_leading_slash_is_rejected(): void
    {
        $storage = new LocalStorage($this->tempDir);

        $this->expectException(StorageException::class);
        $storage->get('/etc/passwd');
    }

    public function test_null_byte_is_rejected(): void
    {
        $storage = new LocalStorage($this->tempDir);

        $this->expectException(StorageException::class);
        $storage->put("safe\0../evil.txt", 'no');
    }

    public function test_name_returns_driver_label(): void
    {
        $this->assertSame('local', (new LocalStorage($this->tempDir))->name());
    }

    public function test_base_directory_returns_resolved_path(): void
    {
        $storage = new LocalStorage($this->tempDir);
        $this->assertSame(realpath($this->tempDir), $storage->baseDirectory());
    }

    public function test_empty_key_is_rejected(): void
    {
        $storage = new LocalStorage($this->tempDir);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Invalid storage key');
        $storage->put('', 'no');
    }

    public function test_size_bytes_throws_for_missing_key(): void
    {
        $storage = new LocalStorage($this->tempDir);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Storage key not found');
        $storage->sizeBytes('does-not-exist.txt');
    }

    public function test_exists_returns_false_for_invalid_key(): void
    {
        // An invalid key would normally throw via resolveKey(); exists() must
        // catch the StorageException and answer false instead.
        $storage = new LocalStorage($this->tempDir);

        $this->assertFalse($storage->exists('../traversal.txt'));
    }

    public function test_constructor_creates_base_directory_if_missing(): void
    {
        $fresh = $this->tempDir . '/auto-created-base';
        $this->assertDirectoryDoesNotExist($fresh);

        $storage = new LocalStorage($fresh);

        $this->assertDirectoryExists($fresh);
        $this->assertSame(realpath($fresh), $storage->baseDirectory());
    }

    public function test_constructor_throws_when_base_directory_cannot_be_created(): void
    {
        // /proc/<pid>/i-do-not-exist is on a read-only filesystem on Linux —
        // mkdir() WILL fail with EROFS, exercising the throw branch.
        $this->expectException(StorageException::class);

        @new LocalStorage('/proc/self/forbidden/' . uniqid());
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir . '/' . $f;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
