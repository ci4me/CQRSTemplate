<?php

declare(strict_types=1);

namespace App\Domain\User\Queries\GetUserById;

/**
 * Query to retrieve a single User by ID.
 *
 * Queries represent requests for DATA without changing state.
 * They are named as questions and return data without modifications.
 */
final readonly class GetUserByIdQuery
{
    /**
     * @param int $id The ID of the user to retrieve
     */
    public function __construct(
        public int $id
    ) {
    }
}
