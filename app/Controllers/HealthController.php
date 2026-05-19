<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Infrastructure\Logging\CorrelationIdService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * Operational health endpoint.
 *
 * GET /health
 *
 * Returns a small JSON document describing whether the application's
 * dependencies are reachable. Designed for:
 *  - Load balancer / reverse proxy probes (HTTP 200 = take traffic)
 *  - Container orchestrator readiness checks
 *  - Uptime monitors
 *
 * The endpoint deliberately does NOT require authentication so probes can
 * hit it without credentials. It also does NOT leak internal details
 * (versions, hostnames, env contents) — only check names and status flags.
 *
 * Status codes:
 *  - 200 every dependency is healthy
 *  - 503 at least one dependency is degraded (still reports JSON so a
 *        monitor can show which)
 */
final class HealthController extends BaseController
{
    public function index(): ResponseInterface
    {
        $checks = [
            'database' => $this->checkDatabase(),
        ];

        $allOk = true;
        foreach ($checks as $check) {
            if ($check['status'] !== 'ok') {
                $allOk = false;
                break;
            }
        }

        $payload = [
            'status' => $allOk ? 'ok' : 'degraded',
            'correlation_id' => CorrelationIdService::get(),
            'checks' => $checks,
            'time' => date(DATE_ATOM),
        ];

        return $this->response
            ->setStatusCode($allOk ? 200 : 503)
            ->setJSON($payload);
    }

    /**
     * @return array{status: string, message?: string}
     */
    private function checkDatabase(): array
    {
        try {
            $db = Database::connect();
            // A trivial query that exercises the connection without depending
            // on any specific schema/table being present.
            $db->query('SELECT 1');
            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            return [
                'status' => 'fail',
                'message' => 'database unreachable',
            ];
        }
    }
}
