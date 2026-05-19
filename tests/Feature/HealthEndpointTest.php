<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\FeatureTestCase;

final class HealthEndpointTest extends FeatureTestCase
{
    protected bool $authenticateByDefault = false;

    public function test_health_endpoint_returns_ok_when_database_reachable(): void
    {
        $result = $this->get('/health');

        $result->assertOK();
        $result->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        $json = (array) json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('ok', $json['status']);
        $this->assertSame('ok', $json['checks']['database']['status']);
        $this->assertArrayHasKey('correlation_id', $json);
        $this->assertArrayHasKey('time', $json);
    }

    public function test_health_endpoint_does_not_require_authentication(): void
    {
        // Explicitly anonymous: $authenticateByDefault = false above; verify
        // no redirect to /auth/login.
        $result = $this->get('/health');

        $result->assertOK();
        $this->assertSame('', $result->response()->getHeaderLine('Location'));
    }
}
