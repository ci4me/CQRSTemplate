<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

/**
 * Thrown by storage drivers for unrecoverable I/O errors. Callers should
 * treat this as a system failure (500) rather than a domain violation —
 * the user did nothing wrong; the disk did.
 */
final class StorageException extends \RuntimeException
{
}
