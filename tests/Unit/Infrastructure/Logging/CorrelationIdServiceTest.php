<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Logging;

use App\Infrastructure\Logging\CorrelationIdService;
use Tests\Support\UnitTestCase;

/**
 * Covers the static correlation-id service: generate, get (lazy-init),
 * set (manual override), clear (test reset), and the UUID v4 format.
 */
final class CorrelationIdServiceTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CorrelationIdService::clear();
    }

    protected function tearDown(): void
    {
        CorrelationIdService::clear();
        parent::tearDown();
    }

    public function test_generate_returns_uuid_v4_formatted_string(): void
    {
        $id = CorrelationIdService::generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }

    public function test_generate_returns_distinct_values_on_subsequent_calls(): void
    {
        $this->assertNotSame(
            CorrelationIdService::generate(),
            CorrelationIdService::generate(),
        );
    }

    public function test_get_lazy_initialises_id_on_first_call(): void
    {
        $first = CorrelationIdService::get();
        $second = CorrelationIdService::get();

        // Cached: same id within one request lifecycle.
        $this->assertSame($first, $second);
        $this->assertNotEmpty($first);
    }

    public function test_set_overrides_the_current_id(): void
    {
        CorrelationIdService::set('cf-incoming-1234');

        $this->assertSame('cf-incoming-1234', CorrelationIdService::get());
    }

    public function test_clear_resets_to_null_and_regenerates_on_next_get(): void
    {
        CorrelationIdService::set('original');
        CorrelationIdService::clear();

        $regenerated = CorrelationIdService::get();
        $this->assertNotSame('original', $regenerated);
        $this->assertNotEmpty($regenerated);
    }
}
