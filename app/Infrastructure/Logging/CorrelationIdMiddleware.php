<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Adopts an inbound X-Correlation-Id header into {@see CorrelationIdService}
 * so that the request's logs, command/query/event payloads, and outbound
 * service calls all share the same correlation id. Echoes the resolved id
 * back on the response so downstream callers can see it.
 *
 * Format validation:
 *  - Length 8..128 chars
 *  - Only ASCII letters, digits, dashes, underscores, and dots
 *
 * Invalid headers are silently ignored (we generate our own id instead),
 * so a hostile client cannot poison aggregation by stuffing weird bytes.
 *
 * Apply globally in Config\Filters as a before-filter alias 'correlation_id'.
 */
final class CorrelationIdMiddleware implements FilterInterface
{
    public const string HEADER = 'X-Correlation-Id';

    private const int MIN_LEN = 8;
    private const int MAX_LEN = 128;

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     * @param RequestInterface $request
     * @param mixed            $arguments
     * @return RequestInterface
     */
    public function before(RequestInterface $request, mixed $arguments = null): RequestInterface
    {
        $inbound = $request->getHeaderLine(self::HEADER);

        if ($inbound !== '' && $this->isValidCorrelationId($inbound)) {
            CorrelationIdService::set($inbound);
            return $request;
        }

        // Force generation now so that the after-filter can echo the same id
        // back even if no log line is emitted during the request.
        CorrelationIdService::get();

        return $request;
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param mixed             $arguments
     * @return ResponseInterface
     */
    public function after(RequestInterface $request, ResponseInterface $response, mixed $arguments = null): ResponseInterface
    {
        $response->setHeader(self::HEADER, CorrelationIdService::get());

        // OBSERVABILITY: clear the per-request id AFTER echoing it back, so
        // the next iteration of a long-lived process (queue worker, CLI loop,
        // persistent FPM child) starts with a fresh slate. Without this, the
        // first request's id leaks into every subsequent request handled by
        // the same worker.
        CorrelationIdService::clear();

        return $response;
    }

    /**
     * isValidCorrelationId.
     *
     * @param string $value
     * @return bool
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function isValidCorrelationId(string $value): bool
    {
        $len = strlen($value);
        if ($len < self::MIN_LEN || $len > self::MAX_LEN) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1;
    }
}
