<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie;

use App\Domain\Cookie\CookieServiceProvider;
use App\Infrastructure\Bus\CommandBus;
use App\Infrastructure\Bus\QueryBus;
use App\Infrastructure\Logging\LoggerFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for the bouncer guard in CookieServiceProvider.
 *
 * The provider re-checks the injected repositories with instanceof and
 * throws if the registry handed it the wrong type. These tests pin
 * that guard so a future refactor can't silently relax it.
 */
#[AllowMockObjectsWithoutExpectations]
final class CookieServiceProviderTest extends UnitTestCase
{
    public function test_register_commands_rejects_wrong_repository_type(): void
    {
        $provider = new CookieServiceProvider();
        // 'cookieRepository' is set to a stdClass — fails the instanceof.
        // E08 added 'clock' to the required repository set.
        $provider->setRepositories([
            'cookieRepository' => new \stdClass(),
            'eventDispatcher' => new \stdClass(),
            'logger' => new \stdClass(),
            'loggingConfig' => new \stdClass(),
            'clock' => new \stdClass(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid repository, event dispatcher, logger or clock type');

        $provider->registerCommands(new CommandBus());
    }

    public function test_register_queries_rejects_wrong_repository_type(): void
    {
        $provider = new CookieServiceProvider();
        // E08 added 'clock' + 'logSampler' to the required query repository set.
        $provider->setRepositories([
            'cookieQueryRepository' => new \stdClass(),
            'logger' => new \stdClass(),
            'loggingConfig' => new \stdClass(),
            'clock' => new \stdClass(),
            'logSampler' => new \stdClass(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid repository, logger, logging config, clock or sampler type');

        $provider->registerQueries(new QueryBus());
    }

    public function test_get_repositories_returns_documented_dependencies(): void
    {
        $provider = new CookieServiceProvider();

        $deps = $provider->getRepositories();

        $this->assertContains('cookieRepository', $deps);
        $this->assertContains('cookieQueryRepository', $deps);
        $this->assertContains('eventDispatcher', $deps);
        $this->assertContains('logger', $deps);
        $this->assertContains('loggingConfig', $deps);
        $this->assertContains('clock', $deps);
        $this->assertContains('logSampler', $deps);
    }

    public function test_register_events_does_not_throw_with_default_dispatcher(): void
    {
        $provider = new CookieServiceProvider();
        $dispatcher = new \App\Infrastructure\Bus\EventDispatcher(
            LoggerFactory::create('test.cookie.events')
        );

        $provider->registerEvents($dispatcher);

        // Five Cookie events should now be subscribed.
        $this->assertTrue($dispatcher->hasListeners(
            \App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent::class
        ));
        $this->assertTrue($dispatcher->hasListeners(
            \App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent::class
        ));
        $this->assertTrue($dispatcher->hasListeners(
            \App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEvent::class
        ));
        $this->assertTrue($dispatcher->hasListeners(
            \App\Domain\Cookie\Events\CookieStockChanged\CookieStockChangedEvent::class
        ));
        $this->assertTrue($dispatcher->hasListeners(
            \App\Domain\Cookie\Events\CookieRestored\CookieRestoredEvent::class
        ));
    }
}
