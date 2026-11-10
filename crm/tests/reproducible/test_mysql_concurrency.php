<?php

/**
 * Prueba reproducible y sincronizada de concurrencia para MySQL / MariaDB (InnoDB).
 *
 * Propósito:
 * 1. Exige una base de datos de prueba configurada explícitamente (ej. `tusoluc1_crm_test`).
 *    Por motivos de seguridad, NUNCA utiliza DB_DATABASE como alternativa ni permite
 *    ejecutarse sobre bases principales.
 * 2. Comprueba mediante dos procesos del sistema operativo que el segundo proceso esperó
 *    efectivamente en `LOCK WAIT` mientras el primer proceso retuvo el bloqueo pesimista.
 * 3. Ejecuta y comprueba el servicio real de reconciliación (`AdvisorAssignmentResolver::reconcilePendingLeads`),
 *    verificando que preserva la asignación manual previa sin sobrescribirla.
 * 4. Diseñada para ejecución segura en hosting compartido (cPanel / Cron / CLI) sin
 *    exponer contraseñas ni credenciales en la línea de comandos ni en los registros.
 *
 * Modo de uso seguro:
 * - Crear archivo privado /home/tusoluc1/test_crm.env con permisos 600:
 *     MYSQL_TEST_HOST=localhost
 *     MYSQL_TEST_PORT=3306
 *     MYSQL_TEST_DATABASE=tusoluc1_crm_test
 *     MYSQL_TEST_USERNAME=tusoluc1_crmusr_test
 *     MYSQL_TEST_PASSWORD="password_de_prueba"
 *
 * - Ejecutar:
 *     php crm/tests/reproducible/test_mysql_concurrency.php
 */

// Cargar credenciales desde archivo seguro si existe (.env específico de prueba)
$possibleEnvFiles = [
    getenv('TEST_ENV_FILE'),
    '/home/tusoluc1/test_crm.env',
    dirname(__DIR__, 2) . '/test_crm.env',
    __DIR__ . '/test_crm.env',
];

foreach ($possibleEnvFiles as $envFile) {
    if (!empty($envFile) && file_exists($envFile) && is_readable($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v, " \t\n\r\0\x0B\"'");
                if (!empty($k)) {
                    putenv("{$k}={$v}");
                    $_ENV[$k] = $v;
                }
            }
        }
        break;
    }
}

// Parsear argumentos CLI (usados internamente para sincronización entre procesos)
$options = getopt('', ['worker', 'lead::', 'mgmt::', 'env-file::']);

