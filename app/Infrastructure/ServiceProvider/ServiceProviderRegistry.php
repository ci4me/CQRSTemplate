<?php

declare(strict_types=1);

namespace App\Infrastructure\ServiceProvider;

use App\Infrastructure\Attributes\DomainServiceProvider;
use App\Infrastructure\Bus\CommandBus;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Bus\QueryBus;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
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
     * Register all domain providers with buses.
     *
     * This is the main entry point called from Services.php.
     *
     * @param CommandBus $commandBus The command bus
     * @param QueryBus $queryBus The query bus
     * @param EventDispatcher $eventDispatcher The event dispatcher
     * @param array<string, object> $repositories Available repositories from Services
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
     * @return DomainServiceProviderInterface[]
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
     * @return DomainServiceProviderInterface[]
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
     * Extract fully qualified class name from a PHP file.
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

        // Extract namespace
        if (preg_match('/namespace\s+(.+?);/', $contents, $namespaceMatch) !== 1) {
            return null;
        }
        $namespace = $namespaceMatch[1];

        // Extract class name (match class declaration, not comments)
        if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $contents, $classMatch) !== 1) {
            return null;
        }
        $className = $classMatch[1];

        return $namespace . '\\' . $className;
    }

    /**
     * Clear the providers cache.
     *
     * Useful for testing or when adding providers dynamically.
     *
     */
    public static function clearCache(): void
    {
        self::$providersCache = null;
    }
}
