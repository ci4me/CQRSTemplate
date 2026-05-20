# Complete File Inventory for New Domain

When adding a new domain to this CQRS template, you create **45 files total** (assuming 2 value objects, 3 commands, 3 queries, 3 events).

---

## Domain Layer (22 files)

### Service Provider (1 file)
1. `app/Domain/{Domain}/{Domain}ServiceProvider.php`

### Entities (1 file)
2. `app/Domain/{Domain}/Entities/{Entity}.php`

### Value Objects (2+ files)
3. `app/Domain/{Domain}/ValueObjects/{Entity}Name.php`
4. `app/Domain/{Domain}/ValueObjects/{Entity}Price.php`
   - Add more value objects as needed for properties requiring validation

### Commands (6 files - 2 files per command)
5. `app/Domain/{Domain}/Commands/Create{Entity}/Create{Entity}Command.php`
6. `app/Domain/{Domain}/Commands/Create{Entity}/Create{Entity}Handler.php`
7. `app/Domain/{Domain}/Commands/Update{Entity}/Update{Entity}Command.php`
8. `app/Domain/{Domain}/Commands/Update{Entity}/Update{Entity}Handler.php`
9. `app/Domain/{Domain}/Commands/Delete{Entity}/Delete{Entity}Command.php`
10. `app/Domain/{Domain}/Commands/Delete{Entity}/Delete{Entity}Handler.php`

### Queries (6 files - 2 files per query)
11. `app/Domain/{Domain}/Queries/Get{Entity}ById/Get{Entity}ByIdQuery.php`
12. `app/Domain/{Domain}/Queries/Get{Entity}ById/Get{Entity}ByIdHandler.php`
13. `app/Domain/{Domain}/Queries/GetAll{Entities}/GetAll{Entities}Query.php`
14. `app/Domain/{Domain}/Queries/GetAll{Entities}/GetAll{Entities}Handler.php`
15. `app/Domain/{Domain}/Queries/Get{Entities}Paginated/Get{Entities}PaginatedQuery.php`
16. `app/Domain/{Domain}/Queries/Get{Entities}Paginated/Get{Entities}PaginatedHandler.php`

### Events (6 files - 2 files per event)
17. `app/Domain/{Domain}/Events/{Entity}Created/{Entity}CreatedEvent.php`
18. `app/Domain/{Domain}/Events/{Entity}Created/{Entity}CreatedEventHandler.php`
19. `app/Domain/{Domain}/Events/{Entity}Updated/{Entity}UpdatedEvent.php`
20. `app/Domain/{Domain}/Events/{Entity}Updated/{Entity}UpdatedEventHandler.php`
21. `app/Domain/{Domain}/Events/{Entity}Deleted/{Entity}DeletedEvent.php`
22. `app/Domain/{Domain}/Events/{Entity}Deleted/{Entity}DeletedEventHandler.php`

---

## Infrastructure Layer (2 files)

### Persistence (2 files)
23. `app/Models/{Domain}/{Entity}Model.php`
24. `app/Infrastructure/Persistence/Repositories/{Entity}Repository.php`

---

## Application Layer (1 file)

### Controllers (1 file)
25. `app/Controllers/Domain/{Domain}/{Entity}Controller.php`

---

## Presentation Layer (4 files)

### Views (4 files)
26. `app/Views/{entities}/index.php` - List view
27. `app/Views/{entities}/show.php` - Detail view
28. `app/Views/{entities}/create.php` - Creation form
29. `app/Views/{entities}/edit.php` - Edit form

---

## Database Layer (1 file)

### Migrations (1 file)
30. `app/Database/Migrations/YYYY-MM-DD-HHMMSS_Create{Entities}Table.php`

---

## Test Layer (14 files)

### Unit Tests - Value Objects (2 files)
31. `tests/Unit/Domain/{Domain}/ValueObjects/{Entity}NameTest.php`
32. `tests/Unit/Domain/{Domain}/ValueObjects/{Entity}PriceTest.php`

### Unit Tests - Entities (1 file)
33. `tests/Unit/Domain/{Domain}/Entities/{Entity}Test.php`

### Unit Tests - Commands (3 files)
34. `tests/Unit/Domain/{Domain}/Commands/Create{Entity}HandlerTest.php`
35. `tests/Unit/Domain/{Domain}/Commands/Update{Entity}HandlerTest.php`
36. `tests/Unit/Domain/{Domain}/Commands/Delete{Entity}HandlerTest.php`

### Unit Tests - Queries (3 files)
37. `tests/Unit/Domain/{Domain}/Queries/Get{Entity}ByIdHandlerTest.php`
38. `tests/Unit/Domain/{Domain}/Queries/GetAll{Entities}HandlerTest.php`
39. `tests/Unit/Domain/{Domain}/Queries/Get{Entities}PaginatedHandlerTest.php`

