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
use App\Infrastructure\Auth\Services\DatabaseTokenBlacklistService;
use App\Domain\Shared\Bus\ClockInterface;
use App\Domain\Shared\Bus\LogSampler;
use App\Domain\Shared\Bus\SystemClock;
use App\Infrastructure\Bus\CommandBus;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Bus\Middleware\AuditMiddleware;
use App\Infrastructure\Bus\Middleware\LoggingMiddleware;
use App\Infrastructure\Bus\Middleware\TransactionMiddleware;
use App\Infrastructure\Bus\QueryBus;
use App\Infrastructure\Projections\ProjectionRegistry;
use App\Infrastructure\Email\EmailService;
use App\Infrastructure\I18n\LocaleResolver;
use App\Infrastructure\Jobs\JobQueue;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Logging\LoggingServiceProvider;
use App\Infrastructure\Notifications\NotificationService;
use App\Infrastructure\ServiceProvider\ServiceProviderRegistry;
use App\Infrastructure\Settings\SettingsService;
use App\Infrastructure\Tenancy\TenantContext;
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
        // Pass a lazy resolver for the shared EventDispatcher so the bus
        // can flip dispatch to strict-rethrow inside the transaction. The
        // resolver runs at handle() time — NOT now — to avoid recursing
        // through ensureProvidersRegistered() before the bus is cached.
        $bus->pushMiddleware(new TransactionMiddleware(
            LoggerFactory::create('infrastructure.command_bus'),
            null,
            static fn (): EventDispatcher => self::eventDispatcher()
        ));
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

        return new EventDispatcher(self::logger());
    }

    /**
     * Get the Projection Registry instance.
     *
     * The registry owns every read-model projection in the app and re-uses
     * the shared EventDispatcher as its delivery channel. Each projection's
     * `subscribesTo()` declares which events it cares about; the registry
     * subscribes its `apply()` to those events. The spark rebuild command
     * looks up projections by name through this registry, so wiring it up
     * in Services is what makes `php spark projections:rebuild` work.
     */
    public static function projectionRegistry(bool $getShared = true): ProjectionRegistry
    {
        if ($getShared) {
            $registry = static::getSharedInstance('projectionRegistry');
            self::ensureProvidersRegistered();
            return $registry;
        }

        $registry = new ProjectionRegistry(self::eventDispatcher());

        // Phase 2 of the stabilization refactor collapsed the Cookie read
        // model into the canonical `cookies` table, so the pilot projection
        // is no longer registered here. The reference implementation is
        // preserved at app/Domain/Cookie/Projections/CookieReadModelProjection.php.example.
        // New domains that genuinely need a denormalised read model register
        // their projection from their own service provider.

        return $registry;
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


        // Get shared instances (without triggering registration again).
        // CommandBus middleware (LoggingMiddleware + TransactionMiddleware +
        // AuditMiddleware) was already wired in commandBus(false). Don't
        // re-register here — main's parallel addMiddleware() call was
        // dropped during merge because pushMiddleware is the canonical API.
        $commandBus = static::getSharedInstance('commandBus');
        $queryBus = static::getSharedInstance('queryBus');
        $eventDispatcher = static::getSharedInstance('eventDispatcher');

        // Register all domain providers. Repository entries are now
        // auto-discovered from #[AutoBind]-tagged classes (Phase 3 Group B);
        // the rest are non-repository services that still register manually
        // — they will move to ports + adapters in Phase 5.
        $loggingConfig = new \App\Infrastructure\Logging\CodeIgniterLogConfig(config('Logging'));

        $repositories = array_merge(
            ServiceProviderRegistry::discoverRepositories(),
            [
                'eventDispatcher' => $eventDispatcher,
                'logger' => self::logger(),
                'loggingConfig' => $loggingConfig,
                'clock' => self::clock(),
                // Shared LogSampler built once from the LogConfigPort's
                // configured rate (closes 04/F12). All query handlers that
                // extend AbstractQueryHandler receive THIS sampler so the
                // sampling distribution is identical across handlers.
                'logSampler' => new LogSampler($loggingConfig->samplingRate()),
                'passwordHasher' => self::passwordHasher(),
                'authenticationService' => self::authenticationService(),
                'tokenBlacklistService' => self::tokenBlacklistService(),
                'rateLimitService' => self::rateLimitService(),
                'jwtService' => self::jwtService(),
                'sessionManagementService' => self::sessionManagementService(),
                'loginAttemptTracker' => self::loginAttemptTracker(),
                'securityEventService' => self::securityEventService(),
                'emailService' => self::emailService(),
            ]
        );

        ServiceProviderRegistry::registerAll(
            $commandBus,
            $queryBus,
            $eventDispatcher,
            $repositories
        );

        self::$providersRegistered = true;

        // Build the projection registry AFTER the domain providers have wired
        // their handlers — the registry adds projection apply() calls to the
        // same dispatcher, and we don't want the dispatcher state to differ
        // depending on which Services::* method touched it first.
        static::getSharedInstance('projectionRegistry');
    }

    /**
     * Resolve an auto-discovered repository by its lower-camelCase short name.
     *
     * Phase 3 Group B: concrete repositories tagged with #[AutoBind] are
     * instantiated by {@see ServiceProviderRegistry::discoverRepositories()}
     * — the per-domain `cookieRepository()` / `userRepository()` factory
     * methods that used to live in this file are gone. Callers that still
     * want a typed reference to a repository (controllers, middleware,
     * tests) go through this accessor.
     *
     * @param string $name Lower-camelCase short name, e.g. 'cookieRepository'.
     * @return object
     * @throws \RuntimeException When the name is not registered.
     */
    public static function repository(string $name): object
    {
        $repositories = ServiceProviderRegistry::discoverRepositories();
        if (!isset($repositories[$name])) {
            throw new \RuntimeException(sprintf(
                'No #[AutoBind] repository registered as "%s". Available: %s',
                $name,
                implode(', ', array_keys($repositories))
            ));
        }
        return $repositories[$name];
    }

    /**
     * Active tenant for the current execution context (D11/B11).
     *
     * Cookie write/read paths consult this to stamp + filter the
     * `tenant_id` column. The default fallback (1) is what keeps the
     * composite UNIQUE(tenant_id, name, deleted_at) on `cookies` actually
     * enforcing uniqueness on single-tenant deploys — without it MySQL
     * treats NULL tenant_ids as distinct and lets duplicate names slip
     * through.
     */
    public static function tenantContext(bool $getShared = true): TenantContext
    {
        if ($getShared) {
            return static::getSharedInstance('tenantContext');
        }

        return new TenantContext(\Config\Services::request());
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

        $userRepository = self::repository('userRepository');
        assert($userRepository instanceof \App\Domain\User\Repositories\UserRepository);

        return new FirebaseJwtAdapter(
            self::jwtService(),
            self::tokenBlacklistService(),
            $userRepository
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

        // SECURITY: production default is the database-backed store so the
        // revocation survives restarts and scales across web nodes. The
        // cache-backed implementation is still available for offline scripts
        // / dev work but MUST NOT be the default exposed to HTTP requests.
        return new DatabaseTokenBlacklistService();
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
     * Monotonic clock used by the CQRS handler bases for timing.
     *
     * The default {@see SystemClock} delegates to `hrtime(true)` so the
     * duration measurements survive wall-clock jumps (NTP, DST). Tests
     * substitute a fake clock returning a controlled sequence to assert
     * deterministic `duration_ms` values.
     *
     * E08 wired this into {@see AbstractCommandHandler}/{@see AbstractQueryHandler}
     * so every domain handler reads time from a single seam — closes
     * 03/F11, 14/F21.
     *
     * @param bool $getShared Whether to return the shared instance.
     * @return ClockInterface
     */
    public static function clock(bool $getShared = true): ClockInterface
    {
        if ($getShared) {
            return static::getSharedInstance('clock');
        }

        return new SystemClock();
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

    /**
     * In-app notification service (D12).
     */
    public static function notificationService(bool $getShared = true): NotificationService
    {
        if ($getShared) {
            return static::getSharedInstance('notificationService');
        }

        return new NotificationService();
    }

    /**
     * Request locale resolver (D8). Supported locales mirror what we ship
     * in app/Language/<locale>/. Add a code here only after the matching
     * translation files exist.
     */
    public static function localeResolver(bool $getShared = true): LocaleResolver
    {
        if ($getShared) {
            return static::getSharedInstance('localeResolver');
        }

        return new LocaleResolver(supported: ['en', 'pt-br'], default: 'en');
    }
}