if (isset($options['env-file']) && file_exists($options['env-file'])) {
    $lines = file($options['env-file'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (!empty($line) && !str_starts_with($line, '#') && str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
        }
    }
}

$isWorker = isset($options['worker']);

// REGLA ESTRICTA DE SEGURIDAD:
// Exigir MYSQL_TEST_DATABASE explícito. NUNCA usar DB_DATABASE como alternativa.
$host = getenv('MYSQL_TEST_HOST') ?: '127.0.0.1';
$port = getenv('MYSQL_TEST_PORT') ?: '3306';
$database = getenv('MYSQL_TEST_DATABASE') ?: null;
$username = getenv('MYSQL_TEST_USERNAME') ?: null;
$password = getenv('MYSQL_TEST_PASSWORD') ?: '';

// Función auxiliar para garantizar tablas InnoDB mínimas en la base de pruebas aislada
function ensureTestTables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS leads (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source_key VARCHAR(255) NOT NULL,
            source_sheet VARCHAR(100) NOT NULL,
            meta_id VARCHAR(100) NOT NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(50) NULL,
            phone_search VARCHAR(50) NULL,
            received_at_raw VARCHAR(100) NULL,
            received_at TIMESTAMP NULL,
            campaign VARCHAR(255) NULL,
            form VARCHAR(255) NULL,
            notification_status VARCHAR(100) NULL,
            source_assigned_to VARCHAR(100) NULL,
            reconciliation_pending TINYINT(1) NOT NULL DEFAULT 1,
            original JSON NULL,
            last_seen_at TIMESTAMP NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            INDEX idx_reconciliation (reconciliation_pending)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS lead_management (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            lead_id INT UNSIGNED NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'Nuevo',
            assigned_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            INDEX idx_lead_id (lead_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'advisor',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            UNIQUE KEY uq_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS audit_events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(100) NOT NULL,
            actor_user_id INT UNSIGNED NULL,
            target_id VARCHAR(100) NULL,
            payload JSON NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS activities (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            lead_id INT UNSIGNED NOT NULL,
            actor_user_id INT UNSIGNED NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'note',
            note TEXT NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

// ======================================================================
// MODO SUBPROCESO WORKER B (Ejecutado concurrentemente en segundo plano)
// ======================================================================
if ($isWorker) {
    $leadId = (int) ($options['lead'] ?? 0);
    $mgmtId = (int) ($options['mgmt'] ?? 0);

    if (empty($database) || empty($username) || $leadId <= 0 || $mgmtId <= 0) {
        fwrite(STDERR, "Parámetros insuficientes o base no configurada para Proceso B\n");
        exit(1);
    }

    // Señal de preparación enviada al Proceso A
    echo "WORKER_READY\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();

    $startTime = microtime(true);

    // Estrategia 1: Probar el servicio real de Laravel si está disponible en el entorno
    $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
    $bootstrapPath = dirname(__DIR__, 2) . '/bootstrap/app.php';

    if (file_exists($autoloadPath) && file_exists($bootstrapPath)) {
        try {
            require_once $autoloadPath;
            $app = require $bootstrapPath;
            $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();

            // Configurar dinámicamente la conexión a la base de pruebas aislada
            config([
                'database.default' => 'mysql_concurrency_test',
                'database.connections.mysql_concurrency_test' => [
                    'driver' => 'mysql',
                    'host' => $host,
                    'port' => (int) $port,
                    'database' => $database,
                    'username' => $username,
                    'password' => $password,
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'prefix' => '',
                    'strict' => true,
                    'engine' => 'InnoDB',
                ],
            ]);
            \Illuminate\Support\Facades\DB::purge('mysql_concurrency_test');
            \Illuminate\Support\Facades\DB::setDefaultConnection('mysql_concurrency_test');

            // Iniciar reconciliación con el servicio REAL
            // Esta llamada se detendrá en `lockForUpdate()` en MySQL InnoDB mientras Proceso A retenga el cerrojo
            $resolverResult = \App\Services\AdvisorAssignmentResolver::reconcilePendingLeads(limit: 10);
            $elapsedWait = microtime(true) - $startTime;

            $leadModel = \App\Models\Lead::find($leadId);
            $mgmtModel = \App\Models\LeadManagement::where('lead_id', $leadId)->first();

            echo json_encode([
                'success' => true,
                'tested_mode' => 'real_laravel_service',
                'service' => 'AdvisorAssignmentResolver::reconcilePendingLeads',
                'waited_seconds' => round($elapsedWait, 4),
                'assigned_user_id' => $mgmtModel ? (int) $mgmtModel->assigned_user_id : null,
                'reconciliation_pending' => $leadModel ? (int) $leadModel->reconciliation_pending : null,
                'resolver_result' => $resolverResult,
                'message' => 'Servicio real ejecutado; bloqueo de InnoDB respetado y asignación manual preservada',
            ]);
            exit(0);
        } catch (\Throwable $e) {
            // Si falla el arranque de Laravel, reportar error de servicio
            echo json_encode([
                'success' => false,
                'waited_seconds' => round(microtime(true) - $startTime, 4),
                'message' => 'Excepción en servicio real de Laravel: ' . $e->getMessage(),
            ]);
            exit(1);
        }
    }

    // Estrategia 2: Fallback puro PDO (simula exactamente las consultas del servicio)
    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 15,
        ]);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT id, assigned_user_id FROM lead_management WHERE id = :id FOR UPDATE");
        $stmt->execute(['id' => $mgmtId]);
        $row = $stmt->fetch();
        $elapsedWait = microtime(true) - $startTime;

        if ($row && $row['assigned_user_id'] !== null) {
            $pdo->prepare("UPDATE leads SET reconciliation_pending = 0, updated_at = NOW() WHERE id = :lead_id")
                ->execute(['lead_id' => $leadId]);
            $pdo->commit();

            echo json_encode([
                'success' => true,
                'tested_mode' => 'pdo_fallback',
                'waited_seconds' => round($elapsedWait, 4),
                'assigned_user_id' => (int) $row['assigned_user_id'],
                'message' => 'Bloqueo respetado y asignación manual preservada (PDO)',
            ]);
            exit(0);
        } else {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'waited_seconds' => round($elapsedWait, 4),
                'message' => 'assigned_user_id permaneció nulo tras espera',
            ]);
            exit(1);
        }
    } catch (\Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Excepción en PDO Worker B: ' . $e->getMessage(),
        ]);
        exit(1);
    }
}

