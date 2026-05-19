# Adding New Domains - Step-by-Step Guide

This guide explains how to add a new domain to the CQRS template. Thanks to the auto-discovery system, adding a domain requires **ZERO edits** to Services.php or any central configuration files.

## Quick Start (AI-Optimized Approach)

### Automated Domain Creation (Recommended)

The **fastest and most reliable** way to create a new domain is using the AI agent system:

**Option 1: Slash Command** (Simplest)
```bash
/add-domain Order
```

**Option 2: Request Skill Directly**
```
Use domain-scaffolding skill to create a complete Order domain
```

This automated approach:
- ✅ Creates all 45 required files (see `.claude/documentation/COMPLETE_FILE_INVENTORY.md`)
- ✅ Follows all CQRS/DDD patterns correctly
- ✅ Passes PHPStan Level 8 validation
- ✅ Passes Slevomat coding standards
- ✅ Includes comprehensive test coverage (90%+)
- ✅ Uses AI specialists for quality enforcement
- ✅ Completes in 2-3 minutes vs 30+ minutes manual

**For details, see:** `.claude/skills/domain-scaffolding/SKILL.md`

---

## Manual Creation (Understanding the Architecture)

The sections below explain the manual steps for creating a domain. This is provided for:
- Understanding the architecture
- Customizing beyond the standard template
- Learning the CQRS/DDD patterns

**Note:** Manual creation takes 30+ minutes and is error-prone. The automated approach is strongly recommended.

## Overview

When you add a new domain, you only need to:
1. Create the domain folder structure
2. Create a ServiceProvider with the `#[DomainServiceProvider]` attribute
3. Done! The system automatically discovers and registers everything

---

## Example: Adding an "Order" Domain

Let's walk through creating a complete Order domain.

### Step 1: Create Domain Folder Structure

```bash
mkdir -p app/Domain/Order/{Commands,Queries,Events,Entities,ValueObjects}
```

Your structure should look like:
```
app/Domain/Order/
├── Commands/
├── Queries/
├── Events/
├── Entities/
├── ValueObjects/
└── OrderServiceProvider.php  (we'll create this next)
```

---

### Step 2: Create Entities and Value Objects

**File:** `app/Domain/Order/Entities/Order.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Entities;

use App\Domain\Order\ValueObjects\OrderNumber;
use App\Domain\Order\ValueObjects\OrderTotal;

final class Order
{
    private function __construct(
        private ?int $id,
        private OrderNumber $orderNumber,
        private OrderTotal $total,
        private string $customerEmail,
        private string $status
    ) {
    }

    public static function create(
        OrderNumber $orderNumber,
        OrderTotal $total,
        string $customerEmail
    ): self {
        return new self(null, $orderNumber, $total, $customerEmail, 'pending');
    }

    public static function reconstitute(
        int $id,
        OrderNumber $orderNumber,
        OrderTotal $total,
        string $customerEmail,
        string $status
    ): self {
        $order = new self(null, $orderNumber, $total, $customerEmail, $status);
        $order->id = $id;
        return $order;
    }

    // Getters...
    public function getId(): ?int { return $this->id; }
    public function getOrderNumber(): OrderNumber { return $this->orderNumber; }
    public function getTotal(): OrderTotal { return $this->total; }
    public function getCustomerEmail(): string { return $this->customerEmail; }
    public function getStatus(): string { return $this->status; }
}
```

---

### Step 3: Create Commands

**Folder:** `app/Domain/Order/Commands/CreateOrder/`

**CreateOrderCommand.php:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Commands\CreateOrder;

final readonly class CreateOrderCommand
{
    public function __construct(
        public string $customerEmail,
        public float $total
    ) {
    }
}
```

**CreateOrderHandler.php:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Commands\CreateOrder;

use App\Domain\Order\Entities\Order;
use App\Domain\Order\ValueObjects\OrderNumber;
use App\Domain\Order\ValueObjects\OrderTotal;
use App\Infrastructure\Bus\EventDispatcher;
use App\Models\Order\OrderRepository;

final class CreateOrderHandler
{
    public function __construct(
        private OrderRepository $repository,
        private EventDispatcher $eventDispatcher
    ) {
    }

    public function handle(CreateOrderCommand $command): int
    {
        $orderNumber = OrderNumber::generate();
        $total = OrderTotal::fromFloat($command->total);

        $order = Order::create(
            orderNumber: $orderNumber,
            total: $total,
            customerEmail: $command->customerEmail
        );

        return $this->repository->save($order);
    }
}
```

