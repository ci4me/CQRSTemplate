<?php

declare(strict_types=1);

namespace App\Domain\User\Events\UserRegistered;

use Psr\Log\LoggerInterface;

final readonly class UserRegisteredEventHandler
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(UserRegisteredEvent $event): void
    {
        $this->logger->info('User registered successfully', [
            'domain' => 'User',
            'event' => 'UserRegisteredEvent',
            'user_id' => $event->userId,
            'email' => $event->email,
            'registered_at' => $event->registeredAt->format('Y-m-d H:i:s'),
        ]);
    }
}
