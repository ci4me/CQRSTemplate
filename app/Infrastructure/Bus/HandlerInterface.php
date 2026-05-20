<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

interface HandlerInterface
{
    public function handle(object $message): mixed;
}
