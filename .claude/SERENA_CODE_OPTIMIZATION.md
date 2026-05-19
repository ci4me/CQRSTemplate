# Serena Code Optimization Guide (PHP)

**Purpose:** Guidelines for writing PHP code that works optimally with Serena's LSP-based semantic intelligence

**Date:** 2025-10-25
**Project:** CQRSTemplate (CodeIgniter4 CQRS)
**Language:** PHP

---

## Core Principle

**Serena works at the SYMBOL level, not the TEXT level.**

This means code should be structured for **semantic understanding** rather than string matching. Every function, class, method, constant, and namespace should be a clear, discoverable symbol with well-defined boundaries.

---

## 🎯 The Golden Rules (PHP Edition)

### 1. **Clear Symbol Boundaries**

**✅ GOOD - Serena can find and edit precisely:**
```php
<?php

namespace App\Commands\User;

use App\Domain\User\ValueObjects\Email;

class CreateUserCommand
{
    public function __construct(
        public readonly Email $email,
        public readonly string $name
    ) {}
}

class CreateUserCommandHandler
{
    public function handle(CreateUserCommand $command): UserId
    {
        $this->validateUserData($command);
        return $this->createUser($command);
    }

    private function validateUserData(CreateUserCommand $command): void
    {
        // Validation logic
    }

    private function createUser(CreateUserCommand $command): UserId
    {
        // Creation logic
    }
}
```

**❌ BAD - Symbols are unclear or nested:**
```php
<?php

class UserService
{
    public function execute($type, $data)
    {
        // Anonymous function - not a top-level symbol
        $validate = function($d) {
            return $d['email'] && $d['name'];
        };

        // Another anonymous function
        $create = function($d) use ($validate) {
            if (!$validate($d)) return null;
            // Creation logic
        };

        return $create($data);
    }
}
```

**Why?** Serena can `find_symbol("validateUserData")` in the good example, but struggles with anonymous functions.

### 2. **Explicit Named Classes and Methods**

**✅ GOOD - Symbols are discoverable:**
```php
<?php

namespace App\Services\User;

class UserRegistrationService
{
    public function registerUser(array $userData): User {}
    public function validateRegistration(array $userData): bool {}
    public function sendWelcomeEmail(User $user): void {}
}

class UserAuthenticationService
{
    public function authenticate(string $email, string $password): bool {}
    public function generateToken(User $user): string {}
    public function revokeToken(string $token): void {}
}
```

**❌ BAD - Generic or ambiguous names:**
```php
<?php

class UserService  // Too generic
{
    public function process($x) {}  // Process what?
    public function handle($y) {}    // Handle what?
    public function do($z) {}        // Do what?
}
```

**Why?** When you ask Serena to "find the user registration function", it should find `registerUser`, not guess between 20 `handle` methods.

### 3. **Descriptive Symbol Names (PSR-12 Compliant)**

**✅ GOOD - Clear, searchable names:**
```php
<?php

class EmailValidator
{
    public function validateEmailFormat(string $email): bool {}
    public function checkEmailDomain(string $email): bool {}
    public function isDisposableEmail(string $email): bool {}
}

class UserNameFormatter
{
    public function formatUserDisplayName(User $user): string {}
    public function generateUsernameFromEmail(string $email): string {}
    public function sanitizeUsername(string $username): string {}
}
```

**❌ BAD - Generic or ambiguous names:**
```php
<?php

class Utils  // What utilities?
{
    public function validate($x) {}  // Validate what?
    public function format($y) {}    // Format what?
    public function calc($z) {}      // Calculate what?
}
```

### 4. **One Symbol Per Logical Unit**

**✅ GOOD - Each concept is a symbol:**
```php
<?php

namespace App\Domain\User;

class User extends AggregateRoot
{
    private function __construct(
        private UserId $id,
        private Email $email,
        private UserName $name
    ) {}

    public static function create(Email $email, UserName $name): self
    {
        $user = new self(
            UserId::generate(),
            $email,
            $name
        );

        $user->recordEvent(new UserCreated($user->id, $email, $name));

        return $user;
    }

    public function updateEmail(Email $newEmail): void
    {
        $this->validateEmailChange($newEmail);
        $this->applyEmailChange($newEmail);
    }

    private function validateEmailChange(Email $newEmail): void
    {
        // Validation logic (separate method)
    }

    private function applyEmailChange(Email $newEmail): void
    {
        // Application logic (separate method)
    }
}
```

