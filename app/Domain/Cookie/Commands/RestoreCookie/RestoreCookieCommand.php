<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\RestoreCookie;

use App\Domain\Shared\ValueObjects\Actor;

/**
 * Command to restore a previously soft-deleted cookie.
 *
 * Soft delete on operational tables is only useful if there is a path back.
 * This command is the symmetric counterpart of {@see DeleteCookieCommand}.
 *
 * E08 renamed `$cookieId` → `$id` so the four Cookie command DTOs share
 * the same identifier shape — closes 03/F2 (CRITICAL) + 03/F7.
 */
final readonly class RestoreCookieCommand
{
    /**
     * @param int   $id         Persisted cookie id to restore.
     * @param Actor $restoredBy Audit actor stamping the restoration.
     */
    public function __construct(
        public int $id,
        public Actor $restoredBy
    ) {
    }
}
