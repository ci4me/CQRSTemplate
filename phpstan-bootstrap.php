<?php

declare(strict_types=1);

/**
 * PHPStan Bootstrap File
 *
 * This file helps PHPStan understand CodeIgniter 4 helper functions
 * and global constants that are available at runtime.
 */

// Define APPPATH constant for PHPStan
if (!defined('APPPATH')) {
    define('APPPATH', __DIR__ . '/app/');
}

if (!defined('ROOTPATH')) {
    define('ROOTPATH', __DIR__ . '/');
}

if (!defined('WRITEPATH')) {
    define('WRITEPATH', __DIR__ . '/writable/');
}

/**
 * Helper function stubs for PHPStan
 * These are defined in CodeIgniter at runtime but PHPStan needs to know about them
 */

if (!function_exists('view')) {
    /**
     * @param array<string, mixed> $data
     */
    function view(string $name, array $data = [], array $options = []): string
    {
        return '';
    }
}

if (!function_exists('redirect')) {
    function redirect(?string $route = null): \CodeIgniter\HTTP\RedirectResponse
    {
        throw new RuntimeException('This is a stub for PHPStan');
    }
}

if (!function_exists('log_message')) {
    function log_message(string $level, string $message, array $context = []): bool
    {
        return true;
    }
}

if (!function_exists('esc')) {
    /**
     * @param string|array<mixed> $data
     * @return string|array<mixed>
     */
    function esc($data, string $context = 'html', ?string $encoding = null)
    {
        return $data;
    }
}

if (!function_exists('service')) {
    /**
     * @template T
     * @param class-string<T>|string $name
     * @return T|object|null
     */
    function service(string $name, bool $getShared = true)
    {
        throw new RuntimeException('This is a stub for PHPStan');
    }
}

if (!function_exists('session')) {
    /**
     * @param string|array<string, mixed>|null $val
     * @return \CodeIgniter\Session\Session|string|array<string, mixed>|null
     */
    function session(?string $val = null)
    {
        throw new RuntimeException('This is a stub for PHPStan');
    }
}
