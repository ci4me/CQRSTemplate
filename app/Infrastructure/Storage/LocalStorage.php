<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

/**
 * Local-filesystem storage driver (D11).
 *
 * Files live under a configured base directory; the storage key becomes the
 * relative path. Defaults to `writable/uploads/` (gitignored by CI4).
 *
 * Path traversal: every key is normalised and checked to remain under
 * $baseDir after realpath() resolution. Keys containing `..`, leading
 * slashes, or null bytes are rejected.
 */
final class LocalStorage implements StorageInterface
{
    private readonly string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $resolved = $baseDir ?? (defined('WRITEPATH') ? WRITEPATH . 'uploads' : sys_get_temp_dir() . '/erp-uploads');
        if (!is_dir($resolved) && !@mkdir($resolved, 0o755, true) && !is_dir($resolved)) {
            throw new StorageException(sprintf('Cannot create storage base directory %s', $resolved));
        }
        $real = realpath($resolved);
        if ($real === false) {
            throw new StorageException(sprintf('Cannot resolve storage base directory %s', $resolved));
        }
        $this->baseDir = $real;
    }

    public function name(): string
    {
        return 'local';
    }

    public function put(string $key, string $contents): void
    {
        $path = $this->resolveKey($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new StorageException(sprintf('Cannot create directory %s', $dir));
        }

        $bytes = @file_put_contents($path, $contents);
        if ($bytes === false) {
            throw new StorageException(sprintf('Failed to write to %s', $path));
        }
    }

    public function get(string $key): string
    {
        $path = $this->resolveKey($key);
        if (!is_file($path)) {
            throw new StorageException(sprintf('Storage key not found: %s', $key));
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new StorageException(sprintf('Failed to read %s', $path));
        }
        return $contents;
    }

    public function exists(string $key): bool
    {
        try {
            $path = $this->resolveKey($key);
            return is_file($path);
        } catch (StorageException) {
            return false;
        }
    }

    public function delete(string $key): void
    {
        $path = $this->resolveKey($key);
        if (!is_file($path)) {
            return;
        }
        @unlink($path);
    }

    public function sizeBytes(string $key): int
    {
        $path = $this->resolveKey($key);
        if (!is_file($path)) {
            throw new StorageException(sprintf('Storage key not found: %s', $key));
        }
        $size = @filesize($path);
        if ($size === false) {
            throw new StorageException(sprintf('Cannot stat %s', $path));
        }
        return $size;
    }

    private function resolveKey(string $key): string
    {
        if ($key === '' || str_contains($key, "\0") || str_contains($key, '..') || str_starts_with($key, '/')) {
            throw new StorageException(sprintf('Invalid storage key: %s', $key));
        }

        // No leading slash, no traversal — concatenate and trust dirname/realpath later.
        $path = $this->baseDir . DIRECTORY_SEPARATOR . $key;

        // The file may not exist yet (during put); only verify the parent stays in $baseDir.
        $parentReal = realpath(dirname($path));
        $parent = $parentReal === false ? $this->baseDir : $parentReal;
        if (!str_starts_with($parent, $this->baseDir)) {
            throw new StorageException(sprintf('Refusing to write outside base directory: %s', $key));
        }

        return $path;
    }

    public function baseDirectory(): string
    {
        return $this->baseDir;
    }
}