**❌ BAD - Multiple concepts in one symbol:**
```php
<?php

class User
{
    public function updateEmailAndSendNotification($email)
    {
        // Validation logic (30 lines)
        // Database update logic (30 lines)
        // Email sending logic (30 lines)
        // Logging logic (30 lines)
        // Hard to edit just one part
    }
}
```

**Why?** Serena can `insert_after_symbol("validateEmailChange")` vs. manually editing a 120-line method.

### 5. **Flat Namespace Structure**

**✅ GOOD - Clear namespace hierarchy:**
```php
<?php

// app/Domain/User/ValueObjects/Email.php
namespace App\Domain\User\ValueObjects;

class Email
{
    public function __construct(private readonly string $value) {}
    public function getValue(): string {}
}

// app/Domain/User/ValueObjects/UserName.php
namespace App\Domain\User\ValueObjects;

class UserName
{
    public function __construct(private readonly string $value) {}
    public function getValue(): string {}
}

// app/Domain/User/Events/UserCreated.php
namespace App\Domain\User\Events;

class UserCreated extends DomainEvent
{
    public function __construct(
        public readonly UserId $userId,
        public readonly Email $email,
        public readonly UserName $name
    ) {}
}
```

**❌ BAD - Deeply nested or unclear structure:**
```php
<?php

namespace App;

class ValueObjects  // Everything in one class?
{
    public static function email($value) {}
    public static function name($value) {}
    public static function address($value) {}
}
```

**Why?** Serena can easily find `App\Domain\User\ValueObjects\Email` but struggles with static method calls.

---

## 📁 Project Structure for Serena (CodeIgniter4 CQRS)

### Recommended Directory Layout

```
app/
├── Commands/           # CQRS Command classes
│   ├── User/
│   │   ├── CreateUserCommand.php
│   │   ├── UpdateUserCommand.php
│   │   └── DeleteUserCommand.php
│   └── Product/
│       ├── CreateProductCommand.php
│       └── UpdateProductCommand.php
├── CommandHandlers/    # CQRS Command handlers
│   ├── User/
│   │   ├── CreateUserCommandHandler.php
│   │   └── UpdateUserCommandHandler.php
│   └── Product/
│       └── CreateProductCommandHandler.php
├── Queries/            # CQRS Query classes
│   ├── User/
│   │   ├── GetUserByIdQuery.php
│   │   └── GetAllUsersQuery.php
│   └── Product/
│       └── GetProductByIdQuery.php
├── QueryHandlers/      # CQRS Query handlers
│   ├── User/
│   │   ├── GetUserByIdQueryHandler.php
│   │   └── GetAllUsersQueryHandler.php
│   └── Product/
│       └── GetProductByIdQueryHandler.php
├── Domain/             # Domain models
│   ├── User/
│   │   ├── User.php
│   │   ├── UserId.php
│   │   ├── ValueObjects/
│   │   │   ├── Email.php
│   │   │   └── UserName.php
│   │   ├── Events/
│   │   │   ├── UserCreated.php
│   │   │   └── UserUpdated.php
│   │   └── Repositories/
│   │       └── UserRepositoryInterface.php
│   └── Product/
│       ├── Product.php
│       └── ProductId.php
├── Infrastructure/     # Infrastructure implementations
│   ├── Repositories/
│   │   ├── User/
│   │   │   └── CodeIgniterUserRepository.php
│   │   └── Product/
│   │       └── CodeIgniterProductRepository.php
│   └── Services/
│       ├── EmailService.php
│       └── CacheService.php
└── Controllers/        # HTTP Controllers
    ├── Api/
    │   ├── UserController.php
    │   └── ProductController.php
    └── Web/
        └── HomeController.php
```

**Key Points:**
- Each file has a clear purpose
- Each file exports well-named classes
- Related functionality is grouped
- Serena can quickly find: "CreateUserCommand class", "Email value object", "UserRepository"

### File Naming Conventions (PSR-4)

