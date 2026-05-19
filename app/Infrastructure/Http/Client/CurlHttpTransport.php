<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Client;

/**
 * cURL-backed implementation of HttpTransportInterface (D18).
 *
 * Production default. Returns an HttpResponse on a complete round-trip,
 * regardless of status code; throws HttpException only for network-level
 * failures (DNS, connection refused, TLS errors) so the retry policy in
 * OutboundHttpClient can decide whether to back off.
 */
final class CurlHttpTransport implements HttpTransportInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, string $body, float $timeoutSeconds): HttpResponse
    {
        if ($url === '') {
            throw new HttpException('Outbound URL must not be empty.');
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new HttpException('Failed to initialise cURL handle.');
        }

        $upperMethod = strtoupper($method);
        if ($upperMethod === '') {
            throw new HttpException('Outbound HTTP method must not be empty.');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $upperMethod);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, (int) round($timeoutSeconds * 1000));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, (int) round(min($timeoutSeconds, 10.0) * 1000));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->formatHeaders($headers));
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $raw = curl_exec($ch);
        if ($raw === false || $raw === true) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new HttpException(sprintf('cURL error: %s', $error));
        }

        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerLen = $headerSize;

        return new HttpResponse(
            statusCode: $status,
            body: substr($raw, $headerLen),
            headers: $this->parseHeaders(substr($raw, 0, $headerLen))
        );
    }

    /**
     * @param array<string, string> $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }
        return $formatted;
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $raw): array
    {
        $parsed = [];
        $lines = preg_split('/\r?\n/', $raw);
        if ($lines === false) {
            return $parsed;
        }
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            $parsed[$name] = $value;
        }
        return $parsed;
    }
}
