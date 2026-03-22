<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    private string $backupDir = 'backups';

    public function index(): JsonResponse
    {
        $files = Storage::files($this->backupDir);

        $backups = collect($files)->map(function ($path) {
            $name = basename($path);
            return [
                'filename' => $name,
                'size'     => Storage::size($path),
                'modified' => Storage::lastModified($path),
            ];
        })->sortByDesc('modified')->values();

        return ApiResponse::success($backups, 'Backups retrieved');
    }

    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'comments' => 'nullable|string|max:500',
            'compress' => 'sometimes|boolean',
        ]);

        $dbName   = config('database.connections.mysql.database', 'erp');
        $dbUser   = config('database.connections.mysql.username', 'root');
        $dbPass   = config('database.connections.mysql.password', '');
        $dbHost   = config('database.connections.mysql.host', '127.0.0.1');

        $timestamp = now()->format('Ymd_His');
        $filename  = "erp_backup_{$timestamp}.sql";
        $backupPath = storage_path("app/{$this->backupDir}/{$filename}");

        if (!is_dir(storage_path("app/{$this->backupDir}"))) {
            mkdir(storage_path("app/{$this->backupDir}"), 0755, true);
        }

        $passwordArg = $dbPass ? "-p{$dbPass}" : '';
        $cmd = "mysqldump -h {$dbHost} -u {$dbUser} {$passwordArg} {$dbName} > {$backupPath} 2>&1";
        exec($cmd, $output, $code);

        if ($code !== 0 || !file_exists($backupPath)) {
            return ApiResponse::validationError(['backup' => ['Backup failed: ' . implode(' ', $output)]]);
        }

        // Compress if requested
        if ($request->boolean('compress')) {
            exec("gzip {$backupPath}");
            $filename .= '.gz';
        }

        return ApiResponse::success(['filename' => $filename], 'Backup created successfully');
    }

    public function download(string $filename): StreamedResponse|JsonResponse
    {
        $path = "{$this->backupDir}/{$filename}";

        if (!Storage::exists($path)) {
            return ApiResponse::notFound('Backup file not found');
        }

        return Storage::download($path, $filename);
    }

    public function restore(Request $request): JsonResponse
    {
        $request->validate([
            'filename' => 'nullable|string',
            'file'     => 'nullable|file',
        ]);

        $dbName   = config('database.connections.mysql.database', 'erp');
        $dbUser   = config('database.connections.mysql.username', 'root');
        $dbPass   = config('database.connections.mysql.password', '');
        $dbHost   = config('database.connections.mysql.host', '127.0.0.1');

        $passwordArg = $dbPass ? "-p{$dbPass}" : '';

        if ($request->hasFile('file')) {
            $uploadedFile = $request->file('file');
            $sqlPath = $uploadedFile->store($this->backupDir);
            $fullPath = storage_path("app/{$sqlPath}");
        } elseif ($request->filled('filename')) {
            $sqlPath  = "{$this->backupDir}/{$request->input('filename')}";
            $fullPath = storage_path("app/{$sqlPath}");
            if (!file_exists($fullPath)) {
                return ApiResponse::notFound('Backup file not found');
            }
        } else {
            return ApiResponse::validationError(['file' => ['No backup file provided']]);
        }

        $cmd = "mysql -h {$dbHost} -u {$dbUser} {$passwordArg} {$dbName} < {$fullPath} 2>&1";
        exec($cmd, $output, $code);

        if ($code !== 0) {
            return ApiResponse::validationError(['restore' => ['Restore failed: ' . implode(' ', $output)]]);
        }

        return ApiResponse::success(null, 'Database restored successfully');
    }

    public function destroy(string $filename): JsonResponse
    {
        $path = "{$this->backupDir}/{$filename}";

        if (!Storage::exists($path)) {
            return ApiResponse::notFound('Backup file not found');
        }

        Storage::delete($path);

        return ApiResponse::deleted('Backup deleted');
    }
}
