<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

/**
 * HandlerInterface.
 */
interface HandlerInterface
{
    /**
     * handle.
     *
     * @param object $message
     * @return mixed
     */
    public function handle(object $message): mixed;
}