### Unit Tests - Events (2 files)
40. `tests/Unit/Domain/{Domain}/Events/{Entity}EventsTest.php` - Tests all events
41. `tests/Unit/Domain/{Domain}/Events/{Entity}EventHandlersTest.php` - Tests all event handlers

### Integration Tests (1 file)
42. `tests/Integration/Repositories/{Entity}RepositoryTest.php`

### Feature Tests (1 file)
43. `tests/Feature/{Domain}/{Entity}CrudTest.php`

### Test Factories (1 file)
44. `tests/Support/Factories/{Entity}Factory.php`

---

## Configuration Updates (1 modification)

### Routes (modification, not new file)
45. Add routes to `app/Config/Routes.php`

---

## File Count by Layer

| Layer | File Count |
|-------|------------|
| Domain Layer | 22 files |
| Infrastructure Layer | 2 files |
| Application Layer | 1 file |
| Presentation Layer | 4 files |
| Database Layer | 1 file |
| Test Layer | 14 files |
| Config Updates | 1 modification |
| **TOTAL** | **45 files** |

---

## Example: Cookie Domain

The Cookie domain demonstrates this complete structure:

```
app/Domain/Cookie/
├── CookieServiceProvider.php          # 1
├── Entities/
│   └── Cookie.php                     # 2
├── ValueObjects/
│   ├── CookieName.php                 # 3
│   └── CookiePrice.php                # 4
├── Commands/
│   ├── CreateCookie/
│   │   ├── CreateCookieCommand.php    # 5
│   │   └── CreateCookieHandler.php    # 6
│   ├── UpdateCookie/
│   │   ├── UpdateCookieCommand.php    # 7
│   │   └── UpdateCookieHandler.php    # 8
│   └── DeleteCookie/
│       ├── DeleteCookieCommand.php    # 9
│       └── DeleteCookieHandler.php    # 10
├── Queries/
│   ├── GetCookieById/
│   │   ├── GetCookieByIdQuery.php     # 11
│   │   └── GetCookieByIdHandler.php   # 12
│   ├── GetAllCookies/
│   │   ├── GetAllCookiesQuery.php     # 13
│   │   └── GetAllCookiesHandler.php   # 14
│   └── GetCookiesPaginated/
│       ├── GetCookiesPaginatedQuery.php   # 15
│       └── GetCookiesPaginatedHandler.php # 16
└── Events/
    ├── CookieCreated/
    │   ├── CookieCreatedEvent.php     # 17
    │   └── CookieCreatedEventHandler.php # 18
    ├── CookieUpdated/
    │   ├── CookieUpdatedEvent.php     # 19
    │   └── CookieUpdatedEventHandler.php # 20
    └── CookieDeleted/
        ├── CookieDeletedEvent.php     # 21
        └── CookieDeletedEventHandler.php # 22

app/Models/Cookie/
├── CookieModel.php                    # 23
└── CookieRepository.php               # 24

app/Controllers/Domain/Cookie/
└── CookieController.php               # 25

app/Views/cookies/
├── index.php                          # 26
├── show.php                           # 27
├── create.php                         # 28
└── edit.php                           # 29

app/Database/Migrations/
└── 2024-10-21-123456_CreateCookiesTable.php # 30

tests/Unit/Domain/Cookie/
├── ValueObjects/
│   ├── CookieNameTest.php             # 31
│   └── CookiePriceTest.php            # 32
├── Entities/
│   └── CookieTest.php                 # 33
├── Commands/
│   ├── CreateCookieHandlerTest.php    # 34
│   ├── UpdateCookieHandlerTest.php    # 35
│   └── DeleteCookieHandlerTest.php    # 36
├── Queries/
│   ├── GetCookieByIdHandlerTest.php   # 37
│   ├── GetAllCookiesHandlerTest.php   # 38
│   └── GetCookiesPaginatedHandlerTest.php # 39
└── Events/
    ├── CookieEventsTest.php           # 40
    └── CookieEventHandlersTest.php    # 41

tests/Integration/Repositories/
└── CookieRepositoryTest.php           # 42

tests/Feature/Cookie/
└── CookieCrudTest.php                 # 43

tests/Support/Factories/
└── CookieFactory.php                  # 44

app/Config/
└── Routes.php                         # 45 (modification)
```

---

## Services.php Update (IF New Repository Needed)

**Only add this IF creating a new repository:**

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

**This is the ONLY edit required outside the domain files!**

---

## Automation

Use the `/add-domain {DomainName}` slash command to generate all 45 files automatically from templates.

Or use the `domain-scaffolding` skill for interactive creation.

---

## Notes

- All numbers assume standard CRUD operations (Create, Read, Update, Delete)
- File count may vary if domain has more/fewer commands, queries, or events
- Value object count depends on properties requiring validation
- All files MUST pass PHPStan Level 8 and Slevomat standards
- All files MUST have comprehensive tests (90% coverage minimum)
