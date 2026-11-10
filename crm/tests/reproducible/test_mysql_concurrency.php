<?php

/**
 * Prueba reproducible y sincronizada de concurrencia para MySQL / MariaDB (InnoDB).
 *
 * Propósito:
 * 1. Exige una base de datos de prueba configurada explícitamente (ej. `tusoluc1_crm_test`).
 *    Por motivos de seguridad, NUNCA utiliza DB_DATABASE como alternativa ni permite
 *    ejecutarse sobre bases principales.
 * 2. Comprueba que el Proceso B intenta adquirir el bloqueo ANTES del commit de A,
 *    y mide estrictamente la espera de esa operación de base de datos (excluyendo el
 *    arranque o inicialización de Laravel).
 * 3. Ejecuta el servicio real de Laravel (`AdvisorAssignmentResolver::reconcilePendingLeads`).
 *    Si el servicio real no está disponible, reporta que la prueba no se ejecutó;
 *    NUNCA se presenta como aprobada mediante un fallback PDO.
 * 4. Verifica el resultado del servicio (`skipped_manual = 1`) y la auditoría registrada
 *    en `audit_events` (`leads.reconciliation_preserved_manual`).
 * 5. Diseñada para ejecución segura en hosting compartido (cPanel / Cron / CLI) sin
 *    exponer contraseñas ni credenciales en la línea de comandos ni en los registros,
 *    con mecanismo de bloqueo para impedir ejecuciones repetidas cada minuto de Cron.
 *
 * Configuración en hosting:
 * - Base de datos de prueba: tusoluc1_crm_test
 * - Usuario exclusivo: tusoluc1_crmtest
 * - PHP CLI comprobado: /usr/local/bin/php
 */

// 1. Detección y prevención de ejecuciones repetitivas en Cron (One-shot run lock)
$options = getopt('', ['worker', 'lead::', 'mgmt::', 'env-file::', 'force']);
$isWorker = isset($options['worker']);

$possibleFlagFiles = [
    getenv('CONCURRENCY_FLAG_FILE'),
    '/home/tusoluc1/concurrency_test_executed.flag',
    sys_get_temp_dir() . '/concurrency_test_executed.flag',
];

$flagFile = null;
foreach ($possibleFlagFiles as $candidate) {
    if (!empty($candidate)) {
        $flagFile = $candidate;
        break;
    }
}

if (!$isWorker && file_exists($flagFile) && !isset($options['force'])) {
    $executedAt = trim(@file_get_contents($flagFile) ?: 'anteriormente');
    echo "======================================================================\n";
    echo "AVISO: La prueba ya fue ejecutada previamente ({$executedAt}).\n";
    echo "Para impedir ejecuciones repetidas cada minuto de Cron, la tarea se detiene.\n";
    echo "Para volver a ejecutarla, elimine el archivo:\n";
    echo "  {$flagFile}\n";
    echo "o invoque el script agregando el parámetro --force.\n";
    echo "======================================================================\n";
    exit(0);
}

