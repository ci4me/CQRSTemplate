---
name: serena-code-generator
description: Generate Serena-optimized PHP code with clear symbols, typed methods, and semantic structure for CodeIgniter4 CQRS projects. Use when writing new code, refactoring existing code, or reviewing code for Serena compatibility. Enforces symbol-level thinking for optimal LSP-based code intelligence.
allowed-tools: Read, Write, Edit, Bash, Grep, Glob
---

# Serena Code Generator Skill (PHP/CodeIgniter4)

This skill ensures all generated PHP code is optimized for Serena's LSP-based semantic intelligence. It enforces symbol-level thinking, clear code boundaries, and patterns that enable instant symbol finding and precise editing.

## When to Use This Skill

Activate this skill when you need to:
- Generate new PHP code (Classes, Commands, Queries, Handlers)
- Refactor existing code for Serena compatibility
- Review code for Serena optimization
- Create new CQRS components (Commands, Queries, Events)
- Add new methods to existing classes
- Ensure code follows semantic best practices
- Verify code is LSP-friendly

**This skill should be used for EVERY PHP code generation task in this project.**

## Core Principles

### 1. Symbol-First Thinking

**Code is made of SYMBOLS (classes, methods, properties), not TEXT.**

Every piece of code should be structured as discoverable, editable symbols:
- Classes are symbols
- Methods are symbols
- Properties are symbols
- Constants are symbols
- Namespaces organize symbols

### 2. Serena's Capabilities

With properly structured PHP code, Serena can:
- `find_symbol("ClassName")` → Find exact class instantly
- `find_symbol("ClassName/methodName")` → Find specific method
- `find_referencing_symbols("ClassName")` → Find all usages
- `replace_symbol_body("ClassName/methodName")` → Replace method implementation
- `insert_after_symbol("ClassName/methodName")` → Add code after method
- `rename_symbol("oldName", "newName")` → Rename everywhere

### 3. The Golden Rules (PHP)

✅ **Clear class names** - Each class has a specific purpose
✅ **Small methods** - Max 50 lines per method (prefer < 20 lines)
✅ **Descriptive names** - Self-documenting code (PSR-12 compliant)
✅ **Flat structure** - Clear namespace hierarchy, not deep nesting
✅ **DocBlocks** - Document all public APIs
✅ **Single responsibility** - One class/method = one purpose
✅ **Strict types** - Use `declare(strict_types=1)`
✅ **Type hints** - All parameters and return types declared

## Code Generation Templates

### Value Object Template

```php
<?php

declare(strict_types=1);

namespace App\Domain\[Domain]\ValueObjects;

use App\Domain\[Domain]\ErrorCodes;
use App\Domain\Shared\Exceptions\ValidationException;

/**
 * Value Object representing [what this represents].
 *
 * Business Rules:
 * - [Rule 1]
 * - [Rule 2]
 * - [Rule 3]
 *
 * Why a Value Object for [Name]:
 * - Centralizes [property] validation logic
 * - Prevents invalid values from entering the domain
 * - Makes code self-documenting ([ValueObjectName] vs string)
 * - Enables consistent validation across create/update operations
 *
 * @package App\Domain\[Domain]\ValueObjects
 */
final readonly class [ValueObjectName]
{
    private const MIN_LENGTH = 3;
    private const MAX_LENGTH = 100;

    /**
     * The validated and normalized value.
     */
    private string $value;

    /**
     * Create a new [ValueObjectName] value object.
     *
     * @param string $value The raw value
     * @throws ValidationException If validation fails
     */
    private function __construct(string $value)
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw ValidationException::required('[field]', ErrorCodes::[DOMAIN]_VALIDATION_[FIELD]);
        }

        $length = mb_strlen($normalized);

        if ($length < self::MIN_LENGTH) {
            throw ValidationException::fieldTooShort('[field]', self::MIN_LENGTH, $length, ErrorCodes::[DOMAIN]_VALIDATION_[FIELD]);
        }

        if ($length > self::MAX_LENGTH) {
            throw ValidationException::fieldTooLong('[field]', self::MAX_LENGTH, $length, ErrorCodes::[DOMAIN]_VALIDATION_[FIELD]);
        }

        $this->value = $normalized;
    }

    /**
     * Create [ValueObjectName] from string.
     *
     * @param string $value The value
     * @throws ValidationException If validation fails
     */
    public static function fromString(string $value): self
    {
        return new self($value);
    }

    /**
     * Get the value.
     *
     * @return string The validated value
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Check if this value equals another.
     *
     * @param [ValueObjectName] $other The other value to compare
     * @return bool True if values are equal
     */
    public function equals([ValueObjectName] $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Convert to string automatically.
     *
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
```

