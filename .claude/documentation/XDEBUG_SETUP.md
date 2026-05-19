# Xdebug Setup Guide

This guide helps you install and configure Xdebug 3.x for debugging PHP code and tests.

## Installation

### Ubuntu/Debian

```bash
# Install Xdebug for PHP 8.4
sudo apt update
sudo apt install php8.4-xdebug

# Verify installation
php -v  # Should show "with Xdebug v3.x"
php -m | grep xdebug
```

### Alternative: Install via PECL

```bash
sudo pecl install xdebug

# Add to php.ini
echo "zend_extension=xdebug.so" | sudo tee /etc/php/8.4/cli/conf.d/99-xdebug.ini
```

## Configuration

### For CLI Debugging (PHPUnit, Scripts)

**File**: `/etc/php/8.4/cli/conf.d/99-xdebug.ini`

```ini
zend_extension=xdebug.so

; Xdebug 3.x Configuration
xdebug.mode=debug,coverage
xdebug.start_with_request=trigger
xdebug.client_host=127.0.0.1
xdebug.client_port=9003
xdebug.log=/tmp/xdebug.log
xdebug.idekey=VSCODE

; Optional: Better var_dump output
xdebug.var_display_max_depth=5
xdebug.var_display_max_children=256
xdebug.var_display_max_data=1024
```

### For Web Debugging (Apache/Nginx)

**File**: `/etc/php/8.4/fpm/conf.d/99-xdebug.ini` (same content as above)

Then restart PHP-FPM:
```bash
sudo systemctl restart php8.4-fpm
```

## VS Code Setup

### 1. Install PHP Debug Extension

Open VS Code Extensions (Ctrl+Shift+X) and install:
- **PHP Debug** by Xdebug

### 2. Configuration Files

Already created in `.vscode/`:
- `launch.json` - Debug configurations
- `settings.json` - VS Code settings

### 3. Available Debug Configurations

**A. Listen for Xdebug** (Browser/General Debugging)
1. Set breakpoint in code (e.g., `CookieController.php:139`)
2. Press F5 → Select "Listen for Xdebug"
3. Trigger code execution (browser request, CLI script)
4. Debugger will pause at breakpoint

**B. Debug Current PHPUnit Test**
1. Open test file (e.g., `CookieTest.php`)
2. Set breakpoint in test method
3. Press F5 → Select "Debug Current PHPUnit Test"
4. Only runs the current file

**C. Debug All PHPUnit Tests**
1. Set breakpoints anywhere in code or tests
2. Press F5 → Select "Debug All PHPUnit Tests"
3. Runs entire test suite with debugging

**D. Debug PHP Spark Serve**
1. Set breakpoints in controllers/handlers
2. Press F5 → Select "Debug PHP Spark Serve"
3. Server runs with debugging enabled
4. Make browser requests to trigger breakpoints

## Usage Examples

### Example 1: Debug a Failing Test

```php
// tests/Unit/Domain/Cookie/Entities/CookieTest.php

public function test_create_with_invalid_stock_throws_exception(): void
{
    // Set breakpoint on next line
    $this->expectException(ValidationException::class);

    Cookie::create(
        name: CookieName::fromString('Test'),
        description: null,
        price: CookiePrice::fromFloat(1.99),
        stock: -5,  // Invalid - breakpoint will pause here
        isActive: true
    );
}
```

**Steps:**
1. Set breakpoint on line with `stock: -5`
2. Run "Debug Current PHPUnit Test"
3. Step through `Cookie::create()` to see validation logic

### Example 2: Debug Command Handler

```php
// app/Domain/Cookie/Commands/CreateCookie/CreateCookieHandler.php

public function handle(CreateCookieCommand $command): int
{
    // Set breakpoint here
    $name = CookieName::fromString($command->name);

    // Step through to see name validation
    if ($this->repository->existsByName($name->getValue())) {
        // Breakpoint here to see uniqueness check
        throw DomainException::businessRuleViolation(...);
    }

    $cookie = Cookie::create(...);
    // ... rest
}
```

