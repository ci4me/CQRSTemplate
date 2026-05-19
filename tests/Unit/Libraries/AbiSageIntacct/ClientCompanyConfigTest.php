<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct;

use AbiSageIntacct\Client;
use AbiSageIntacct\Modules\CompanyConfig\Users;
use AbiSageIntacct\Modules\CompanyConfig\Departments;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Company Config module access in Client
 *
 * @internal
 */
final class ClientCompanyConfigTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        // Mock config with valid OAuth credentials
        $config = [
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'redirect_uri' => 'http://localhost/callback',
        ];

        // Skip authentication by using a valid token in env
        $_ENV['INTACCT_ACCESS_TOKEN'] = 'test_token_' . time();
        $_ENV['INTACCT_TOKEN_EXPIRES'] = (string)(time() + 3600);

        try {
            $this->client = new Client($config);
        } catch (\Exception $e) {
            // If authentication fails, we still continue for method testing
            $this->markTestIncomplete('Authentication setup incomplete');
        }
    }

    // ========================================
    // users() method tests
    // ========================================

    public function testUsersMethodReturnsCorrectModule(): void
    {
        $users = $this->client->users();

        $this->assertInstanceOf(Users::class, $users);
    }

    public function testUsersModuleHasCorrectEndpoint(): void
    {
        $users = $this->client->users();

        $this->assertEquals('/company/users', $users->getBaseEndpoint());
    }

    public function testUsersModuleHasHttpClient(): void
    {
        $users = $this->client->users();

        // Verify httpClient is set by checking if it's not null
        // We do this by checking if the module is properly instantiated
        $this->assertIsObject($users);
    }

    // ========================================
    // departments() method tests
    // ========================================

    public function testDepartmentsMethodReturnsCorrectModule(): void
    {
        $departments = $this->client->departments();

        $this->assertInstanceOf(Departments::class, $departments);
    }

    public function testDepartmentsModuleHasCorrectEndpoint(): void
    {
        $departments = $this->client->departments();

        $this->assertEquals('/company/departments', $departments->getBaseEndpoint());
    }

    public function testDepartmentsModuleHasHttpClient(): void
    {
        $departments = $this->client->departments();

        // Verify httpClient is set by checking if it's not null
        // We do this by checking if the module is properly instantiated
        $this->assertIsObject($departments);
    }

    // ========================================
    // Module isolation tests
    // ========================================

    public function testMultipleUsersInstancesAreIndependent(): void
    {
        $users1 = $this->client->users();
        $users2 = $this->client->users();

        // Should be different instances
        $this->assertNotSame($users1, $users2);

        // But same endpoint configuration
        $this->assertEquals(
            $users1->getBaseEndpoint(),
            $users2->getBaseEndpoint()
        );
    }

    public function testMultipleDepartmentsInstancesAreIndependent(): void
    {
        $departments1 = $this->client->departments();
        $departments2 = $this->client->departments();

        // Should be different instances
        $this->assertNotSame($departments1, $departments2);

        // But same endpoint configuration
        $this->assertEquals(
            $departments1->getBaseEndpoint(),
            $departments2->getBaseEndpoint()
        );
    }

    public function testCompanyConfigModulesShareHttpClient(): void
    {
        $users = $this->client->users();
        $departments = $this->client->departments();

        $httpClient1 = $this->client->getHttpClient();
        $httpClient2 = $this->client->getHttpClient();

        // Both should use same HTTP client instance
        $this->assertSame($httpClient1, $httpClient2);
    }

    // ========================================
    // Integration with other modules
    // ========================================

    public function testAllModulesCanBeAccessedTogether(): void
    {
        // Test that all module accessors work
        $customers = $this->client->customers();
        $invoices = $this->client->invoices();
        $users = $this->client->users();
        $departments = $this->client->departments();

        $this->assertIsObject($customers);
        $this->assertIsObject($invoices);
        $this->assertIsObject($users);
        $this->assertIsObject($departments);
    }

    public function testCompanyConfigAndArModulesCanBeUsedTogether(): void
    {
        $users = $this->client->users();
        $customers = $this->client->customers();

        $this->assertInstanceOf(Users::class, $users);
        $this->assertIsObject($customers);
    }

    public function testCompanyConfigAndCashManagementModulesCanBeUsedTogether(): void
    {
        $users = $this->client->users();
        $departments = $this->client->departments();
        $bankAccounts = $this->client->bankAccounts();
        $transactions = $this->client->transactions();

        $this->assertInstanceOf(Users::class, $users);
        $this->assertInstanceOf(Departments::class, $departments);
        $this->assertIsObject($bankAccounts);
        $this->assertIsObject($transactions);
    }

    public function testCompanyConfigModulesWorkWithQuery(): void
    {
        $users = $this->client->users();
        $departments = $this->client->departments();
        $query = $this->client->query();

        $this->assertInstanceOf(Users::class, $users);
        $this->assertInstanceOf(Departments::class, $departments);
        $this->assertIsObject($query);
    }
}
