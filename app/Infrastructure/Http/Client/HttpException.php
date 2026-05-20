<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Client;

/**
 * Thrown when an outbound HTTP call fails after all retries (D18).
 *
 * Carries the last response (if any) so the caller can introspect status
 * or body for problem+json content.
 */
final class HttpException extends \RuntimeException
{
    /**
     * __construct.
     *
     * @param string            $message
     * @param HttpResponse|null $lastResponse
     * @param int               $attempts
     * @param \Throwable|null   $previous
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function __construct(
        string $message,
        public readonly ?HttpResponse $lastResponse = null,
        public readonly int $attempts = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, previous: $previous);
    }
}
