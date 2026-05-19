# Quick Setup Guide

## Final Setup Steps

Your CodeIgniter 4 CQRS template is ready! Follow these final steps to get it running:

### 1. Create Database

```bash
# Method 1: Using mysql command
mysql -u root -p
# Then run:
CREATE DATABASE ci4_cqrs;
exit;

# Method 2: Using phpMyAdmin or other GUI tool
# Create a database named: ci4_cqrs
```

### 2. Run Migrations

```bash
php spark migrate
```

Expected output:
```
Running: 2025-01-21-000001_CreateCookiesTable
Migrated: 2025-01-21-000001_CreateCookiesTable
```

### 3. Seed Sample Data

```bash
php spark db:seed CookieSeeder
```

This will create 10 sample cookies in your database.

### 4. Start Development Server

```bash
php spark serve
```

### 5. Test the Application

Open your browser and visit:
- **Cookie List**: `http://localhost:8080/cookies`
- **Create Cookie**: `http://localhost:8080/cookies/create`

## What Was Built

✅ **Complete CQRS Infrastructure**
- CommandBus for write operations
- QueryBus for read operations
- EventDispatcher for domain events

✅ **Cookie Domain (Template)**
- Value Objects: CookieName, CookiePrice
- Entity: Cookie with business rules
- Commands: Create, Update, Delete (with handlers)
- Queries: GetById, GetAll, GetPaginated (with handlers)
- Events: CookieCreated, CookieUpdated, CookieDeleted

✅ **Persistence Layer**
- CookieModel (CodeIgniter Model)
- CookieRepository (Domain Repository Pattern)
- Database migration with indexes
- Seeder with 10 sample cookies

✅ **Presentation Layer**
- CookieController (thin controller, no business logic)
- RESTful routes in Routes.php
- Bootstrap 5 views (index, show, create, edit)
- CSRF protection on all forms

✅ **Shared Infrastructure**
- Exception classes (DomainException, ValidationException)
- Value Objects (Email, Money, DateTimeValue)
- Dependency Injection configured in Services.php

✅ **Code Quality Setup**
- PHPStan Level 8 configuration (phpstan.neon)
- PHPCS with PSR-12 + Slevomat (phpcs.xml)
- Composer scripts for quality checks

✅ **Documentation**
- **README.md**: Project overview and quick start
- **CLAUDE.md**: Complete context for AI agents
- **MODIFYING_ENTITIES.md**: Checklist for adding/removing entity properties
- **SETUP.md**: This file

## Verify Everything Works

### Test CRUD Operations

1. **List Cookies**: Visit `/cookies`
   - Should see 10 cookies
   - Test pagination
   - Test search

2. **View Cookie**: Click on any cookie name
   - Should see full details
   - Edit and Delete buttons visible

3. **Create Cookie**: Click "Create New Cookie"
   - Fill form with:
     - Name: "Test Cookie"
     - Price: 3.99
     - Stock: 50
     - Check "Active"
   - Submit
   - Should redirect to cookie detail page

4. **Update Cookie**: Click "Edit" on any cookie
   - Change price to 4.99
   - Submit
   - Should see success message

5. **Delete Cookie**: Click "Delete" on any cookie
   - Confirm deletion
   - Should be soft-deleted (deleted_at set)

### Run Code Quality Checks

```bash
# Run all checks
composer check

# Individual checks
composer phpstan     # Static analysis (should be 0 errors)
composer phpcs       # Code style (should be 0 violations)
composer test        # Run tests (if tests are added)
```

## Next Steps

### Add a New Domain

Use the Cookie domain as a template:

1. Copy `app/Domain/Cookie/` to `app/Domain/YourDomain/`
2. Rename all classes
3. Create migration
4. Update `Config/Services.php`
5. Create controller and routes
6. Create views

See **MODIFYING_ENTITIES.md** for detailed instructions.

### Add Tests

The test structure is ready in `tests/` directory. Add tests for:
- Command Handlers (unit tests)
- Query Handlers (unit tests)
- Value Objects (unit tests)
- Repository (integration tests)
- Controller (feature tests)

Target: 90%+ coverage

### Deploy to Production

1. Update `.env` for production
2. Run migrations on production database
3. Set `CI_ENVIRONMENT = production`
4. Configure web server (Apache/Nginx)
5. Enable HTTPS
6. Set proper file permissions

## Troubleshooting

### Database Connection Error

Check `.env` file:
```ini
database.default.hostname = localhost
database.default.database = ci4_cqrs
database.default.username = root
database.default.password = root
```

### Page Not Found

Make sure routes are correct in `app/Config/Routes.php`

### PHPStan Errors

The project is configured for PHPStan Level 8. Some errors may need to be addressed after adding new code.

### Permission Errors

Make sure `writable/` directory is writable:
```bash
chmod -R 777 writable/
```

## Success Criteria

Your setup is complete when:

- ✅ `/cookies` displays list of 10 cookies
- ✅ Create cookie form works
- ✅ Edit cookie form works
- ✅ Delete cookie works (soft delete)
- ✅ Search and pagination work
- ✅ No PHP errors in browser
- ✅ `composer phpstan` returns 0 errors
- ✅ `composer phpcs` returns 0 violations

## Getting Help

- **Architecture Questions**: Read CLAUDE.md
- **Modifying Entities**: Read MODIFYING_ENTITIES.md
- **Setup Issues**: Check this file (SETUP.md)
- **General Usage**: Read README.md

---

**Congratulations! Your CQRS template is ready to use. 🎉**
