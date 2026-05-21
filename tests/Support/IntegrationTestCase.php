<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Cookie\Repositories\CookieRepository;
use App\Infrastructure\Logging\LoggerFactory;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Base Test Case for Integration Tests.
 *
 * Integration tests:
 * - Test interactions between components (e.g., Repository + Database)
 * - Use real database
 * - Test repository methods
 * - Do NOT test HTTP layer
 *
 * @package Tests\Support
 */
abstract class IntegrationTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;

    /**
     * Should the database be migrated before each test?
     *
     * @var bool
     */
    protected $migrate = true;

    /**
     * Should the database be refreshed (dropped and recreated) before each test?
     *
     * @var bool
     */
    protected $refresh = true;

    /**
     * The namespace to use for migrations.
     *
     * @var string|null
     */
    protected $namespace = null;

    protected CookieRepository $cookieRepository;

    /**
     * Setup before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetServices();
        \Config\Services::resetProviders();

        // Create repository with dependencies
        $logger = LoggerFactory::create('test.cookie.repository');
        $loggingConfig = config('Logging');
        $this->cookieRepository = new CookieRepository($logger, $loggingConfig);
    }

    /**
     * Clean up after each test.
     */
    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Assert that a record exists in the database.
     *
     * @param string $table Table name
     * @param array<string, mixed> $criteria Where conditions
     */
    protected function assertDatabaseHas(string $table, array $criteria): void
    {
        $this->seeInDatabase($table, $criteria);
    }

    /**
     * Assert that a record does NOT exist in the database.
     *
     * @param string $table Table name
     * @param array<string, mixed> $criteria Where conditions
     */
    protected function assertDatabaseMissing(string $table, array $criteria): void
    {
        $this->dontSeeInDatabase($table, $criteria);
    }
}
