# Domain Creation Protocol

**MANDATORY PROTOCOL for creating new domains. ALL steps MUST be followed.**

---

## Pre-Creation Checklist

Before creating a new domain, verify:

- [ ] Domain name follows PascalCase (Order, Product, Customer)
- [ ] Entity name is singular (Cookie, not Cookies)
- [ ] No existing domain with same name
- [ ] Database table name planned (plural, snake_case)
- [ ] Value objects identified (which properties need validation?)

---

## Step 1: Create Directory Structure

```bash
mkdir -p app/Domain/{Domain}/{Commands,Queries,Events,Entities,ValueObjects}
mkdir -p app/Models/{Domain}
mkdir -p app/Controllers/Domain/{Domain}
mkdir -p app/Views/{entities}
mkdir -p app/Database/Migrations
mkdir -p tests/Unit/Domain/{Domain}/{ValueObjects,Entities,Commands,Queries,Events}
mkdir -p tests/Integration/Repositories
mkdir -p tests/Feature/{Domain}
mkdir -p tests/Support/Factories
```

**Specialist Required:** None (structural task)

---

## Step 2: Create Value Objects

**For EACH property requiring validation** (name, price, email, etc.):

```php
// app/Domain/{Domain}/ValueObjects/{Entity}{Property}.php

declare(strict_types=1);

namespace App\Domain\{Domain}\ValueObjects;

use App\Domain\Shared\Exceptions\ValidationException;

final readonly class {Entity}{Property}
{
    private const MIN_{CONSTANT} = value;
    private const MAX_{CONSTANT} = value;

    private function __construct(private {type} $value)
    {
        // Validation logic
        if ($value < self::MIN_{CONSTANT}) {
            throw ValidationException::tooSmall('{property}', self::MIN_{CONSTANT}, $value);
        }
    }

    public static function from{Type}({type} $value): self
    {
        return new self($value);
    }

    public function getValue(): {type}
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

**Specialists Required:**
- `ddd-specialist` - Verify value object pattern
- `php-specialist` - Verify PHP 8.3+ features, types
- `phpstan-specialist` - Verify type safety

**Quality Gate:** PHPStan Level 8 pass, no violations

---

## Step 3: Create Entity

```php
// app/Domain/{Domain}/Entities/{Entity}.php

declare(strict_types=1);

namespace App\Domain\{Domain}\Entities;

use App\Domain\{Domain}\ValueObjects\{Entity}Name;

final class {Entity}
{
    private function __construct(
        private ?int $id,
        private {Entity}Name $name,
        // ... other properties
        private ?string $createdAt = null,
        private ?string $updatedAt = null,
        private ?string $deletedAt = null
    ) {
    }

    public static function create(
        {Entity}Name $name,
        // ... other parameters
    ): self {
        // Validation and invariants
        return new self(null, $name, ...);
    }

    public static function reconstitute(
        int $id,
        {Entity}Name $name,
        // ... other parameters
    ): self {
        return new self($id, $name, ...);
    }

    // Getters and command methods
}
```

**Specialists Required:**
- `ddd-specialist` - Verify entity pattern, factory methods
- `clean-code-specialist` - Verify method length, complexity
- `php-specialist` - Verify types, readonly where appropriate

**Quality Gate:** PHPStan Level 8 pass, max 200 lines per class

---

## Step 4: Create Commands (3 Standard Commands)

### For EACH command (Create, Update, Delete):

**Command:**
```php
// app/Domain/{Domain}/Commands/{Action}{Entity}/{Action}{Entity}Command.php

declare(strict_types=1);

namespace App\Domain\{Domain}\Commands\{Action}{Entity};

final readonly class {Action}{Entity}Command
{
    public function __construct(
        // Command properties
    ) {
    }
}
```

**Handler:**
```php
// app/Domain/{Domain}/Commands/{Action}{Entity}/{Action}{Entity}Handler.php

declare(strict_types=1);

namespace App\Domain\{Domain}\Commands\{Action}{Entity};

use App\Models\{Domain}\{Entity}Repository;
use App\Infrastructure\Bus\EventDispatcher;

final class {Action}{Entity}Handler
{
    public function __construct(
        private {Entity}Repository $repository,
        private EventDispatcher $eventDispatcher
    ) {
    }

