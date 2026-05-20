<?php

declare(strict_types=1);

namespace App\Domain\User\Events\UserRegistered;

use App\Domain\Shared\Events\DomainEventInterface;

/**
 * Event fired when a new User is successfully registered.
 *
 * Domain Events represent facts that have happened in the domain.
 * They are named in past tense and are immutable.
 */
final readonly class UserRegisteredEvent implements DomainEventInterface
{
    /**
     * @param int $userId The ID of the newly registered user
     * @param string $email The email address of the registered user
     * @param \DateTimeImmutable $registeredAt When the user was registered
     */
    public function __construct(
        public int $userId,
        public string $email,
        public \DateTimeImmutable $registeredAt
    ) {
    }
}
