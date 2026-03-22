<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SystemDiagnosticsController extends Controller
{
    public function index(): JsonResponse
    {
        $phpVersion     = PHP_VERSION;
        $laravelVersion = app()->version();

        try {
            $dbVersionRow = DB::select('SELECT VERSION() as version');
            $dbVersion    = $dbVersionRow[0]->version ?? 'Unknown';
        } catch (\Exception $e) {
            $dbVersion = 'Error: ' . $e->getMessage();
        }

        $cacheDriver  = config('cache.default', 'file');
        $queueDriver  = config('queue.default', 'sync');
        $memoryLimit  = ini_get('memory_limit');
        $maxExecTime  = ini_get('max_execution_time');

        $totalDisk = disk_total_space(storage_path());
        $freeDisk  = disk_free_space(storage_path());
        $usedDisk  = $totalDisk - $freeDisk;

        $diagnostics = [
            'php_version'    => $phpVersion,
            'laravel_version'=> $laravelVersion,
            'db_version'     => $dbVersion,
            'cache_driver'   => $cacheDriver,
            'queue_driver'   => $queueDriver,
            'memory_limit'   => $memoryLimit,
            'max_exec_time'  => $maxExecTime . 's',
            'disk_total'     => $this->formatBytes($totalDisk),
            'disk_used'      => $this->formatBytes($usedDisk),
            'disk_free'      => $this->formatBytes($freeDisk),
            'disk_used_pct'  => $totalDisk > 0 ? round(($usedDisk / $totalDisk) * 100, 1) : 0,
            'storage_path'   => storage_path(),
            'env'            => app()->environment(),
            'debug_mode'     => config('app.debug') ? 'On' : 'Off',
        ];

        return ApiResponse::success($diagnostics, 'System diagnostics retrieved');
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow   = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
    }
}
