<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Http\Client;

use App\Infrastructure\Http\Client\HttpResponse;
use Tests\Support\UnitTestCase;

final class HttpResponseTest extends UnitTestCase
{
    public function test_status_classifiers_2xx_3xx_4xx_5xx(): void
    {
        $this->assertTrue((new HttpResponse(200, '', []))->isSuccessful());
        $this->assertTrue((new HttpResponse(204, '', []))->isSuccessful());
        $this->assertFalse((new HttpResponse(301, '', []))->isSuccessful());
        $this->assertFalse((new HttpResponse(404, '', []))->isSuccessful());

        $this->assertTrue((new HttpResponse(400, '', []))->isClientError());
        $this->assertTrue((new HttpResponse(422, '', []))->isClientError());
        $this->assertFalse((new HttpResponse(500, '', []))->isClientError());

        $this->assertTrue((new HttpResponse(500, '', []))->isServerError());
        $this->assertTrue((new HttpResponse(503, '', []))->isServerError());
        $this->assertFalse((new HttpResponse(200, '', []))->isServerError());
    }

    public function test_header_lookup_is_case_insensitive(): void
    {
        $response = new HttpResponse(200, '', ['content-type' => 'application/json']);

        $this->assertSame('application/json', $response->header('Content-Type'));
        $this->assertSame('application/json', $response->header('CONTENT-TYPE'));
        $this->assertNull($response->header('Authorization'));
    }

    public function test_json_decodes_body_into_array(): void
    {
        $response = new HttpResponse(200, '{"id":7,"name":"Alice"}', []);

        $payload = $response->json();

        $this->assertSame(['id' => 7, 'name' => 'Alice'], $payload);
    }

    public function test_json_throws_for_non_json_body(): void
    {
        $response = new HttpResponse(200, 'not-json', []);

        $this->expectException(\JsonException::class);
        $response->json();
    }
}
