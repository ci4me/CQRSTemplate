<?php

declare(strict_types=1);

namespace App\Domain\User\Ports;

/**
 * Token Blacklist Interface.
 *
 * Port for token revocation functionality.
 */
interface TokenBlacklistInterface
{
    /**
     * Add a token to the blacklist.
     *
     * @param string $token JWT token to blacklist
     */
    public function blacklist(string $token): void;

    /**
     * Check if a token is blacklisted.
     *
     * @param string $token JWT token to check
     * @return bool True if blacklisted
     */
    public function isBlacklisted(string $token): bool;
}