**✅ GOOD:**
- `CreateUserCommand.php` → contains `CreateUserCommand` class
- `UserRepositoryInterface.php` → contains `UserRepositoryInterface`
- `CodeIgniterUserRepository.php` → contains `CodeIgniterUserRepository`

**❌ BAD:**
- `utils.php` → contains many unrelated functions (too generic)
- `helpers.php` → contains random functions (unclear)
- `functions.php` → procedural functions (prefer classes)

---

## 🔧 Code Patterns for Serena (PHP)

### Value Objects

**✅ GOOD - Clear value object symbols:**
```php
<?php

namespace App\Domain\User\ValueObjects;

final class Email
{
    private const PATTERN = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';

    private function __construct(private readonly string $value)
    {
        $this->validate();
    }

    public static function fromString(string $email): self
    {
        return new self($email);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function equals(Email $other): bool
    {
        return $this->value === $other->value;
    }

    private function validate(): void
    {
        if (!$this->isValidFormat()) {
            throw new \InvalidArgumentException("Invalid email format");
        }

        if ($this->isDisposableEmail()) {
            throw new \InvalidArgumentException("Disposable emails not allowed");
        }
    }

    private function isValidFormat(): bool
    {
        return preg_match(self::PATTERN, $this->value) === 1;
    }

    private function isDisposableEmail(): bool
    {
        // Check against disposable email domains
        return false;
    }
}
```

**Serena can:**
- `find_symbol("Email")` → Find value object
- `find_symbol("Email/validate")` → Find validation method
- `find_referencing_symbols("Email")` → Find all usages

### Commands and Handlers (CQRS)

**✅ GOOD - Clear command/handler symbols:**
```php
<?php

namespace App\Commands\User;

final class CreateUserCommand
{
    public function __construct(
        public readonly string $email,
        public readonly string $name,
        public readonly string $password
    ) {}
}

namespace App\CommandHandlers\User;

use App\Commands\User\CreateUserCommand;
use App\Domain\User\User;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\UserName;

final class CreateUserCommandHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordHasher $passwordHasher
    ) {}

    public function handle(CreateUserCommand $command): UserId
    {
        $email = $this->createEmailValueObject($command->email);
        $name = $this->createUserNameValueObject($command->name);
        $hashedPassword = $this->hashPassword($command->password);

        $user = $this->buildUser($email, $name, $hashedPassword);

        $this->saveUser($user);

        return $user->getId();
    }

    private function createEmailValueObject(string $email): Email
    {
        return Email::fromString($email);
    }

    private function createUserNameValueObject(string $name): UserName
    {
        return UserName::fromString($name);
    }

    private function hashPassword(string $password): string
    {
        return $this->passwordHasher->hash($password);
    }

    private function buildUser(Email $email, UserName $name, string $password): User
    {
        return User::create($email, $name, $password);
    }

    private function saveUser(User $user): void
    {
        $this->userRepository->save($user);
    }
}
```

**Serena can:**
- `find_symbol("CreateUserCommand")` → Find command
- `find_symbol("CreateUserCommandHandler")` → Find handler
- `find_symbol("CreateUserCommandHandler/handle")` → Find handle method
- `find_referencing_symbols("UserRepositoryInterface")` → Find all repository usages

### Domain Entities

**✅ GOOD - Clear entity symbols:**
```php
<?php

namespace App\Domain\User;

use App\Domain\Shared\AggregateRoot;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\Events\UserCreated;
use App\Domain\User\Events\UserEmailUpdated;

final class User extends AggregateRoot
{
    private function __construct(
        private UserId $id,
        private Email $email,
        private UserName $name,
        private string $hashedPassword,
        private \DateTimeImmutable $createdAt
    ) {}

    public static function create(
        Email $email,
        UserName $name,
        string $hashedPassword
    ): self {
        $user = new self(
            UserId::generate(),
            $email,
            $name,
            $hashedPassword,
            new \DateTimeImmutable()
        );

        $user->recordEvent(new UserCreated(
            $user->id,
            $user->email,
            $user->name,
            $user->createdAt
        ));

        return $user;
    }

    public function updateEmail(Email $newEmail): void
    {
        if ($this->isEmailAlreadySet($newEmail)) {
            return;
        }

        $this->applyEmailChange($newEmail);
        $this->recordEmailChangeEvent($newEmail);
    }

    private function isEmailAlreadySet(Email $newEmail): bool
    {
        return $this->email->equals($newEmail);
    }

    private function applyEmailChange(Email $newEmail): void
    {
        $this->email = $newEmail;
    }

    private function recordEmailChangeEvent(Email $newEmail): void
    {
        $this->recordEvent(new UserEmailUpdated($this->id, $newEmail));
    }

    // Getters
    public function getId(): UserId { return $this->id; }
    public function getEmail(): Email { return $this->email; }
    public function getName(): UserName { return $this->name; }
}
```

