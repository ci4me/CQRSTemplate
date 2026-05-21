<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\ServiceProvider\ServiceProviderRegistry;
use Tests\Support\UnitTestCase;

/**
 * Smoke test for Phase 3 Group B's #[AutoBind] auto-discovery.
 *
 * Confirms that the four concrete repositories tagged with the attribute
 * are discovered, instantiated with their dependencies resolved, and
 * exposed under the expected lower-camelCase short names.
 *
 * @internal Phase 3 verification — keep this around so a future
 *           regression that removes the attribute or breaks the
 *           scanner fails loudly.
 */
final class AutoBindSmokeTest extends UnitTestCase
{
    public function test_discoverRepositories_returns_all_attribute_tagged_classes(): void
    {
        ServiceProviderRegistry::clearCache();
        $repositories = ServiceProviderRegistry::discoverRepositories();

        $this->assertArrayHasKey('cookieRepository', $repositories);
        $this->assertArrayHasKey('cookieQueryRepository', $repositories);
        $this->assertArrayHasKey('userRepository', $repositories);
        $this->assertArrayHasKey('passwordHistoryRepository', $repositories);

        $this->assertInstanceOf(
            \App\Domain\Cookie\Repositories\CookieRepository::class,
            $repositories['cookieRepository']
        );
        $this->assertInstanceOf(
            \App\Domain\Cookie\Repositories\CookieQueryRepository::class,
            $repositories['cookieQueryRepository']
        );
        $this->assertInstanceOf(
            \App\Domain\User\Repositories\UserRepository::class,
            $repositories['userRepository']
        );
        $this->assertInstanceOf(
            \App\Domain\User\Repositories\PasswordHistoryRepository::class,
            $repositories['passwordHistoryRepository']
        );
    }

    public function test_discoverRepositories_is_cached(): void
    {
        ServiceProviderRegistry::clearCache();
        $first = ServiceProviderRegistry::discoverRepositories();
        $second = ServiceProviderRegistry::discoverRepositories();

        $this->assertSame($first, $second, 'AutoBind discovery should be cached per-process');
    }
}
