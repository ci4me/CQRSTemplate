<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Auth;

use App\Infrastructure\Auth\AuthContext;
use Tests\Support\UnitTestCase;

/**
 * Pins AuthContext's tiny static surface: the middlewares (Session / JWT)
 * stash the authenticated user id here so command handlers downstream can
 * read it without changing every command signature. Static state is
 * dangerous — these tests make sure the getter falls back cleanly when no
 * one set it and that reset() actually clears.
 */
final class AuthContextTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AuthContext::reset();
    }

    protected function tearDown(): void
    {
        AuthContext::reset();
        parent::tearDown();
    }

    public function test_unset_returns_zero_fallback(): void
    {
        $this->assertSame(0, AuthContext::getCurrentUserId());
    }

    public function test_set_and_get_round_trip(): void
    {
        AuthContext::setCurrentUserId(42);
        $this->assertSame(42, AuthContext::getCurrentUserId());
    }

    public function test_set_null_falls_back_to_zero(): void
    {
        AuthContext::setCurrentUserId(7);
        AuthContext::setCurrentUserId(null);
        $this->assertSame(0, AuthContext::getCurrentUserId());
    }

    public function test_reset_clears_the_current_user_id(): void
    {
        AuthContext::setCurrentUserId(99);
        AuthContext::reset();
        $this->assertSame(0, AuthContext::getCurrentUserId());
    }
}