**Serena can:**
- `find_symbol("User")` → Find entity
- `find_symbol("User/updateEmail")` → Find method
- `find_referencing_symbols("UserCreated")` → Find all event usages

---

## 📝 Documentation for Serena (PHP)

### DocBlocks (PHP's JSDoc)

**✅ GOOD - Complete DocBlocks:**
```php
<?php

namespace App\Services\Email;

/**
 * Validates email addresses according to RFC 5322
 *
 * This service provides comprehensive email validation including
 * format checking, domain verification, and disposable email detection.
 *
 * @package App\Services\Email
 * @author Your Name
 */
final class EmailValidator
{
    /**
     * Validate email format
     *
     * Checks if the provided email address conforms to RFC 5322 standard
     * and is not from a known disposable email provider.
     *
     * @param string $email Email address to validate
     * @return bool True if valid, false otherwise
     *
     * @example
     * ```php
     * $validator = new EmailValidator();
     * $validator->validateEmailFormat('user@example.com'); // true
     * $validator->validateEmailFormat('invalid'); // false
     * ```
     */
    public function validateEmailFormat(string $email): bool
    {
        return $this->matchesRFC5322Pattern($email)
            && !$this->isDisposableEmailDomain($email);
    }

    /**
     * Check if email matches RFC 5322 pattern
     *
     * @param string $email Email to check
     * @return bool True if matches pattern
     */
    private function matchesRFC5322Pattern(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Check if email is from disposable email provider
     *
     * @param string $email Email to check
     * @return bool True if disposable
     */
    private function isDisposableEmailDomain(string $email): bool
    {
        // Implementation
        return false;
    }
}
```

**Why?** LSP servers use DocBlocks to provide better symbol information to Serena.

### Type Declarations (PHP 7.4+)

**✅ GOOD - Strict type declarations:**
```php
<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Domain\User\User;
use App\Domain\User\UserId;
use App\Domain\User\ValueObjects\Email;

/**
 * Service for managing user data
 */
final class UserManagementService
{
    public function __construct(
        private readonly UserRepositoryInterface $repository
    ) {}

    /**
     * Find user by ID
     *
     * @param UserId $userId User identifier
     * @return User|null User if found, null otherwise
     */
    public function findUserById(UserId $userId): ?User
    {
        return $this->repository->findById($userId);
    }

    /**
     * Find users by email domain
     *
     * @param string $domain Email domain
     * @return array<int, User> Array of users
     */
    public function findUsersByDomain(string $domain): array
    {
        return $this->repository->findByEmailDomain($domain);
    }
}
```

**Why?** Strong typing helps LSP provide better symbol resolution.

---

## 🚫 Anti-Patterns to Avoid (PHP)

### 1. **Dynamic Method Calls**

**❌ AVOID:**
```php
<?php

class ServiceDispatcher
{
    private array $services = [
        'user' => UserService::class,
        'product' => ProductService::class,
    ];

    public function call(string $service, string $method, array $args)
    {
        $instance = new $this->services[$service]();
        return $instance->$method(...$args);  // Serena can't track this
    }
}
```

**✅ PREFER:**
```php
<?php

interface ServiceInterface
{
    public function execute(array $args): mixed;
}

class UserService implements ServiceInterface
{
    public function execute(array $args): mixed {}
}

class ServiceDispatcher
{
    public function __construct(
        private readonly UserService $userService,
        private readonly ProductService $productService
    ) {}

    public function callUserService(array $args): mixed
    {
        return $this->userService->execute($args);
        // Serena can track UserService usage
    }

    public function callProductService(array $args): mixed
    {
        return $this->productService->execute($args);
        // Serena can track ProductService usage
    }
}
```

