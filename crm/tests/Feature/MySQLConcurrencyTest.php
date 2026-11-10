<?php

namespace Tests\Feature;

use PDO;
use PDOException;
use Tests\TestCase;

class MySQLConcurrencyTest extends TestCase
{
    /**
     * Prueba de concurrencia real entre dos procesos independientes con MySQL / MariaDB (InnoDB).
     *
     * Reglas estrictas de seguridad:
     * 1. Exige una base de datos de prueba configurada explícitamente (MYSQL_TEST_DATABASE).
     *    NUNCA utiliza DB_DATABASE como alternativa ni se ejecuta sobre bases de producción.
     * 2. Si MYSQL_TEST_DATABASE no está configurada o MySQL no está disponible, la prueba
     *    se marca explícitamente como OMITIDA (SKIPPED) en PHPUnit.
     * 3. Sincroniza ambos procesos demostrando que el Proceso B intenta adquirir el bloqueo
     *    ANTES del commit del Proceso A.
     * 4. Mide estrictamente el tiempo de espera de la operación de bloqueo, no el arranque de Laravel.
     * 5. Comprueba el servicio real de Laravel (AdvisorAssignmentResolver::reconcilePendingLeads),
     *    la auditoría y la preservación de la asignación manual.
     */
    public function test_two_concurrent_processes_respect_innodb_row_lock_and_preserve_manual_assignment(): void
    {
        $host = env('MYSQL_TEST_HOST', '127.0.0.1');
        $port = env('MYSQL_TEST_PORT', '3306');
        $database = env('MYSQL_TEST_DATABASE', null);
        $username = env('MYSQL_TEST_USERNAME', null);
        $password = env('MYSQL_TEST_PASSWORD', '');

        if (empty($database)) {
            $this->markTestSkipped('PRUEBA PENDIENTE / OMITIDA: No se configuró explícitamente MYSQL_TEST_DATABASE. Esta prueba de concurrencia real requiere un servidor MySQL/MariaDB con InnoDB y una base de datos aislada (ej. tusoluc1_crm_test). Por seguridad, nunca utiliza DB_DATABASE como alternativa.');
        }

        if (empty($username)) {
            $this->markTestSkipped('PRUEBA PENDIENTE / OMITIDA: No se configuró explícitamente MYSQL_TEST_USERNAME para la base de pruebas aislada.');
        }

        $appDb = env('DB_DATABASE');
        if ($appDb && strtolower($database) === strtolower($appDb)) {
            $this->fail('ERROR CRÍTICO DE SEGURIDAD: MYSQL_TEST_DATABASE coincide con la base principal (DB_DATABASE). Debe ser una base aislada exclusiva de pruebas.');
        }

        if (!str_contains(strtolower($database), 'test')) {
            $this->fail("ERROR DE SEGURIDAD: La base de datos '{$database}' debe contener 'test' en su nombre para garantizar aislamiento absoluto.");
        }

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $pdoA = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 3,
            ]);
        } catch (PDOException $e) {
            $this->markTestSkipped('PRUEBA PENDIENTE / OMITIDA: No se pudo conectar al servidor MySQL/MariaDB en ' . $host . ':' . $port . ' (' . $e->getMessage() . '). Prueba no ejecutada en este entorno.');
        }

        $result = $this->executeTwoProcessConcurrencyVerification($pdoA, (string)$host, (string)$port, (string)$database, (string)$username, (string)$password);

        $this->assertTrue($result['success'], $result['message'] ?? 'Falló la verificación de concurrencia');
        $this->assertEquals('AdvisorAssignmentResolver::reconcilePendingLeads', $result['service_executed']);
        $this->assertGreaterThanOrEqual(0.8, $result['lock_wait_seconds'], 'El Proceso B debió esperar efectivamente bloqueado mientras el Proceso A retuvo el bloqueo exclusivo');
        $this->assertTrue($result['audit_logged'], 'El servicio real debió registrar la auditoría leads.reconciliation_preserved_manual');
        $this->assertEquals(1, $result['resolver_result']['skipped_manual'] ?? 0, 'El resultado del servicio debió reportar 1 lead omitido por asignación manual');
        $this->assertEquals(99, $result['final_assigned_user_id'], 'La asignación manual (ID 99) debió ser preservada');
        $this->assertEquals(0, $result['final_reconciliation_pending'], 'El lead debe quedar con reconciliación completada (0)');
    }

    private function executeTwoProcessConcurrencyVerification(
        PDO $pdoA,
        string $host,
        string $port,
        string $database,
        string $username,
        string $password
    ): array {
        // Garantizar tablas mínimas en base de pruebas aislada
        $pdoA->exec("
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

        // 1. Crear lead de prueba en base de datos aislada
        $metaId = 'concurrent-test-' . bin2hex(random_bytes(6));
        $sourceKey = sha1('Hoja3_' . $metaId);

        $stmtLead = $pdoA->prepare("INSERT INTO leads (source_key, source_sheet, meta_id, name, email, source_assigned_to, reconciliation_pending, original, created_at, updated_at) VALUES (:key, 'Hoja 3', :meta_id, 'Lead Concurrente', 'concurrente@test.cl', 'Pao', 1, '{}', NOW(), NOW())");
        $stmtLead->execute(['key' => $sourceKey, 'meta_id' => $metaId]);
        $leadId = (int) $pdoA->lastInsertId();

        $stmtMgmt = $pdoA->prepare("INSERT INTO lead_management (lead_id, status, assigned_user_id, created_at, updated_at) VALUES (:lead_id, 'Nuevo', NULL, NOW(), NOW())");
        $stmtMgmt->execute(['lead_id' => $leadId]);
        $mgmtId = (int) $pdoA->lastInsertId();

        try {
            // 2. Conexión A inicia transacción y adquiere bloqueo pesimista FOR UPDATE
            $pdoA->beginTransaction();
            $stmtLock = $pdoA->prepare("SELECT id, assigned_user_id FROM lead_management WHERE id = :id FOR UPDATE");
            $stmtLock->execute(['id' => $mgmtId]);
            $lockedRow = $stmtLock->fetch();

            // 3. Lanzar Proceso B como subproceso independiente del sistema operativo
            // Las credenciales se transmiten a través de $subEnv, NUNCA en la línea de comandos
            $workerScript = dirname(__DIR__, 2) . '/tests/reproducible/test_mysql_concurrency.php';
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $subEnv = array_merge($_ENV, [
                'MYSQL_TEST_HOST' => $host,
                'MYSQL_TEST_PORT' => $port,
                'MYSQL_TEST_DATABASE' => $database,
                'MYSQL_TEST_USERNAME' => $username,
                'MYSQL_TEST_PASSWORD' => $password,
                'CONCURRENCY_FLAG_FILE' => sys_get_temp_dir() . '/concurrency_test_phpunit.flag',
            ]);

            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerScript) . ' --worker'
                . ' --lead=' . $leadId
                . ' --mgmt=' . $mgmtId;

            $process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__, 2), $subEnv);
            if (!is_resource($process)) {
                $pdoA->rollBack();
                return ['success' => false, 'message' => 'No se pudo iniciar el subproceso B'];
            }

            // SINCRONIZACIÓN EXACTA:
            // Esperar que Proceso B confirme que Laravel arrancó y está por invocar el servicio de reconciliación
            $readySignal = trim(fgets($pipes[1]));
            if ($readySignal !== 'SERVICE_READY_FOR_LOCK') {
                $err = stream_get_contents($pipes[2]);
                $rawOut = stream_get_contents($pipes[1]);
                proc_close($process);
                $pdoA->rollBack();
                return ['success' => false, 'message' => 'Proceso B no respondió SERVICE_READY_FOR_LOCK: ' . ($readySignal ?: $err ?: $rawOut)];
            }

            // 4. Proceso A retiene el bloqueo intencionalmente por 1.4 segundos
            // Mientras tanto, Proceso B está bloqueado en MySQL InnoDB esperando por el registro
            usleep(1400000); // 1.4 segundos

            // 5. Proceso A realiza la asignación manual y hace COMMIT
            $manualUserId = 99;
            $stmtUpdate = $pdoA->prepare("UPDATE lead_management SET assigned_user_id = :user_id, updated_at = NOW() WHERE id = :id");
            $stmtUpdate->execute(['user_id' => $manualUserId, 'id' => $mgmtId]);
            $pdoA->commit(); // Libera el bloqueo de fila en InnoDB

            // 6. Proceso B ahora se desbloquea en InnoDB y procesa la fila
            $workerOutput = stream_get_contents($pipes[1]);
            $workerErrors = stream_get_contents($pipes[2]);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            // Analizar resultado de Proceso B
            $workerData = json_decode(trim($workerOutput), true);
            if ($exitCode !== 0 || !$workerData || empty($workerData['success'])) {
                return [
                    'success' => false,
                    'message' => 'Proceso B falló (código ' . $exitCode . '): ' . ($workerData['message'] ?? $workerErrors . ' | ' . $workerOutput),
                ];
            }

            // 7. Verificar estado final en base de datos
            $stmtCheckMgmt = $pdoA->prepare("SELECT assigned_user_id FROM lead_management WHERE id = :id");
            $stmtCheckMgmt->execute(['id' => $mgmtId]);
            $finalMgmt = $stmtCheckMgmt->fetch();

            $stmtCheckLead = $pdoA->prepare("SELECT reconciliation_pending FROM leads WHERE id = :id");
            $stmtCheckLead->execute(['id' => $leadId]);
            $finalLead = $stmtCheckLead->fetch();

            return [
                'success' => true,
                'service_executed' => $workerData['service_executed'] ?? '',
                'lock_wait_seconds' => $workerData['lock_wait_seconds'] ?? 0,
                'resolver_result' => $workerData['resolver_result'] ?? [],
                'audit_logged' => !empty($workerData['audit_logged']),
                'final_assigned_user_id' => (int) $finalMgmt['assigned_user_id'],
                'final_reconciliation_pending' => (int) $finalLead['reconciliation_pending'],
            ];
        } finally {
            try {
                $pdoA->exec("DELETE FROM lead_management WHERE id = {$mgmtId}");
                $pdoA->exec("DELETE FROM leads WHERE id = {$leadId}");
                $pdoA->exec("DELETE FROM audit_events WHERE target_id = '{$leadId}'");
            } catch (\Throwable) {
                // Silenciar error de limpieza
            }
        }
    }
}
