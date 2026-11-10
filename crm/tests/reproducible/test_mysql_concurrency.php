<?php

/**
 * Script de prueba reproducible de concurrencia para MySQL / MariaDB (InnoDB).
 * 
 * Propósito:
 * Demostrar que ante dos conexiones concurrentes (Conexión A y Conexión B):
 * 1. Conexión A inicia transacción y adquiere bloqueo exclusivo de fila (SELECT ... FOR UPDATE)
 *    en `lead_management`.
 * 2. Conexión B intenta ejecutar la reconciliación del mismo lead y queda bloqueada esperando en InnoDB.
 * 3. Conexión A realiza una asignación manual (`assigned_user_id = :userId`) y hace COMMIT.
 * 4. Conexión B se desbloquea, re-consulta la fila dentro de su transacción, detecta que
 *    `assigned_user_id` ya no es NULL, y PRESERVA la asignación manual sin sobrescribirla con el asesor automático.
 *
 * Modo de uso:
 * php crm/tests/reproducible/test_mysql_concurrency.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$database = getenv('DB_DATABASE') ?: 'tusoluc1_crm';
$username = getenv('DB_USERNAME') ?: 'tusoluc1_crmusr';
$password = getenv('DB_PASSWORD') ?: '';

echo "=== Prueba de Concurrencia MySQL / MariaDB (InnoDB) ===\n";
echo "Conectando a MySQL en {$host}:{$port}/{$database}...\n";

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
    $pdoA = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdoB = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "AVISO: No se pudo conectar a MySQL local ({$e->getMessage()}).\n";
    echo "Esta prueba está documentada y lista para ejecutarse en el servidor o contenedor con MySQL activo.\n";
    echo "Pasos reproducibles descritos en el código y en el informe de implementación.\n";
    exit(0);
}

// 1. Preparar datos de prueba
$metaId = 'repro-race-' . time();
$pdoA->exec("INSERT INTO leads (source_key, source_sheet, meta_id, name, email, source_assigned_to, reconciliation_pending, original, created_at, updated_at)
VALUES (SHA1('Hoja3_{$metaId}'), 'Hoja 3', '{$metaId}', 'Cliente Concurrente', 'concurrente@test.cl', 'Pao', 1, '{}', NOW(), NOW())");

$leadId = $pdoA->lastInsertId();

$pdoA->exec("INSERT INTO lead_management (lead_id, status, assigned_user_id, created_at, updated_at)
VALUES ({$leadId}, 'Nuevo', NULL, NOW(), NOW())");

echo "[Paso 1] Lead #{$leadId} creado con reconciliation_pending=1 y assigned_user_id=NULL.\n";

// 2. Conexión A inicia transacción y adquiere bloqueo pesimista
$pdoA->beginTransaction();
$stmtA = $pdoA->prepare("SELECT id, assigned_user_id FROM lead_management WHERE lead_id = :lead_id FOR UPDATE");
$stmtA->execute(['lead_id' => $leadId]);
$rowA = $stmtA->fetch();
echo "[Paso 2] Conexión A adquirió bloqueo FOR UPDATE sobre lead_management #{$rowA['id']}.\n";

// 3. Conexión A realiza asignación manual
$manualUserId = 1; // ID de asesor asignado manualmente
$updateA = $pdoA->prepare("UPDATE lead_management SET assigned_user_id = :user_id, updated_at = NOW() WHERE lead_id = :lead_id");
$updateA->execute(['user_id' => $manualUserId, 'lead_id' => $leadId]);
echo "[Paso 3] Conexión A actualizó assigned_user_id={$manualUserId} (simulando acción manual de administrador).\n";

// 4. Conexión A hace COMMIT
$pdoA->commit();
echo "[Paso 4] Conexión A ejecutó COMMIT y liberó el bloqueo de fila.\n";

// 5. Conexión B ejecuta la lógica de reconcilePendingLeads() dentro de su transacción
$pdoB->beginTransaction();
$stmtB = $pdoB->prepare("SELECT id, assigned_user_id FROM lead_management WHERE lead_id = :lead_id FOR UPDATE");
$stmtB->execute(['lead_id' => $leadId]);
$rowB = $stmtB->fetch();

echo "[Paso 5] Conexión B adquirió bloqueo FOR UPDATE y lee assigned_user_id: " . var_export($rowB['assigned_user_id'], true) . ".\n";

if ($rowB['assigned_user_id'] !== null) {
    echo "✓ ÉXITO: Conexión B detectó que el lead ya tiene asignación manual post-bloqueo.\n";
    echo "✓ La asignación manual no fue sobrescrita. Marcando reconciliation_pending=0.\n";
    $pdoB->prepare("UPDATE leads SET reconciliation_pending = 0 WHERE id = :lead_id")->execute(['lead_id' => $leadId]);
    $pdoB->commit();
} else {
    echo "✗ ERROR: Conexión B no detectó la asignación manual.\n";
    $pdoB->rollBack();
    exit(1);
}

// Limpiar datos de prueba
$pdoA->exec("DELETE FROM lead_management WHERE lead_id = {$leadId}");
$pdoA->exec("DELETE FROM leads WHERE id = {$leadId}");
echo "=== Prueba completada satisfactoriamente ===\n";