### 2. **Anonymous Classes and Closures in Arrays**

**❌ AVOID:**
```php
<?php

class EventHandlers
{
    private array $handlers = [
        'user.created' => function($event) {  // Not a discoverable symbol
            // Handle event
        },
        'user.updated' => function($event) {  // Not a discoverable symbol
            // Handle event
        },
    ];
}
```

**✅ PREFER:**
```php
<?php

class UserCreatedEventHandler
{
    public function handle(UserCreated $event): void
    {
        // Handle event (discoverable symbol)
    }
}

class UserUpdatedEventHandler
{
    public function handle(UserUpdated $event): void
    {
        // Handle event (discoverable symbol)
    }
}

class EventDispatcher
{
    public function __construct(
        private readonly UserCreatedEventHandler $userCreatedHandler,
        private readonly UserUpdatedEventHandler $userUpdatedHandler
    ) {}

    public function dispatch(DomainEvent $event): void
    {
        if ($event instanceof UserCreated) {
            $this->userCreatedHandler->handle($event);
        } elseif ($event instanceof UserUpdated) {
            $this->userUpdatedHandler->handle($event);
        }
    }
}
```

### 3. **Mega Classes (God Objects)**

**❌ AVOID:**
```php
<?php

class UserService  // 2000 lines
{
    public function createUser() {}       // 100 lines
    public function updateUser() {}       // 100 lines
    public function deleteUser() {}       // 100 lines
    public function validateUser() {}     // 100 lines
    public function sendEmail() {}        // 100 lines
    public function generateReport() {}   // 100 lines
    // ... 20 more methods
}
```

**✅ PREFER:**
```php
<?php

// Domain/User/Services/UserCreationService.php
class UserCreationService
{
    public function createUser(CreateUserCommand $command): User {}
}

// Domain/User/Services/UserUpdateService.php
class UserUpdateService
{
    public function updateUser(UpdateUserCommand $command): User {}
}

// Domain/User/Services/UserValidationService.php
class UserValidationService
{
    public function validateUserData(array $data): bool {}
}

// Infrastructure/Email/UserEmailService.php
class UserEmailService
{
    public function sendWelcomeEmail(User $user): void {}
}

// Infrastructure/Reports/UserReportGenerator.php
class UserReportGenerator
{
    public function generateUserReport(UserId $userId): Report {}
}
```

### 4. **Static Helper Classes**

**❌ AVOID:**
```php
<?php

class StringHelper
{
    public static function validate($x) {}
    public static function format($y) {}
    public static function sanitize($z) {}
}

class ArrayHelper
{
    public static function merge($a, $b) {}
    public static function filter($arr) {}
}
```

**✅ PREFER:**
```php
<?php

namespace App\Domain\Shared\ValueObjects;

final class SanitizedString
{
    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        return new self(self::sanitize($value));
    }

    public function getValue(): string
    {
        return $this->value;
    }

    private static function sanitize(string $value): string
    {
        return trim(strip_tags($value));
    }
}

namespace App\Infrastructure\Collections;

final class Collection
{
    public function __construct(private readonly array $items) {}

    public function merge(Collection $other): self
    {
        return new self(array_merge($this->items, $other->items));
    }

    public function filter(callable $callback): self
    {
        return new self(array_filter($this->items, $callback));
    }
}
```

---

## 🎨 CodeIgniter4 Specific Patterns

### Controllers