    public function handle({Action}{Entity}Command $command): {ReturnType}
    {
        // Business logic
        // Dispatch events
    }
}
```

**Specialists Required:**
- `cqrs-specialist` - Verify CQRS pattern, command/handler separation
- `ddd-specialist` - Verify domain logic placement
- `clean-code-specialist` - Verify method length (max 20 lines)
- `php-specialist` - Verify types, named parameters

**Quality Gate:** Each handler max 20 lines, PHPStan pass

---

## Step 5: Create Queries (3 Standard Queries)

### For EACH query (ById, All, Paginated):

Follow same pattern as commands but:
- Query handlers return data (entities or DTOs)
- Query handlers NEVER modify state
- Use readonly class for query

**Specialists Required:** Same as commands

---

## Step 6: Create Events (3 Standard Events)

### For EACH event (Created, Updated, Deleted):

**Event:**
```php
final readonly class {Entity}{Action}Event
{
    public function __construct(
        public int {entity}Id,
        // ... relevant data
    ) {
    }
}
```

**Event Handler:**
```php
final class {Entity}{Action}EventHandler
{
    public function __invoke({Entity}{Action}Event $event): void
    {
        log_message('info', sprintf('[{Entity}] {Action}: ID=%d', $event->{entity}Id));
    }
}
```

**Specialists Required:**
- `cqrs-specialist` - Verify event pattern, past tense naming
- `php-specialist` - Verify readonly, types

---

## Step 7: Create Service Provider

```php
// app/Domain/{Domain}/{Domain}ServiceProvider.php

declare(strict_types=1);

namespace App\Domain\{Domain};

use App\Infrastructure\Attributes\DomainServiceProvider;
use App\Infrastructure\Bus\{CommandBus, QueryBus, EventDispatcher, EventDispatcherInterface};
use App\Infrastructure\ServiceProvider\DomainServiceProviderInterface;
use Psr\Log\LoggerInterface;

#[DomainServiceProvider]  // CRITICAL - enables auto-discovery
final class {Domain}ServiceProvider implements DomainServiceProviderInterface
{
    private array $repositories = [];

    public function registerCommands(CommandBus $commandBus): void
    {
        $repository = $this->getRepository('{entity}Repository');
        $eventDispatcher = $this->getRepository('eventDispatcher');
        $logger = $this->getRepository('logger');

        $commandBus->register(
            Create{Entity}Command::class,
            new Create{Entity}Handler($repository, $eventDispatcher, $logger)
        );
        // Register other commands...
    }

    public function registerQueries(QueryBus $queryBus): void
    {
        $repository = $this->getRepository('{entity}Repository');

        $queryBus->register(
            Get{Entity}ByIdQuery::class,
            new Get{Entity}ByIdHandler($repository)
        );
        // Register other queries...
    }

    public function registerEvents(EventDispatcher $dispatcher): void
    {
        $dispatcher->subscribe(
            {Entity}CreatedEvent::class,
            new {Entity}CreatedEventHandler()
        );
        // Register other events...
    }

