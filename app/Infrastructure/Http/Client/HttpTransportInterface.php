<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Client;

/**
 * Low-level transport contract (D18).
 *
 * Implementations are stateless and either:
 *  - {@see CurlHttpTransport} — production default, talks to the network
 *  - test doubles that return canned responses
 *
 * The OutboundHttpClient sits on top of this; retry/idempotency logic
 * belongs there, not here, so transports stay simple.
 */
interface HttpTransportInterface
{
    /**
     * @param string                $method
     * @param string                $url
     * @param array<string, string> $headers
     * @param string                $body
     * @param float                 $timeoutSeconds
     * @return HttpResponse
     */
    public function send(string $method, string $url, array $headers, string $body, float $timeoutSeconds): HttpResponse;
}
