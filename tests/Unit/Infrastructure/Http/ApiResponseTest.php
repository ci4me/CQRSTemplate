<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Http;

use App\Infrastructure\Http\ApiResponse;
use App\Infrastructure\Logging\CorrelationIdService;
use CodeIgniter\Test\CIUnitTestCase;

final class ApiResponseTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CorrelationIdService::clear();
        CorrelationIdService::set('test-corr');
    }

    protected function tearDown(): void
    {
        CorrelationIdService::clear();
        parent::tearDown();
    }

    public function test_ok_returns_data_with_correlation_id_in_meta(): void
    {
        $response = ApiResponse::ok(['hello' => 'world']);

        $this->assertSame(200, $response->getStatusCode());
        $body = (array) json_decode((string) $response->getBody(), true);
        $this->assertSame(['hello' => 'world'], $body['data']);
        $this->assertSame('test-corr', $body['meta']['correlation_id']);
    }

    public function test_created_returns_201_and_optional_location(): void
    {
        $response = ApiResponse::created(['id' => 7], '/cookies/7');

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('/cookies/7', $response->getHeaderLine('Location'));
    }

    public function test_paginated_includes_pagination_meta(): void
    {
        $response = ApiResponse::paginated(
            data: [['id' => 1], ['id' => 2]],
            page: 2,
            perPage: 20,
            total: 47,
            lastPage: 3
        );

        $body = (array) json_decode((string) $response->getBody(), true);
        $this->assertCount(2, $body['data']);
        $this->assertSame([
            'page' => 2,
            'per_page' => 20,
            'total' => 47,
            'last_page' => 3,
        ], $body['meta']['pagination']);
    }

    public function test_no_content_returns_204_with_empty_body(): void
    {
        $response = ApiResponse::noContent();

        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_problem_response_is_rfc7807_shape(): void
    {
        $response = ApiResponse::problem(400, 'Bad Request', 'malformed input');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));
        $body = (array) json_decode((string) $response->getBody(), true);
        $this->assertSame('about:blank', $body['type']);
        $this->assertSame('Bad Request', $body['title']);
        $this->assertSame(400, $body['status']);
        $this->assertSame('malformed input', $body['detail']);
        $this->assertSame('test-corr', $body['correlation_id']);
    }

    public function test_validation_failed_includes_errors_map(): void
    {
        $response = ApiResponse::validationFailed(['email' => 'must be a valid email']);

        $this->assertSame(422, $response->getStatusCode());
        $body = (array) json_decode((string) $response->getBody(), true);
        $this->assertSame('Validation failed', $body['title']);
        $this->assertSame('must be a valid email', $body['errors']['email']);
    }

    public function test_not_found_returns_404(): void
    {
        $response = ApiResponse::notFound('cookie 999 does not exist');

        $this->assertSame(404, $response->getStatusCode());
        $body = (array) json_decode((string) $response->getBody(), true);
        $this->assertSame('Not Found', $body['title']);
    }

    public function test_conflict_returns_409(): void
    {
        $response = ApiResponse::conflict('row modified by someone else');

        $this->assertSame(409, $response->getStatusCode());
    }
}
