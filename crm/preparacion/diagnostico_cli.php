<?php
declare(strict_types=1);

/**
 * Diagnóstico seguro para ejecutar exclusivamente vía CLI o tarea de Cron en cPanel.
 *
 * RESTRICCIONES DE SEGURIDAD:
 * 1. Prohibida la ejecución web/HTTP (termina inmediatamente con código 403).
 * 2. No expone secretos, contraseñas, tokens ni datos sensibles.
 * 3. Escribe el resultado en una ruta privada fuera de public_html.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso denegado. Este script solo puede ejecutarse desde la línea de comandos (CLI/Cron).\n";
    exit(1);
}

$startTime = microtime(true);
$results = [
    'timestamp' => date('Y-m-d H:i:s P'),
    'php_cli_version' => PHP_VERSION,
    'php_sapi' => PHP_SAPI,
    'php_binary' => PHP_BINARY,
    'os' => PHP_OS,
    'user' => get_current_user(),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
];

// 1. Verificación de extensiones requeridas para Laravel 11 / PHP 8.4
$requiredExtensions = [
    'pdo' => 'Obligatoria (base de datos)',
    'pdo_mysql' => 'Obligatoria (MySQL/MariaDB)',
    'mbstring' => 'Obligatoria (manipulación de strings y normalización UTF-8)',
    'openssl' => 'Obligatoria (cifrado, tokens OAuth y JWT)',
    'curl' => 'Obligatoria (comunicación HTTP con Google APIs)',
    'tokenizer' => 'Obligatoria (Blade y Laravel core)',
    'xml' => 'Obligatoria (parsers)',
    'json' => 'Obligatoria (payloads y columnas JSON)',
    'bcmath' => 'Recomendada (cálculos precisos)',
    'fileinfo' => 'Recomendada (detección de tipos MIME)',
    'intl' => 'Recomendada (internacionalización y fechas)',
    'zip' => 'Recomendada (descompresión de paquetes y despliegue)',
];

$extCheck = [];
$missingRequired = [];
foreach ($requiredExtensions as $ext => $desc) {
    $loaded = extension_loaded($ext);
    $extCheck[$ext] = [
        'loaded' => $loaded,
        'description' => $desc,
    ];
    if (!$loaded && str_starts_with($desc, 'Obligatoria')) {
        $missingRequired[] = $ext;
    }
}
$results['extensions'] = $extCheck;
$results['missing_required_extensions'] = $missingRequired;

// 2. Búsqueda de ejecutables alternativos de PHP (versión 8.4 en cPanel CloudLinux / ea-php)
$candidatePhpBinaries = [
    '/usr/local/bin/ea-php84',
    '/opt/cpanel/ea-php84/root/usr/bin/php',
    '/usr/bin/php84',
    '/opt/alt/php84/usr/bin/php',
    '/usr/local/bin/php',
    '/usr/bin/php',
];
$foundPhpBinaries = [];
foreach ($candidatePhpBinaries as $bin) {
    if (file_exists($bin) && is_executable($bin)) {
        $verOutput = @shell_exec(escapeshellcmd($bin) . ' -r "echo PHP_VERSION;" 2>/dev/null');
        $foundPhpBinaries[$bin] = trim((string)$verOutput) ?: 'Detectado';
    }
}
$results['available_php_binaries'] = $foundPhpBinaries;

// 3. Búsqueda de Composer en el sistema
$candidateComposerBinaries = [
    '/usr/local/bin/composer',
    '/opt/cpanel/composer/bin/composer',
    '/usr/bin/composer',
    getenv('HOME') . '/bin/composer',
    getenv('HOME') . '/.config/composer/vendor/bin/composer',
];
$foundComposer = [];
foreach ($candidateComposerBinaries as $comp) {
    if (file_exists($comp)) {
        $compVer = @shell_exec(escapeshellcmd($comp) . ' --version 2>/dev/null');
        $foundComposer[$comp] = trim((string)$compVer) ?: 'Detectado';
    }
}
// Probar comando 'composer' directo en PATH
$pathComposer = @shell_exec('composer --version 2>/dev/null');
if ($pathComposer) {
    $foundComposer['composer (en PATH)'] = trim($pathComposer);
}
$results['composer'] = $foundComposer;

// 4. Verificación de conectividad saliente HTTPS hacia Google (OAuth y Sheets)
$connectivity = [];
$targets = [
    'google_accounts' => 'https://accounts.google.com',
    'google_sheets_api' => 'https://sheets.googleapis.com',
];
foreach ($targets as $label => $url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    // En PHP >= 8.0 curl_close() ya no es necesario
    $connectivity[$label] = [
        'url' => $url,
        'http_code' => $httpCode,
        'success' => ($httpCode > 0),
        'error' => $curlErr ?: null,
    ];
}
$results['connectivity'] = $connectivity;

// 5. Verificación de espacio y permisos en directorio base
$homeDir = getenv('HOME') ?: (isset($_SERVER['HOME']) ? $_SERVER['HOME'] : dirname(__DIR__, 3));
$results['home_directory'] = $homeDir;
$results['free_space_bytes'] = @disk_free_space($homeDir) ?: null;
$results['is_home_writable'] = is_writable($homeDir);

// 6. Determinar ruta segura de salida
$customOut = $argv[1] ?? null;
$outPath = $customOut ?: ($homeDir . '/crm_diagnostico_resultado.txt');

$executionTimeSec = round(microtime(true) - $startTime, 3);
$results['execution_time_seconds'] = $executionTimeSec;

$formattedText = "=== DIAGNÓSTICO CLI HOSTING CRM ===\n";
$formattedText .= "Fecha: " . $results['timestamp'] . "\n";
$formattedText .= "Usuario: " . $results['user'] . "\n";
$formattedText .= "PHP CLI: " . $results['php_cli_version'] . " (SAPI: " . $results['php_sapi'] . ")\n";
$formattedText .= "Binario actual: " . $results['php_binary'] . "\n";
$formattedText .= "Límite de memoria: " . $results['memory_limit'] . "\n\n";

$formattedText .= "--- Binarios PHP disponibles ---\n";
if (empty($foundPhpBinaries)) {
    $formattedText .= "No se detectaron binarios alternativos en rutas estándar.\n";
} else {
    foreach ($foundPhpBinaries as $bin => $ver) {
        $formattedText .= " - $bin: $ver\n";
    }
}

$formattedText .= "\n--- Composer ---\n";
if (empty($foundComposer)) {
    $formattedText .= "No detectado en rutas estándar de cPanel. (Se recomienda subir vendor compilado o usar cron controlado).\n";
} else {
    foreach ($foundComposer as $comp => $ver) {
        $formattedText .= " - $comp: $ver\n";
    }
}

$formattedText .= "\n--- Extensiones PHP ---\n";
foreach ($extCheck as $ext => $info) {
    $status = $info['loaded'] ? '[OK]' : '[FALTA]';
    $formattedText .= " $status $ext - {$info['description']}\n";
}

$formattedText .= "\n--- Conectividad saliente HTTPS ---\n";
foreach ($connectivity as $label => $c) {
    $st = $c['success'] ? '[OK]' : '[ERROR]';
    $formattedText .= " $st $label: HTTP {$c['http_code']}" . ($c['error'] ? " ({$c['error']})" : "") . "\n";
}

$formattedText .= "\n--- Permisos y almacenamiento ---\n";
$formattedText .= "Directorio HOME: " . $results['home_directory'] . "\n";
$formattedText .= "Escritura en HOME: " . ($results['is_home_writable'] ? 'Sí (permite crear carpeta privada crm-app)' : 'No') . "\n";
if ($results['free_space_bytes']) {
    $formattedText .= "Espacio libre: " . round($results['free_space_bytes'] / (1024 * 1024), 2) . " MB\n";
}

$formattedText .= "\nTiempo de ejecución: {$executionTimeSec}s\n";
$formattedText .= "===================================\n";

// Guardar en archivo privado
@file_put_contents($outPath, $formattedText);

// Mostrar mensaje en STDOUT para la salida del Cron/CLI
echo "Diagnóstico completado con éxito en {$executionTimeSec}s.\n";
echo "Resultado privado guardado en: $outPath\n";
if (!empty($missingRequired)) {
    echo "ALERTA: Faltan extensiones obligatorias: " . implode(', ', $missingRequired) . "\n";
}
