<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Logging\LoggerFactory;
use App\Models\Cookie\CookieRepository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Base Test Case for Feature Tests.
 *
 * Feature tests:
 * - Test full HTTP request/response flows
 * - Test complete user journeys
 * - Use real database
 * - Test controllers, views, redirects
 *
 * @package Tests\Support
 */
abstract class FeatureTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

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
     * Assert that response was a redirect to a specific URL.
     *
     * @param string $url Expected redirect URL
     */
    protected function assertRedirectTo(string $url): void
    {
        $this->assertTrue(
            condition: $this->response->isRedirect(),
            message: 'Response is not a redirect'
        );

        $location = $this->response->getHeaderLine('Location');

        $this->assertEquals(
            expected: $url,
            actual: $location,
            message: sprintf('Expected redirect to "%s" but got "%s"', $url, $location)
        );
    }

    /**
     * Assert that a flash message was set.
     *
     * @param string $key Flash message key
     * @param string|null $expectedValue Expected message content (optional)
     */
    protected function assertFlashMessage(string $key, ?string $expectedValue = null): void
    {
        $session = session();
        $flashData = $session->getFlashdata($key);

        $this->assertNotNull(
            actual: $flashData,
            message: sprintf('Flash message with key "%s" was not set', $key)
        );

        if ($expectedValue === null) {
            return;
        }

        $this->assertStringContainsString(
            needle: $expectedValue,
            haystack: (string) $flashData,
            message: sprintf(
                'Flash message "%s" does not contain "%s"',
                $flashData,
                $expectedValue
            )
        );
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

    /**
     * Assert that validation errors exist.
     */
    protected function assertHasValidationErrors(): void
    {
        $session = session();
        $errors = $session->getFlashdata('errors');

        $this->assertNotNull(
            actual: $errors,
            message: 'No validation errors found in session'
        );

        $this->assertNotEmpty(
            actual: $errors,
            message: 'Validation errors array is empty'
        );
    }

    /**
     * Assert that a specific validation error exists.
     *
     * @param string $field Field name
     * @param string $expectedMessage Expected error message substring
     */
    protected function assertValidationError(string $field, string $expectedMessage): void
    {
        $session = session();
        $errors = $session->getFlashdata('errors');

        $this->assertNotNull(
            actual: $errors,
            message: 'No validation errors found in session'
        );

        $this->assertArrayHasKey(
            key: $field,
            array: $errors,
            message: sprintf('No validation error for field "%s"', $field)
        );

        $this->assertStringContainsString(
            needle: $expectedMessage,
            haystack: $errors[$field],
            message: sprintf(
                'Validation error for "%s" does not contain "%s"',
                $field,
                $expectedMessage
            )
        );
    }
}
