<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\DeleteCookie;

/**
 * Command to delete a Cookie (soft delete).
 *
 * Note: This performs a SOFT delete, meaning the cookie record
 * remains in the database but is marked as deleted.
 *
 * @package App\Domain\Cookie\Commands\DeleteCookie
 */
final readonly class DeleteCookieCommand
{
    /**
     * Create a new DeleteCookieCommand.
     *
     * @param int $id The ID of the cookie to delete
     */
    public function __construct(
        public int $id
    ) {
    }
}