**✅ Serena-Optimized Controller:**
```php
<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;
use App\Commands\User\CreateUserCommand;
use App\CommandHandlers\User\CreateUserCommandHandler;
use App\Queries\User\GetUserByIdQuery;
use App\QueryHandlers\User\GetUserByIdQueryHandler;

class UserController extends BaseController
{
    public function __construct(
        private readonly CreateUserCommandHandler $createUserHandler,
        private readonly GetUserByIdQueryHandler $getUserHandler
    ) {}

    /**
     * Create new user
     *
     * @return ResponseInterface
     */
    public function create(): ResponseInterface
    {
        $validatedData = $this->validateCreateUserRequest();
        $command = $this->buildCreateUserCommand($validatedData);
        $userId = $this->executeCreateUserCommand($command);

        return $this->respondWithCreatedUser($userId);
    }

    /**
     * Get user by ID
     *
     * @param string $id User ID
     * @return ResponseInterface
     */
    public function show(string $id): ResponseInterface
    {
        $query = $this->buildGetUserQuery($id);
        $user = $this->executeGetUserQuery($query);

        if ($this->userNotFound($user)) {
            return $this->respondWithNotFound();
        }

        return $this->respondWithUser($user);
    }

    private function validateCreateUserRequest(): array
    {
        return $this->validate([
            'email' => 'required|valid_email',
            'name' => 'required|min_length[3]',
            'password' => 'required|min_length[8]',
        ]);
    }

    private function buildCreateUserCommand(array $data): CreateUserCommand
    {
        return new CreateUserCommand(
            email: $data['email'],
            name: $data['name'],
            password: $data['password']
        );
    }

    private function executeCreateUserCommand(CreateUserCommand $command): UserId
    {
        return $this->createUserHandler->handle($command);
    }

    private function respondWithCreatedUser(UserId $userId): ResponseInterface
    {
        return $this->respond([
            'id' => $userId->getValue(),
            'message' => 'User created successfully'
        ], 201);
    }

    private function buildGetUserQuery(string $id): GetUserByIdQuery
    {
        return new GetUserByIdQuery($id);
    }

    private function executeGetUserQuery(GetUserByIdQuery $query): ?User
    {
        return $this->getUserHandler->handle($query);
    }

    private function userNotFound(?User $user): bool
    {
        return $user === null;
    }

    private function respondWithUser(User $user): ResponseInterface
    {
        return $this->respond([
            'id' => $user->getId()->getValue(),
            'email' => $user->getEmail()->getValue(),
            'name' => $user->getName()->getValue(),
        ]);
    }

    private function respondWithNotFound(): ResponseInterface
    {
        return $this->failNotFound('User not found');
    }
}
```

**Serena benefits:**
- Each method is a clear symbol
- Small, focused methods (< 20 lines)
- Easy to find and edit specific parts
- Clear dependencies

---

## ✅ Checklist for Serena-Optimized PHP Code

When writing new code:

- [ ] Each class/method has a clear, descriptive name
- [ ] Classes follow Single Responsibility Principle
- [ ] Methods are < 50 lines (prefer < 20 lines)
- [ ] No deeply nested structures
- [ ] DocBlocks for all public APIs
- [ ] Clear namespace boundaries (PSR-4)
- [ ] No circular dependencies
- [ ] Strict type declarations (`declare(strict_types=1)`)
- [ ] Prefer composition over inheritance
- [ ] Value objects for domain concepts
- [ ] Command/Query separation (CQRS)
- [ ] No static helper classes
- [ ] No anonymous classes in arrays
- [ ] Tests are well-organized with clear names
- [ ] No mega-classes (< 300 lines per file)

---

## 🎯 Summary

**The Serena Mindset (PHP Edition):**

> "Write code as if you're building a library. Every class should be discoverable, every method should be clear, every namespace should have a single purpose."

**Key Principles:**
1. **Symbol-first thinking** - Code is made of symbols (classes, methods), not text
2. **Explicit over implicit** - Clear class names, typed parameters, DocBlocks
3. **Flat over nested** - Clear namespace hierarchy, avoid deep nesting
4. **Small over large** - Small methods (< 20 lines), focused classes (< 300 lines)
5. **Documented over undocumented** - DocBlocks help LSP help Serena
6. **CQRS patterns** - Commands, Queries, Handlers are all discoverable symbols
7. **Value Objects** - Domain concepts as first-class symbols
8. **PSR compliance** - Follow PSR-4, PSR-12 standards

**The Result:**
- Serena can find any symbol instantly
- Serena can track all usages accurately
- Serena can edit precisely at symbol boundaries
- Serena can refactor across the entire codebase safely

**This makes AI-assisted development 10x more powerful!** 🚀

---

**Last Updated:** 2025-10-25
**Project:** CQRSTemplate (CodeIgniter4 CQRS)
**Language:** PHP
**For:** Serena LSP-Based Semantic Code Intelligence
