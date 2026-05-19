<?php

declare(strict_types=1);

namespace Config;

use App\Domain\User\Ports\AuthenticationServiceInterface;
use App\Domain\User\Ports\PasswordHasherInterface;
use App\Domain\User\Ports\RateLimitInterface;
use App\Domain\User\Ports\TokenBlacklistInterface;
use App\Infrastructure\Auth\Adapters\Jwt\FirebaseJwtAdapter;
use App\Infrastructure\Auth\Services\ActorResolver;
use App\Infrastructure\Auth\Services\JwtService;
use App\Infrastructure\Auth\Services\PermissionService;
use App\Infrastructure\Auth\Services\LoginAttemptTracker;
use App\Infrastructure\Auth\Services\PasswordHashingService;
use App\Infrastructure\Auth\Services\RateLimitService;
use App\Infrastructure\Auth\Services\SecurityEventService;
use App\Infrastructure\Auth\Services\SessionManagementService;
use App\Infrastructure\Auth\Services\TokenBlacklistService;
use App\Infrastructure\Bus\CommandBus;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Bus\Middleware\AuditMiddleware;
use App\Infrastructure\Bus\Middleware\LoggingMiddleware;
use App\Infrastructure\Bus\Middleware\TransactionMiddleware;
use App\Infrastructure\Bus\QueryBus;
use App\Infrastructure\Email\EmailService;
use App\Infrastructure\Jobs\JobQueue;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Logging\LoggingServiceProvider;
use App\Infrastructure\Settings\SettingsService;
use App\Infrastructure\Persistence\Models\UserModel;
use App\Infrastructure\Persistence\Repositories\PasswordHistoryRepository;
use App\Infrastructure\Persistence\Repositories\UserRepository;
use App\Infrastructure\ServiceProvider\ServiceProviderRegistry;
use App\Models\Cookie\CookieRepository;
use CodeIgniter\Config\BaseService;
use Psr\Log\LoggerInterface;

/**
 * Services Configuration file for Dependency Injection.
 *
 * This file configures the application's services using CodeIgniter's
 * service container. All CQRS infrastructure (buses, handlers, repositories)
 * are registered here as singletons.
 *
 * Why Dependency Injection:
 * - Decouples components (easy to test, swap implementations)
 * - Central configuration (all dependencies in one place)
 * - Lazy loading (services created only when needed)
 * - Singleton pattern (reuse expensive objects)
 *
 * When adding a new domain:
 * 1. Add repository service method
 * 2. Add command handlers to commandBus()
 * 3. Add query handlers to queryBus()
 * 4. Register event listeners if needed
 *
 * @package Config
 */
class Services extends BaseService
{
    /**
     * Flag to track if providers have been registered.
     */
    private static bool $providersRegistered = false;

    /**
     * Reset the providers registered flag.
     * This is used in tests when services are reset.
     *
     * @return void
     */
    public static function resetProviders(): void
    {
        self::$providersRegistered = false;
    }

    /**
     * Get the Command Bus instance.
     *
     * The Command Bus is a singleton with all command handlers registered
     * via automatic service provider discovery.
     *
     * @param bool $getShared Whether to return the shared instance
     * @return CommandBus
     */
    public static function commandBus(bool $getShared = true): CommandBus
    {
        if ($getShared) {
            $bus = static::getSharedInstance('commandBus');
            self::ensureProvidersRegistered();
            return $bus;
        }

        $bus = new CommandBus();

        // Outermost first: log every dispatch, then wrap the rest in a DB
        // transaction so handler writes + synchronous event listeners share
        // the same unit of work (B8). AuditMiddleware lives INSIDE the
        // transaction so the audit row commits/rolls back atomically with
        // the business change (D2).
        $bus->pushMiddleware(new LoggingMiddleware(LoggerFactory::create('infrastructure.command_bus')));
        $bus->pushMiddleware(new TransactionMiddleware(LoggerFactory::create('infrastructure.command_bus')));
        $bus->pushMiddleware(new AuditMiddleware(
            LoggerFactory::create('infrastructure.audit'),
            self::actorResolver()
        ));

        return $bus;
    }