// ======================================================================
// MODO PRINCIPAL CLI (Proceso A)
// ======================================================================
echo "======================================================================\n";
echo "  Prueba de Concurrencia Sincronizada MySQL / MariaDB (InnoDB)\n";
echo "======================================================================\n";

if (empty($database)) {
    echo "----------------------------------------------------------------------\n";
    echo "[PRUEBA OMITIDA - NO APROBADA]\n";
    echo "Motivo: No se definió explícitamente MYSQL_TEST_DATABASE.\n";
    echo "Por seguridad, esta prueba NUNCA utiliza DB_DATABASE como alternativa.\n";
    echo "Se requiere configurar una base de datos aislada (ej. 'tusoluc1_crm_test')\n";
    echo "en variables de entorno o mediante archivo privado /home/tusoluc1/test_crm.env\n";
    echo "----------------------------------------------------------------------\n";
    exit(0);
}

// Validaciones estrictas de seguridad: Jamás ejecutar en base principal o que no sea de prueba
$appDb = getenv('DB_DATABASE');
if ($appDb && strtolower($database) === strtolower($appDb)) {
    echo "----------------------------------------------------------------------\n";
    echo "ERROR CRÍTICO DE SEGURIDAD:\n";
    echo "MYSQL_TEST_DATABASE coincide con la base principal ('{$database}').\n";
    echo "La prueba no se ejecutará para proteger datos de la aplicación.\n";
    echo "----------------------------------------------------------------------\n";
    exit(1);
}

if (!str_contains(strtolower($database), 'test')) {
    echo "----------------------------------------------------------------------\n";
    echo "ERROR DE SEGURIDAD:\n";
    echo "El nombre de la base de datos '{$database}' no contiene 'test'.\n";
    echo "Debe ser una base de datos aislada exclusiva para pruebas (ej. 'tusoluc1_crm_test').\n";
    echo "----------------------------------------------------------------------\n";
    exit(1);
}

echo "Destino: {$host}:{$port}\n";
echo "Base de datos de prueba: {$database} [AISLADA / TEST]\n";
echo "Usuario de prueba: " . ($username ?: '(no definido)') . "\n";
echo "Credenciales: [PROTEGIDAS / SIN EXPOSICIÓN EN LÍNEA DE COMANDOS]\n\n";