// 2. Cargar credenciales desde archivo seguro si existe (.env específico de prueba)
$possibleEnvFiles = [
    $options['env-file'] ?? null,
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
            updated_at TIMESTAMP NULL,
            INDEX idx_target (target_id),
            INDEX idx_action (action)
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

    $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
    $bootstrapPath = dirname(__DIR__, 2) . '/bootstrap/app.php';

    // REGLA ESTRICTA: Exigir el servicio real de Laravel.
    // Si no está disponible, reportar que la prueba NO se ejecutó; NO aprobar mediante fallback.
    if (!file_exists($autoloadPath) || !file_exists($bootstrapPath)) {
        echo json_encode([
            'success' => false,
            'error_type' => 'SERVICE_UNAVAILABLE',
            'message' => 'PRUEBA NO EJECUTADA: El framework Laravel o dependencias de vendor/ no están disponibles en este entorno. No se aprueba mediante fallback simulado.',
        ]);
        exit(1);
    }

    try {
        // 1. Inicializar Laravel ANTES de medir la espera del bloqueo
        require_once $autoloadPath;
        $app = require $bootstrapPath;
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        if (!class_exists(\App\Services\AdvisorAssignmentResolver::class)) {
            echo json_encode([
                'success' => false,
                'error_type' => 'SERVICE_UNAVAILABLE',
                'message' => 'PRUEBA NO EJECUTADA: La clase AdvisorAssignmentResolver no existe en el código cargado.',
            ]);
            exit(1);
        }

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

        // 2. SINCRONIZACIÓN: Laravel ya arrancó y la conexión está lista.
        // Se envía la señal a Proceso A indicando que Proceso B está por ingresar al bloqueo.
        echo "SERVICE_READY_FOR_LOCK\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();

        // 3. MEDICIÓN ESTRICTA DE LA ESPERA DEL BLOQUEO:
        // Se toma el tiempo inmediatamente antes de invocar reconcilePendingLeads()
        // (excluyendo por completo el tiempo de arranque de Laravel).
        $startLockWait = microtime(true);

        // Esta llamada ejecuta DB::transaction() y entra en lockForUpdate(),
        // quedando físicamente retenida en LOCK WAIT en InnoDB hasta que Proceso A hace COMMIT.
        $resolverResult = \App\Services\AdvisorAssignmentResolver::reconcilePendingLeads(limit: 10);
        $lockWaitDuration = microtime(true) - $startLockWait;

        // 4. Verificación exhaustiva post-desbloqueo
        $finalLead = \App\Models\Lead::find($leadId);
        $finalMgmt = \App\Models\LeadManagement::where('lead_id', $leadId)->first();

        // Comprobar auditoría registrada
        $auditEvent = \App\Models\AuditEvent::where('action', 'leads.reconciliation_preserved_manual')
            ->where('target_id', (string)$leadId)
            ->first();

        echo json_encode([
            'success' => true,
            'service_executed' => 'AdvisorAssignmentResolver::reconcilePendingLeads',
            'lock_wait_seconds' => round($lockWaitDuration, 4),
            'resolver_result' => $resolverResult,
            'audit_logged' => $auditEvent !== null,
            'audit_payload' => $auditEvent ? $auditEvent->payload : null,
            'assigned_user_id' => $finalMgmt ? (int)$finalMgmt->assigned_user_id : null,
            'reconciliation_pending' => $finalLead ? (int)$finalLead->reconciliation_pending : null,
            'message' => 'Servicio real ejecutado; bloqueo de InnoDB respetado y asignación manual preservada',
        ]);
        exit(0);
    } catch (\Throwable $e) {
        echo json_encode([
            'success' => false,
            'error_type' => 'EXECUTION_EXCEPTION',
            'message' => 'Excepción en servicio real de Laravel: ' . $e->getMessage(),
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
echo "PHP CLI comprobado: " . PHP_BINARY . "\n";
echo "Credenciales: [PROTEGIDAS / CARGADAS DESDE ARCHIVO SEGURO]\n\n";

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
    echo "[PRUEBA PENDIENTE / OMITIDA - NO APROBADA]\n";
    echo "Motivo: No se pudo conectar a la base de pruebas ({$e->getMessage()}).\n";
    echo "Compruebe que el servicio MySQL esté activo y el usuario tenga privilegios en '{$database}'.\n";
    echo "----------------------------------------------------------------------\n";
    exit(0);
}

// Garantizar que las tablas de prueba existan en la base aislada
ensureTestTables($pdoA);

// 1. Preparar registro de prueba con reconciliation_pending = 1 y assigned_user_id = NULL
$metaId = 'crm-sync-race-' . bin2hex(random_bytes(6));
$sourceKey = sha1('Hoja3_' . $metaId);

echo "[1/6] Creando lead de prueba en base aislada (meta_id: {$metaId})...\n";
$stmtLead = $pdoA->prepare("INSERT INTO leads (source_key, source_sheet, meta_id, name, email, source_assigned_to, reconciliation_pending, original, created_at, updated_at) VALUES (:key, 'Hoja 3', :meta, 'Lead Concurrente Test', 'test@concurrente.cl', 'Pao', 1, '{}', NOW(), NOW())");
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
    echo "      -> Bloqueo exclusivo de fila retenido exitosamente por Proceso A.\n";

    // 3. Lanzar Proceso B como subproceso independiente
    // Las credenciales se transmiten mediante variables de entorno seguras de proc_open, NUNCA en argv
    echo "[3/6] Iniciando Proceso B concurrente (inicializando Laravel y servicio real)...\n";
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

    // SINCRONIZACIÓN EXACTA:
    // Esperar señal de que Proceso B ya inicializó Laravel por completo y está entrando a la consulta de bloqueo
    $signal = trim(fgets($pipes[1]));
    if ($signal !== 'SERVICE_READY_FOR_LOCK') {
        $err = stream_get_contents($pipes[2]);
        $rawOut = stream_get_contents($pipes[1]);
        proc_close($proc);
        echo "----------------------------------------------------------------------\n";
        echo "[PRUEBA NO EJECUTADA - SERVICIO NO DISPONIBLE]\n";
        echo "El Proceso B no pudo inicializar el servicio real de Laravel.\n";
        echo "Detalle recibido: " . ($signal ?: $err ?: $rawOut) . "\n";
        echo "Por directriz de seguridad, no se aprueba mediante fallback simulado.\n";
        echo "----------------------------------------------------------------------\n";
        exit(1);
    }
    echo "      -> Proceso B arrancó Laravel y conectó a la base aislada.\n";
    echo "      -> Proceso B invoca AdvisorAssignmentResolver::reconcilePendingLeads().\n";
    echo "      -> Motor MySQL InnoDB suspende a Proceso B en LOCK WAIT esperando por fila #{$mgmtId}.\n";

    // 4. Proceso A retiene el bloqueo intencionalmente por 1.4 segundos
    // Esto demuestra de forma irrefutable que Proceso B está bloqueado esperando antes del commit
    $holdSeconds = 1.4;
    echo "[4/6] Proceso A retiene el bloqueo por {$holdSeconds}s (verificación de espera física de la consulta)...\n";
    usleep((int)($holdSeconds * 1000000));

    // 5. Proceso A realiza la asignación manual (usuario ID 99) y ejecuta COMMIT
    echo "[5/6] Proceso A asigna manualmente asesor #99 y ejecuta COMMIT...\n";
    $manualUserId = 99;
    $pdoA->prepare("UPDATE lead_management SET assigned_user_id = :user_id, updated_at = NOW() WHERE id = :id")
        ->execute(['user_id' => $manualUserId, 'id' => $mgmtId]);
    $pdoA->commit();
    echo "      -> COMMIT completado. Bloqueo de fila liberado en InnoDB.\n";

    // 6. Proceso B se desbloquea y completa su ejecución
    echo "[6/6] Esperando desbloqueo y finalización del servicio real en Proceso B...\n";
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

    $lockWaited = $data['lock_wait_seconds'] ?? 0;
    echo "      -> Proceso B finalizó exitosamente (código 0).\n";
    echo "      -> Servicio probado: " . ($data['service_executed'] ?? 'desconocido') . "\n";
    echo "      -> Tiempo de espera efectivo de la operación en InnoDB: {$lockWaited} segundos (excluye arranque de Laravel).\n";

    // Comprobación de que esperó efectivamente el bloqueo (al menos el 70% del tiempo de retención)
    if ($lockWaited < ($holdSeconds * 0.7)) {
        echo "✗ ERROR: Proceso B no esperó efectivamente el bloqueo de fila (esperó {$lockWaited}s, requerido >= " . ($holdSeconds * 0.7) . "s).\n";
        exit(1);
    }

    // Comprobación de resultado del servicio
    $resolverResult = $data['resolver_result'] ?? [];
    echo "      -> Resultado del servicio: reconciliados={$resolverResult['reconciled']}, omitidos por manual={$resolverResult['skipped_manual']}, pendientes={$resolverResult['unresolved']}.\n";
    if (($resolverResult['skipped_manual'] ?? 0) !== 1) {
        echo "✗ ERROR: El servicio de reconciliación no reportó skipped_manual = 1.\n";
        exit(1);
    }

    // Comprobación de auditoría
    if (empty($data['audit_logged'])) {
        echo "✗ ERROR: No se encontró el registro de auditoría 'leads.reconciliation_preserved_manual'.\n";
        exit(1);
    }
    echo "      -> Auditoría verificada: 'leads.reconciliation_preserved_manual' registrada con éxito.\n";

    // Comprobación de estado final en base de datos
    $stmtFinal = $pdoA->prepare("SELECT lm.assigned_user_id, l.reconciliation_pending FROM lead_management lm JOIN leads l ON l.id = lm.lead_id WHERE lm.id = :id");
    $stmtFinal->execute(['id' => $mgmtId]);
    $final = $stmtFinal->fetch();

    if ((int)$final['assigned_user_id'] === 99 && (int)$final['reconciliation_pending'] === 0) {
        echo "\n======================================================================\n";
        echo "✓ PRUEBA APROBADA EXITOSAMENTE:\n";
        echo "  1. Base de datos de prueba aislada comprobada: {$database}.\n";
        echo "  2. Proceso B sincronizado: arrancó Laravel y solicitó bloqueo ANTES del commit.\n";
        echo "  3. Tiempo de espera medido estrictamente sobre la consulta: {$lockWaited}s.\n";
        echo "  4. Servicio real AdvisorAssignmentResolver ejecutado con éxito.\n";
        echo "  5. Asignación manual preservada (ID 99) sin sobrescritura.\n";
        echo "  6. Auditoría 'leads.reconciliation_preserved_manual' verificada.\n";
        echo "======================================================================\n";

        // Registrar bandera de ejecución exitosa para prevenir repetición en Cron
        if ($flagFile) {
            @file_put_contents($flagFile, date('Y-m-d H:i:s T') . ' - Ejecución única exitosa');
        }
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
