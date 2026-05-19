<?php

/**
 * Test bootstrap - loads all necessary libraries and configurations
 */

declare(strict_types=1);

// First, load the CodeIgniter bootstrap
require_once __DIR__ . '/../vendor/codeigniter4/framework/system/Test/bootstrap.php';

// Then load custom library bootstraps
require_once __DIR__ . '/_support/bootstrap_libraries.php';
