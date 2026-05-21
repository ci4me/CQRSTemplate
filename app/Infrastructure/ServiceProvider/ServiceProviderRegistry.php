<?php

declare(strict_types=1);

namespace App\Infrastructure\ServiceProvider;

use App\Infrastructure\Attributes\AutoBind;
use App\Infrastructure\Attributes\DomainServiceProvider;
use App\Infrastructure\Bus\CommandBus;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Bus\QueryBus;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Outbox\EventOutboxWriter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * Service Provider Registry - Auto-Discovery System.
 *
 * This class automatically discovers and registers all domain service providers
 * marked with the #[DomainServiceProvider] attribute.
 *
 * How it works:
 * 1. Scans app/Domain/ directory for PHP files
 * 2. Loads classes and checks for #[DomainServiceProvider] attribute
 * 3. Instantiates provider and injects required repositories
 * 4. Calls registration methods (registerCommands, registerQueries, registerEvents)
 *
 * Benefits:
 * - Zero configuration when adding new domains
 * - Automatic discovery via PHP attributes
 * - Type-safe via interface enforcement
 * - Performance: Can be cached in production
 *
 * Usage in Services.php:
 * ```php
 * $bus = new CommandBus();
 * ServiceProviderRegistry::registerAll($bus, $queryBus, $dispatcher, [
 *     'cookieRepository' => self::cookieRepository()
 * ]);
 * ```
 *
 * @package App\Infrastructure\ServiceProvider
 */
final class ServiceProviderRegistry
{
    /**
     * Paths that may contain service providers.
     *
     * Domain providers live under app/Domain. Cross-cutting providers, such as
     * authentication, can live under app/Infrastructure and still opt in with
     * the DomainServiceProvider attribute.
     *
     * @var string[]
     */
    private const PROVIDER_PATHS = [
        APPPATH . 'Domain',
        APPPATH . 'Infrastructure',
    ];

    /**
     * Cache of discovered providers (for performance).
     *
     * @var array<int, DomainServiceProviderInterface>|null
     */
    private static ?array $providersCache = null;

    /**
     * Cache of #[AutoBind]-discovered repositories, keyed by lower-camelCase
     * short class name (e.g. 'cookieRepository' => CookieRepository instance).
     *
     * @var array<string, object>|null
     */
    private static ?array $autoBindCache = null;

    /**
     * Register all domain providers with buses.
     *
     * This is the main entry point called from Services.php.
     *
     * @param CommandBus            $commandBus      The command bus
     * @param QueryBus              $queryBus        The query bus
     * @param EventDispatcher       $eventDispatcher The event dispatcher
     * @param array<string, object> $repositories    Available repositories from Services
     * @return void
     * @throws RuntimeException
     */
    public static function registerAll(
        CommandBus $commandBus,
        QueryBus $queryBus,
        EventDispatcher $eventDispatcher,
        array $repositories
    ): void {
        $providers = self::discoverProviders();

        foreach ($providers as $provider) {
            // Inject required repositories
            $requiredRepos = $provider->getRepositories();
            $injectedRepos = [];

            foreach ($requiredRepos as $repoName) {
                if (!isset($repositories[$repoName])) {
                    throw new RuntimeException(
                        sprintf(
                            'Repository "%s" required by %s not found in Services.php',
                            $repoName,
                            $provider::class
                        )
                    );
                }
                $injectedRepos[$repoName] = $repositories[$repoName];
            }

            $provider->setRepositories($injectedRepos);

            // Register handlers
            $provider->registerCommands($commandBus);
            $provider->registerQueries($queryBus);
            $provider->registerEvents($eventDispatcher);
        }
    }

    /**
     * Discover all service providers with #[DomainServiceProvider] attribute.
     *
     * Scans Domain directory and finds classes marked with the attribute.
     * Results are cached for performance.
     *
     * @return array<mixed>
     */
    private static function discoverProviders(): array
    {
        // Return cached providers if available
        if (self::$providersCache !== null) {
            return self::$providersCache;
        }


        $providers = [];

        foreach (self::PROVIDER_PATHS as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $providers = [
                ...$providers,
                ...self::discoverProvidersInPath($path),
            ];
        }

        // Cache for future calls
        self::$providersCache = $providers;

        return $providers;
    }

    /**
     * Discover providers in one path.
     *
     * @param string $path
     * @return array<mixed>
     * @throws RuntimeException
     */
    private static function discoverProvidersInPath(string $path): array
    {
        $providers = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = self::getClassNameFromFile($file->getPathname());

            if ($className === null || !class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);
            $attributes = $reflection->getAttributes(DomainServiceProvider::class);

            if (count($attributes) === 0) {
                continue;
            }

            if (!$reflection->implementsInterface(DomainServiceProviderInterface::class)) {
                throw new RuntimeException(
                    sprintf(
                        'Class %s has #[DomainServiceProvider] attribute but does not implement DomainServiceProviderInterface',
                        $className
                    )
                );
            }

            $instance = new $className();

            if (!$instance instanceof DomainServiceProviderInterface) {
                throw new RuntimeException(
                    sprintf('Provider %s must implement DomainServiceProviderInterface', $className)
                );
            }

            $providers[] = $instance;
        }