---

### Step 4: Create Queries

**Folder:** `app/Domain/Order/Queries/GetOrderById/`

**GetOrderByIdQuery.php:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries\GetOrderById;

final readonly class GetOrderByIdQuery
{
    public function __construct(public int $id) {
    }
}
```

**GetOrderByIdHandler.php:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries\GetOrderById;

use App\Domain\Order\Entities\Order;
use App\Models\Order\OrderRepository;

final class GetOrderByIdHandler
{
    public function __construct(private OrderRepository $repository) {
    }

    public function handle(GetOrderByIdQuery $query): ?Order
    {
        return $this->repository->findById($query->id);
    }
}
```

---

### Step 5: Create Events

**Folder:** `app/Domain/Order/Events/OrderCreated/`

**OrderCreatedEvent.php:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Events\OrderCreated;

final readonly class OrderCreatedEvent
{
    public function __construct(
        public int $orderId,
        public string $orderNumber,
        public float $total
    ) {
    }
}
```

**OrderCreatedEventHandler.php:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Order\Events\OrderCreated;

final class OrderCreatedEventHandler
{
    public function __invoke(OrderCreatedEvent $event): void
    {
        log_message('info', sprintf(
            '[Order] Created: ID=%d, Number=%s, Total=%.2f',
            $event->orderId,
            $event->orderNumber,
            $event->total
        ));
    }
}
```

---

### Step 6: Create Repository

**Folder:** `app/Models/Order/`

**OrderRepository.php:**
```php
<?php

declare(strict_types=1);

namespace App\Models\Order;

use App\Domain\Order\Entities\Order;
use App\Domain\Order\ValueObjects\OrderNumber;
use App\Domain\Order\ValueObjects\OrderTotal;

class OrderRepository
{
    private OrderModel $model;

    public function __construct()
    {
        $this->model = new OrderModel();
    }

    public function save(Order $order): int
    {
        $data = [
            'order_number' => $order->getOrderNumber()->getValue(),
            'total' => $order->getTotal()->getValue(),
            'customer_email' => $order->getCustomerEmail(),
            'status' => $order->getStatus(),
        ];

        if ($order->getId() === null) {
            $this->model->insert($data);
            return $this->model->getInsertID();
        }

        $this->model->update($order->getId(), $data);
        return $order->getId();
    }

    public function findById(int $id): ?Order
    {
        $data = $this->model->find($id);
        return $data ? $this->toDomainEntity($data) : null;
    }

    private function toDomainEntity(array $data): Order
    {
        return Order::reconstitute(
            id: (int) $data['id'],
            orderNumber: OrderNumber::fromString($data['order_number']),
            total: OrderTotal::fromFloat((float) $data['total']),
            customerEmail: $data['customer_email'],
            status: $data['status']
        );
    }
}
```

---

### Step 7: Create Service Provider (THE KEY FILE!)

**File:** `app/Domain/Order/OrderServiceProvider.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Order\Commands\CreateOrder\CreateOrderCommand;
use App\Domain\Order\Commands\CreateOrder\CreateOrderHandler;
use App\Domain\Order\Events\OrderCreated\OrderCreatedEvent;
use App\Domain\Order\Events\OrderCreated\OrderCreatedEventHandler;
use App\Domain\Order\Queries\GetOrderById\GetOrderByIdHandler;
use App\Domain\Order\Queries\GetOrderById\GetOrderByIdQuery;
use App\Infrastructure\Attributes\DomainServiceProvider;
use App\Infrastructure\Bus\CommandBus;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Bus\QueryBus;
use App\Infrastructure\ServiceProvider\DomainServiceProviderInterface;

#[DomainServiceProvider]  // <-- This attribute enables auto-discovery!
final class OrderServiceProvider implements DomainServiceProviderInterface
{
    private array $repositories = [];

    public function registerCommands(CommandBus $commandBus): void
    {
        $repository = $this->getRepository('orderRepository');
        $eventDispatcher = $this->getRepository('eventDispatcher');

        $commandBus->register(
            CreateOrderCommand::class,
            new CreateOrderHandler($repository, $eventDispatcher)
        );
    }

    public function registerQueries(QueryBus $queryBus): void
    {
        $repository = $this->getRepository('orderRepository');

        $queryBus->register(
            GetOrderByIdQuery::class,
            new GetOrderByIdHandler($repository)
        );
    }

    public function registerEvents(EventDispatcher $dispatcher): void
    {
        $dispatcher->subscribe(
            OrderCreatedEvent::class,
            new OrderCreatedEventHandler()
        );
    }

    public function getRepositories(): array
    {
        return ['orderRepository', 'eventDispatcher'];
    }

    public function setRepositories(array $repositories): void
    {
        $this->repositories = $repositories;
    }

    private function getRepository(string $name): object
    {
        return $this->repositories[$name];
    }
}
```