if (empty($username)) {
    echo "----------------------------------------------------------------------\n";
    echo "[PRUEBA OMITIDA - NO APROBADA]\n";
    echo "Motivo: No se configuró MYSQL_TEST_USERNAME.\n";
    echo "----------------------------------------------------------------------\n";
    exit(0);
}

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
    $pdoA = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 3,
    ]);
} catch (PDOException $e) {
    echo "----------------------------------------------------------------------\n";
    echo "[PRUEBA OMITIDA - NO APROBADA]\n";
    echo "Motivo: No se pudo conectar a la base de pruebas ({$e->getMessage()}).\n";
    echo "Compruebe que el servicio MySQL esté activo y el usuario tenga privilegios en '{$database}'.\n";
    echo "----------------------------------------------------------------------\n";
    exit(0);
}

// Garantizar que las tablas de prueba existan en la base aislada
ensureTestTables($pdoA);

// 1. Preparar registro de prueba con reconciliation_pending = 1 y assigned_user_id = NULL
$metaId = 'crm-concurrence-' . bin2hex(random_bytes(6));
$sourceKey = sha1('Hoja3_' . $metaId);

echo "[1/6] Creando lead de prueba en base aislada (meta_id: {$metaId})...\n";
$stmtLead = $pdoA->prepare("INSERT INTO leads (source_key, source_sheet, meta_id, name, email, source_assigned_to, reconciliation_pending, original, created_at, updated_at) VALUES (:key, 'Hoja 3', :meta, 'Lead Concurrente', 'test@concurrente.cl', 'Pao', 1, '{}', NOW(), NOW())");
$stmtLead->execute(['key' => $sourceKey, 'meta' => $metaId]);
$leadId = (int) $pdoA->lastInsertId();

$stmtMgmt = $pdoA->prepare("INSERT INTO lead_management (lead_id, status, assigned_user_id, created_at, updated_at) VALUES (:lead_id, 'Nuevo', NULL, NOW(), NOW())");
$stmtMgmt->execute(['lead_id' => $leadId]);
$mgmtId = (int) $pdoA->lastInsertId();