        return $providers;
    }

    /**
     * Extract the fully qualified class name from a PHP file using the
     * native PHP tokenizer (C6).
     *
     * The previous regex-based implementation was brittle against:
     *  - `class` mentioned in comments / docblocks
     *  - `class` in trait or interface declarations (skipped here)
     *  - anonymous classes (`return new class {...}`)
     *  - multi-line namespace declarations
     *
     * The tokenizer pass is also more accurate when token_get_all returns
     * compound `T_NAME_QUALIFIED` tokens for namespaces (PHP 8+).
     *
     * @param string $filePath Path to PHP file
     * @return string|null Fully qualified class name or null if not found
     */
    private static function getClassNameFromFile(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return null;
        }

        $tokens = token_get_all($contents);
        $namespace = '';
        $className = null;

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            // Namespace declaration: `namespace Foo\Bar;`
            if ($token[0] === T_NAMESPACE) {
                $namespace = self::readNamespaceName($tokens, $i, $count);
                continue;
            }

            // Class declaration. Skip anonymous classes (`new class { ... }`)
            // by ensuring the previous meaningful token is NOT T_NEW.
            if ($token[0] !== T_CLASS || self::isAnonymousClass($tokens, $i)) {
                continue;
            }

            $className = self::readNextIdentifier($tokens, $i, $count);
            if ($className !== null) {
                break;
            }
        }

        if ($className === null) {
            return null;
        }

        return $namespace === '' ? $className : $namespace . '\\' . $className;
    }

    /**
     * Read the namespace name starting after a T_NAMESPACE token. Returns
     * the dot-free string (e.g. "App\Domain\Cookie") or '' if not found.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param int                                           $start
     * @param int                                           $count
     * @return string
     */
    private static function readNamespaceName(array $tokens, int $start, int $count): string
    {
        for ($j = $start + 1; $j < $count; $j++) {
            $next = $tokens[$j];
            if ($next === ';' || $next === '{') {
                return '';
            }
            if (!is_array($next)) {
                continue;
            }
            // PHP 8: T_NAME_QUALIFIED carries the entire dotted name.
            if ($next[0] === T_NAME_QUALIFIED || $next[0] === T_STRING) {
                return $next[1];
            }
        }
        return '';
    }

    /**
     * Read the next T_STRING identifier after a position — used to find
     * the class name following a T_CLASS token.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param int                                           $start
     * @param int                                           $count
     * @return string|null
     */
    private static function readNextIdentifier(array $tokens, int $start, int $count): ?string
    {
        for ($j = $start + 1; $j < $count; $j++) {
            $next = $tokens[$j];
            if (is_array($next) && $next[0] === T_STRING) {
                return $next[1];
            }
        }
        return null;
    }

    /**
     * Look backwards from a T_CLASS token to detect `new class { ... }`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param int                                           $classIndex
     * @return bool
     */
    private static function isAnonymousClass(array $tokens, int $classIndex): bool
    {
        for ($j = $classIndex - 1; $j >= 0; $j--) {
            $prev = $tokens[$j];
            if (!is_array($prev)) {
                return false;
            }
            // Skip whitespace, comments, and modifiers (readonly, final, ...)
            if (in_array($prev[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_READONLY, T_FINAL, T_ABSTRACT], true)) {
                continue;
            }
            return $prev[0] === T_NEW;
        }
        return false;
    }

    /**
     * Auto-discover #[AutoBind]-tagged classes and return the
     * `lowerCamelShortName => instance` map that ensureProvidersRegistered()
     * passes to setRepositories() on every DomainServiceProvider.
     *
     * Scans the same PROVIDER_PATHS as discoverProviders(); for each class
     * that carries the {@see AutoBind} attribute, this method instantiates
     * the class by reflecting on its constructor parameters and resolving
     * each one against the small well-known dependency set documented on
     * the attribute. Optional ($type|null) parameters with a known type are
     * filled; optional parameters with an unknown type are passed as null.
     *
     * The result is cached per-process. {@see clearCache()} resets both
     * the provider cache and this cache (used by tests).
     *
     * @return array<string, object> Map of repository short-name to instance.
     */
    public static function discoverRepositories(): array
    {
        if (self::$autoBindCache !== null) {
            return self::$autoBindCache;
        }

        $repositories = [];

        foreach (self::PROVIDER_PATHS as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $repositories = [
                ...$repositories,
                ...self::discoverAutoBindInPath($path),
            ];
        }

        self::$autoBindCache = $repositories;

        return $repositories;
    }

    /**
     * Discover #[AutoBind] classes inside a single root path.
     *
     * @param string $path
     * @return array<string, object>
     */
    private static function discoverAutoBindInPath(string $path): array
    {
        $repositories = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = self::getClassNameFromFile($file->getPathname());
            if ($className === null || !class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);
            if (count($reflection->getAttributes(AutoBind::class)) === 0) {
                continue;
            }

            $shortName = lcfirst($reflection->getShortName());
            $repositories[$shortName] = self::instantiateAutoBind($reflection);
        }

        return $repositories;
    }

    /**
     * Reflect on a class's constructor and instantiate it by mapping each
     * parameter's type-hint to a known shared dependency.
     *
     * Recognised parameter types (see {@see AutoBind} for the rationale):
     *
     *   - CodeIgniter\Database\BaseConnection        -> Config\Database::connect()
     *   - Psr\Log\LoggerInterface                    -> LoggerFactory channel
     *   - Config\Logging                             -> new \Config\Logging()
     *   - App\Infrastructure\Tenancy\TenantContext   -> Services::tenantContext()
     *   - App\Infrastructure\Bus\EventDispatcher     -> Services::eventDispatcher()
     *   - App\Infrastructure\Outbox\EventOutboxWriter -> new EventOutboxWriter()
     *   - App\Infrastructure\Persistence\Models\UserModel -> new UserModel()
     *   - App\Models\Cookie\CookieModel              -> new CookieModel()
     *
     * Unknown nullable parameters fall back to null; unknown required
     * parameters raise RuntimeException, which fails fast at boot rather
     * than silently constructing a half-wired adapter.
     *
     * @param ReflectionClass<object> $reflection
     * @return object
     * @throws RuntimeException
     */
    private static function instantiateAutoBind(ReflectionClass $reflection): object
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = self::resolveAutoBindParameter($reflection, $parameter);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * Resolve one constructor parameter for an #[AutoBind] class.
     *
     * @param ReflectionClass<object> $reflection
     * @param ReflectionParameter     $parameter
     * @return mixed
     * @throws RuntimeException
     */
    private static function resolveAutoBindParameter(
        ReflectionClass $reflection,
        ReflectionParameter $parameter
    ): mixed {
        $type = $parameter->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

        $value = $typeName === null ? null : self::resolveKnownDependency($typeName, $reflection);

        if ($value !== null) {
            return $value;
        }

        if ($parameter->isOptional()) {
            return $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
        }

        throw new RuntimeException(sprintf(
            'Cannot resolve required parameter $%s (%s) for #[AutoBind] class %s. '
            . 'Add the type to ServiceProviderRegistry::resolveKnownDependency() or make the parameter optional.',
            $parameter->getName(),
            $typeName ?? 'no-type',
            $reflection->getName()
        ));
    }

    /**
     * Map a type-hint string to a shared dependency instance.
     *
     * Returns null when the type is unrecognised — caller decides whether
     * to fall back to a default value or to raise.
     *
     * @param string                  $typeName
     * @param ReflectionClass<object> $reflection For LoggerInterface channel naming.
     * @return object|null
     */
    private static function resolveKnownDependency(string $typeName, ReflectionClass $reflection): ?object
    {
        return match ($typeName) {
            'CodeIgniter\\Database\\BaseConnection' => \Config\Database::connect(),
            'Psr\\Log\\LoggerInterface' => LoggerFactory::create(
                self::deriveLogChannel($reflection->getShortName())
            ),
            'Config\\Logging' => new \Config\Logging(),
            'App\\Infrastructure\\Tenancy\\TenantContext' => \Config\Services::tenantContext(),
            'App\\Infrastructure\\Bus\\EventDispatcher' => \Config\Services::eventDispatcher(),
            'App\\Infrastructure\\Outbox\\EventOutboxWriter' => new EventOutboxWriter(),
            'App\\Infrastructure\\Persistence\\Models\\UserModel' => new \App\Infrastructure\Persistence\Models\UserModel(),
            'App\\Models\\Cookie\\CookieModel' => new \App\Models\Cookie\CookieModel(),
            default => null,
        };
    }

    /**
     * Derive the monolog channel for an auto-bound repository so its log
     * lines stay grouped under a stable, conventional name.
     *
     * 'CookieRepository' -> 'cookie.repository'
     * 'CookieQueryRepository' -> 'cookie.repository.query'
     * 'PasswordHistoryRepository' -> 'password_history.repository'
     *
     * @param string $shortName
     * @return string
     */
    private static function deriveLogChannel(string $shortName): string
    {
        $base = preg_replace('/Repository$/', '', $shortName) ?? $shortName;
        $snake = strtolower((string) preg_replace('/(?<!^)([A-Z])/', '_$1', $base));
        return $snake . '.repository';
    }

    /**
     * Clear the providers cache.
     *
     * Useful for testing or when adding providers dynamically.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$providersCache = null;
        self::$autoBindCache = null;
    }
}
