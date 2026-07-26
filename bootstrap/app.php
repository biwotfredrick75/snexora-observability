<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Load environment variables
if (file_exists(dirname(__DIR__) . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role'       => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
        $middleware->append(\App\Http\Middleware\OpenTelemetryMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Without this, any uncaught foreign-key constraint violation (e.g.
        // deleting a row other records still reference) bubbles up as a raw
        // SQLSTATE[23000]/1451 string straight into the JSON response — seen
        // live deleting a BOM whose bom_items hadn't been cleaned up first.
        // That specific case is now fixed at the source (BomController
        // deletes its lines before the parent), but this is the safety net
        // for every other controller with the same class of gap.
        $exceptions->render(function (\Illuminate\Database\QueryException $e, $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null; // not an API request — let the default handler render it
            }

            $driverCode = $e->errorInfo[1] ?? null;
            $message    = $e->getMessage();

            // 1451: deleting/updating a row that other rows still reference (FK RESTRICT)
            if ($driverCode === 1451 || str_contains($message, '1451')) {
                return \App\Http\Responses\ApiResponse::error(
                    'Cannot delete — other records still depend on this.',
                    409,
                    null,
                    'FK_CONSTRAINT'
                );
            }

            // 1452: inserting/updating a row that references something that doesn't exist
            if ($driverCode === 1452 || str_contains($message, '1452')) {
                return \App\Http\Responses\ApiResponse::error(
                    'Cannot save — this references a record that does not exist.',
                    422,
                    null,
                    'FK_CONSTRAINT'
                );
            }

            return null; // anything else — fall through to default rendering
        });
    })->create();