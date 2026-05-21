<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

/**
 * Identity of the user performing a command.
 *
 * Carried inside every command that mutates state so handlers can attribute
 * changes (audit trail, domain events). A {@see self::system()} actor exists
 * for legitimate server-side operations (background jobs, migrations, seeds)
 * where no human is responsible.
 */
final readonly class Actor
{
    public const int SYSTEM_ID = 0;

    /**
     * __construct.
     */
    private function __construct(
        public int $id,
        public string $label
    ) {
    }

    /**
     * user.
     *
     * @throws \InvalidArgumentException
     */
    public static function user(int $userId): self
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException(
                sprintf('Actor user id must be > 0, got %d', $userId)
            );
        }

        return new self($userId, sprintf('user:%d', $userId));
    }

    /**
     * system.
     */
    public static function system(string $label = 'system'): self
    {
        return new self(self::SYSTEM_ID, $label);
    }

    /**
     * isSystem.
     */
    public function isSystem(): bool
    {
        return $this->id === self::SYSTEM_ID;
    }
}
