<?php

declare(strict_types=1);

namespace Tests\Integration\Events;

/**
 * Object-reference counter used by ProcessedEventStoreFlowTest to keep
 * PHPStan from narrowing the by-ref `$attempts` scalar across multiple
 * dispatch calls.
 */
final class FlowCounter
{
    private int $value = 0;

    public function increment(): void
    {
        $this->value++;
    }

    public function value(): int
    {
        return $this->value;
    }
}
