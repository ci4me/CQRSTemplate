<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie;

use App\Domain\Cookie\ErrorCodes;
use ReflectionClass;
use Tests\Support\UnitTestCase;

/**
 * Structural smoke tests for {@see ErrorCodes}.
 *
 * Each Cookie error code is documented as living inside one of five
 * numeric ranges (validation 100-199, not-found 200-299, business-rule
 * 300-399, state 400-499, repository 500-599). The codes are emitted into
 * structured log records and API problem-details, so a copy-paste typo
 * that pushes COOKIE_VALIDATION_FOO into the 200 range, or a duplicate
 * value, would corrupt downstream filtering silently.
 *
 * This test catches both via reflection — it does NOT need updating when
 * new constants are added, only when the *ranges* change.
 *
 * Closes slice 12/F2 + missing-4.
 */
final class ErrorCodesTest extends UnitTestCase
{
    /**
     * @var array<string, array{int, int}> prefix -> [min, max] (inclusive)
     */
    private const array RANGES = [
        'COOKIE_VALIDATION_' => [100, 199],
        'COOKIE_NOT_FOUND' => [200, 299],
        'COOKIE_NAME_NOT_UNIQUE' => [200, 299],
        'COOKIE_BUSINESS_RULE_' => [300, 399],
        'COOKIE_STATE_' => [400, 499],
        'COOKIE_REPOSITORY_' => [500, 599],
    ];

    public function test_every_constant_is_an_integer(): void
    {
        foreach ($this->allConstants() as $name => $value) {
            $this->assertIsInt(
                $value,
                sprintf('ErrorCodes::%s must be an int (got %s)', $name, get_debug_type($value))
            );
        }
    }

    public function test_no_duplicate_values(): void
    {
        $values = array_values($this->allConstants());
        $unique = array_unique($values);

        $this->assertCount(
            count($values),
            $unique,
            sprintf(
                'ErrorCodes has duplicate values: %s',
                json_encode(array_diff_assoc($values, $unique))
            )
        );
    }

    public function test_every_constant_falls_inside_its_documented_range(): void
    {
        foreach ($this->allConstants() as $name => $value) {
            $range = $this->rangeFor($name);
            $this->assertNotNull(
                $range,
                sprintf(
                    'ErrorCodes::%s has no documented numeric range — add a prefix mapping in CookieErrorCodesTest::RANGES',
                    $name
                )
            );
            [$min, $max] = $range;
            $this->assertGreaterThanOrEqual(
                $min,
                $value,
                sprintf('ErrorCodes::%s = %d must be >= %d', $name, $value, $min)
            );
            $this->assertLessThanOrEqual(
                $max,
                $value,
                sprintf('ErrorCodes::%s = %d must be <= %d', $name, $value, $max)
            );
        }
    }

    public function test_phase_2_e07_lifecycle_codes_are_present(): void
    {
        // E07 introduced state-error codes for the entity's new
        // lifecycle assertions (softDelete refuses unpersisted, restore
        // refuses already-active, etc.). Pin them so a future "tidy-up"
        // does not silently drop them.
        $this->assertSame(403, ErrorCodes::COOKIE_STATE_NOT_PERSISTED);
        $this->assertSame(404, ErrorCodes::COOKIE_STATE_NOT_DELETED);
        $this->assertSame(401, ErrorCodes::COOKIE_STATE_DELETED);
        $this->assertSame(402, ErrorCodes::COOKIE_STATE_CONCURRENT_MODIFICATION);
    }

    public function test_known_canonical_codes_are_stable(): void
    {
        // Stability pin: external consumers (logs, problem-details
        // bodies, API clients) treat these as a contract. Changing them
        // is a breaking change; flag it explicitly here.
        $this->assertSame(101, ErrorCodes::COOKIE_VALIDATION_NAME);
        $this->assertSame(102, ErrorCodes::COOKIE_VALIDATION_PRICE);
        $this->assertSame(103, ErrorCodes::COOKIE_VALIDATION_STOCK);
        $this->assertSame(201, ErrorCodes::COOKIE_NOT_FOUND);
        $this->assertSame(202, ErrorCodes::COOKIE_NAME_NOT_UNIQUE);
        $this->assertSame(301, ErrorCodes::COOKIE_BUSINESS_RULE_STOCK_NEGATIVE);
        $this->assertSame(302, ErrorCodes::COOKIE_BUSINESS_RULE_INACTIVE);
        $this->assertSame(303, ErrorCodes::COOKIE_BUSINESS_RULE_NAME_DUPLICATE);
        $this->assertSame(501, ErrorCodes::COOKIE_REPOSITORY_SAVE_FAILED);
        $this->assertSame(502, ErrorCodes::COOKIE_REPOSITORY_DELETE_FAILED);
        $this->assertSame(503, ErrorCodes::COOKIE_REPOSITORY_QUERY_FAILED);
    }

    /**
     * @return array<string, int>
     */
    private function allConstants(): array
    {
        $reflection = new ReflectionClass(ErrorCodes::class);
        /** @var array<string, int> $constants */
        $constants = $reflection->getConstants();
        return $constants;
    }

    /**
     * @return array{int, int}|null
     */
    private function rangeFor(string $constantName): ?array
    {
        foreach (self::RANGES as $prefix => $range) {
            if (str_starts_with($constantName, $prefix)) {
                return $range;
            }
        }
        return null;
    }
}