    public function getRepositories(): array
    {
        return ['{entity}Repository', 'eventDispatcher', 'logger', 'loggingConfig'];
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

**Specialists Required:**
- `cqrs-specialist` - Verify registration pattern
- `php-specialist` - Verify attribute usage

**Quality Gate:** All handlers registered, attribute present

---

## Step 8: Create Repository & Model

**Model:**
```php
// app/Models/{Domain}/{Entity}Model.php

namespace App\Models\{Domain};

use CodeIgniter\Model;

class {Entity}Model extends Model
{
    protected $table = '{entities}';
    protected $primaryKey = 'id';
    protected $allowedFields = ['field1', 'field2', ...];
    protected $useTimestamps = true;
    protected $useSoftDeletes = true;
    protected $returnType = 'array';
}
```

**Repository:**
```php
// app/Infrastructure/Persistence/Repositories/{Entity}Repository.php

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\{Domain}\Entities\{Entity};
use App\Domain\{Domain}\Ports\{Entity}RepositoryInterface;
use App\Models\{Domain}\{Entity}Model;

class {Entity}Repository implements {Entity}RepositoryInterface
{
    private {Entity}Model $model;

    public function __construct()
    {
        $this->model = new {Entity}Model();
    }

    public function save({Entity} ${entity}): int { /* ... */ }
    public function findById(int $id): ?{Entity} { /* ... */ }
    public function delete(int $id): bool { /* ... */ }

    /**
     * @param array{id: int, name: string, ...} $data
     */
    private function toDomainEntity(array $data): {Entity} { /* ... */ }
}
```

**Specialists Required:**
- `codeigniter4-specialist` - Verify CI4 model conventions
- `php-specialist` - Verify types, array shapes
- `phpstan-specialist` - Verify array shape annotations

---

## Step 9: Add Repository to Services.php

```php
// app/Config/Services.php

public static function {entity}Repository(bool $getShared = true): {Entity}Repository
{
    if ($getShared) {
        return static::getSharedInstance('{entity}Repository');
    }

    return new {Entity}Repository();
}
```

**This is the ONLY edit to Services.php needed!**

---

## Step 10: Create Migration

```bash
php spark make:migration Create{Entities}Table
```

```php
public function up(): void
{
    $this->forge->addField([
        'id' => ['type' => 'INT', 'auto_increment' => true],
        // ... fields
        'created_at' => ['type' => 'DATETIME', 'null' => true],
        'updated_at' => ['type' => 'DATETIME', 'null' => true],
        'deleted_at' => ['type' => 'DATETIME', 'null' => true],
    ]);
    $this->forge->addKey('id', true);
    $this->forge->createTable('{entities}');
}

public function down(): void
{
    $this->forge->dropTable('{entities}');
}
```

**Run migration:** `php spark migrate`

---

## Step 11: Create Controller

```php
// app/Controllers/Domain/{Domain}/{Entity}Controller.php

namespace App\Controllers\Domain\{Domain};

use CodeIgniter\HTTP\ResponseInterface;

final class {Entity}Controller extends BaseController
{
    // Thin controller - delegate to command/query buses
    // Max 100 lines total
}
```

**Specialists Required:**
- `codeigniter4-specialist` - Verify CI4 patterns
- `cqrs-specialist` - Verify delegation to buses
- `clean-code-specialist` - Max 100 lines

---

## Step 12: Create Routes

```php
// app/Config/Routes.php

$routes->group('{entities}', ['namespace' => 'App\Controllers\Domain\{Domain}'], static function ($routes) {
    $routes->get('', '{Entity}Controller::index');
    $routes->get('create', '{Entity}Controller::create');
    $routes->post('', '{Entity}Controller::store');
    $routes->get('(:num)', '{Entity}Controller::show/$1');
    $routes->get('(:num)/edit', '{Entity}Controller::edit/$1');
    $routes->post('(:num)', '{Entity}Controller::update/$1');
    $routes->post('(:num)/delete', '{Entity}Controller::delete/$1');
});
```

---

## Step 13: Create Views (4 files)

- `index.php` - List view with search, pagination
- `show.php` - Detail view
- `create.php` - Creation form with validation
- `edit.php` - Edit form with validation

Use Cookie domain views as templates.

---

## Step 14: Create ALL Tests (14 files minimum)

**Unit Tests:**
- 2 Value object tests
- 1 Entity test
- 3 Command handler tests
- 3 Query handler tests
- 2 Event tests

**Integration Tests:**
- 1 Repository test

**Feature Tests:**
- 1 CRUD test

**Test Factory:**
- 1 Factory for test data

**Specialists Required:**
- `test-specialist` - Create all tests, verify 90% coverage

**Quality Gate:** 90% coverage, all tests pass

---

## Step 15: Final Validation

Run ALL quality checks:

```bash
# PHPStan Level 8
vendor/bin/phpstan analyse

# Slevomat
vendor/bin/phpcs

# Tests
vendor/bin/phpunit

# All checks
composer check
```

**Specialists Required:**
- `phpstan-specialist` - 0 errors required
- `slevomat-specialist` - 0 violations required
- `test-specialist` - 90% coverage required

**Quality Gate:** ALL checks pass with 0 errors/violations

---

## Completion Checklist

- [ ] All 45+ files/touchpoints created or updated
- [ ] ServiceProvider has #[DomainServiceProvider] attribute
- [ ] All handlers registered in ServiceProvider
- [ ] Repository added to Services.php
- [ ] Migration created and run
- [ ] Routes added to Routes.php
- [ ] PHPStan Level 8: 0 errors
- [ ] Slevomat: 0 violations
- [ ] Tests: 90%+ coverage, all passing
- [ ] Cookie domain used as reference

---

## Automation

Use `/add-domain {DomainName}` to automate this entire protocol.

---

**DEVIATION FROM THIS PROTOCOL IS NOT PERMITTED.**
