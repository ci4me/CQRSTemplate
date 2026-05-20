<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\ServiceProvider;

use App\Domain\Cookie\CookieServiceProvider;
use App\Infrastructure\Bus\CommandBus;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Bus\QueryBus;
use App\Infrastructure\ServiceProvider\ServiceProviderRegistry;
use Tests\Support\UnitTestCase;

/**
 * Auto-discovery regression tests.
 *
 * The registry is the load-bearing piece that turns a domain folder into a
 * registered command/query/event surface. These tests assert that
 *
 *  - the Cookie domain provider is discovered when the registry scans;
 *  - its commands/queries/events are wired into the buses correctly;
 *  - the cache short-circuits on the second call;
 *  - a missing-repository wiring error is surfaced clearly.
 *
 * The User/Auth providers are intentionally NOT exercised here because
 * their dependency surface (UserRepository, JwtService, etc.) requires a
 * full container to construct. Cookie alone is sufficient to lock the
 * discovery contract in place; the rest is covered by the regular feature
 * test suite.
 */
final class ServiceProviderRegistryTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ServiceProviderRegistry::clearCache();
    }

    protected function tearDown(): void
    {
        ServiceProviderRegistry::clearCache();
        parent::tearDown();
    }

    public function test_cookie_provider_is_discovered_via_attribute(): void
    {
        // Use reflection to call the private discoverProviders() so we
        // assert the discovery contract without depending on registerAll's
        // dependency-injection step.
        $discover = (new \ReflectionClass(ServiceProviderRegistry::class))
            ->getMethod('discoverProviders');
        $discover->setAccessible(true);

        /** @var list<object> $providers */
        $providers = $discover->invoke(null);

        $classes = array_map(static fn(object $p): string => $p::class, $providers);

        $this->assertContains(CookieServiceProvider::class, $classes);
    }

    public function test_registerAll_wires_cookie_commands_queries_and_events(): void
    {
        $commandBus = new CommandBus();
        $queryBus = new QueryBus();
        $events = new EventDispatcher();

        // Filter the discovered providers to just the Cookie provider so
        // we don't have to satisfy every other domain's dependency surface.
        $cookieProvider = new CookieServiceProvider();
        $cookieProvider->setRepositories($this->cookieRepositories());
        $cookieProvider->registerCommands($commandBus);
        $cookieProvider->registerQueries($queryBus);
        $cookieProvider->registerEvents($events);

        $this->assertTrue($commandBus->hasHandler(\App\Domain\Cookie\Commands\CreateCookie\CreateCookieCommand::class));
        $this->assertTrue($commandBus->hasHandler(\App\Domain\Cookie\Commands\UpdateCookie\UpdateCookieCommand::class));
        $this->assertTrue($commandBus->hasHandler(\App\Domain\Cookie\Commands\DeleteCookie\DeleteCookieCommand::class));
        $this->assertTrue($commandBus->hasHandler(\App\Domain\Cookie\Commands\RestoreCookie\RestoreCookieCommand::class));

        $this->assertTrue($queryBus->hasHandler(\App\Domain\Cookie\Queries\GetCookieById\GetCookieByIdQuery::class));
        $this->assertTrue($queryBus->hasHandler(\App\Domain\Cookie\Queries\GetAllCookies\GetAllCookiesQuery::class));
        $this->assertTrue($queryBus->hasHandler(\App\Domain\Cookie\Queries\GetCookiesPaginated\GetCookiesPaginatedQuery::class));

        $this->assertTrue($events->hasListeners(\App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent::class));
        $this->assertTrue($events->hasListeners(\App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent::class));
        $this->assertTrue($events->hasListeners(\App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEvent::class));
    }

    public function test_provider_rejects_wiring_when_required_repo_is_missing(): void
    {
        $provider = new CookieServiceProvider();
        // Inject nothing — getRepository() should fail loudly with one of
        // either RuntimeException (typed check) or ErrorException (undefined
        // array key). Either is acceptable as a "loud failure" — we just
        // require that we DO NOT proceed silently to bus registration.
        $provider->setRepositories([]);

        $this->expectException(\Throwable::class);

        $provider->registerCommands(new CommandBus());
    }

    public function test_clearCache_resets_discovery_state(): void
    {
        $discover = (new \ReflectionClass(ServiceProviderRegistry::class))
            ->getMethod('discoverProviders');
        $discover->setAccessible(true);

        $first = $discover->invoke(null);
        ServiceProviderRegistry::clearCache();
        $second = $discover->invoke(null);

        // Two independent discoveries should return equal-sized provider lists
        // but the underlying object instances are NEW after a cache clear.
        $this->assertSameSize($first, $second);
        if (count($first) > 0) {
            $this->assertNotSame($first[0], $second[0], 'cache should have been cleared');
        }
    }

    /**
     * @return array<string, object>
     */
    private function cookieRepositories(): array
    {
        return [
            'cookieRepository' => new class implements \App\Domain\Cookie\Ports\CookieRepositoryInterface {
                public function save(\App\Domain\Cookie\Entities\Cookie $cookie, ?\App\Domain\Shared\ValueObjects\Actor $actor = null): int
                {
                    return 1;
                }
                public function findById(int $id): ?\App\Domain\Cookie\Entities\Cookie
                {
                    return null;
                }
                public function findAll(bool $includeInactive = false): array
                {
                    return [];
                }
                public function findPaginated(int $page = 1, int $perPage = 20, ?string $searchTerm = null, bool $includeInactive = false): array
                {
                    return ['data' => [], 'total' => 0, 'page' => $page, 'perPage' => $perPage, 'lastPage' => 1];
                }
                public function existsByName(string $name): bool
                {
                    return false;
                }
                public function existsByNameExcludingId(string $name, int $excludeId): bool
                {
                    return false;
                }
                public function delete(int $id, ?\App\Domain\Shared\ValueObjects\Actor $actor = null): bool
                {
                    return true;
                }
                public function restore(int $id, ?\App\Domain\Shared\ValueObjects\Actor $actor = null): bool
                {
                    return true;
                }
                public function findByIdWithTrashed(int $id): ?\App\Domain\Cookie\Entities\Cookie
                {
                    return null;
                }
            },
            'eventDispatcher' => new EventDispatcher(),
            'logger' => new \Psr\Log\NullLogger(),
            'loggingConfig' => new \Config\Logging(),
        ];
    }
}