    /**
     * Get the Query Bus instance.
     *
     * The Query Bus is a singleton with all query handlers registered
     * via automatic service provider discovery.
     *
     * @param bool $getShared Whether to return the shared instance
     * @return QueryBus
     */
    public static function queryBus(bool $getShared = true): QueryBus
    {
        if ($getShared) {
            $bus = static::getSharedInstance('queryBus');
            self::ensureProvidersRegistered();
            return $bus;
        }

        return new QueryBus();
    }

    /**
     * Get the Event Dispatcher instance.
     *
     * The Event Dispatcher is a singleton with all event listeners registered
     * via automatic service provider discovery.
     *
     * @param bool $getShared Whether to return the shared instance
     * @return EventDispatcher
     */
    public static function eventDispatcher(bool $getShared = true): EventDispatcher
    {
        if ($getShared) {
            $dispatcher = static::getSharedInstance('eventDispatcher');
            self::ensureProvidersRegistered();
            return $dispatcher;
        }

        return new EventDispatcher();
    }

    /**
     * Ensure service providers are registered exactly once.
     *
     * This method is called by each bus getter to ensure handlers are registered
     * on the shared instances before they are used.
     *
     * @return void
     */
    private static function ensureProvidersRegistered(): void
    {
        if (self::$providersRegistered) {
            return;
        }


        // Get shared instances (without triggering registration again)
        $commandBus = static::getSharedInstance('commandBus');
        $queryBus = static::getSharedInstance('queryBus');
        $eventDispatcher = static::getSharedInstance('eventDispatcher');


        // Register all domain providers
        ServiceProviderRegistry::registerAll(
            $commandBus,
            $queryBus,
            $eventDispatcher,
            [
                'cookieRepository' => self::cookieRepository(),
                'userRepository' => self::userRepository(),
                'eventDispatcher' => $eventDispatcher,
                'logger' => self::logger(),
                'loggingConfig' => config('Logging'),
                'passwordHasher' => self::passwordHasher(),
                'authenticationService' => self::authenticationService(),
                'tokenBlacklistService' => self::tokenBlacklistService(),
                'rateLimitService' => self::rateLimitService(),
                'jwtService' => self::jwtService(),
                'passwordHistoryRepository' => self::passwordHistoryRepository(),
                'sessionManagementService' => self::sessionManagementService(),
                'loginAttemptTracker' => self::loginAttemptTracker(),
                'securityEventService' => self::securityEventService(),
                'emailService' => self::emailService(),
            ]
        );

        self::$providersRegistered = true;
    }

    /**
     * Get the Cookie Repository instance.
     *
     * @param bool $getShared Whether to return the shared instance
     * @return CookieRepository
     */
    public static function cookieRepository(bool $getShared = true): CookieRepository
    {
        if ($getShared) {
            return static::getSharedInstance('cookieRepository');
        }

        return new CookieRepository(
            self::logger(),
            config('Logging')
        );
    }

    /**
     * Get the User Repository instance.
     *
     * @param bool $getShared Whether to return the shared instance
     * @return UserRepository
     */
    public static function userRepository(bool $getShared = true): UserRepository
    {
        if ($getShared) {
            return static::getSharedInstance('userRepository');
        }

        return new UserRepository(
            new UserModel(),
            self::logger(),
            config('Logging')
        );
    }

    /**
     * Get the Password Hasher service.
     *
     * @param bool $getShared Whether to return the shared instance
     * @return PasswordHasherInterface
     */
    public static function passwordHasher(bool $getShared = true): PasswordHasherInterface
    {
        if ($getShared) {
            return static::getSharedInstance('passwordHasher');
        }

        return new PasswordHashingService();
    }

