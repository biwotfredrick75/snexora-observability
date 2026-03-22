<?php

// Strip /backend prefix so Laravel routes match correctly
$uri = $_SERVER['REQUEST_URI'];
$_SERVER['REQUEST_URI'] = '/' . ltrim(substr($uri, strlen('/backend')), '/');
if (empty($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/';
}

// Boot Laravel from public/
chdir(__DIR__ . '/public');
require __DIR__ . '/public/index.php';