**Real Example from Cookie Domain:**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Cookie\ValueObjects;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Shared\Exceptions\ValidationException;

/**
 * Value Object representing a Cookie name.
 *
 * Business Rules:
 * - Name must be between 3 and 100 characters
 * - Name is trimmed of whitespace
 * - Name cannot be empty after trimming
 *
 * @package App\Domain\Cookie\ValueObjects
 */
final readonly class CookieName
{
    private const MIN_LENGTH = 3;
    private const MAX_LENGTH = 100;

    private string $value;

    private function __construct(string $name)
    {
        $normalized = trim($name);

        if ($normalized === '') {
            throw ValidationException::required('name', ErrorCodes::COOKIE_VALIDATION_NAME);
        }

        $length = mb_strlen($normalized);

        if ($length < self::MIN_LENGTH) {
            throw ValidationException::fieldTooShort('name', self::MIN_LENGTH, $length, ErrorCodes::COOKIE_VALIDATION_NAME);
        }

        if ($length > self::MAX_LENGTH) {
            throw ValidationException::fieldTooLong('name', self::MAX_LENGTH, $length, ErrorCodes::COOKIE_VALIDATION_NAME);
        }

        $this->value = $normalized;
    }

    public static function fromString(string $name): self
    {
        return new self($name);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function equals(CookieName $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
```

**Key Points:**
- DocBlocks with descriptions, params, returns
- Named static factory method
- Private constructor for immutability
- Strict type declarations
- Error codes for monitoring
- Small, focused methods
- Each validation step is separate

### Command Template (CQRS)

```php
<?php

declare(strict_types=1);

namespace App\Domain\[Domain]\Commands\[CommandName];

/**
 * Command to [describe what this command does]
 *
 * Commands represent the INTENT to perform an action.
 * This command contains all data needed to [describe action].
 *
 * Commands are:
 * - Immutable DTOs (Data Transfer Objects)
 * - Named in imperative ([CommandName], not [Entity][ActionName]ed)
 * - Validated by their handlers
 * - Do not contain business logic
 *
 * @package App\Domain\[Domain]\Commands\[CommandName]
 */
final readonly class [CommandName]Command
{
    /**
     * Create a new [CommandName]Command.
     *
     * @param string $param1 Description of param1 (e.g., The cookie name (3-100 chars))
     * @param string|null $param2 Description of param2 (e.g., The cookie description)
     * @param float $param3 Description of param3 (e.g., The cookie price (must be > 0))
     */
    public function __construct(
        public string $param1,
        public ?string $param2,
        public float $param3,
    ) {
    }
}
```

**Real Example from Cookie Domain:**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\CreateCookie;

/**
 * Command to create a new Cookie.
 *
 * Commands represent the INTENT to perform an action.
 * This command contains all data needed to create a cookie.
 *
 * @package App\Domain\Cookie\Commands\CreateCookie
 */
final readonly class CreateCookieCommand
{
    public function __construct(
        public string $name,
        public ?string $description,
        public float $price,
        public int $stock,
        public bool $isActive = true
    ) {
    }
}
```

### Command Handler Template (CQRS)

```php
<?php

declare(strict_types=1);

namespace App\CommandHandlers\[Domain];

use App\Commands\[Domain]\[CommandName]Command;
use App\Domain\[Domain]\[Entity];
use App\Domain\[Domain]\Repositories\[Entity]RepositoryInterface;

/**
 * Handler for [CommandName]Command
 *
 * Processes [describe what this handler does]
 *
 * @package App\CommandHandlers\[Domain]
 */
final class [CommandName]CommandHandler
{
    /**
     * Initialize handler with dependencies
     *
     * @param [Entity]RepositoryInterface $repository
     */
    public function __construct(
        private readonly [Entity]RepositoryInterface $repository
    ) {}

    /**
     * Handle the command
     *
     * @param [CommandName]Command $command
     * @return [Entity]Id
     * @throws \DomainException
     */
    public function handle([CommandName]Command $command): [Entity]Id
    {
        $this->validateCommand($command);

        $entity = $this->buildEntity($command);

        $this->saveEntity($entity);

        return $entity->getId();
    }

    /**
     * Validate command data
     *
     * @param [CommandName]Command $command
     * @return void
     * @throws \DomainException
     */
    private function validateCommand([CommandName]Command $command): void
    {
        // Validation logic
    }

    /**
     * Build entity from command
     *
     * @param [CommandName]Command $command
     * @return [Entity]
     */
    private function buildEntity([CommandName]Command $command): [Entity]
    {
        return [Entity]::create(
            // Map command properties to entity
        );
    }

    /**
     * Persist entity to repository
     *
     * @param [Entity] $entity
     * @return void
     */
    private function saveEntity([Entity] $entity): void
    {
        $this->repository->save($entity);
    }
}
```

**Key Points:**
- Handler has single public method: `handle()`
- Each step of handling is a private method
- Clear separation of concerns
- All methods are discoverable symbols

### Domain Entity Template

```php
<?php

declare(strict_types=1);

namespace App\Domain\[Domain];

use App\Domain\Shared\AggregateRoot;
use App\Domain\[Domain]\ValueObjects\[ValueObject];
use App\Domain\[Domain]\Events\[Event];

/**
 * [Entity name] domain entity
 *
 * [Description of what this entity represents in the domain]
 *
 * @package App\Domain\[Domain]
 */
final class [EntityName] extends AggregateRoot
{
    /**
     * Create new entity instance
     *
     * @param [ValueObject] $property1
     * @param [ValueObject] $property2
     * @return self
     */
    public static function create(
        [ValueObject] $property1,
        [ValueObject] $property2
    ): self {
        $entity = new self(
            [EntityName]Id::generate(),
            $property1,
            $property2,
            new \DateTimeImmutable()
        );

        $entity->recordCreationEvent($property1, $property2);

        return $entity;
    }

    /**
     * Private constructor for controlled instantiation
     *
     * @param [EntityName]Id $id
     * @param [ValueObject] $property1
     * @param [ValueObject] $property2
     * @param \DateTimeImmutable $createdAt
     */
    private function __construct(
        private [EntityName]Id $id,
        private [ValueObject] $property1,
        private [ValueObject] $property2,
        private \DateTimeImmutable $createdAt
    ) {}

    /**
     * Update entity property
     *
     * @param [ValueObject] $newValue
     * @return void
     */
    public function updateProperty([ValueObject] $newValue): void
    {
        if ($this->isPropertyUnchanged($newValue)) {
            return;
        }

        $this->applyPropertyChange($newValue);
        $this->recordPropertyChangeEvent($newValue);
    }

    /**
     * Check if property value is unchanged
     *
     * @param [ValueObject] $newValue
     * @return bool
     */
    private function isPropertyUnchanged([ValueObject] $newValue): bool
    {
        return $this->property1->equals($newValue);
    }

    /**
     * Apply property change
     *
     * @param [ValueObject] $newValue
     * @return void
     */
    private function applyPropertyChange([ValueObject] $newValue): void
    {
        $this->property1 = $newValue;
    }

    /**
     * Record domain event for property change
     *
     * @param [ValueObject] $newValue
     * @return void
     */
    private function recordPropertyChangeEvent([ValueObject] $newValue): void
    {
        $this->recordEvent(new [Event]($this->id, $newValue));
    }

    /**
     * Record creation domain event
     *
     * @param [ValueObject] $property1
     * @param [ValueObject] $property2
     * @return void
     */
    private function recordCreationEvent(
        [ValueObject] $property1,
        [ValueObject] $property2
    ): void {
        $this->recordEvent(new [EntityName]Created(
            $this->id,
            $property1,
            $property2,
            $this->createdAt
        ));
    }

    // Getters
    public function getId(): [EntityName]Id { return $this->id; }
    public function getProperty1(): [ValueObject] { return $this->property1; }
    public function getProperty2(): [ValueObject] { return $this->property2; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
```

### Controller Template (CodeIgniter4)

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;
use App\Commands\[Domain]\[CommandName]Command;
use App\CommandHandlers\[Domain]\[CommandName]CommandHandler;
use App\Queries\[Domain]\[QueryName]Query;
use App\QueryHandlers\[Domain]\[QueryName]QueryHandler;

/**
 * [Resource] API Controller
 *
 * Handles HTTP requests for [resource] management
 *
 * @package App\Controllers\Api
 */
class [ResourceName]Controller extends BaseController
{
    /**
     * Initialize controller with dependencies
     *
     * @param [CommandName]CommandHandler $commandHandler
     * @param [QueryName]QueryHandler $queryHandler
     */
    public function __construct(
        private readonly [CommandName]CommandHandler $commandHandler,
        private readonly [QueryName]QueryHandler $queryHandler
    ) {}

    /**
     * Create new resource
     *
     * @return ResponseInterface
     */
    public function create(): ResponseInterface
    {
        $validatedData = $this->validateCreateRequest();

        $command = $this->buildCreateCommand($validatedData);

        $id = $this->executeCreateCommand($command);

        return $this->respondWithCreatedResource($id);
    }

    /**
     * Get resource by ID
     *
     * @param string $id Resource ID
     * @return ResponseInterface
     */
    public function show(string $id): ResponseInterface
    {
        $query = $this->buildGetByIdQuery($id);

        $resource = $this->executeGetByIdQuery($query);

        if ($this->resourceNotFound($resource)) {
            return $this->respondWithNotFound();
        }

        return $this->respondWithResource($resource);
    }

    /**
     * Validate create request data
     *
     * @return array<string, mixed>
     * @throws \ValidationException
     */
    private function validateCreateRequest(): array
    {
        return $this->validate([
            'field1' => 'required|min_length[3]',
            'field2' => 'required|valid_email',
            'field3' => 'required|numeric',
        ]);
    }

    /**
     * Build create command from validated data
     *
     * @param array<string, mixed> $data
     * @return [CommandName]Command
     */
    private function buildCreateCommand(array $data): [CommandName]Command
    {
        return new [CommandName]Command(
            field1: $data['field1'],
            field2: $data['field2'],
            field3: (int) $data['field3']
        );
    }

    /**
     * Execute create command
     *
     * @param [CommandName]Command $command
     * @return [Resource]Id
     */
    private function executeCreateCommand([CommandName]Command $command): [Resource]Id
    {
        return $this->commandHandler->handle($command);
    }

    /**
     * Respond with created resource
     *
     * @param [Resource]Id $id
     * @return ResponseInterface
     */
    private function respondWithCreatedResource([Resource]Id $id): ResponseInterface
    {
        return $this->respond([
            'id' => $id->getValue(),
            'message' => '[Resource] created successfully'
        ], 201);
    }

    /**
     * Build get by ID query
     *
     * @param string $id
     * @return [QueryName]Query
     */
    private function buildGetByIdQuery(string $id): [QueryName]Query
    {
        return new [QueryName]Query($id);
    }

    /**
     * Execute get by ID query
     *
     * @param [QueryName]Query $query
     * @return [Resource]|null
     */
    private function executeGetByIdQuery([QueryName]Query $query): ?[Resource]
    {
        return $this->queryHandler->handle($query);
    }

    /**
     * Check if resource was not found
     *
     * @param [Resource]|null $resource
     * @return bool
     */
    private function resourceNotFound(?[Resource] $resource): bool
    {
        return $resource === null;
    }

    /**
     * Respond with resource data
     *
     * @param [Resource] $resource
     * @return ResponseInterface
     */
    private function respondWithResource([Resource] $resource): ResponseInterface
    {
        return $this->respond([
            'id' => $resource->getId()->getValue(),
            // Map other properties
        ]);
    }

    /**
     * Respond with not found error
     *
     * @return ResponseInterface
     */
    private function respondWithNotFound(): ResponseInterface
    {
        return $this->failNotFound('[Resource] not found');
    }
}
```

## Code Review Checklist

When reviewing or generating PHP code, verify:

### Symbol Structure
- [ ] All classes have clear, descriptive names (PSR-4 compliant)
- [ ] No anonymous classes or closures as primary logic
- [ ] Methods are not deeply nested
- [ ] Classes follow Single Responsibility Principle
- [ ] Clear namespace hierarchy

### Naming Conventions
- [ ] Class names are PascalCase
- [ ] Method names are camelCase
- [ ] Constants are UPPER_SNAKE_CASE
- [ ] No generic names (`Utils`, `Helper`, `Manager`)
- [ ] Names indicate purpose (`validateEmailFormat` not `validate`)

### Method Size
- [ ] No method exceeds 50 lines
- [ ] Each method has single responsibility
- [ ] Large methods are split into multiple symbols
- [ ] Helper logic extracted to private methods

### Documentation
- [ ] All public methods have DocBlocks
- [ ] DocBlocks include @param and @return
- [ ] Complex logic has inline comments
- [ ] @throws documented for exceptions

### Type Safety
- [ ] `declare(strict_types=1)` at top of file
- [ ] All parameters have type hints
- [ ] All return types declared
- [ ] Readonly properties where applicable

### CQRS/DDD Patterns
- [ ] Commands are immutable DTOs
- [ ] Handlers have single `handle()` method
- [ ] Entities use static factory methods
- [ ] Value Objects are immutable and validated
- [ ] Domain events recorded properly

### File Organization
- [ ] Files < 500 lines
- [ ] Clear file naming (matches class name)
- [ ] Related functionality grouped in namespace
- [ ] No circular dependencies

## Anti-Patterns to Fix

### ❌ Bad: Generic Service Class

```php
<?php

class UserService
{
    public function process($data, $type) {
        // 200 lines of mixed responsibilities
    }
}
```

### ✅ Good: Specific Handler Classes

```php
<?php

class CreateUserCommandHandler
{
    public function handle(CreateUserCommand $command): UserId
    {
        // Focused responsibility (< 50 lines)
    }
}

class UpdateUserCommandHandler
{
    public function handle(UpdateUserCommand $command): void
    {
        // Focused responsibility (< 50 lines)
    }
}
```

---

### ❌ Bad: Anonymous Closures

```php
<?php

$validators = [
    'email' => function($x) { return filter_var($x, FILTER_VALIDATE_EMAIL); },
    'phone' => function($x) { return preg_match('/^\d{10}$/', $x); },
];
```

### ✅ Good: Named Validation Methods

```php
<?php

class UserDataValidator
{
    public function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function validatePhone(string $phone): bool
    {
        return preg_match('/^\d{10}$/', $phone) === 1;
    }
}
```

---

### ❌ Bad: Mega Method

```php
<?php

public function createUserAndSendEmailAndLog($data) {
    // Validation logic (30 lines)
    // Database insert logic (30 lines)
    // Email sending logic (30 lines)
    // Logging logic (30 lines)
}
```

### ✅ Good: Small, Focused Methods

```php
<?php

public function handle(CreateUserCommand $command): UserId
{
    $this->validateCommand($command);
    $user = $this->createUser($command);
    $this->sendWelcomeEmail($user);
    $this->logUserCreation($user);
    return $user->getId();
}

private function validateCommand(CreateUserCommand $command): void { }
private function createUser(CreateUserCommand $command): User { }
private function sendWelcomeEmail(User $user): void { }
private function logUserCreation(User $user): void { }
```

## Best Practices

1. **Always start with the template** - Use provided templates as starting point
2. **Extract methods early** - Don't wait until methods are too large
3. **Name everything clearly** - No generic names or abbreviations
4. **Document as you write** - DocBlocks while coding, not after
5. **Think in symbols** - Every piece of logic should be a findable symbol
6. **Keep it flat** - Avoid deep nesting, prefer clear structure
7. **Review before committing** - Use the checklist to verify
8. **Follow PSR standards** - PSR-4 for autoloading, PSR-12 for style
9. **Use strict types** - Always declare strict types
10. **Immutability** - Prefer readonly properties and immutable objects

## Summary

The Serena Code Generator skill ensures all PHP code is optimized for Serena's semantic intelligence. By following symbol-first thinking, using clear naming, keeping methods small, and maintaining flat structures, the code becomes:

- ✅ **Instantly findable** - Serena can locate any symbol
- ✅ **Precisely editable** - Edit at symbol boundaries
- ✅ **Safely refactorable** - Track all usages across codebase
- ✅ **Efficiently navigable** - No need to read entire files
- ✅ **AI-friendly** - Perfect for AI-assisted development
- ✅ **CQRS-compliant** - Follows Command/Query separation
- ✅ **DDD-aligned** - Respects domain boundaries

**Use this skill for EVERY PHP code generation task to maintain Serena compatibility!**

---

**Last Updated:** 2025-10-25
**Project:** CQRSTemplate (CodeIgniter4 CQRS)
**Language:** PHP
**Related Documents:**
- `.claude/SERENA_CODE_OPTIMIZATION.md` - Complete optimization guide
- `.claude/CLAUDE.md` - Project-wide Serena requirements
- `.serena/project.yml` - Serena project configuration
