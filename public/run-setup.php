<?php

// IMPORTANT: Delete this file after running!
define('LARAVEL_START', microtime(true));

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

echo '<pre>';

echo "=== Running Migrations ===\n";
$kernel->call('migrate', ['--force' => true]);
echo $kernel->output();

echo "\n=== Installing Passport ===\n";
$kernel->call('passport:install', ['--force' => true]);
echo $kernel->output();

echo "\n=== Seeding Roles & Permissions ===\n";
$kernel->call('db:seed', ['--class' => 'RolePermissionSeeder', '--force' => true]);
echo $kernel->output();

echo "\n=== Seeding Transaction References ===\n";
$kernel->call('db:seed', ['--class' => 'TransactionReferenceSeeder', '--force' => true]);
echo $kernel->output();

echo "\n=== Clearing Config Cache ===\n";
$kernel->call('config:clear');
echo $kernel->output();

echo "\nDONE! Delete this file immediately from public/run-setup.php\n";
echo '</pre>';
