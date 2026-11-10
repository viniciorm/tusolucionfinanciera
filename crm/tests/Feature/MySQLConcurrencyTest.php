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
     * Si MySQL no está disponible en el entorno o no se configuró una base de datos aislada,
     * la prueba se marca explícitamente como OMITIDA (SKIPPED) en PHPUnit, impidiendo
     * presentarla falsamente como aprobada.
     */
    public function test_two_concurrent_processes_respect_innodb_row_lock_and_preserve_manual_assignment(): void
    {
        $host = env('MYSQL_TEST_HOST', env('DB_HOST', '127.0.0.1'));
        $port = env('MYSQL_TEST_PORT', env('DB_PORT', '3306'));
        $database = env('MYSQL_TEST_DATABASE', null);
        $username = env('MYSQL_TEST_USERNAME', env('DB_USERNAME', 'root'));
        $password = env('MYSQL_TEST_PASSWORD', env('DB_PASSWORD', ''));

        if (empty($database)) {
            $this->markTestSkipped('PRUEBA OMITIDA: No se configuró MYSQL_TEST_DATABASE. Esta prueba de concurrencia real requiere un servidor MySQL/MariaDB con InnoDB y una base de datos de pruebas aislada.');
        }

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $pdoA = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 2,
            ]);
        } catch (PDOException $e) {
            $this->markTestSkipped('PRUEBA OMITIDA: No se pudo conectar al servidor MySQL/MariaDB en ' . $host . ':' . $port . ' (' . $e->getMessage() . '). Prueba no ejecutada en este entorno.');
        }

        $result = $this->executeTwoProcessConcurrencyVerification($pdoA, $host, $port, $database, $username, $password);

        $this->assertTrue($result['success'], $result['message'] ?? 'Falló la verificación de concurrencia');
        $this->assertGreaterThanOrEqual(0.8, $result['waited_seconds'], 'El Proceso B debió esperar bloqueado mientras el Proceso A mantuvo el bloqueo exclusivo');
        $this->assertEquals(99, $result['final_assigned_user_id'], 'La asignación manual (ID 99) debió ser preservada');
        $this->assertEquals(0, $result['final_reconciliation_pending'], 'El lead debe quedar con reconciliación completada');
    }

    private function executeTwoProcessConcurrencyVerification(
        PDO $pdoA,
        string $host,
        string $port,
        string $database,
        string $username,
        string $password
    ): array {
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
            $workerScript = __DIR__ . '/../../tests/reproducible/test_mysql_concurrency.php';
            $descriptors = [
                0 => ['pipe', 'r'], // stdin
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w'], // stderr
            ];

            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerScript) . ' --worker'
                . ' --host=' . escapeshellarg($host)
                . ' --port=' . escapeshellarg($port)
                . ' --db=' . escapeshellarg($database)
                . ' --user=' . escapeshellarg($username)
                . ' --pass=' . escapeshellarg($password)
                . ' --lead=' . $leadId
                . ' --mgmt=' . $mgmtId;

            $process = proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($process)) {
                $pdoA->rollBack();
                return ['success' => false, 'message' => 'No se pudo iniciar el subproceso B'];
            }

            // Esperar que Proceso B confirme conexión e inicio de consulta bloqueada
            $readySignal = trim(fgets($pipes[1]));
            if ($readySignal !== 'WORKER_READY') {
                $err = stream_get_contents($pipes[2]);
                proc_close($process);
                $pdoA->rollBack();
                return ['success' => false, 'message' => 'Proceso B no respondió WORKER_READY: ' . $err];
            }

            // 4. Proceso A retiene el bloqueo intencionalmente por 1 segundo
            // Mientras tanto, Proceso B está bloqueado en MySQL InnoDB esperando por el registro
            usleep(1000000); // 1.0 segundo

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
                'waited_seconds' => $workerData['waited_seconds'] ?? 0,
                'final_assigned_user_id' => (int) $finalMgmt['assigned_user_id'],
                'final_reconciliation_pending' => (int) $finalLead['reconciliation_pending'],
            ];
        } finally {
            // Limpieza en base de datos aislada
            try {
                $pdoA->exec("DELETE FROM lead_management WHERE id = {$mgmtId}");
                $pdoA->exec("DELETE FROM leads WHERE id = {$leadId}");
            } catch (\Throwable) {
                // Silenciar error de limpieza
            }
        }
    }
}
