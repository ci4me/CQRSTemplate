<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Auth\Services;

use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\User\Entities\User;
use App\Infrastructure\Auth\Services\ActorResolver;
use CodeIgniter\HTTP\RequestInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\Factories\UserFactory;
use Tests\Support\UnitTestCase;

#[AllowMockObjectsWithoutExpectations]
final class ActorResolverTest extends UnitTestCase
{
    public function test_resolves_user_when_request_has_authenticated_user(): void
    {
        $resolver = new ActorResolver();
        $request = $this->createMock(RequestInterface::class);
        /** @phpstan-ignore-next-line dynamic property mirrors auth middleware */
        $request->user = UserFactory::createPersistedUser(['id' => 7]);

        $actor = $resolver->resolve($request);

        $this->assertInstanceOf(Actor::class, $actor);
        $this->assertSame(7, $actor->id);
        $this->assertFalse($actor->isSystem());
    }

    public function test_resolves_system_when_no_request_passed(): void
    {
        $resolver = new ActorResolver();

        $actor = $resolver->resolve(null);

        $this->assertTrue($actor->isSystem());
    }

    public function test_resolves_system_when_request_has_no_user(): void
    {
        $resolver = new ActorResolver();
        $request = $this->createMock(RequestInterface::class);

        $actor = $resolver->resolve($request);

        $this->assertTrue($actor->isSystem());
    }

    public function test_ignores_non_user_object_on_request(): void
    {
        $resolver = new ActorResolver();
        $request = $this->createMock(RequestInterface::class);
        /** @phpstan-ignore-next-line dynamic property test */
        $request->user = 'not-a-user';

        $actor = $resolver->resolve($request);

        $this->assertTrue($actor->isSystem());
    }

    public function test_ignores_user_with_null_id(): void
    {
        // A freshly created User (not yet persisted) has null id; resolver must skip it
        $resolver = new ActorResolver();
        $request = $this->createMock(RequestInterface::class);
        /** @phpstan-ignore-next-line dynamic property test */
        $request->user = $this->stubUserWithoutId();

        $actor = $resolver->resolve($request);

        $this->assertTrue($actor->isSystem());
    }

    public function test_resolve_or_system_returns_system_actor(): void
    {
        $resolver = new ActorResolver();

        $actor = $resolver->resolveOrSystem('cron');

        $this->assertTrue($actor->isSystem());
        $this->assertSame('cron', $actor->label);
    }

    private function stubUserWithoutId(): User
    {
        return UserFactory::createUser(); // create() leaves id null
    }
}
