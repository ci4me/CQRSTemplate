<?php

declare(strict_types=1);

namespace App\Infrastructure\I18n;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\Session\Session;

/**
 * Resolves the active locale for the current request (D8).
 *
 * Resolution order, most specific to least:
 *  1. Explicit session value (`?locale=xx` is handled by a controller
 *     action that writes it here, so it survives the next request).
 *  2. `?locale=xx` query parameter — useful for one-off views.
 *  3. Authenticated user's stored preferred_locale (future column).
 *  4. `Accept-Language` header best match from the supported list.
 *  5. Configured default (`en`).
 *
 * The resolver normalises the result to a known supported code so the rest
 * of the application never has to worry about wild values from clients.
 */
final readonly class LocaleResolver
{
    /**
     * @param list<string> $supported ISO-639 codes (lowercase) we ship
     * @param string       $default
     *                                  translations for.
     */
    public function __construct(
        private array $supported = ['en'],
        private string $default = 'en'
    ) {
    }

    /**
     * resolve.
     *
     * @param RequestInterface $request
     * @return string
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function resolve(RequestInterface $request): string
    {
        $session = $this->session();

        $candidates = [
            $session?->get('locale'),
            $request instanceof IncomingRequest ? $request->getGet('locale') : null,
            $this->userPreferredLocale(),
            $this->fromAcceptLanguage($request),
            $this->default,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $matched = $this->matchSupported($candidate);
            if ($matched !== null) {
                return $matched;
            }
        }

        return $this->default;
    }

    /**
     * Persist the chosen locale in the session so subsequent requests
     * pick it up without re-resolving from the header.
     *
     * @param string $locale
     * @return bool
     */
    public function persistChoice(string $locale): bool
    {
        $matched = $this->matchSupported($locale);
        if ($matched === null) {
            return false;
        }

        $session = $this->session();
        $session?->set('locale', $matched);
        return true;
    }

    /**
     * @return list<string>
     */
    public function supported(): array
    {
        return $this->supported;
    }

    /**
     * default.
     *
     * @return string
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function default(): string
    {
        return $this->default;
    }

    /**
     * fromAcceptLanguage.
     *
     * @param RequestInterface $request
     * @return string|null
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function fromAcceptLanguage(RequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Accept-Language');
        if ($header === '') {
            return null;
        }

        $best = null;
        $bestQuality = 0.0;
        foreach (explode(',', $header) as $part) {
            $tokens = explode(';', trim($part));
            $tag = strtolower(trim($tokens[0]));
            $quality = 1.0;
            if (isset($tokens[1]) && str_starts_with(trim($tokens[1]), 'q=')) {
                $quality = (float) substr(trim($tokens[1]), 2);
            }
            $matched = $this->matchSupported($tag);
            if ($matched === null) {
                continue;
            }
            if ($quality <= $bestQuality) {
                continue;
            }
            $bestQuality = $quality;
            $best = $matched;
        }
        return $best;
    }

    /**
     * Future hook: users.preferred_locale. For now returns null and we
     * rely on session/header. When the column lands, look it up via
     * UserRepository here.
     *
     * @phpstan-ignore-next-line method return type kept ?string for future use
     * @return string|null
     */
    private function userPreferredLocale(): ?string
    {
        return null;
    }

    /**
     * Match an inbound tag against the supported list.
     *
     * Tries the exact tag (lowercased) first so a request for "pt-BR"
     * picks up our "pt-br" translations. If that fails, falls back to
     * the primary subtag, so "en-US" matches "en". Returns the matched
     * supported code or null when nothing fits.
     *
     * @param string $value
     * @return string|null
     */
    private function matchSupported(string $value): ?string
    {
        $full = strtolower(trim($value));
        if ($full === '') {
            return null;
        }

        if (in_array($full, $this->supported, true)) {
            return $full;
        }

        $primary = explode('-', $full)[0];
        if ($primary !== '' && in_array($primary, $this->supported, true)) {
            return $primary;
        }

        return null;
    }

    /**
     * session.
     *
     * @return Session|null
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function session(): ?Session
    {
        if (!function_exists('session')) {
            return null;
        }
        $session = session();
        return $session instanceof Session ? $session : null;
    }
}
