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
     * 4. Utiliza las migraciones reales del CRM (audit_events con lead_id y details).
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
        $this->assertGreaterThanOrEqual(0.8, $result['service_elapsed_seconds'], 'El Proceso B debió esperar efectivamente bloqueado mientras el Proceso A retuvo el bloqueo exclusivo');
        $this->assertTrue($result['audit_logged'], 'El servicio real debió registrar la auditoría leads.reconciliation_preserved_manual');
        $expectedUserId = $result['test_user_id'];
        $this->assertEquals($expectedUserId, $result['audit_assigned_user_id'] ?? null, "La auditoría oficial debió registrar details.assigned_user_id = {$expectedUserId}");
        $this->assertEquals(1, $result['resolver_result']['skipped_manual'] ?? 0, 'El resultado del servicio debió reportar 1 lead omitido por asignación manual');
        $this->assertEquals($expectedUserId, $result['final_assigned_user_id'], "La asignación manual (ID {$expectedUserId}) debió ser preservada");
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
        // Ejecutar las migraciones reales del CRM en la base de pruebas aislada
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
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);

        // 1. Crear usuario ficticio para respetar la clave foránea (assigned_user_id -> users.id)
        $testUserEmail = 'test_advisor_race_' . bin2hex(random_bytes(4)) . '@test.local';
        $stmtUser = $pdoA->prepare("INSERT INTO users (name, email, role, active, created_at, updated_at) VALUES ('Asesor Test Concurrencia', :email, 'advisor', 1, NOW(), NOW())");
        $stmtUser->execute(['email' => $testUserEmail]);
        $testUserId = (int) $pdoA->lastInsertId();

        // 2. Crear lead de prueba en base de datos aislada
        $metaId = 'concurrent-test-' . bin2hex(random_bytes(6));
        $sourceKey = sha1('Hoja3_' . $metaId);

        $stmtLead = $pdoA->prepare("INSERT INTO leads (source_key, source_sheet, meta_id, name, email, source_assigned_to, reconciliation_pending, original, created_at, updated_at) VALUES (:key, 'Hoja 3', :meta_id, 'Lead Concurrente', 'concurrente@test.cl', 'Pao', 1, '{}', NOW(), NOW())");
        $stmtLead->execute(['key' => $sourceKey, 'meta_id' => $metaId]);
        $leadId = (int) $pdoA->lastInsertId();

        $stmtMgmt = $pdoA->prepare("INSERT INTO lead_management (lead_id, status, assigned_user_id, created_at, updated_at) VALUES (:lead_id, 'Nuevo', NULL, NOW(), NOW())");
        $stmtMgmt->execute(['lead_id' => $leadId]);
        $mgmtId = (int) $pdoA->lastInsertId();

        try {
            // 3. Conexión A inicia transacción y adquiere bloqueo pesimista FOR UPDATE
            $pdoA->beginTransaction();
            $stmtLock = $pdoA->prepare("SELECT id, assigned_user_id FROM lead_management WHERE id = :id FOR UPDATE");
            $stmtLock->execute(['id' => $mgmtId]);
            $lockedRow = $stmtLock->fetch();

            // 4. Lanzar Proceso B como subproceso independiente del sistema operativo
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
            // Esperar que Proceso B confirme que Laravel arrancó y transmitió su connection_id
            $readySignal = trim(fgets($pipes[1]));
            $workerConnId = 0;
            if (preg_match('/^SERVICE_READY_FOR_LOCK:conn_id=(\d+)$/', $readySignal, $matches)) {
                $workerConnId = (int)$matches[1];
            } elseif ($readySignal !== 'SERVICE_READY_FOR_LOCK') {
                $err = stream_get_contents($pipes[2]);
                $rawOut = stream_get_contents($pipes[1]);
                proc_close($process);
                $pdoA->rollBack();
                return ['success' => false, 'message' => 'Proceso B no respondió SERVICE_READY_FOR_LOCK: ' . ($readySignal ?: $err ?: $rawOut)];
            }

            // Comprobación de espera activa de la conexión de B en InnoDB
            $detectedWait = false;
            for ($attempt = 0; $attempt < 25; $attempt++) {
                usleep(80000);
                if ($workerConnId > 0) {
                    try {
                        $trxStmt = $pdoA->prepare("SELECT trx_id FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = :conn_id AND trx_state = 'LOCK WAIT'");
                        $trxStmt->execute(['conn_id' => $workerConnId]);
                        if ($trxStmt->fetch()) {
                            $detectedWait = true;
                            break;
                        }
                    } catch (\Throwable) {}
                }
                try {
                    $procList = $pdoA->query("SHOW PROCESSLIST")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($procList as $p) {
                        $pId = (int)($p['Id'] ?? 0);
                        if ($workerConnId > 0 && $pId !== $workerConnId) {
                            continue;
                        }
                        $st = (string)($p['State'] ?? '');
                        if (stripos($st, 'lock') !== false || stripos($st, 'updating') !== false) {
                            $detectedWait = true;
                            break 2;
                        }
                    }
                } catch (\Throwable) {}
            }

            if (!$detectedWait) {
                $pdoA->rollBack();
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return [
                    'success' => false,
                    'message' => 'RESULTADO NO CONCLUYENTE - NO APROBADO: No se demostró de forma inequívoca que la conexión #' . $workerConnId . ' estuviera en LOCK WAIT antes del commit.',
                ];
            }

            // 5. Proceso A retiene el bloqueo intencionalmente por 1.4 segundos
            // Mientras tanto, Proceso B está bloqueado en MySQL InnoDB esperando por el registro
            usleep(1400000); // 1.4 segundos

            // 6. Proceso A realiza la asignación manual utilizando el ID real del usuario ficticio y hace COMMIT
            $stmtUpdate = $pdoA->prepare("UPDATE lead_management SET assigned_user_id = :user_id, updated_at = NOW() WHERE id = :id");
            $stmtUpdate->execute(['user_id' => $testUserId, 'id' => $mgmtId]);
            $pdoA->commit(); // Libera el bloqueo de fila en InnoDB

            // 7. Proceso B ahora se desbloquea en InnoDB y procesa la fila
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

            // 8. Verificar estado final en base de datos
            $stmtCheckMgmt = $pdoA->prepare("SELECT assigned_user_id FROM lead_management WHERE id = :id");
            $stmtCheckMgmt->execute(['id' => $mgmtId]);
            $finalMgmt = $stmtCheckMgmt->fetch();

            $stmtCheckLead = $pdoA->prepare("SELECT reconciliation_pending FROM leads WHERE id = :id");
            $stmtCheckLead->execute(['id' => $leadId]);
            $finalLead = $stmtCheckLead->fetch();

            return [
                'success' => true,
                'test_user_id' => $testUserId,
                'service_executed' => $workerData['service_executed'] ?? '',
                'service_elapsed_seconds' => $workerData['service_elapsed_seconds'] ?? 0,
                'resolver_result' => $workerData['resolver_result'] ?? [],
                'audit_logged' => !empty($workerData['audit_logged']),
                'audit_assigned_user_id' => $workerData['audit_assigned_user_id'] ?? null,
                'final_assigned_user_id' => (int) $finalMgmt['assigned_user_id'],
                'final_reconciliation_pending' => (int) $finalLead['reconciliation_pending'],
            ];
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
            } catch (\Throwable) {
                // Silenciar error de limpieza
            }
        }
    }
}
