# Test Coverage Action Plan
**Goal:** Achieve 90% code coverage
**Current:** 20.06%
**Gap:** -69.94%

## Critical Issues (Must Fix First)

### 1. Service Provider - Logger Registration
**File:** `app/Config/Services.php`

Add logger service:
```php
public static function logger(bool $getShared = true): LoggerInterface
{
    if ($getShared) {
        return static::getSharedInstance('logger');
    }
    return LoggerFactory::create('app');
}
```

### 2. Update Test Base Classes
**Files:**
- `tests/Support/UnitTestCase.php`
- `tests/Support/IntegrationTestCase.php` ✅ Fixed
- `tests/Support/FeatureTestCase.php` ✅ Fixed

Add mock logger for tests.

### 3. Fix Unit Test Constructor Dependencies
**Files:** All handler tests

Update to provide:
- Mock Repository
- Mock Event Dispatcher
- Mock Logger

## Quick Wins (High Impact)

### Handler Tests (0% → 30% coverage)
1. `tests/Unit/Domain/Cookie/Commands/CreateCookieHandlerTest.php`
2. `tests/Unit/Domain/Cookie/Commands/UpdateCookieHandlerTest.php`
3. `tests/Unit/Domain/Cookie/Commands/DeleteCookieHandlerTest.php`
4. Fix `tests/Unit/Domain/Cookie/Queries/*HandlerTest.php` (3 files)
5. Fix `tests/Unit/Domain/Cookie/Events/CookieEventHandlersTest.php`

### Repository Tests (2% → 20% coverage)
1. Expand `tests/Integration/Repositories/CookieRepositoryTest.php`
   - Add pagination tests
   - Add search tests
   - Add soft delete tests
   - Add logging tests

### Controller Tests (2% → 15% coverage)
1. Create `tests/Unit/Controllers/CookieControllerTest.php`
   - Test all 7 actions
   - Mock command/query buses
   - Verify responses

## Priority Order

1. **Today** (2-4 hours)
   - ✅ Fix FeatureTestCase
   - ✅ Fix IntegrationTestCase
   - ⬜ Add logger to Services.php
   - ⬜ Fix UnitTestCase with mock logger
   - ⬜ Run tests → 0 failures

2. **This Week** (16-24 hours)
   - ⬜ Create 3 command handler tests
   - ⬜ Fix 3 query handler tests
   - ⬜ Fix event handler tests
   - **Target:** 50% coverage

3. **Next Week** (12-20 hours)
   - ⬜ Expand repository tests
   - ⬜ Add service provider tests
   - ⬜ Add infrastructure tests
   - **Target:** 75% coverage

4. **Week 3** (12-20 hours)
   - ⬜ Fix feature tests
   - ⬜ Add controller tests
   - **Target:** 90% coverage

## Measurement

Check progress:
```bash
vendor/bin/phpunit --coverage-text
```

Target metrics:
- Classes: 11.76% → 80%+
- Methods: 28.32% → 90%+
- Lines: 20.06% → 90%+
- Tests: 192 (102 passing) → 192 (192 passing)

## Files to Create

### Unit Tests
- [ ] `tests/Unit/Domain/Cookie/Commands/CreateCookieHandlerTest.php`
- [ ] `tests/Unit/Domain/Cookie/Commands/UpdateCookieHandlerTest.php`
- [ ] `tests/Unit/Domain/Cookie/Commands/DeleteCookieHandlerTest.php`
- [ ] `tests/Unit/Controllers/CookieControllerTest.php`
- [ ] `tests/Unit/Infrastructure/Logging/CorrelationIdServiceTest.php`
- [ ] `tests/Unit/Infrastructure/ServiceProvider/ServiceProviderRegistryTest.php`

### Integration Tests
- [ ] Expand `tests/Integration/Repositories/CookieRepositoryTest.php`
- [ ] `tests/Integration/ServiceProviders/CookieServiceProviderTest.php`

### Files to Fix
- [ ] `tests/Unit/Domain/Cookie/Queries/GetCookieByIdHandlerTest.php` (add logger)
- [ ] `tests/Unit/Domain/Cookie/Queries/GetAllCookiesHandlerTest.php` (add logger)
- [ ] `tests/Unit/Domain/Cookie/Queries/GetCookiesPaginatedHandlerTest.php` (add logger)
- [ ] `tests/Unit/Domain/Cookie/Events/CookieEventHandlersTest.php` (add logger)
- [ ] `tests/Feature/Cookie/CookieCrudTest.php` (fix services)

## Success Metrics

**Phase 1 Complete:**
- [ ] 0 failing tests
- [ ] Infrastructure working
- [ ] Can instantiate all handlers

**Phase 2 Complete:**
- [ ] All handlers tested
- [ ] 50%+ coverage
- [ ] All command/query paths working

**Phase 3 Complete:**
- [ ] Repository fully tested
- [ ] 75%+ coverage
- [ ] Database operations verified

**Phase 4 Complete:**
- [ ] Feature tests passing
- [ ] 90%+ coverage
- [ ] Production ready

---

**Last Updated:** 2025-10-26
**Status:** Phase 1 in progress (fixing infrastructure)
