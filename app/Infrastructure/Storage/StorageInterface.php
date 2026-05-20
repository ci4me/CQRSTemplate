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
    /**
     * name.
     *
     * @return string
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function name(): string;

    /**
     * Persist the given bytes under $key. If a row with the same key
     * already exists, it is overwritten (idempotent re-uploads are common
     * for retried requests).
     *
     * @param string $key
     * @param string $contents
     * @return void
     */
    public function put(string $key, string $contents): void;

    /**
     * Read the bytes back. Throws StorageException if the key does not exist.
     *
     * @param string $key
     * @return string
     */
    public function get(string $key): string;

    /**
     * exists.
     *
     * @param string $key
     * @return bool
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function exists(string $key): bool;

    /**
     * delete.
     *
     * @param string $key
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function delete(string $key): void;

    /**
     * sizeBytes.
     *
     * @param string $key
     * @return int
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function sizeBytes(string $key): int;
}