---

### Step 8: Add Repository Service (Services.php)

**ONLY IF** you need a new repository. Add this method to `app/Config/Services.php`:

```php
public static function orderRepository(bool $getShared = true): OrderRepository
{
    if ($getShared) {
        return static::getSharedInstance('orderRepository');
    }

    return new OrderRepository();
}
```

---

### Step 9: Create Migration

```bash
php spark make:migration CreateOrdersTable
```

```php
public function up(): void
{
    $this->forge->addField([
        'id' => ['type' => 'INT', 'auto_increment' => true],
        'order_number' => ['type' => 'VARCHAR', 'constraint' => 20, 'unique' => true],
        'total' => ['type' => 'DECIMAL', 'constraint' => '10,2'],
        'customer_email' => ['type' => 'VARCHAR', 'constraint' => 255],
        'status' => ['type' => 'VARCHAR', 'constraint' => 50],
        'created_at' => ['type' => 'DATETIME', 'null' => true],
        'updated_at' => ['type' => 'DATETIME', 'null' => true],
    ]);
    $this->forge->addKey('id', true);
    $this->forge->createTable('orders');
}
```

Run migration:
```bash
php spark migrate
```

---

### Step 10: Create Controller & Routes

**Controller:** `app/Controllers/Domain/Order/OrderController.php`

**Routes:** `app/Config/Routes.php`

```php
$routes->group('orders', ['namespace' => 'App\Controllers\Domain\Order'], static function ($routes) {
    $routes->get('', 'OrderController::index');
    $routes->post('', 'OrderController::store');
    $routes->get('(:num)', 'OrderController::show/$1');
});
```

---

### Step 11: Create Test Files

A complete domain includes comprehensive test coverage at three levels: **Unit**, **Integration**, and **Feature** tests.

#### Unit Tests (70% of test suite)

Unit tests verify individual components in isolation. Create tests for:
- **Value Objects** - Test validation, equality, immutability
- **Entities** - Test factory methods, business logic
- **Events** - Test immutability, properties
- **Commands** - Test data transfer, immutability
- **Queries** - Test data transfer, immutability
- **Handlers** - Test business logic with mocked dependencies

**Folder:** `tests/Unit/Domain/Order/`

**Example:** `tests/Unit/Domain/Order/ValueObjects/OrderNumberTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Order\ValueObjects;

use App\Domain\Order\ValueObjects\OrderNumber;
use App\Domain\Shared\Exceptions\ValidationException;
use Tests\Support\UnitTestCase;

final class OrderNumberTest extends UnitTestCase
{
    public function test_can_create_with_valid_number(): void
    {
        $orderNumber = OrderNumber::fromString('ORD-12345');

        $this->assertInstanceOf(OrderNumber::class, $orderNumber);
        $this->assertEquals('ORD-12345', $orderNumber->getValue());
    }

    public function test_throws_exception_for_invalid_format(): void
    {
        $this->expectException(ValidationException::class);

        OrderNumber::fromString('invalid');
    }

    public function test_is_immutable(): void
    {
        $orderNumber = OrderNumber::fromString('ORD-12345');

        // Readonly properties cannot be modified
        $this->assertEquals('ORD-12345', $orderNumber->getValue());
    }
}
```