**Steps:**
1. Set breakpoints at validation points
2. Run test or make HTTP request
3. Inspect `$command`, `$name`, `$cookie` variables

### Example 3: Debug HTTP Request

```php
// app/Controllers/Domain/Cookie/CookieController.php

public function store(): RedirectResponse
{
    $commandBus = Services::commandBus();

    try {
        $nameParam = $this->request->getPost('name');
        // Set breakpoint here to inspect POST data
        $name = is_string($nameParam) ? $nameParam : '';

        // ... create command
        $cookieId = $commandBus->dispatch($command);
        // Breakpoint here to see result

        return redirect()->to("/cookies/{$cookieId}")
            ->with('success', 'Cookie created successfully');
    } catch (ValidationException $e) {
        // Breakpoint here to inspect validation errors
        return redirect()->back()
            ->with('errors', $e->getErrors());
    }
}
```

**Steps:**
1. Start "Debug PHP Spark Serve"
2. Set breakpoints in controller
3. Submit form in browser
4. VS Code pauses at breakpoints

## Debugging Tips

### Inspect Variables

When paused at breakpoint:
- **Hover** over variable to see value
- **Variables panel** (left sidebar) shows all local variables
- **Watch panel** - Add expressions to monitor
- **Debug Console** - Execute PHP expressions

### Step Through Code

- **F10** - Step Over (next line, don't enter functions)
- **F11** - Step Into (enter function calls)
- **Shift+F11** - Step Out (exit current function)
- **F5** - Continue (run to next breakpoint)

### Conditional Breakpoints

Right-click breakpoint → "Edit Breakpoint" → Add condition:
```php
$command->name === 'Chocolate Chip'  // Only pause for this name
$cookieId > 100  // Only pause for high IDs
```

### Log Points

Right-click line → "Add Logpoint" → Enter message:
```
Cookie created: {$cookie->getName()->getValue()}
```
Logs without pausing execution.

## Common Issues

### "Cannot find Xdebug"

**Solution**: Check PHP version matches Xdebug:
```bash
php -v
php -m | grep xdebug
```

### "Breakpoint not hit"

**Solutions**:
1. Check `xdebug.mode=debug` in php.ini
2. Verify port 9003 is not in use: `sudo lsof -i :9003`
3. Check path mappings in `launch.json`
4. Look at `/tmp/xdebug.log` for errors

### "Step debugging is slow"

**Solution**: Disable code coverage when debugging:
```bash
php -dxdebug.mode=debug vendor/bin/phpunit  # Coverage OFF
```

Or use configuration "Debug All PHPUnit Tests" which already has `--no-coverage`.

## Performance Notes

### Xdebug vs PCOV

**For Code Coverage:**
- ✅ **PCOV** - 10-20x faster (already installed)
- ❌ **Xdebug** - Slower but more features

**For Debugging:**
- ✅ **Xdebug** - Full step debugging, variable inspection
- ❌ **PCOV** - No debugging features

**Recommendation**: Use both!
- PCOV for fast test coverage: `composer test:coverage`
- Xdebug for debugging: VS Code debug mode

### Disable Xdebug When Not Debugging

```bash
# Disable
sudo phpdismod -s cli xdebug

# Enable
sudo phpenmod -s cli xdebug

# Or use environment variable
php -dxdebug.mode=off vendor/bin/phpunit  # Xdebug completely off
```

## Resources

- [Xdebug 3.x Documentation](https://xdebug.org/docs/)
- [VS Code PHP Debugging](https://code.visualstudio.com/docs/languages/php)
- [PHPUnit with Xdebug](https://phpunit.de/manual/current/en/code-coverage-analysis.html)

## Next Steps

1. Install Xdebug: `sudo apt install php8.4-xdebug`
2. Verify: `php -m | grep xdebug`
3. Set a breakpoint in `CookieController.php`
4. Press F5 → "Listen for Xdebug"
5. Make a browser request
6. Debug! 🐛
