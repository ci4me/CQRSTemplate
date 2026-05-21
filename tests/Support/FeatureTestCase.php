<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Cookie\Entities\Cookie;
use App\Infrastructure\Persistence\Models\UserModel;
use App\Infrastructure\Persistence\Repositories\CookieRepository;
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
     * Set to false in a child class to test routes as an anonymous visitor.
     */
    protected bool $authenticateByDefault = true;

    /**
     * Setup before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetServices();
        \Config\Services::resetProviders();

        // Simulate authenticated session for feature test requests
        $this->withSession([
            'logged_in' => true,
            'user_id' => 1,
            'role' => 'admin',
        ]);

        // Use the Services-wired repository so writes carry the tenant
        // stamp that Services-wired query repository filters on. Building
        // a bare CookieRepository here used to be safe because the read
        // side ran through a projection that stamped a default tenant_id
        // regardless of the source row; Phase 2 collapsed that projection
        // into the canonical `cookies` table, so the read and write paths
        // now share the same physical row and must agree on tenant_id.
        $this->cookieRepository = \Config\Services::cookieRepository();

        if (!$this->authenticateByDefault) {
            return;
        }

        $this->loginAsAdmin();
    }

    /**
     * Seed an active admin user and authenticate the test session as that user.
     *
     * Uses the framework's MockSession (ArrayHandler) so that
     * {@see \App\Infrastructure\Auth\Middleware\SessionAuthMiddleware} sees
     * the values via the standard `session()` helper during feature requests.
     */
    protected function loginAsAdmin(): int
    {
        $userId = $this->seedActiveAdminUser();

        $this->mockSession();
        $session = session();
        $session->set('user_id', $userId);
        $session->set('email', 'feature-test-admin@example.test');
        $session->set('role', 'admin');
        $session->set('logged_in', true);

        $this->session = [
            'user_id' => $userId,
            'email' => 'feature-test-admin@example.test',
            'role' => 'admin',
            'logged_in' => true,
        ];

        return $userId;
    }

    private function seedActiveAdminUser(): int
    {
        $model = new UserModel();
        $now = date('Y-m-d H:i:s');

        $id = $model->insert([
            'name' => 'Feature Test Admin',
            'email' => 'feature-test-admin@example.test',
            'password_hash' => '$argon2id$v=19$m=65536,t=4,p=1$Zm9v$' . str_repeat('a', 43),
            'role' => 'admin',
            'status' => 'active',
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ], true);

        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            throw new \RuntimeException('Failed to seed test admin user');
        }

        return (int) $id;
    }

    /**
     * Clean up after each test.
     */
    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Persist a Cookie via the write side so a subsequent GET /cookies/:id
     * can read it.
     *
     * Phase 2 of the stabilization refactor collapsed Cookie's read model
     * into the canonical `cookies` table, so the read side now sees a write
     * the moment the repository commits — no projection step is needed.
     * The method name is kept for backwards compatibility with feature tests
     * that already call it.
     */
    protected function saveCookieAndProject(Cookie $cookie): int
    {
        return $this->cookieRepository->save($cookie);
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
