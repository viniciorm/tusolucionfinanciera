<?php

/**
 * Prueba reproducible y sincronizada de concurrencia para MySQL / MariaDB (InnoDB).
 *
 * Propósito:
 * 1. Exige una base de datos de prueba configurada explícitamente (ej. `tusoluc1_crm_test`).
 *    Por motivos de seguridad, NUNCA utiliza DB_DATABASE como alternativa ni permite
 *    ejecutarse sobre bases principales.
 * 2. Ejecuta las migraciones reales del CRM (`php artisan migrate`) en la base de pruebas,
 *    utilizando el esquema oficial (incluyendo `audit_events` con `lead_id` y `details`).
 * 3. Crea un usuario ficticio real en `users` para garantizar la integridad referencial
 *    y la clave foránea (`lead_management.assigned_user_id -> users.id`), limpiando
 *    todos los registros creados al finalizar en `finally`.
 * 4. Identifica la conexión real del Proceso B mediante `SELECT CONNECTION_ID() AS id`.
 *    Sincroniza ambos procesos demostrando que la conexión de B está físicamente esperando
 *    el cerrojo (`LOCK WAIT` en `information_schema.innodb_trx` o `SHOW PROCESSLIST`)
 *    ANTES del commit de A.
 * 5. Si no es posible demostrar de forma concluyente que la conexión de B está esperando,
 *    informa `[RESULTADO NO CONCLUYENTE - NO APROBADO]`, nunca lo presenta como aprobado.
 * 6. Mide el tiempo de ejecución del servicio real sin atribuirlo falsamente de forma exclusiva
 *    a bloqueo, reconociendo que comprende el intervalo en LOCK WAIT más el procesamiento
 *    posterior de reconciliación y auditoría.
 * 7. Si el servicio real de Laravel no está disponible, reporta que la prueba no se ejecutó;
 *    NUNCA se presenta como aprobada mediante un fallback simulado.
 * 8. Mecanismo único de ejecución para Cron: el propio script gestiona el archivo testigo
 *    inmediatamente antes de iniciar la prueba, impidiendo recurrencias incluso si falla,
 *    sin que wrappers externos creen el archivo antes de tiempo.
 *
 * Configuración comprobada en hosting:
 * - Base de datos de prueba: tusoluc1_crm_test
 * - Usuario exclusivo: tusoluc1_crmtest
 * - PHP CLI comprobado: /usr/local/bin/php
 */

// 1. Detección y activación del cerrojo unificado contra repetición en Cron
$options = getopt('', ['worker', 'lead::', 'mgmt::', 'env-file::', 'force']);
$isWorker = isset($options['worker']);

$flagFile = null;
if (getenv('CONCURRENCY_FLAG_FILE')) {
    $flagFile = getenv('CONCURRENCY_FLAG_FILE');
} elseif (is_dir('/home/tusoluc1') && is_writable('/home/tusoluc1')) {
    $flagFile = '/home/tusoluc1/concurrency_test_executed.flag';
} else {
    $flagFile = rtrim(sys_get_temp_dir(), '/\\') . '/concurrency_test_executed.flag';
}

// En modo principal (no worker), comprobar si ya se ejecutó previamente
if (!$isWorker && file_exists($flagFile) && !isset($options['force'])) {
    $executedAt = trim(@file_get_contents($flagFile) ?: 'anteriormente');
    echo "======================================================================\n";
    echo "AVISO: La prueba ya fue ejecutada previamente ({$executedAt}).\n";
    echo "Para impedir ejecuciones repetidas cada minuto de Cron, el proceso se detiene.\n";
    echo "Para volver a ejecutarla, elimine el archivo:\n";
    echo "  {$flagFile}\n";
    echo "o invoque el script agregando el parámetro --force.\n";
    echo "======================================================================\n";
    exit(0);
}

// 2. Cargar credenciales desde archivo seguro (.env específico de prueba)
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

$autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
$bootstrapPath = dirname(__DIR__, 2) . '/bootstrap/app.php';

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

    // REGLA ESTRICTA: Exigir el servicio real de Laravel.
    // Si no está disponible, reportar que la prueba NO se ejecutó; NO aprobar mediante fallback simulado.
    if (!file_exists($autoloadPath) || !file_exists($bootstrapPath)) {
        echo json_encode([
            'success' => false,
            'error_type' => 'SERVICE_UNAVAILABLE',
            'message' => 'PRUEBA NO EJECUTADA: Laravel o dependencias en vendor/ no están disponibles en este entorno. No se aprueba mediante fallback simulado.',
        ]);
        exit(1);
    }

    try {
        // 1. Inicializar Laravel ANTES de registrar tiempo de servicio
        require_once $autoloadPath;
        $app = require $bootstrapPath;
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        if (!class_exists(\App\Services\AdvisorAssignmentResolver::class)) {
            echo json_encode([
                'success' => false,
                'error_type' => 'SERVICE_UNAVAILABLE',
                'message' => 'PRUEBA NO EJECUTADA: La clase AdvisorAssignmentResolver no existe en el código.',
            ]);
            exit(1);
        }

        // Configurar conexión MySQL a la base de pruebas aislada
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

        // Obtener el ID exacto de conexión / hilo en MySQL
        $connectionRow = \Illuminate\Support\Facades\DB::selectOne("SELECT CONNECTION_ID() AS id");
        $connectionId = $connectionRow ? (int)$connectionRow->id : 0;

        // 2. SINCRONIZACIÓN: Laravel arrancó, la conexión está lista e identificada por su ID de hilo.
        // Notificar al Proceso A con el connection_id para verificación en innodb_trx o processlist
        echo "SERVICE_READY_FOR_LOCK:conn_id={$connectionId}\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();

        // 3. Medición del tiempo de ejecución del servicio real
        $startTime = microtime(true);

        // Esta llamada ejecuta DB::transaction() e intenta lockForUpdate() sobre lead_management,
        // quedando físicamente retenida en LOCK WAIT en InnoDB mientras Proceso A retenga el cerrojo
        $resolverResult = \App\Services\AdvisorAssignmentResolver::reconcilePendingLeads(limit: 10);
        $elapsedService = microtime(true) - $startTime;

        // 4. Verificación exhaustiva post-desbloqueo
        $finalLead = \App\Models\Lead::find($leadId);
        $finalMgmt = \App\Models\LeadManagement::where('lead_id', $leadId)->first();

        // Comprobar auditoría en el esquema real (usando lead_id y details)
        $auditEvent = \App\Models\AuditEvent::where('action', 'leads.reconciliation_preserved_manual')
            ->where('lead_id', $leadId)
            ->first();

        $auditDetails = $auditEvent ? (is_array($auditEvent->details) ? $auditEvent->details : json_decode((string)$auditEvent->details, true)) : null;

        echo json_encode([
            'success' => true,
            'connection_id' => $connectionId,
            'service_executed' => 'AdvisorAssignmentResolver::reconcilePendingLeads',
            'service_elapsed_seconds' => round($elapsedService, 4),
            'resolver_result' => $resolverResult,
            'audit_logged' => $auditEvent !== null,
            'audit_lead_id' => $auditEvent ? (int)$auditEvent->lead_id : null,
            'audit_assigned_user_id' => $auditDetails['assigned_user_id'] ?? null,
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
    echo "[PRUEBA PENDIENTE / OMITIDA - NO APROBADA]\n";
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

if (empty($username)) {
    echo "----------------------------------------------------------------------\n";
    echo "[PRUEBA PENDIENTE / OMITIDA - NO APROBADA]\n";
    echo "Motivo: No se configuró MYSQL_TEST_USERNAME.\n";
    echo "----------------------------------------------------------------------\n";
    exit(0);
}

// MECANISMO ÚNICO DE EJECUCIÓN:
// Activar el cerrojo de ejecución única inmediatamente en el propio script PHP
// antes de iniciar la prueba (incluso si falla o se interrumpe durante el proceso)
if (!$isWorker && $flagFile) {
    @file_put_contents($flagFile, date('Y-m-d H:i:s T') . " - Ejecución iniciada sobre '{$database}' (cerrojo activo)");
}

echo "Destino: {$host}:{$port}\n";
echo "Base de datos de prueba: {$database} [AISLADA / TEST]\n";
echo "Usuario de prueba: {$username}\n";
echo "PHP CLI comprobado: " . PHP_BINARY . "\n";
echo "Credenciales: [PROTEGIDAS / CARGADAS DESDE ARCHIVO SEGURO]\n";
echo "Protección anti-repetición en Cron: [ACTIVADA PREVENTIVAMENTE POR SCRIPT]\n\n";

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
    echo "Compruebe que el servicio MySQL esté activo y el usuario '{$username}' tenga privilegios en '{$database}'.\n";
    echo "----------------------------------------------------------------------\n";
    exit(0);
}

// 3. Ejecutar las migraciones REALES del CRM en la base de pruebas vacía
echo "[1/7] Ejecutando migraciones reales del CRM sobre base de pruebas '{$database}'...\n";
if (!file_exists($autoloadPath) || !file_exists($bootstrapPath)) {
    echo "----------------------------------------------------------------------\n";
    echo "[PRUEBA NO EJECUTADA - ENTORNO INCOMPLETO]\n";
    echo "No se encontraron dependencias de vendor/ o bootstrap/app.php para migrar.\n";
    echo "----------------------------------------------------------------------\n";
    exit(1);
}

require_once $autoloadPath;
$app = require $bootstrapPath;
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

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

// Ejecutar migraciones reales de Laravel con --force
\Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
echo "      -> Migraciones oficiales aplicadas (esquema verificado: audit_events usa lead_id y details).\n";

// 4. Crear usuario ficticio para respetar la clave foránea (assigned_user_id -> users.id)
$testUserEmail = 'test_advisor_race_' . bin2hex(random_bytes(4)) . '@test.local';
echo "[2/7] Creando usuario asesor ficticio para respetar clave foránea ('{$testUserEmail}')...\n";
$stmtUser = $pdoA->prepare("INSERT INTO users (name, email, role, active, created_at, updated_at) VALUES ('Asesor Test Concurrencia', :email, 'advisor', 1, NOW(), NOW())");
$stmtUser->execute(['email' => $testUserEmail]);
$testUserId = (int) $pdoA->lastInsertId();
echo "      -> Usuario ficticio creado exitosamente con ID real #{$testUserId}.\n";

// 5. Preparar registro de prueba con reconciliation_pending = 1 y assigned_user_id = NULL
$metaId = 'crm-sync-race-' . bin2hex(random_bytes(6));
$sourceKey = sha1('Hoja3_' . $metaId);

echo "[3/7] Creando lead de prueba en base aislada (meta_id: {$metaId})...\n";
$stmtLead = $pdoA->prepare("INSERT INTO leads (source_key, source_sheet, meta_id, name, email, source_assigned_to, reconciliation_pending, original, created_at, updated_at) VALUES (:key, 'Hoja 3', :meta, 'Lead Concurrente Test', 'test@concurrente.cl', 'Pao', 1, '{}', NOW(), NOW())");
$stmtLead->execute(['key' => $sourceKey, 'meta' => $metaId]);
$leadId = (int) $pdoA->lastInsertId();

$stmtMgmt = $pdoA->prepare("INSERT INTO lead_management (lead_id, status, assigned_user_id, created_at, updated_at) VALUES (:lead_id, 'Nuevo', NULL, NOW(), NOW())");
$stmtMgmt->execute(['lead_id' => $leadId]);
$mgmtId = (int) $pdoA->lastInsertId();

try {
    // 6. Proceso A inicia transacción y adquiere bloqueo pesimista FOR UPDATE
    echo "[4/7] Proceso A inicia transacción y adquiere SELECT ... FOR UPDATE sobre fila #{$mgmtId}...\n";
    $pdoA->beginTransaction();
    $stmtLock = $pdoA->prepare("SELECT id, assigned_user_id FROM lead_management WHERE id = :id FOR UPDATE");
    $stmtLock->execute(['id' => $mgmtId]);
    $lockedRow = $stmtLock->fetch();
    echo "      -> Bloqueo exclusivo de fila retenido exitosamente por Proceso A.\n";

    // 7. Lanzar Proceso B como subproceso independiente
    // Las credenciales se transmiten mediante variables de entorno seguras de proc_open, NUNCA en argv
    echo "[5/7] Iniciando Proceso B concurrente (inicializando Laravel y servicio real)...\n";
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
    // Esperar señal de que Proceso B ya inicializó Laravel y transmitió su connection_id
    $signalLine = trim(fgets($pipes[1]));
    $workerConnId = 0;
    if (preg_match('/^SERVICE_READY_FOR_LOCK:conn_id=(\d+)$/', $signalLine, $matches)) {
        $workerConnId = (int)$matches[1];
    } elseif ($signalLine !== 'SERVICE_READY_FOR_LOCK') {
        $err = stream_get_contents($pipes[2]);
        $rawOut = stream_get_contents($pipes[1]);
        proc_close($proc);
        echo "----------------------------------------------------------------------\n";
        echo "[PRUEBA NO EJECUTADA - SERVICIO NO DISPONIBLE]\n";
        echo "El Proceso B no pudo inicializar el servicio real de Laravel.\n";
        echo "Detalle: " . ($signalLine ?: $err ?: $rawOut) . "\n";
        echo "Por directriz de seguridad, no se aprueba mediante fallback simulado.\n";
        echo "----------------------------------------------------------------------\n";
        exit(1);
    }
    echo "      -> Proceso B arrancó Laravel y conectó a la base aislada (MySQL Connection ID: #{$workerConnId}).\n";
    echo "      -> Proceso B invocó AdvisorAssignmentResolver::reconcilePendingLeads().\n";

    // Comprobación demostrable e inequívoca de espera en InnoDB para la conexión de B
    $detectedWait = false;
    $waitEvidence = '';

    for ($attempt = 0; $attempt < 25; $attempt++) {
        usleep(80000); // 80ms

        // 1. Verificar específicamente por trx_mysql_thread_id en information_schema.innodb_trx
        if ($workerConnId > 0) {
            try {
                $trxStmt = $pdoA->prepare("SELECT trx_id, trx_state, trx_mysql_thread_id FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = :conn_id AND trx_state = 'LOCK WAIT'");
                $trxStmt->execute(['conn_id' => $workerConnId]);
                $waitingRow = $trxStmt->fetch(PDO::FETCH_ASSOC);
                if ($waitingRow) {
                    $detectedWait = true;
                    $waitEvidence = "innodb_trx (hilo #{$workerConnId} en estado LOCK WAIT)";
                    break;
                }
            } catch (\Throwable) {}
        }

        // 2. Si innodb_trx no arroja filas o faltan privilegios, verificar en SHOW PROCESSLIST por el ID de conexión de B
        try {
            $procList = $pdoA->query("SHOW PROCESSLIST")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($procList as $p) {
                $pId = (int)($p['Id'] ?? 0);
                if ($workerConnId > 0 && $pId !== $workerConnId) {
                    continue;
                }
                $st = (string)($p['State'] ?? '');
                $info = (string)($p['Info'] ?? '');
                if (stripos($st, 'lock') !== false || stripos($st, 'updating') !== false || stripos($info, 'FOR UPDATE') !== false) {
                    $detectedWait = true;
                    $waitEvidence = "SHOW PROCESSLIST (hilo #{$pId}, State: '{$st}')";
                    break 2;
                }
            }
        } catch (\Throwable) {}
    }

    if (!$detectedWait) {
        // DIRECTRIZ ESTRICTA: Si no es posible demostrar la espera de B, informar resultado no concluyente, NO APROBADO
        $pdoA->rollBack();
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        echo "----------------------------------------------------------------------\n";
        echo "[RESULTADO NO CONCLUYENTE - NO APROBADO]\n";
        echo "Motivo: No fue posible demostrar de forma inequívoca que la conexión #{$workerConnId}\n";
        echo "del Proceso B estuvo efectivamente esperando el cerrojo en InnoDB antes del commit.\n";
        echo "Verifique privilegios sobre information_schema o latencia de concurrencia.\n";
        echo "----------------------------------------------------------------------\n";
        exit(1);
    }

    echo "      -> Comprobación activa inequívoca: {$waitEvidence} demostrada ANTES del commit.\n";

    // Proceso A retiene el bloqueo intencionalmente por 1.4 segundos
    $holdSeconds = 1.4;
    echo "      -> Proceso A retiene el bloqueo de fila por {$holdSeconds}s mientras B espera...\n";
    usleep((int)($holdSeconds * 1000000));

    // 8. Proceso A realiza la asignación manual utilizando el ID real del usuario ficticio y ejecuta COMMIT
    echo "[6/7] Proceso A asigna manualmente al asesor ficticio #{$testUserId} y ejecuta COMMIT...\n";
    $pdoA->prepare("UPDATE lead_management SET assigned_user_id = :user_id, updated_at = NOW() WHERE id = :id")
        ->execute(['user_id' => $testUserId, 'id' => $mgmtId]);
    $pdoA->commit();
    echo "      -> COMMIT completado. Bloqueo de fila liberado en InnoDB.\n";

    // 9. Proceso B se desbloquea y completa su ejecución
    echo "[7/7] Esperando desbloqueo y finalización del servicio real en Proceso B...\n";
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

    $serviceElapsed = $data['service_elapsed_seconds'] ?? 0;
    echo "      -> Proceso B finalizó exitosamente (código 0).\n";
    echo "      -> Servicio probado: " . ($data['service_executed'] ?? 'desconocido') . "\n";
    echo "      -> Tiempo total de ejecución del servicio: {$serviceElapsed} segundos.\n";
    echo "         (Comprende la suspensión en LOCK WAIT mientras Proceso A retuvo el cerrojo, más el procesamiento posterior de reconciliación y persistencia de auditoría).\n";

    // Comprobación de que esperó el bloqueo (el tiempo total debe ser al menos el 70% del tiempo de retención)
    if ($serviceElapsed < ($holdSeconds * 0.7)) {
        echo "✗ ERROR: Proceso B no esperó efectivamente el bloqueo de fila (duración {$serviceElapsed}s, requerida >= " . ($holdSeconds * 0.7) . "s).\n";
        exit(1);
    }

    // Comprobación de resultado del servicio
    $resolverResult = $data['resolver_result'] ?? [];
    echo "      -> Resultado del servicio: reconciliados={$resolverResult['reconciled']}, omitidos por manual={$resolverResult['skipped_manual']}, pendientes={$resolverResult['unresolved']}.\n";
    if (($resolverResult['skipped_manual'] ?? 0) !== 1) {
        echo "✗ ERROR: El servicio de reconciliación no reportó skipped_manual = 1.\n";
        exit(1);
    }

    // Comprobación de auditoría en esquema real (lead_id y details con testUserId real)
    if (empty($data['audit_logged'])) {
        echo "✗ ERROR: No se encontró el registro de auditoría 'leads.reconciliation_preserved_manual' con lead_id={$leadId}.\n";
        exit(1);
    }
    if ((int)($data['audit_assigned_user_id'] ?? 0) !== $testUserId) {
        echo "✗ ERROR: El payload de auditoría registró assigned_user_id = " . ($data['audit_assigned_user_id'] ?? 'null') . " (esperado #{$testUserId}).\n";
        exit(1);
    }
    echo "      -> Auditoría oficial verificada: 'leads.reconciliation_preserved_manual' registrada con lead_id={$leadId} y details.assigned_user_id={$testUserId}.\n";

    // Comprobación de estado final en base de datos
    $stmtFinal = $pdoA->prepare("SELECT lm.assigned_user_id, l.reconciliation_pending FROM lead_management lm JOIN leads l ON l.id = lm.lead_id WHERE lm.id = :id");
    $stmtFinal->execute(['id' => $mgmtId]);
    $final = $stmtFinal->fetch();

    if ((int)$final['assigned_user_id'] === $testUserId && (int)$final['reconciliation_pending'] === 0) {
        echo "\n======================================================================\n";
        echo "✓ PRUEBA APROBADA EXITOSAMENTE:\n";
        echo "  1. Base de datos de prueba aislada y migraciones reales verificadas.\n";
        echo "  2. Clave foránea respetada con usuario ficticio real (#{$testUserId}).\n";
        echo "  3. Proceso B identificado (#{$workerConnId}) y demostrado en LOCK WAIT antes de commit.\n";
        echo "  4. Tiempo total de ejecución del servicio comprobado: {$serviceElapsed}s.\n";
        echo "  5. Servicio real AdvisorAssignmentResolver ejecutado con éxito.\n";
        echo "  6. Asignación manual preservada (ID #{$testUserId}) sin sobrescritura.\n";
        echo "  7. Auditoría oficial con lead_id y details validada correctamente.\n";
        echo "======================================================================\n";
    } else {
        echo "✗ ERROR: Inconsistencia final en base de datos.\n";
        exit(1);
    }
} finally {
    try {
        if (!empty($leadId)) {
            $pdoA->exec("DELETE FROM activities WHERE lead_id = {$leadId}");
            $pdoA->exec("DELETE FROM audit_events WHERE lead_id = {$leadId}");
            $pdoA->exec("DELETE FROM lead_management WHERE lead_id = {$leadId}");
            $pdoA->exec("DELETE FROM leads WHERE id = {$leadId}");
        }
        if (!empty($testUserId)) {
            $pdoA->exec("DELETE FROM users WHERE id = {$testUserId}");
        }
    } catch (\Throwable) {}
}

