<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/**
 * Static auth context for propagating authenticated user ID
 * to command handlers without modifying command signatures.
 *
 * Set by authentication middleware (Session or JWT) and read
 * by command handlers that need to know who performed an action.
 *
 * @package App\Infrastructure\Auth
 */
final class AuthContext
{
    /** @var int|null */
    private static ?int $currentUserId = null;

    /**
     * setCurrentUserId.
     *
     * @param int|null $userId
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
    public static function setCurrentUserId(?int $userId): void
    {
        self::$currentUserId = $userId;
    }

    /**
     * getCurrentUserId.
     *
     * @return int
     * @todo Auto-generated docblock — review and replace this description.
     */
    public static function getCurrentUserId(): int
    {
        return self::$currentUserId ?? 0;
    }

    /**
     * reset.
     *
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
    public static function reset(): void
    {
        self::$currentUserId = null;
    }
}
