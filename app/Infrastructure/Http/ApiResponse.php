<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Infrastructure\Logging\CorrelationIdService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Standard JSON envelope for every API response (D14).
 *
 * Success shape:
 * {
 *     "data": <payload>,
 *     "meta": {
 *         "correlation_id": "uuid-v4"
 *     }
 * }
 *
 * Paginated shape:
 * {
 *     "data": [...],
 *     "meta": {
 *         "correlation_id": "...",
 *         "pagination": {
 *             "page": 1,
 *             "per_page": 20,
 *             "total": 47,
 *             "last_page": 3
 *         }
 *     }
 * }
 *
 * Error shape (RFC 7807 problem+json):
 * {
 *     "type": "about:blank",
 *     "title": "Validation failed",
 *     "status": 422,
 *     "detail": "...",
 *     "errors": {"email": "must be a valid email"},
 *     "correlation_id": "..."
 * }
 *
 * Controllers should never hand-craft JSON; route everything through this
 * helper so the envelope stays consistent across endpoints.
 *
 * Migration status (round-2 review r10): the User API controller has
 * been migrated (p4-batch14). All API responses now carry the
 * `{data, meta.correlation_id}` envelope on success and the RFC 7807
 * `{type, title, status, detail, errors, correlation_id}` shape on
 * failure. Clients reading the previous `{success, data, message}`
 * shape need to switch — this is documented as a BREAKING change.
 */
final class ApiResponse
{
    /**
     * Success response with optional HTTP status (defaults to 200).
     *
     */
    public static function ok(mixed $data = null, int $status = 200): ResponseInterface
    {
        return self::respond([
            'data' => $data,
            'meta' => [
                'correlation_id' => CorrelationIdService::get(),
            ],
        ], $status);
    }

    /**
     * Created response (201) for a newly persisted resource.
     *
     */
    public static function created(mixed $data, ?string $location = null): ResponseInterface
    {
        $response = self::ok($data, 201);
        if ($location !== null) {
            $response->setHeader('Location', $location);
        }
        return $response;
    }

    /**
     * Paginated success.
     *
     * @param list<mixed> $data
     */
    public static function paginated(
        array $data,
        int $page,
        int $perPage,
        int $total,
        int $lastPage
    ): ResponseInterface {
        return self::respond([
            'data' => $data,
            'meta' => [
                'correlation_id' => CorrelationIdService::get(),
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => $lastPage,
                ],
            ],
        ], 200);
    }

    /**
     * Empty 204 No Content.
     */
    public static function noContent(): ResponseInterface
    {
        return Services::response()->setStatusCode(204);
    }

    /**
     * RFC 7807 problem+json error.
     *
     * @param array<string, mixed> $errors  Optional per-field validation errors
     */
    public static function problem(
        int $status,
        string $title,
        string $detail = '',
        array $errors = [],
        string $type = 'about:blank'
    ): ResponseInterface {
        $body = [
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'correlation_id' => CorrelationIdService::get(),
        ];

        if ($detail !== '') {
            $body['detail'] = $detail;
        }

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        // Order matters: setJSON sets Content-Type to application/json, so
        // setContentType MUST come after setJSON to win.
        return Services::response()
            ->setStatusCode($status)
            ->setJSON($body)
            ->setContentType('application/problem+json');
    }

    /**
     * 422 validation error helper.
     *
     * @param array<string, mixed> $errors
     */
    public static function validationFailed(array $errors, string $detail = ''): ResponseInterface
    {
        return self::problem(422, 'Validation failed', $detail, $errors);
    }

    /**
     * 404 not-found helper.
     */
    public static function notFound(string $detail = ''): ResponseInterface
    {
        return self::problem(404, 'Not Found', $detail);
    }

    /**
     * 409 conflict helper (e.g. optimistic-locking, duplicate).
     */
    public static function conflict(string $detail = ''): ResponseInterface
    {
        return self::problem(409, 'Conflict', $detail);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function respond(array $body, int $status): ResponseInterface
    {
        return Services::response()
            ->setStatusCode($status)
            ->setContentType('application/json')
            ->setJSON($body);
    }
}
