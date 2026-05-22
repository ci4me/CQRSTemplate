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
    /**
     * Construct a new RestoreCookieCommand.
     *
     * @param int   $cookieId   Target cookie id; must point to a CURRENTLY soft-deleted row.
     * @param Actor $restoredBy Audit-trail actor that initiated the restore; logged alongside the event.
     */
    public function __construct(
        public int $cookieId,
        public Actor $restoredBy
    ) {
    }
}
