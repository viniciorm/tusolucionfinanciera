<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Detección inteligente de ruta: hosting compartido vs entorno local
$autoloadPaths = [
    __DIR__ . '/../../crm-app/vendor/autoload.php', // Hosting: public_html/crm -> crm-app
    __DIR__ . '/../vendor/autoload.php',            // Entorno local / git estándar
];

$bootstrapPaths = [
    __DIR__ . '/../../crm-app/bootstrap/app.php',
    __DIR__ . '/../bootstrap/app.php',
];

$autoloadFound = null;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        $autoloadFound = $path;
        break;
    }
}

if (!$autoloadFound) {
    http_response_code(500);
    echo "Error crítico: No se encontró vendor/autoload.php. Verifica la ruta privada de crm-app.";
    exit(1);
}

require $autoloadFound;

$bootstrapFound = null;
foreach ($bootstrapPaths as $path) {
    if (file_exists($path)) {
        $bootstrapFound = $path;
        break;
    }
}

if (!$bootstrapFound) {
    http_response_code(500);
    echo "Error crítico: No se encontró bootstrap/app.php.";
    exit(1);
}

/** @var \Illuminate\Foundation\Application $app */
$app = require_once $bootstrapFound;

// Establecer la ruta pública física
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
