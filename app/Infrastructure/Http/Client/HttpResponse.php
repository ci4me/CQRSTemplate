<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Client;

/**
 * Immutable read-model for an outbound HTTP response (D18).
 *
 * Wraps just enough to make assertions trivial in tests:
 *   $response->isSuccessful() / isClientError() / isServerError()
 *   $response->json() decodes the body once; throws if it's not JSON.
 */
final readonly class HttpResponse
{
    /**
     * @param int                   $statusCode
     * @param string                $body
     * @param array<string, string> $headers    Lowercased header name -> first value.
     */
    public function __construct(
        public int $statusCode,
        public string $body,
        public array $headers
    ) {
    }

    /**
     * isSuccessful.
     *
     * @return bool
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * isClientError.
     *
     * @return bool
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * isServerError.
     *
     * @return bool
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500 && $this->statusCode < 600;
    }

    /**
     * header.
     *
     * @param string $name
     * @return string|null
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return mixed Decoded JSON; throws JsonException if the body is not JSON.
     */
    public function json(): mixed
    {
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }
}
