<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\RestoreCookie;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Events\CookieRestored\CookieRestoredEvent;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Infrastructure\Bus\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

final readonly class RestoreCookieHandler
{
    public function __construct(
        private CookieRepositoryInterface $repository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger
    ) {
    }

    public function handle(RestoreCookieCommand $command): void
    {
        $cookie = $this->repository->findByIdWithTrashed($command->cookieId);

        if ($cookie === null) {
            throw DomainException::notFound('Cookie', (string) $command->cookieId, ErrorCodes::COOKIE_NOT_FOUND);
        }

        if (!$cookie->isDeleted()) {
            throw DomainException::businessRuleViolation(
                'Cookie is not deleted; nothing to restore.',
                (string) $command->cookieId,
                ErrorCodes::COOKIE_NOT_FOUND
            );
        }

        $restored = $this->repository->restore($command->cookieId, $command->restoredBy);

        if (!$restored) {
            $this->logger->error('Cookie restore failed', [
                'domain' => 'Cookie',
                'command' => 'RestoreCookieCommand',
                'cookie_id' => $command->cookieId,
            ]);
            throw new \RuntimeException(
                sprintf('Failed to restore cookie #%d', $command->cookieId)
            );
        }

        $this->logger->info('Cookie restored', [
            'domain' => 'Cookie',
            'command' => 'RestoreCookieCommand',
            'cookie_id' => $command->cookieId,
            'restored_by' => $command->restoredBy->id,
        ]);

        $this->eventDispatcher->dispatch(
            new CookieRestoredEvent(
                cookieId: $command->cookieId,
                restoredBy: $command->restoredBy->id,
                restoredAt: (new \DateTimeImmutable())->format('c')
            )
        );
    }
}