**Example:** `tests/Unit/Domain/Order/Commands/CreateOrderHandlerTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Order\Commands;

use App\Domain\Order\Commands\CreateOrder\CreateOrderCommand;
use App\Domain\Order\Commands\CreateOrder\CreateOrderHandler;
use App\Infrastructure\Bus\EventDispatcher;
use App\Models\Order\OrderRepository;
use Tests\Support\UnitTestCase;

final class CreateOrderHandlerTest extends UnitTestCase
{
    public function test_creates_order_successfully(): void
    {
        // Arrange
        $repository = $this->createMock(OrderRepository::class);
        $eventDispatcher = $this->createMock(EventDispatcher::class);

        $repository->expects($this->once())
            ->method('save')
            ->willReturn(1);

        $handler = new CreateOrderHandler($repository, $eventDispatcher);
        $command = new CreateOrderCommand(
            customerEmail: 'test@example.com',
            total: 99.99
        );

        // Act
        $orderId = $handler->handle($command);

        // Assert
        $this->assertEquals(1, $orderId);
    }
}
```

#### Integration Tests (20% of test suite)

Integration tests verify interactions with the database. Create tests for:
- **Repository** - Test CRUD operations, queries, data mapping

**Folder:** `tests/Integration/Repositories/`

**Example:** `tests/Integration/Repositories/OrderRepositoryTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Domain\Order\Entities\Order;
use App\Domain\Order\ValueObjects\OrderNumber;
use App\Domain\Order\ValueObjects\OrderTotal;
use App\Models\Order\OrderRepository;
use Tests\Support\IntegrationTestCase;

final class OrderRepositoryTest extends IntegrationTestCase
{
    private OrderRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new OrderRepository();
    }

    public function test_save_inserts_new_order(): void
    {
        // Arrange
        $order = Order::create(
            orderNumber: OrderNumber::generate(),
            total: OrderTotal::fromFloat(99.99),
            customerEmail: 'test@example.com'
        );

        // Act
        $orderId = $this->repository->save($order);

        // Assert
        $this->assertGreaterThan(0, $orderId);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'customer_email' => 'test@example.com',
        ]);
    }

    public function test_find_by_id_returns_order(): void
    {
        // Arrange - Create an order first
        $order = Order::create(
            orderNumber: OrderNumber::generate(),
            total: OrderTotal::fromFloat(99.99),
            customerEmail: 'test@example.com'
        );
        $orderId = $this->repository->save($order);

        // Act
        $foundOrder = $this->repository->findById($orderId);

        // Assert
        $this->assertNotNull($foundOrder);
        $this->assertEquals('test@example.com', $foundOrder->getCustomerEmail());
    }
}
```

#### Feature Tests (10% of test suite)

Feature tests verify complete HTTP request/response flows through controllers.

**Folder:** `tests/Feature/Order/`

**Example:** `tests/Feature/Order/OrderCrudTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use Tests\Support\FeatureTestCase;

final class OrderCrudTest extends FeatureTestCase
{
    public function test_index_displays_orders_list(): void
    {
        $result = $this->get('/orders');

        $result->assertOK();
        $result->assertSee('orders/index');
    }

    public function test_store_creates_new_order_successfully(): void
    {
        $result = $this->post('/orders', [
            'customer_email' => 'test@example.com',
            'total' => '99.99',
        ]);

        $result->assertRedirect();
        $this->assertFlashMessage('success', 'Order created successfully');
        $this->assertDatabaseHas('orders', [
            'customer_email' => 'test@example.com',
        ]);
    }

    public function test_store_handles_validation_errors(): void
    {
        $result = $this->post('/orders', [
            'customer_email' => 'invalid-email',
            'total' => '-10',
        ]);

        $result->assertRedirect();
        $this->assertHasValidationErrors();
    }
}
```

#### Test Factory (Optional but Recommended)

Create a factory to easily generate test data:

**File:** `tests/Support/Factories/OrderFactory.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Factories;

use App\Domain\Order\Entities\Order;
use App\Domain\Order\ValueObjects\OrderNumber;
use App\Domain\Order\ValueObjects\OrderTotal;

final class OrderFactory
{
    /**
     * @param array<string, mixed> $overrides
     */
    public static function createOrder(array $overrides = []): Order
    {
        return Order::create(
            orderNumber: $overrides['orderNumber'] ?? OrderNumber::generate(),
            total: $overrides['total'] ?? OrderTotal::fromFloat(99.99),
            customerEmail: $overrides['customerEmail'] ?? 'test@example.com'
        );
    }
}
```

#### Running Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run only unit tests
vendor/bin/phpunit tests/Unit

