---
name: codeigniter4-specialist
description: Use when working with CodeIgniter 4 features - migrations, models, controllers, routing, or spark commands. Understands CI4 conventions and best practices.
tools: Read, Bash
---

# CodeIgniter 4 Specialist (CI4 4.6.3+)

## Migrations

**Create migration:**
```bash
php spark make:migration CreateCookiesTable
```

**Run migrations:**
```bash
php spark migrate --all

# Rollback last batch
php spark migrate:rollback

# Rollback all migrations
php spark migrate:rollback -all

# Refresh (rollback all + migrate)
php spark migrate:refresh

# Status (show migration state)
php spark migrate:status
```

**Real Migration Example from Cookie Domain:**

```php
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateCookiesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'comment' => 'Cookie name (unique)',
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
                'comment' => 'Cookie description',
            ],
            'price' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'comment' => 'Cookie price (must be > 0)',
            ],
            'stock' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 0,
                'comment' => 'Stock quantity',
            ],
            'is_active' => [
                'type' => 'BOOLEAN',
                'default' => true,
                'comment' => 'Whether cookie is visible to customers',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'comment' => 'Soft delete timestamp',
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('cookies');
    }

    public function down(): void
    {
        $this->forge->dropTable('cookies', true);
    }
}
```

**Location:** `app/Database/Migrations/{timestamp}_CreateCookiesTable.php`

## Models (Data Layer)

**CodeIgniter 4 Model** (wraps database table, NOT domain model):

```php
declare(strict_types=1);

namespace App\Models\Cookie;

use CodeIgniter\Model;

/**
 * CookieModel - Database access layer for cookies table.
 *
 * This is NOT a domain model - it's a CodeIgniter Model for database operations.
 * Domain entities are in app/Domain/Cookie/Entities/Cookie.php
 */
final class CookieModel extends Model
{
    protected $table = 'cookies';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = true;
    protected $allowedFields = [
        'name',
        'description',
        'price',
        'stock',
        'is_active',
    ];

    // Timestamps
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    // Validation rules (basic, real validation in value objects)
    protected $validationRules = [
        'name' => 'required|min_length[3]|max_length[100]',
        'price' => 'required|decimal|greater_than[0]',
        'stock' => 'required|integer|greater_than_equal_to[0]',
    ];
}
```

**Location:** `app/Models/{Domain}/{Entity}Model.php`

## Controllers (HTTP Layer)

**Thin controllers** - only handle HTTP, delegate to CQRS buses:

```php
declare(strict_types=1);

namespace App\Controllers\Domain\Cookie;

use App\Controllers\BaseController;
use App\Domain\Cookie\Commands\CreateCookie\CreateCookieCommand;
use App\Domain\Cookie\Queries\GetCookieById\GetCookieByIdQuery;
use App\Infrastructure\Bus\CommandBus;
use App\Infrastructure\Bus\QueryBus;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * CookieController - HTTP interface for cookie operations.
 *
 * Responsibilities:
 * - Parse HTTP requests
 * - Validate input (basic)
 * - Dispatch to command/query buses
 * - Return HTTP responses
 *
 * NO business logic here!
 */
final class CookieController extends BaseController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus
    ) {}

    /**
     * Display cookie form (GET /cookies/new)
     */
    public function new(): string
    {
        return view('cookies/new');
    }

    /**
     * Store new cookie (POST /cookies)
     */
    public function store(): ResponseInterface
    {
        $validation = $this->validate([
            'name' => 'required|min_length[3]',
            'price' => 'required|decimal|greater_than[0]',
            'stock' => 'required|integer|greater_than_equal_to[0]',
        ]);

        if (!$validation) {
            return redirect()->back()
                ->withInput()
                ->with('errors', $this->validator->getErrors());
        }

        $command = new CreateCookieCommand(
            name: $this->request->getPost('name'),
            description: $this->request->getPost('description'),
            price: (float) $this->request->getPost('price'),
            stock: (int) $this->request->getPost('stock'),
            isActive: (bool) $this->request->getPost('is_active', true)
        );

        $cookieId = $this->commandBus->dispatch($command);

        return redirect()->to("/cookies/{$cookieId}")
            ->with('success', 'Cookie created successfully');
    }

    /**
     * Display single cookie (GET /cookies/{id})
     */
    public function show(int $id): string
    {
        $query = new GetCookieByIdQuery($id);
        $cookie = $this->queryBus->dispatch($query);

        if ($cookie === null) {
            throw new \CodeIgniter\Exceptions\PageNotFoundException("Cookie not found: {$id}");
        }

        return view('cookies/show', ['cookie' => $cookie]);
    }
}
```

**Location:** `app/Controllers/Domain/{Domain}/{Entity}Controller.php`

## Routing

**File:** `app/Config/Routes.php`

**Real Example from Cookie Domain:**

