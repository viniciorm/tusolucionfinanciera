<?php

/**
 * Prueba reproducible y sincronizada de concurrencia para MySQL / MariaDB (InnoDB).
 *
 * Propósito:
 * Verificar mediante dos procesos reales e independientes del sistema operativo
 * que el bloqueo de fila (SELECT ... FOR UPDATE) en `lead_management` detiene
 * efectivamente al Proceso B en InnoDB hasta que el Proceso A hace COMMIT,
 * y que tras el COMMIT, el Proceso B preserva la asignación manual realizada
 * por el Proceso A sin sobrescribirla.
 *
 * Modo de uso:
 * 1. Como script CLI:
 *    php crm/tests/reproducible/test_mysql_concurrency.php [--host=127.0.0.1] [--db=test_crm] ...
 *
 * 2. Si MySQL no está disponible, reporta explícitamente:
 *    [PRUEBA OMITIDA - NO APROBADA]
 */

// Parsear argumentos CLI
$options = getopt('', ['worker', 'host::', 'port::', 'db::', 'user::', 'pass::', 'lead::', 'mgmt::']);

$isWorker = isset($options['worker']);
$host = $options['host'] ?? getenv('MYSQL_TEST_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1';
$port = $options['port'] ?? getenv('MYSQL_TEST_PORT') ?: getenv('DB_PORT') ?: '3306';
$database = $options['db'] ?? getenv('MYSQL_TEST_DATABASE') ?: getenv('DB_DATABASE') ?: null;
$username = $options['user'] ?? getenv('MYSQL_TEST_USERNAME') ?: getenv('DB_USERNAME') ?: 'root';
$password = $options['pass'] ?? getenv('MYSQL_TEST_PASSWORD') ?: getenv('DB_PASSWORD') ?: '';

// MODO SUBPROCESO WORKER B (Ejecutado concurrentemente en segundo plano)
if ($isWorker) {
    $leadId = (int) ($options['lead'] ?? 0);
    $mgmtId = (int) ($options['mgmt'] ?? 0);

    if (empty($database) || $leadId <= 0 || $mgmtId <= 0) {
        fwrite(STDERR, "Parámetros insuficientes para Proceso B\n");
        exit(1);
    }

    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ]);
    } catch (PDOException $e) {
        fwrite(STDERR, "Proceso B no pudo conectar a MySQL: " . $e->getMessage() . "\n");
        exit(1);
    }

    // Señal de preparación enviada al Proceso A
    echo "WORKER_READY\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();

    // Iniciar transacción de Proceso B
    $pdo->beginTransaction();
    $startTime = microtime(true);

    try {
        // Esta consulta se BLOQUEA en MySQL InnoDB mientras el Proceso A mantenga su transacción abierta
        $stmt = $pdo->prepare("SELECT id, assigned_user_id FROM lead_management WHERE id = :id FOR UPDATE");
        $stmt->execute(['id' => $mgmtId]);
        $row = $stmt->fetch();
        $elapsedWait = microtime(true) - $startTime;

        if (!$row) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Fila no encontrada']);
            exit(1);
        }

        // Si Proceso A hizo COMMIT de la asignación manual, assigned_user_id ya no es NULL
        if ($row['assigned_user_id'] !== null) {
            // Regla de negocio: Preservar asignación manual y marcar reconciliación como completada
            $pdo->prepare("UPDATE leads SET reconciliation_pending = 0, updated_at = NOW() WHERE id = :lead_id")
                ->execute(['lead_id' => $leadId]);
            $pdo->commit();

            echo json_encode([
                'success' => true,
                'waited_seconds' => round($elapsedWait, 4),
                'assigned_user_id' => (int) $row['assigned_user_id'],
                'message' => 'Bloqueo respetado y asignación manual preservada',
            ]);
            exit(0);
        } else {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'waited_seconds' => round($elapsedWait, 4),
                'message' => 'Error: assigned_user_id permaneció NULL tras la espera del bloqueo',
            ]);
            exit(1);
        }
    } catch (\Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Excepción en Proceso B: ' . $e->getMessage()]);
        exit(1);
    }
}

// MODO PRINCIPAL CLI (Proceso A)
echo "======================================================================\n";
echo "  Prueba de Concurrencia Sincronizada MySQL / MariaDB (InnoDB)\n";
echo "======================================================================\n";
echo "Destino: {$host}:{$port} | Base de datos: " . ($database ?: '(no definida)') . "\n\n";

if (empty($database)) {
    echo "----------------------------------------------------------------------\n";
    echo "[PRUEBA OMITIDA - NO APROBADA]\n";
    echo "Motivo: No se definió base de datos de pruebas aislada (MYSQL_TEST_DATABASE).\n";
    echo "Para ejecutar esta prueba se requiere un servidor MySQL con InnoDB activo.\n";
    echo "----------------------------------------------------------------------\n";
    exit(0);
}

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
    $pdoA = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 2,
    ]);
} catch (PDOException $e) {
    echo "----------------------------------------------------------------------\n";
    echo "[PRUEBA OMITIDA - NO APROBADA]\n";
    echo "Motivo: No se pudo conectar a MySQL en {$host}:{$port} ({$e->getMessage()}).\n";
    echo "El servidor MySQL/MariaDB no está activo en este entorno de pruebas.\n";
    echo "Esta prueba no se presenta como aprobada; queda registrada como omitida.\n";
    echo "----------------------------------------------------------------------\n";
    exit(0);
}

