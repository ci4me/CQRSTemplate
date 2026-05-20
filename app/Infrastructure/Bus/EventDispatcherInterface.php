<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

interface EventDispatcherInterface
{
    public function subscribe(string $eventClass, callable $listener): void;

    public function dispatch(object $event): void;
}
