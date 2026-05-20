<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

/**
 * HandlerInterface.
 *
 * @todo Auto-generated docblock — review and replace this description.
 */
interface HandlerInterface
{
    /**
     * handle.
     *
     * @param object $message
     * @return mixed
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function handle(object $message): mixed;
}
