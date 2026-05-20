<?php

declare(strict_types=1);

namespace App\Infrastructure\I18n;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Resolves and applies the request locale (D8).
 *
 * Wires the LocaleResolver into the request lifecycle: runs before any
 * controller / view / handler that might emit translated strings so
 * lang() returns the right messages without callers having to know.
 *
 * If the request carried `?locale=xx`, the choice is persisted to the
 * session so the next request keeps the language without re-querying
 * Accept-Language.
 *
 * Register as filter alias 'locale' and apply globally in Config\Filters.
 */
final class LocaleMiddleware implements FilterInterface
{
    public function __construct(private readonly ?LocaleResolver $resolver = null)
    {
    }

    public function before(RequestInterface $request, mixed $arguments = null): RequestInterface
    {
        // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
        unset($arguments);

        $resolver = $this->resolver ?? Services::localeResolver();
        $locale = $resolver->resolve($request);

        // Persist explicit ?locale=xx choices to the session.
        if ($request instanceof IncomingRequest) {
            $queryLocale = $request->getGet('locale');
            if (is_string($queryLocale) && $queryLocale !== '') {
                $resolver->persistChoice($locale);
            }
            $request->setLocale($locale);
        }

        // Tell any subsequent service-fetched request to use the same locale.
        $serviceRequest = Services::request();
        if ($serviceRequest instanceof IncomingRequest) {
            $serviceRequest->setLocale($locale);
        }

        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, mixed $arguments = null): ResponseInterface
    {
        // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
        unset($request, $arguments);

        // Vary on Accept-Language so caching tiers can still serve per-locale.
        $existingVary = $response->getHeaderLine('Vary');
        $varyValue = $existingVary === '' ? 'Accept-Language' : $existingVary . ', Accept-Language';
        $response->setHeader('Vary', $varyValue);

        return $response;
    }
}
