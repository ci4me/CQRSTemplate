<?php

declare(strict_types=1);

namespace App\Domain\User\DTOs;

use App\Domain\User\Entities\User;

/**
 * Data Transfer Object for User entity.
 *
 * Prevents domain entities from leaking into the presentation layer.
 *
 * @package App\Domain\User\DTOs
 */
final readonly class UserDTO
{
    public function __construct(
        public ?int $id,
        public string $name,
        public string $email,
        public string $role,
        public string $status,
        public int $failedLoginAttempts,
        public ?string $lockedUntil,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    public static function fromEntity(User $user): self
    {
        return new self(
            id: $user->getId(),
            name: $user->getName()->getValue(),
            email: $user->getEmail()->getValue(),
            role: $user->getRole()->value,
            status: $user->getStatus()->value,
            failedLoginAttempts: $user->getFailedLoginAttempts(),
            lockedUntil: $user->getLockedUntil()?->format('Y-m-d H:i:s'),
            createdAt: $user->getCreatedAt()->format('Y-m-d H:i:s'),
            updatedAt: $user->getUpdatedAt()?->format('Y-m-d H:i:s'),
        );
    }
}
