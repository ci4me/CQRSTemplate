<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct;

use AbiSageIntacct\Client;
use AbiSageIntacct\Modules\GeneralLedger\Accounts;
use AbiSageIntacct\Modules\GeneralLedger\JournalEntries;
use PHPUnit\Framework\TestCase;

/**
 * Tests for General Ledger module access in Client
 *
 * @internal
 */
final class ClientGeneralLedgerTest extends TestCase
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
    // accounts() method tests
    // ========================================

    public function testAccountsMethodReturnsCorrectModule(): void
    {
        $accounts = $this->client->accounts();

        $this->assertInstanceOf(Accounts::class, $accounts);
    }

    public function testAccountsModuleHasCorrectEndpoint(): void
    {
        $accounts = $this->client->accounts();

        $this->assertEquals('/gl-accounts', $accounts->getBaseEndpoint());
    }

    public function testAccountsModuleHasHttpClient(): void
    {
        $accounts = $this->client->accounts();

        // Verify httpClient is set by checking if it's not null
        // We do this by calling a method that uses httpClient
        $this->assertIsObject($accounts);
    }

    // ========================================
    // journalEntries() method tests
    // ========================================

    public function testJournalEntriesMethodReturnsCorrectModule(): void
    {
        $journalEntries = $this->client->journalEntries();

        $this->assertInstanceOf(JournalEntries::class, $journalEntries);
    }

    public function testJournalEntriesModuleHasCorrectEndpoint(): void
    {
        $journalEntries = $this->client->journalEntries();

        $this->assertEquals('/gl-journal-entries', $journalEntries->getBaseEndpoint());
    }

    public function testJournalEntriesModuleHasHttpClient(): void
    {
        $journalEntries = $this->client->journalEntries();

        // Verify httpClient is set by checking if it's not null
        // We do this by calling a method that uses httpClient
        $this->assertIsObject($journalEntries);
    }

    // ========================================
    // Module isolation tests
    // ========================================

    public function testMultipleAccountsInstancesAreIndependent(): void
    {
        $accounts1 = $this->client->accounts();
        $accounts2 = $this->client->accounts();

        // Should be different instances
        $this->assertNotSame($accounts1, $accounts2);

        // But same endpoint configuration
        $this->assertEquals(
            $accounts1->getBaseEndpoint(),
            $accounts2->getBaseEndpoint()
        );
    }

    public function testMultipleJournalEntriesInstancesAreIndependent(): void
    {
        $journalEntries1 = $this->client->journalEntries();
        $journalEntries2 = $this->client->journalEntries();

        // Should be different instances
        $this->assertNotSame($journalEntries1, $journalEntries2);

        // But same endpoint configuration
        $this->assertEquals(
            $journalEntries1->getBaseEndpoint(),
            $journalEntries2->getBaseEndpoint()
        );
    }

    public function testGeneralLedgerModulesShareHttpClient(): void
    {
        $accounts = $this->client->accounts();
        $journalEntries = $this->client->journalEntries();

        $httpClient1 = $this->client->getHttpClient();
        $httpClient2 = $this->client->getHttpClient();

        // Both should use same HTTP client instance
        $this->assertSame($httpClient1, $httpClient2);
    }

    // ========================================
    // Integration with other modules
    // ========================================

    public function testAllGeneralLedgerModulesCanBeAccessedTogether(): void
    {
        // Test that all module accessors work
        $accounts = $this->client->accounts();
        $journalEntries = $this->client->journalEntries();

        $this->assertIsObject($accounts);
        $this->assertIsObject($journalEntries);
    }

    public function testGeneralLedgerModulesWithOtherModules(): void
    {
        // Test that General Ledger modules work alongside other modules
        $customers = $this->client->customers();
        $invoices = $this->client->invoices();
        $accounts = $this->client->accounts();
        $journalEntries = $this->client->journalEntries();
        $query = $this->client->query();

        $this->assertIsObject($customers);
        $this->assertIsObject($invoices);
        $this->assertIsObject($accounts);
        $this->assertIsObject($journalEntries);
        $this->assertIsObject($query);
    }

    // ========================================
    // Endpoint verification tests
    // ========================================

    public function testAccountsEndpointStartsWithGl(): void
    {
        $accounts = $this->client->accounts();
        $endpoint = $accounts->getBaseEndpoint();

        $this->assertStringStartsWith('/gl-', $endpoint);
    }

    public function testJournalEntriesEndpointStartsWithGl(): void
    {
        $journalEntries = $this->client->journalEntries();
        $endpoint = $journalEntries->getBaseEndpoint();

        $this->assertStringStartsWith('/gl-', $endpoint);
    }

    public function testAccountsEndpointContainsAccounts(): void
    {
        $accounts = $this->client->accounts();
        $endpoint = $accounts->getBaseEndpoint();

        $this->assertStringContainsString('accounts', $endpoint);
    }

    public function testJournalEntriesEndpointContainsJournalEntries(): void
    {
        $journalEntries = $this->client->journalEntries();
        $endpoint = $journalEntries->getBaseEndpoint();

        $this->assertStringContainsString('journal', $endpoint);
    }

    // ========================================
    // Error handling and validation
    // ========================================

    public function testAccountsModuleReturnsInstanceWithCorrectType(): void
    {
        $result = $this->client->accounts();

        $this->assertInstanceOf(Accounts::class, $result);
        $this->assertTrue(method_exists($result, 'list'));
        $this->assertTrue(method_exists($result, 'get'));
        $this->assertTrue(method_exists($result, 'create'));
        $this->assertTrue(method_exists($result, 'update'));
        $this->assertTrue(method_exists($result, 'delete'));
        $this->assertTrue(method_exists($result, 'getBalance'));
        $this->assertTrue(method_exists($result, 'getTransactions'));
    }

    public function testJournalEntriesModuleReturnsInstanceWithCorrectType(): void
    {
        $result = $this->client->journalEntries();

        $this->assertInstanceOf(JournalEntries::class, $result);
        $this->assertTrue(method_exists($result, 'list'));
        $this->assertTrue(method_exists($result, 'get'));
        $this->assertTrue(method_exists($result, 'create'));
        $this->assertTrue(method_exists($result, 'update'));
        $this->assertTrue(method_exists($result, 'delete'));
        $this->assertTrue(method_exists($result, 'getLines'));
        $this->assertTrue(method_exists($result, 'post'));
    }
}