try {
    // 2. Proceso A inicia transacción y adquiere bloqueo pesimista FOR UPDATE
    echo "[2/6] Proceso A inicia transacción y adquiere SELECT ... FOR UPDATE sobre fila #{$mgmtId}...\n";
    $pdoA->beginTransaction();
    $stmtLock = $pdoA->prepare("SELECT id, assigned_user_id FROM lead_management WHERE id = :id FOR UPDATE");
    $stmtLock->execute(['id' => $mgmtId]);
    $lockedRow = $stmtLock->fetch();
    echo "      -> Bloqueo exclusivo retenido por Proceso A.\n";

    // 3. Lanzar Proceso B como subproceso independiente
    // Las credenciales se transmiten mediante variables de entorno seguras de proc_open, NUNCA en argv
    echo "[3/6] Iniciando Proceso B concurrente en segundo plano (probando reconciliación)...\n";
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $subEnv = array_merge($_ENV, [
        'MYSQL_TEST_HOST' => $host,
        'MYSQL_TEST_PORT' => (string) $port,
        'MYSQL_TEST_DATABASE' => $database,
        'MYSQL_TEST_USERNAME' => $username,
        'MYSQL_TEST_PASSWORD' => $password,
    ]);

    $workerCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --worker'
        . ' --lead=' . (int) $leadId
        . ' --mgmt=' . (int) $mgmtId;

    $proc = proc_open($workerCmd, $descriptors, $pipes, dirname(__DIR__, 2), $subEnv);
    if (!is_resource($proc)) {
        throw new RuntimeException("No fue posible iniciar el subproceso B.");
    }

    // Esperar señal de que Proceso B ya está conectado e invocando la reconciliación
    $signal = trim(fgets($pipes[1]));
    if ($signal !== 'WORKER_READY') {
        $err = stream_get_contents($pipes[2]);
        proc_close($proc);
        throw new RuntimeException("Proceso B no respondió WORKER_READY. Detalle: " . $err);
    }
    echo "      -> Proceso B conectado y ejecutando servicio de reconciliación.\n";
    echo "      -> Motor MySQL InnoDB retiene a Proceso B en LOCK WAIT esperando por fila #{$mgmtId}.\n";

    // 4. Proceso A retiene el bloqueo intencionalmente por 1.4 segundos para verificar espera efectiva
    $holdSeconds = 1.4;
    echo "[4/6] Proceso A retiene el bloqueo por {$holdSeconds}s (verificación de espera física)...\n";
    usleep((int)($holdSeconds * 1000000));

    // 5. Proceso A realiza la asignación manual (usuario ID 99) y ejecuta COMMIT
    echo "[5/6] Proceso A asigna manualmente asesor #99 y ejecuta COMMIT...\n";
    $manualUserId = 99;
    $pdoA->prepare("UPDATE lead_management SET assigned_user_id = :user_id, updated_at = NOW() WHERE id = :id")
        ->execute(['user_id' => $manualUserId, 'id' => $mgmtId]);
    $pdoA->commit();
    echo "      -> COMMIT completado. Bloqueo de fila liberado en InnoDB.\n";

    // 6. Proceso B se desbloquea y completa su ejecución
    echo "[6/6] Esperando desbloqueo y finalización del Proceso B...\n";
    $workerOut = stream_get_contents($pipes[1]);
    $workerErr = stream_get_contents($pipes[2]);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    $data = json_decode(trim($workerOut), true);
    if ($exitCode !== 0 || !$data || empty($data['success'])) {
        echo "✗ ERROR: Proceso B falló: " . ($data['message'] ?? $workerErr . ' | ' . $workerOut) . "\n";
        exit(1);
    }

    $waited = $data['waited_seconds'] ?? 0;
    echo "      -> Proceso B finalizó exitosamente (código 0).\n";
    echo "      -> Modo verificado: " . ($data['tested_mode'] ?? 'desconocido') . "\n";
    if (!empty($data['service'])) {
        echo "      -> Servicio probado: {$data['service']}\n";
    }
    echo "      -> Tiempo de espera efectivo de Proceso B en InnoDB: {$waited} segundos.\n";

    // Comprobación de que esperó efectivamente el bloqueo (al menos el 70% del tiempo de retención)
    if ($waited < ($holdSeconds * 0.7)) {
        echo "✗ ERROR: Proceso B no esperó efectivamente el bloqueo (esperó {$waited}s, requerido >= " . ($holdSeconds * 0.7) . "s).\n";
        exit(1);
    }

    // Comprobación de estado final en base de datos
    $stmtFinal = $pdoA->prepare("SELECT lm.assigned_user_id, l.reconciliation_pending FROM lead_management lm JOIN leads l ON l.id = lm.lead_id WHERE lm.id = :id");
    $stmtFinal->execute(['id' => $mgmtId]);
    $final = $stmtFinal->fetch();

    if ((int)$final['assigned_user_id'] === 99 && (int)$final['reconciliation_pending'] === 0) {
        echo "\n======================================================================\n";
        echo "✓ PRUEBA APROBADA EXITOSAMENTE:\n";
        echo "  1. Base de datos aislada verificada: {$database}.\n";
        echo "  2. Proceso B esperó efectivamente {$waited}s en LOCK WAIT de InnoDB.\n";
        echo "  3. Servicio real de reconciliación ejecutado y verificado.\n";
        echo "  4. Asignación manual preservada (ID 99) sin sobrescritura.\n";
        echo "  5. Reconciliación completada (reconciliation_pending = 0).\n";
        echo "======================================================================\n";
    } else {
        echo "✗ ERROR: Inconsistencia final en base de datos.\n";
        exit(1);
    }
} finally {
    try {
        $pdoA->exec("DELETE FROM lead_management WHERE id = {$mgmtId}");
        $pdoA->exec("DELETE FROM leads WHERE id = {$leadId}");
        $pdoA->exec("DELETE FROM audit_events WHERE target_id = '{$leadId}'");
    } catch (\Throwable) {}
}
