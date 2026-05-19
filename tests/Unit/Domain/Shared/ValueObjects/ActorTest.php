<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\ValueObjects;

use App\Domain\Shared\ValueObjects\Actor;
use Tests\Support\UnitTestCase;

final class ActorTest extends UnitTestCase
{
    public function test_user_factory_creates_actor_with_positive_id(): void
    {
        $actor = Actor::user(42);

        $this->assertSame(42, $actor->id);
        $this->assertSame('user:42', $actor->label);
        $this->assertFalse($actor->isSystem());
    }

    public function test_system_factory_returns_zero_id_actor(): void
    {
        $actor = Actor::system();

        $this->assertSame(Actor::SYSTEM_ID, $actor->id);
        $this->assertSame(0, $actor->id);
        $this->assertSame('system', $actor->label);
        $this->assertTrue($actor->isSystem());
    }

    public function test_system_factory_accepts_custom_label(): void
    {
        $actor = Actor::system('migration');

        $this->assertTrue($actor->isSystem());
        $this->assertSame('migration', $actor->label);
    }

    public function test_user_factory_rejects_zero_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be > 0');

        Actor::user(0);
    }

    public function test_user_factory_rejects_negative_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Actor::user(-1);
    }
}
