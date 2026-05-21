<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\User\Ports\AuthenticationServiceInterface;
use App\Domain\User\Ports\TokenBlacklistInterface;
use App\Infrastructure\Attributes\DomainServiceProvider;
use App\Infrastructure\Auth\Commands\LoginUser\LoginUserCommand;
use App\Infrastructure\Auth\Commands\LoginUser\LoginUserHandler;
use App\Infrastructure\Auth\Commands\LogoutUser\LogoutUserCommand;
use App\Infrastructure\Auth\Commands\LogoutUser\LogoutUserHandler;
use App\Infrastructure\Auth\Commands\RefreshToken\RefreshTokenCommand;
use App\Infrastructure\Auth\Commands\RefreshToken\RefreshTokenHandler;
use App\Infrastructure\Auth\Commands\RequestPasswordReset\RequestPasswordResetCommand;
use App\Infrastructure\Auth\Commands\RequestPasswordReset\RequestPasswordResetHandler;
use App\Infrastructure\Auth\Commands\ResetPassword\ResetPasswordCommand;
use App\Infrastructure\Auth\Commands\ResetPassword\ResetPasswordHandler;
use App\Infrastructure\Auth\Services\JwtService;
use App\Infrastructure\Auth\Services\LoginAttemptTracker;
use App\Infrastructure\Auth\Services\SecurityEventService;
use App\Infrastructure\Auth\Services\SessionManagementService;
use App\Infrastructure\Bus\CommandBus;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Bus\QueryBus;
use App\Infrastructure\Email\EmailService;
use App\Infrastructure\Persistence\Repositories\PasswordHistoryRepository;
use App\Infrastructure\Persistence\Repositories\UserRepository;
use App\Infrastructure\ServiceProvider\DomainServiceProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Auth Infrastructure Service Provider.
 *
 * Registers authentication-related commands.
 */
#[DomainServiceProvider]
final class AuthServiceProvider implements DomainServiceProviderInterface
{
    /**
     * @var array<string, object>
     */
    private array $repositories = [];

    /**
     * registerCommands.
     *
     * @param CommandBus $commandBus
     * @return void
     * @throws \RuntimeException
     */
    public function registerCommands(CommandBus $commandBus): void
    {
        $userRepository = $this->getRepository('userRepository');
        $authService = $this->getRepository('authenticationService');
        $tokenBlacklist = $this->getRepository('tokenBlacklistService');
        $jwtService = $this->getRepository('jwtService');
        $passwordHistory = $this->getRepository('passwordHistoryRepository');
        $sessionManager = $this->getRepository('sessionManagementService');
        $loginAttemptTracker = $this->getRepository('loginAttemptTracker');
        $securityEvents = $this->getRepository('securityEventService');
        $emailService = $this->getRepository('emailService');
        $logger = $this->getRepository('logger');

        if (
            !$userRepository instanceof UserRepository
            || !$authService instanceof AuthenticationServiceInterface
            || !$tokenBlacklist instanceof TokenBlacklistInterface
            || !$jwtService instanceof JwtService
            || !$passwordHistory instanceof PasswordHistoryRepository
            || !$sessionManager instanceof SessionManagementService
            || !$loginAttemptTracker instanceof LoginAttemptTracker
            || !$securityEvents instanceof SecurityEventService
            || !$emailService instanceof EmailService
            || !$logger instanceof LoggerInterface
        ) {
            throw new \RuntimeException('Invalid dependencies injected');
        }

        $commandBus->register(
            LoginUserCommand::class,
            new LoginUserHandler(
                $userRepository,
                $authService,
                $sessionManager,
                $loginAttemptTracker,
                $securityEvents,
                $logger
            )
        );

        $commandBus->register(
            LogoutUserCommand::class,
            new LogoutUserHandler($tokenBlacklist, $sessionManager, $logger)
        );

        $commandBus->register(
            RefreshTokenCommand::class,
            new RefreshTokenHandler($jwtService, $userRepository, $tokenBlacklist)
        );

        $commandBus->register(
            RequestPasswordResetCommand::class,
            new RequestPasswordResetHandler($userRepository, $emailService)
        );

        $commandBus->register(
            ResetPasswordCommand::class,
            new ResetPasswordHandler($userRepository, $passwordHistory)
        );
    }

    /**
     * registerQueries.
     *
     * @param QueryBus $_queryBus
     * @return void
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     */
    public function registerQueries(QueryBus $_queryBus): void
    {
        // No queries in auth module
    }

    /**
     * registerEvents.
     *
     * @param EventDispatcher $_dispatcher
     * @return void
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     */
    public function registerEvents(EventDispatcher $_dispatcher): void
    {
        // No events in auth module
    }

    /**
     * @return array<mixed>
     */
    public function getRepositories(): array
    {
        return [
            'userRepository',
            'authenticationService',
            'tokenBlacklistService',
            'jwtService',
            'passwordHistoryRepository',
            'sessionManagementService',
            'loginAttemptTracker',
            'securityEventService',
            'emailService',
            'logger',
        ];
    }

    /**
     * @param array<string, object> $repositories
     * @return void
     */
    public function setRepositories(array $repositories): void
    {
        $this->repositories = $repositories;
    }

    /**
     * getRepository.
     *
     * @param string $name
     * @return object
     */
    private function getRepository(string $name): object
    {
        return $this->repositories[$name];
    }
}
