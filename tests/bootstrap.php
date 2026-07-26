<?php

require __DIR__ . '/../vendor/autoload.php';

/**
 * Works around a host misconfiguration: /etc/php/8.3/cli/php.ini has
 * variables_order="GPCS" (no "E"), so PHP's CLI SAPI never populates
 * $_ENV from the process environment. Laravel's env() helper and
 * vlucas/phpdotenv's "immutable" repository both check $_ENV/$_SERVER
 * (not getenv()) to decide whether a variable is "already set" — since
 * $_ENV stays empty, Dotenv never sees phpunit.xml's <env> overrides as
 * already-defined and silently loads .env's real values on top of them.
 * Confirmed live: this caused `php8.3 artisan test` to run every test
 * against the real dev "erp" database instead of the isolated
 * "erp_testing" one (RefreshDatabase migrated/wiped "erp" on every run).
 *
 * Fix: mirror whatever the CLI SAPI *did* populate (real OS env vars are
 * always visible via getenv(), variables_order or not) into $_ENV/$_SERVER
 * ourselves, before phpunit's own <env> block runs and before Laravel ever
 * boots Dotenv.
 */
foreach (getenv() as $key => $value) {
    $_ENV[$key]    = $_ENV[$key]    ?? $value;
    $_SERVER[$key] = $_SERVER[$key] ?? $value;
}
