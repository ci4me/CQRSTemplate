<?php

/**
 * Test bootstrap - loads the CodeIgniter test harness.
 *
 * Round-4 R2: the legacy `tests/_support/bootstrap_libraries.php` hook was
 * removed together with the framework-scaffold test trees (tests/_support,
 * tests/_skipped, tests/unit, tests/session, tests/database). It only
 * conditionally loaded a bootstrap for a library that does not exist in
 * this repository. Project test support code lives in tests/Support.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/codeigniter4/framework/system/Test/bootstrap.php';
