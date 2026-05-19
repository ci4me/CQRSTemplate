<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\RestoreCookie;

use App\Domain\Shared\ValueObjects\Actor;

/**
 * Command to restore a previously soft-deleted cookie.
 *
 * Soft delete on operational tables is only useful if there is a path back.
 * This command is the symmetric counterpart of {@see DeleteCookieCommand}.
 */
final readonly class RestoreCookieCommand
{
    public function __construct(
        public int $cookieId,
        public Actor $restoredBy
    ) {
    }
}
