<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\DeleteCookie;

use App\Domain\Shared\ValueObjects\Actor;

/**
 * Command to delete a Cookie (soft delete).
 *
 * Note: This performs a SOFT delete — the row stays in the table with
 * `deleted_at` set. The {@see Actor} stamps the `deleted_by` audit
 * column.
 *
 * @package App\Domain\Cookie\Commands\DeleteCookie
 */
final readonly class DeleteCookieCommand
{
    /**
     * __construct.
     *
     * @param int   $id
     * @param Actor $deletedBy
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function __construct(
        public int $id,
        public Actor $deletedBy
    ) {
    }
}
