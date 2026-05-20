---
name: api-endpoint-creation
description: Add a new REST API endpoint following the CQRS pattern. Use when creating new API routes with commands, queries, handlers, and DTOs.
---

# Adding a New API Endpoint (CQRS Pattern)

## Overview

Every API endpoint in this repo follows CQRS:
- **Write operations** (POST/PUT/DELETE) → Command + CommandHandler
- **Read operations** (GET) → Query + QueryHandler → returns DTO

## Step-by-Step

### 1. Create Command/Query DTO

**For writes** — `app/Domain/{Domain}/Commands/{Action}/{Action}Command.php`:
```php
<?php
declare(strict_types=1);
namespace App\Domain\{Domain}\Commands\{Action};

final readonly class {Action}Command
{
    public function __construct(
        public string $field1,
        public int $field2,
    ) {}
}
```

**For reads** — `app/Domain/{Domain}/Queries/{Query}/{Query}Query.php`:
```php
<?php
declare(strict_types=1);
namespace App\Domain\{Domain}\Queries\{Query};

final readonly class {Query}Query
{
    public function __construct(
        public int $id,
    ) {}
}
```

### 2. Create Handler

`app/Domain/{Domain}/Commands/{Action}/{Action}Handler.php`:
```php
<?php
declare(strict_types=1);
namespace App\Domain\{Domain}\Commands\{Action};

use App\Infrastructure\Bus\HandlerInterface;

final class {Action}Handler implements HandlerInterface
{
    public function __construct(
        private {Domain}RepositoryInterface $repository,
        private EventDispatcher $eventDispatcher,
        private LoggerInterface $logger,
    ) {}

    public function handle({Action}Command $command): mixed
    {
        // Validate, create entity, persist, dispatch events
    }
}
```

### 3. Register in ServiceProvider

`app/Domain/{Domain}/{Domain}ServiceProvider.php` — add to `registerCommands()`:
```php
$commandBus->register(
    {Action}Command::class,
    new {Action}Handler($repository, $eventDispatcher, $logger)
);
```

### 4. Add API Route

`app/Config/Routes.php` — inside the `api/v1` group:
```php
$routes->group('{domain}', ['filter' => ['jwt', 'role:admin']], static function ($routes) {
    $routes->get('', '{Domain}Controller::index');
    $routes->post('', '{Domain}Controller::create');
    $routes->get('(:num)', '{Domain}Controller::show/$1');
    $routes->post('(:num)', '{Domain}Controller::update/$1');
    $routes->post('(:num)/delete', '{Domain}Controller::delete/$1');
});
```

### 5. Create/Update API Controller

`app/Controllers/Api/{Domain}Controller.php` — thin controller that dispatches:
```php
public function create(): ResponseInterface
{
    $data = $this->request->getJSON(true);
    $command = new {Action}Command(
        field1: $data['field1'],
        field2: (int) $data['field2'],
    );
    $result = $this->commandBus->dispatch($command);
    return $this->respond(['id' => $result], 201);
}
```

### 6. Return DTOs from Query Handlers

Create `app/Domain/{Domain}/DTOs/{Domain}DTO.php` with `fromEntity()` factory.
Query handlers must return DTOs, never domain entities.

## Conventions

- Commands/Queries are `final readonly` DTOs
- Handlers implement `HandlerInterface`
- No business logic in controllers
- API controllers extend `ResourceController` or use `ResponseTrait`
- JWT auth filter on protected endpoints
- Rate limiting on public auth endpoints: `['filter' => 'ratelimit:5,300']`
- Always run `composer check` after changes
