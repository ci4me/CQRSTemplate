<?php

namespace Config;

use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\FrameworkException;
use CodeIgniter\HotReloader\HotReloader;

/*
 * --------------------------------------------------------------------
 * Application Events
 * --------------------------------------------------------------------
 * Events allow you to tap into the execution of the program without
 * modifying or extending core files. This file provides a central
 * location to define your events, though they can always be added
 * at run-time, also, if needed.
 *
 * You create code that can execute by subscribing to events with
 * the 'on()' method. This accepts any form of callable, including
 * Closures, that will be executed when the event is triggered.
 *
 * Example:
 *      Events::on('create', [$myInstance, 'myMethod']);
 */

Events::on('pre_system', static function (): void {
    if (ENVIRONMENT !== 'testing') {
        if (ini_get('zlib.output_compression')) {
            throw FrameworkException::forEnabledZlibOutputCompression();
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        ob_start(static fn ($buffer) => $buffer);
    }

    /*
     * --------------------------------------------------------------------
     * Debug Toolbar Listeners.
     * --------------------------------------------------------------------
     * If you delete, they will no longer be collected.
     */
    if (CI_DEBUG && ! is_cli()) {
        Events::on('DBQuery', 'CodeIgniter\Debug\Toolbar\Collectors\Database::collect');
        service('toolbar')->respond();
        // Hot Reload route - for framework use on the hot reloader.
        if (ENVIRONMENT === 'development') {
            service('routes')->get('__hot-reload', static function (): void {
                (new HotReloader())->run();
            });
        }
    }

    /*
     * --------------------------------------------------------------------
     * SECURITY (A11): boot-time JWT secret presence check.
     * --------------------------------------------------------------------
     * In production we refuse to start the application if JWT_SECRET_KEY is
     * absent or shorter than 32 chars. This catches misconfiguration AT
     * BOOT instead of waiting until the first auth-protected API request.
     * The check is skipped in CLI (so migrations/spark commands without
     * JWT can still run) and in testing.
     */
    if (ENVIRONMENT === 'production' && ! is_cli()) {
        $secret = getenv('JWT_SECRET_KEY');
        if ($secret === false || strlen((string) $secret) < 32) {
            throw new \RuntimeException(
                'SECURITY ERROR: JWT_SECRET_KEY must be set and >= 32 chars in production.'
            );
        }
    }
});
