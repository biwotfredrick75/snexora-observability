<?php

namespace App\Modules\Hrm;

use App\Modules\Hrm\Http\Middleware\EnsureHrmEnabled;
use App\Modules\Hrm\Repositories\EmployeeRepository;
use App\Modules\Hrm\Services\EmployeeService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * HRM Module Service Provider.
 *
 * Self-contained registration for the HRM module — migrations, routes and
 * service bindings all live inside app/Modules/Hrm. Removing this provider
 * line from bootstrap/providers.php fully unplugs the module.
 */
class HrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmployeeRepository::class, fn () => new EmployeeRepository);

        $this->app->bind(EmployeeService::class, fn ($app) => new EmployeeService(
            $app->make(EmployeeRepository::class)
        ));
    }

    public function boot(): void
    {
        // Module-owned migrations (database/migrations/hrm equivalent)
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        // Module API routes — namespaced, guarded, and gated by the on/off switch
        Route::prefix('api/hrm')
            ->middleware(['api', 'auth:api', EnsureHrmEnabled::class])
            ->group(__DIR__.'/routes.php');
    }
}