    /**
     * Get the Authentication Service (JWT).
     *
     * @param bool $getShared Whether to return the shared instance
     * @return AuthenticationServiceInterface
     */
    public static function authenticationService(bool $getShared = true): AuthenticationServiceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('authenticationService');
        }

        return new FirebaseJwtAdapter(
            self::jwtService(),
            self::tokenBlacklistService(),
            self::userRepository()
        );
    }

    /**
     * Get the JWT Service.
     *
     * @param bool $getShared Whether to return the shared instance
     * @return JwtService
     */
    public static function jwtService(bool $getShared = true): JwtService
    {
        if ($getShared) {
            return static::getSharedInstance('jwtService');
        }

        return new JwtService();
    }

    /**
     * Get the Token Blacklist Service.
     *
     * @param bool $getShared Whether to return the shared instance
     * @return TokenBlacklistInterface
     */
    public static function tokenBlacklistService(bool $getShared = true): TokenBlacklistInterface
    {
        if ($getShared) {
            return static::getSharedInstance('tokenBlacklistService');
        }

        return new TokenBlacklistService(\Config\Services::cache());
    }

    /**
     * Get the Rate Limit Service.
     *
     * @param bool $getShared Whether to return the shared instance
     * @return RateLimitInterface
     */
    public static function rateLimitService(bool $getShared = true): RateLimitInterface
    {
        if ($getShared) {
            return static::getSharedInstance('rateLimitService');
        }

        return new RateLimitService(\Config\Services::cache());
    }

    /**
     * Get the application email service.
     *
     * @param bool $getShared Whether to return the shared instance
     * @return EmailService
     */
    public static function emailService(bool $getShared = true): EmailService
    {
        if ($getShared) {
            return static::getSharedInstance('emailService');
        }

        return new EmailService(self::logger());
    }

    /**
     * Get the Logger instance.
     *
     * Returns a PSR-3 compliant logger that can be injected into
     * CQRS handlers and other application components.
     *
     * The logger is configured with:
     * - JSON formatting for AI-readable logs
     * - Rotating file handler (30 days retention)
     * - CQRS context processor for domain/command/query/event extraction
     *
     * @param bool $getShared Whether to return the shared instance
     * @return LoggerInterface PSR-3 compliant logger instance
     */
    public static function logger(bool $getShared = true): LoggerInterface
    {
        if ($getShared) {
            return static::getSharedInstance('logger');
        }

        return LoggingServiceProvider::createLogger('app');
    }

    /**
     * Get the Password History Repository instance.
     *
     * @param bool $getShared Whether to return the shared instance
     * @return PasswordHistoryRepository
     */
    public static function passwordHistoryRepository(bool $getShared = true): PasswordHistoryRepository
    {
        if ($getShared) {
            return static::getSharedInstance('passwordHistoryRepository');
        }

        return new PasswordHistoryRepository(
            \Config\Database::connect()
        );
    }

    /**
     * Get the Session Management Service instance.
     *
     * @param bool $getShared Whether to return the shared instance
     * @return SessionManagementService
     */
    public static function sessionManagementService(bool $getShared = true): SessionManagementService
    {
        if ($getShared) {
            return static::getSharedInstance('sessionManagementService');
        }

        return new SessionManagementService();
    }

    /**
     * Get the Login Attempt Tracker instance.
     *
     * @param bool $getShared Whether to return the shared instance
     * @return LoginAttemptTracker
     */
    public static function loginAttemptTracker(bool $getShared = true): LoginAttemptTracker
    {
        if ($getShared) {
            return static::getSharedInstance('loginAttemptTracker');
        }

        return new LoginAttemptTracker();
    }

    /**
     * Get the Security Event Service instance.
     *
     * @param bool $getShared Whether to return the shared instance
     * @return SecurityEventService
     */
    public static function securityEventService(bool $getShared = true): SecurityEventService
    {
        if ($getShared) {
            return static::getSharedInstance('securityEventService');
        }

        return new SecurityEventService();
    }

    /**
     * Resolves the authenticated actor for the current request.
     */
    public static function actorResolver(bool $getShared = true): ActorResolver
    {
        if ($getShared) {
            return static::getSharedInstance('actorResolver');
        }

        return new ActorResolver();
    }

    /**
     * RBAC permission gate (D3).
     */
    public static function permissionService(bool $getShared = true): PermissionService
    {
        if ($getShared) {
            return static::getSharedInstance('permissionService');
        }

        return new PermissionService();
    }

    /**
     * Runtime settings store (D10).
     */
    public static function settingsService(bool $getShared = true): SettingsService
    {
        if ($getShared) {
            return static::getSharedInstance('settingsService');
        }

        return new SettingsService();
    }

    /**
     * Database-backed job queue producer (D6).
     * Workers run via `php spark jobs:work`.
     */
    public static function jobQueue(bool $getShared = true): JobQueue
    {
        if ($getShared) {
            return static::getSharedInstance('jobQueue');
        }

        return new JobQueue();
    }
}