```php
// Cookie domain routes
$routes->group('cookies', [
    'namespace' => 'App\Controllers\Domain\Cookie'
], static function ($routes) {
    // List all cookies
    $routes->get('', 'CookieController::index');

    // Create new cookie (form)
    $routes->get('new', 'CookieController::new');

    // Store new cookie
    $routes->post('', 'CookieController::store');

    // Show single cookie
    $routes->get('(:num)', 'CookieController::show/$1');

    // Edit cookie (form)
    $routes->get('(:num)/edit', 'CookieController::edit/$1');

    // Update cookie
    $routes->post('(:num)', 'CookieController::update/$1');

    // Delete cookie (soft delete)
    $routes->post('(:num)/delete', 'CookieController::delete/$1');
});
```

**Route Placeholders:**
- `(:num)` - Matches numeric ID
- `(:alpha)` - Matches alphabetic characters
- `(:alphanum)` - Matches alphanumeric characters
- `(:segment)` - Matches any segment (except /)
- `(:any)` - Matches everything

## Development Server

```bash
# Start server (default: localhost:8080)
php spark serve

# Custom port
php spark serve --port=8000

# Custom host
php spark serve --host=192.168.1.100

# Custom host and port
php spark serve --host=0.0.0.0 --port=9000
```

## Database Commands

```bash
# Create database (if using MySQL)
php spark db:create {database_name}

# Seed database
php spark db:seed DatabaseSeeder

# Seed specific seeder with namespace
php spark db:seed App\\Database\\Seeds\\DatabaseSeeder
```

## Auto-Discovery with Attributes

**This project uses native PHP attributes for auto-discovery:**

```php
use App\Infrastructure\Attributes\DomainServiceProvider;

#[DomainServiceProvider]  // ← Auto-discovered!
final class CookieServiceProvider implements DomainServiceProviderInterface
{
    public function registerCommands(CommandBus $bus): void
    {
        $bus->register(CreateCookieCommand::class, CreateCookieHandler::class);
    }

    public function registerQueries(QueryBus $bus): void
    {
        $bus->register(GetCookieByIdQuery::class, GetCookieByIdHandler::class);
    }

    public function registerEvents(EventDispatcher $dispatcher): void
    {
        $dispatcher->register(CookieCreatedEvent::class, CookieCreatedEventHandler::class);
    }
}
```

**No need to manually register in Services.php!**

## Helpers

**Load helper:**
```php
helper('url');  // URL helper
helper('form'); // Form helper
helper('text'); // Text helper
```

**Common helpers:**
- `url_to()` - Generate URL from route
- `base_url()` - Get base URL
- `site_url()` - Get site URL
- `redirect()` - Create redirect response
- `session()` - Access session
- `old()` - Get old input value (after validation)
- `csrf_field()` - Generate CSRF hidden input
- `form_open()` - Open form with CSRF protection

## Environment Configuration

**File:** `.env`

```ini
# Environment
CI_ENVIRONMENT = development

# Database
database.default.hostname = localhost
database.default.database = codeit4me
database.default.username = root
database.default.password =
database.default.DBDriver = MySQLi
database.default.DBPrefix =
database.default.charset = utf8mb4

# App
app.baseURL = 'http://localhost:8080/'
app.indexPage = ''
```

## Best Practices

**Controllers:**
- Should be THIN (max 100 lines)
- Only handle HTTP concerns (request/response)
- Delegate to command/query buses for business logic
- No direct database access (use repositories via buses)

**Models:**
- Only for database operations
- NO business logic
- Wrapped by repositories
- Return arrays (domain entities created by repositories)

**Repositories:**
- Wrap CodeIgniter models
- Convert arrays to domain entities
- Handle domain-specific queries
- Located in: `app/Infrastructure/Persistence/Repositories/`

**Routing:**
- Use route groups for organization
- Use named parameters: `(:num)`, `(:segment)`
- Keep routes RESTful when possible
- Use namespaces to organize controllers

## Integration with CQRS/DDD

**Separation of Concerns:**
- **Controllers** → HTTP layer (request/response)
- **Commands/Queries** → Application layer (use cases)
- **Handlers** → Application layer (business logic)
- **Entities/Value Objects** → Domain layer (business rules)
- **Models** → Infrastructure layer (database)
- **Repositories** → Infrastructure layer (data access)

**Example Flow:**
1. HTTP Request → Controller
2. Controller → Create Command
3. Controller → Dispatch to CommandBus
4. CommandBus → Find Handler
5. Handler → Execute business logic
6. Handler → Use Repository
7. Repository → Use Model
8. Model → Database query
9. Repository → Convert to Entity
10. Handler → Return result
11. Controller → Create HTTP Response

## Reference Implementation

**Use Cookie domain as reference:**
- Migration: `app/Database/Migrations/*_CreateCookiesTable.php`
- Model: `app/Models/Cookie/CookieModel.php`
- Controller: `app/Controllers/Domain/Cookie/CookieController.php`
- Routes: `app/Config/Routes.php` (cookies group)

## Integration with Other Specialists

- **cqrs-specialist** - Ensure controllers delegate to buses
- **ddd-specialist** - Models should NOT contain business logic
- **test-specialist** - Write feature tests for HTTP endpoints
- **php-specialist** - Verify PHP 8.3+ syntax and type safety