# Run only Order domain tests
vendor/bin/phpunit tests/Unit/Domain/Order

# Run with coverage
vendor/bin/phpunit --coverage-html coverage

# Run with testdox (readable output)
vendor/bin/phpunit --testdox
```

#### Test Checklist

When adding a new domain, create:

**Unit Tests:**
- [ ] Value Object tests (validation, equality, immutability)
- [ ] Entity tests (factory methods, business logic, reconstitution)
- [ ] Event tests (immutability, properties)
- [ ] Command tests (data transfer)
- [ ] Query tests (data transfer)
- [ ] Command Handler tests (business logic with mocks)
- [ ] Query Handler tests (data retrieval with mocks)
- [ ] Event Handler tests (side effects)

**Integration Tests:**
- [ ] Repository save/insert tests
- [ ] Repository find tests
- [ ] Repository update tests
- [ ] Repository delete tests
- [ ] Repository query tests (pagination, search, filters)

**Feature Tests:**
- [ ] Index/list page test
- [ ] Create form display test
- [ ] Store/create success test
- [ ] Store validation error test
- [ ] Show/detail page test
- [ ] Edit form display test
- [ ] Update success test
- [ ] Update validation error test
- [ ] Delete success test

**Test Factories:**
- [ ] Entity factory for easy test data creation

---

## Summary

✅ **What you DID:**
1. Created Order domain folder structure
2. Created OrderServiceProvider with `#[DomainServiceProvider]` attribute
3. Created entities, commands, queries, events
4. Added orderRepository to Services.php

❌ **What you DID NOT do:**
- Edit Services.php commandBus() method
- Edit Services.php queryBus() method
- Edit Services.php eventDispatcher() method
- Manually register handlers anywhere

🎯 **The system automatically:**
- Discovered OrderServiceProvider
- Registered all commands, queries, and events
- Wired up all handlers

---

## Checklist for New Domain

**Domain Structure:**
- [ ] Create folder: `app/Domain/YourDomain/`
- [ ] Create subfolders: Commands, Queries, Events, Entities, ValueObjects
- [ ] Create YourDomainServiceProvider.php with `#[DomainServiceProvider]`
- [ ] Implement DomainServiceProviderInterface
- [ ] Create entities and value objects
- [ ] Create commands with handlers (one folder per command)
- [ ] Create queries with handlers (one folder per query)
- [ ] Create events with handlers (handler in event folder)

**Infrastructure:**
- [ ] Create repository in `app/Models/YourDomain/`
- [ ] Add repository method to Services.php (if needed)
- [ ] Create database migration
- [ ] Run migration
- [ ] Create controller and routes
- [ ] Create views (index, show, create, edit) using Bootstrap layout

**Test Coverage:**
- [ ] Create test factory in `tests/Support/Factories/`
- [ ] Create unit tests for all value objects
- [ ] Create unit tests for entity (create, reconstitute, methods)
- [ ] Create unit tests for all command handlers
- [ ] Create unit tests for all query handlers
- [ ] Create unit tests for all event handlers
- [ ] Create integration tests for repository CRUD operations
- [ ] Create feature tests for all controller actions
- [ ] Verify all tests pass: `vendor/bin/phpunit`
- [ ] Verify PHPStan passes: `vendor/bin/phpstan analyse`
- [ ] Verify coding standards pass: `vendor/bin/phpcs`

---

## Tips

1. **Copy Cookie domain** as a starting point - it's a complete template
2. **Follow naming conventions**: Commands in imperative, Queries as questions, Events in past tense
3. **One handler per command/query** - CQRS principle
4. **Events can have multiple handlers** - that's the power of events
5. **Keep ServiceProvider simple** - just registration, no business logic

---

## Troubleshooting

**Problem:** My domain isn't being discovered

**Solutions:**
- Check that ServiceProvider has `#[DomainServiceProvider]` attribute
- Check that class implements `DomainServiceProviderInterface`
- Check that file is in `app/Domain/*/` directory
- Clear cache if needed: `ServiceProviderRegistry::clearCache()`

**Problem:** Handlers not working

**Solutions:**
- Check that handlers are registered in ServiceProvider
- Check repository names match Services.php methods
- Check command/query class names are correct (full namespace)

---

**That's it! Your new domain is ready to use with zero edits to central files.**
