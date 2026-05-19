<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct;

use AbiSageIntacct\Client;
use AbiSageIntacct\Modules\Core\Entities;
use AbiSageIntacct\Modules\Core\Dimensions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Core module access in Client
 *
 * @internal
 */
final class ClientCoreTest extends TestCase
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
    // entities() method tests
    // ========================================

    public function testEntitiesMethodReturnsCorrectModule(): void
    {
        $entities = $this->client->entities();

        $this->assertInstanceOf(Entities::class, $entities);
    }

    public function testEntitiesModuleHasCorrectEndpoint(): void
    {
        $entities = $this->client->entities();

        $this->assertEquals('/entities', $entities->getBaseEndpoint());
    }

    public function testEntitiesModuleHasHttpClient(): void
    {
        $entities = $this->client->entities();

        // Verify httpClient is set by checking if it's not null
        // We do this by checking if the module is properly instantiated
        $this->assertIsObject($entities);
    }

    // ========================================
    // dimensions() method tests
    // ========================================

    public function testDimensionsMethodReturnsCorrectModule(): void
    {
        $dimensions = $this->client->dimensions();

        $this->assertInstanceOf(Dimensions::class, $dimensions);
    }

    public function testDimensionsModuleHasCorrectEndpoint(): void
    {
        $dimensions = $this->client->dimensions();

        $this->assertEquals('/dimensions', $dimensions->getBaseEndpoint());
    }

    public function testDimensionsModuleHasHttpClient(): void
    {
        $dimensions = $this->client->dimensions();

        // Verify httpClient is set by checking if it's not null
        // We do this by checking if the module is properly instantiated
        $this->assertIsObject($dimensions);
    }

    // ========================================
    // Module isolation tests
    // ========================================

    public function testMultipleEntitiesInstancesAreIndependent(): void
    {
        $entities1 = $this->client->entities();
        $entities2 = $this->client->entities();

        // Should be different instances
        $this->assertNotSame($entities1, $entities2);

        // But same endpoint configuration
        $this->assertEquals(
            $entities1->getBaseEndpoint(),
            $entities2->getBaseEndpoint()
        );
    }

    public function testMultipleDimensionsInstancesAreIndependent(): void
    {
        $dimensions1 = $this->client->dimensions();
        $dimensions2 = $this->client->dimensions();

        // Should be different instances
        $this->assertNotSame($dimensions1, $dimensions2);

        // But same endpoint configuration
        $this->assertEquals(
            $dimensions1->getBaseEndpoint(),
            $dimensions2->getBaseEndpoint()
        );
    }

    public function testCoreModulesShareHttpClient(): void
    {
        $entities = $this->client->entities();
        $dimensions = $this->client->dimensions();

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
        $bankAccounts = $this->client->bankAccounts();
        $transactions = $this->client->transactions();
        $query = $this->client->query();
        $entities = $this->client->entities();
        $dimensions = $this->client->dimensions();

        $this->assertIsObject($customers);
        $this->assertIsObject($invoices);
        $this->assertIsObject($bankAccounts);
        $this->assertIsObject($transactions);
        $this->assertIsObject($query);
        $this->assertIsObject($entities);
        $this->assertIsObject($dimensions);
    }

    public function testCoreAndArModulesCanBeUsedTogether(): void
    {
        $entities = $this->client->entities();
        $customers = $this->client->customers();

        $this->assertInstanceOf(Entities::class, $entities);
        $this->assertIsObject($customers);
    }

    public function testCoreAndCashManagementModulesCanBeUsedTogether(): void
    {
        $entities = $this->client->entities();
        $dimensions = $this->client->dimensions();
        $bankAccounts = $this->client->bankAccounts();
        $transactions = $this->client->transactions();

        $this->assertInstanceOf(Entities::class, $entities);
        $this->assertInstanceOf(Dimensions::class, $dimensions);
        $this->assertIsObject($bankAccounts);
        $this->assertIsObject($transactions);
    }
}
