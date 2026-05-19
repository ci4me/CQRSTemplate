<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

/**
 * Contract for file-bytes storage backends (D11).
 *
 * Implementations resolve a `storage_key` to actual bytes (local disk,
 * S3, etc.). All keys are opaque strings — callers MUST NOT construct
 * filesystem paths from them; that is the driver's job.
 *
 * Keys are tenant-namespaced by convention (e.g. `tenant-7/invoices/abc.pdf`)
 * but the storage layer does not enforce that — it is the upload caller's
 * responsibility to compute a safe, non-colliding key.
 */
interface StorageInterface
{
    public function name(): string;

    /**
     * Persist the given bytes under $key. If a row with the same key
     * already exists, it is overwritten (idempotent re-uploads are common
     * for retried requests).
     */
    public function put(string $key, string $contents): void;

    /**
     * Read the bytes back. Throws StorageException if the key does not exist.
     */
    public function get(string $key): string;

    public function exists(string $key): bool;

    public function delete(string $key): void;

    public function sizeBytes(string $key): int;
}
