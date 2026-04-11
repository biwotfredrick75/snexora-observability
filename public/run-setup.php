<?php
  ini_set('display_errors', 1);
  error_reporting(E_ALL);
  set_time_limit(300);
  define('LARAVEL_START', microtime(true));

  require __DIR__ . '/vendor/autoload.php';
  $app = require __DIR__ . '/bootstrap/app.php';
  $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

  echo '<pre>';
  echo "Running migrations...\n";
  $kernel->call('migrate', ['--force' => true]);
  echo $kernel->output();

  echo "\nInstalling Passport...\n";
  $kernel->call('passport:install', ['--force' => true]);
  echo $kernel->output();

  echo "\nSeeding roles...\n";
  $kernel->call('db:seed', ['--class' => 'RolePermissionSeeder', '--force' =>
  true]);
  echo $kernel->output();

  echo "\nSeeding transaction refs...\n";
  $kernel->call('db:seed', ['--class' => 'TransactionReferenceSeeder', '--force'
   => true]);
  echo $kernel->output();

  echo "\nDONE! Delete this file now.\n";
  echo '</pre>';