// 1. Crear registro de prueba
$metaId = 'cli-race-' . bin2hex(random_bytes(6));
$sourceKey = sha1('Hoja3_' . $metaId);

echo "[1/6] Insertando lead de prueba (meta_id: {$metaId})...\n";
$pdoA->prepare("INSERT INTO leads (source_key, source_sheet, meta_id, name, email, source_assigned_to, reconciliation_pending, original, created_at, updated_at) VALUES (:key, 'Hoja 3', :meta, 'Test Concurrente', 'concurrente@test.cl', 'Pao', 1, '{}', NOW(), NOW())")
    ->execute(['key' => $sourceKey, 'meta' => $metaId]);
$leadId = (int) $pdoA->lastInsertId();

$pdoA->prepare("INSERT INTO lead_management (lead_id, status, assigned_user_id, created_at, updated_at) VALUES (:id, 'Nuevo', NULL, NOW(), NOW())")
    ->execute(['id' => $leadId]);
$mgmtId = (int) $pdoA->lastInsertId();

try {
    // 2. Proceso A inicia transacción y adquiere bloqueo FOR UPDATE
    echo "[2/6] Proceso A inicia transacción y ejecuta SELECT ... FOR UPDATE sobre fila #{$mgmtId}...\n";
    $pdoA->beginTransaction();
    $stmtA = $pdoA->prepare("SELECT id, assigned_user_id FROM lead_management WHERE id = :id FOR UPDATE");
    $stmtA->execute(['id' => $mgmtId]);
    $rowA = $stmtA->fetch();
    echo "      -> Bloqueo exclusivo adquirido exitosamente por Proceso A.\n";

    // 3. Lanzar Proceso B concurrente
    echo "[3/6] Iniciando Proceso B concurrente en segundo plano...\n";
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $workerCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --worker'
        . ' --host=' . escapeshellarg($host)
        . ' --port=' . escapeshellarg($port)
        . ' --db=' . escapeshellarg($database)
        . ' --user=' . escapeshellarg($username)
        . ' --pass=' . escapeshellarg($password)
        . ' --lead=' . $leadId
        . ' --mgmt=' . $mgmtId;

    $proc = proc_open($workerCmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException("No fue posible iniciar el subproceso B.");
    }

    $signal = trim(fgets($pipes[1]));
    if ($signal !== 'WORKER_READY') {
        $err = stream_get_contents($pipes[2]);
        proc_close($proc);
        throw new RuntimeException("Proceso B no respondió WORKER_READY. Detalle: " . $err);
    }
    echo "      -> Proceso B conectado y ejecutando SELECT ... FOR UPDATE (InnoDB lo mantiene bloqueado esperando).\n";

    // 4. Proceso A mantiene el bloqueo intencionalmente por 1.2 segundos
    echo "[4/6] Proceso A retiene el bloqueo por 1.2s para demostrar la exclusión mutua...\n";
    usleep(1200000);

    // 5. Proceso A actualiza asignación manual (ID 99) y ejecuta COMMIT
    echo "[5/6] Proceso A realiza asignación manual a usuario #99 y ejecuta COMMIT...\n";
    $pdoA->prepare("UPDATE lead_management SET assigned_user_id = 99, updated_at = NOW() WHERE id = :id")
        ->execute(['id' => $mgmtId]);
    $pdoA->commit();
    echo "      -> COMMIT realizado. Bloqueo de fila liberado por Proceso A.\n";

    // 6. Proceso B se desbloquea y completa su ejecución
    echo "[6/6] Esperando desbloqueo y finalización de Proceso B...\n";
    $workerOut = stream_get_contents($pipes[1]);
    $workerErr = stream_get_contents($pipes[2]);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);

    $data = json_decode(trim($workerOut), true);
    if ($status !== 0 || !$data || empty($data['success'])) {
        echo "✗ ERROR: Proceso B reportó fallo: " . ($data['message'] ?? $workerErr) . "\n";
        exit(1);
    }

    echo "      -> Proceso B finalizó exitosamente en código {$status}.\n";
    echo "      -> Tiempo de espera forzada de Proceso B en InnoDB: {$data['waited_seconds']}s.\n";
    echo "      -> assigned_user_id observado por Proceso B: {$data['assigned_user_id']}.\n\n";

    // Verificación final en BD
    $stmtFinal = $pdoA->prepare("SELECT lm.assigned_user_id, l.reconciliation_pending FROM lead_management lm JOIN leads l ON l.id = lm.lead_id WHERE lm.id = :id");
    $stmtFinal->execute(['id' => $mgmtId]);
    $final = $stmtFinal->fetch();

    if ((int)$final['assigned_user_id'] === 99 && (int)$final['reconciliation_pending'] === 0) {
        echo "======================================================================\n";
        echo "✓ PRUEBA APROBADA: Exclusión mutua de InnoDB y preservación de\n";
        echo "  asignación manual comprobadas con 2 procesos concurrentes sincronizados.\n";
        echo "======================================================================\n";
    } else {
        echo "✗ ERROR: Estado final inconsistente en base de datos.\n";
        exit(1);
    }
} finally {
    try {
        $pdoA->exec("DELETE FROM lead_management WHERE id = {$mgmtId}");
        $pdoA->exec("DELETE FROM leads WHERE id = {$leadId}");
    } catch (\Throwable) {}
}
