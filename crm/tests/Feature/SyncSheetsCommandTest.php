<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\GoogleSheetsReaderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class SyncSheetsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_fails_gracefully_when_credentials_file_is_missing(): void
    {
        config(['services.sheets.service_account_path' => '/non/existent/path.json']);
        config(['services.sheets.spreadsheet_id' => 'dummy-sheet-id']);

        $exitCode = Artisan::call('crm:sync-sheets');
        $output = Artisan::output();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('No se encontró el archivo de la cuenta de servicio', $output);
    }

    public function test_prevents_overlapping_executions_when_shared_lock_is_active(): void
    {
        $lock = GoogleSheetsReaderService::getSharedLock();
        $this->assertTrue($lock->get());

        $exitCode = Artisan::call('crm:sync-sheets');
        $output = Artisan::output();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Ya existe una instancia de sincronización en ejecución', $output);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'sync.skipped_overlap',
        ]);

        $lock->release();
    }

    public function test_force_option_breaks_stale_lock_and_executes_successfully(): void
    {
        // 1. Simular un bloqueo huérfano dejado por un proceso que murió
        $staleLock = GoogleSheetsReaderService::getSharedLock();
        $this->assertTrue($staleLock->get());

        $tempKeyPath = tempnam(sys_get_temp_dir(), 'sa_force_test');
        file_put_contents($tempKeyPath, json_encode([
            'client_email' => 'sa@test.iam.gserviceaccount.com',
            'private_key' => 'mock-key',
        ]));
        config(['services.sheets.service_account_path' => $tempKeyPath]);
        config(['services.sheets.spreadsheet_id' => 'test-spreadsheet-123']);

        $mockService = $this->createPartialMock(GoogleSheetsReaderService::class, ['getAccessTokenFromServiceAccount', 'fetchSheetValues']);
        $mockService->expects($this->atLeastOnce())->method('getAccessTokenFromServiceAccount')->willReturn('mock-access-token');
        $mockService->expects($this->atLeastOnce())->method('fetchSheetValues')->willReturn([
            ['id', 'full_name', 'email', 'phone'],
            ['m-force-1', 'Lead Forzado', 'force@test.cl', '+56911112222'],
        ]);
        $this->app->instance(GoogleSheetsReaderService::class, $mockService);

        // 2. Invocar con --force
        $exitCode = Artisan::call('crm:sync-sheets', ['--force' => true]);
        $output = Artisan::output();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Opción --force detectada', $output);
        $this->assertStringContainsString('Sincronización completada exitosamente', $output);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'sync.forced_lock_release',
        ]);

        @unlink($tempKeyPath);
    }

    public function test_manual_sync_is_blocked_when_command_holds_shared_lock(): void
    {
        $admin = User::create([
            'email' => 'admin@tusolucionfinanciera.cl',
            'name' => 'Admin TSF',
            'role' => 'admin',
            'active' => true,
        ]);

        $tempKeyPath = tempnam(sys_get_temp_dir(), 'sa_lock_test');
        file_put_contents($tempKeyPath, json_encode(['mock' => 'key']));
        config(['services.sheets.service_account_path' => $tempKeyPath]);
        config(['services.sheets.spreadsheet_id' => 'test-spreadsheet-123']);

        // Simular que el comando o un proceso en background ya adquirió el bloqueo compartido
        $lock = GoogleSheetsReaderService::getSharedLock();
        $this->assertTrue($lock->get());

        $response = $this->actingAs($admin)->post('/admin/sync');

        $response->assertSessionHasErrors('error');
        $this->assertStringContainsString(
            'Ya existe una sincronización en curso',
            session('errors')->first('error')
        );

        $this->assertDatabaseHas('audit_events', [
            'action' => 'sync.manual_skipped_overlap',
            'actor_user_id' => $admin->id,
        ]);

        $lock->release();
        @unlink($tempKeyPath);
    }

    public function test_lock_is_released_when_manual_sync_encounters_failure(): void
    {
        $admin = User::create([
            'email' => 'admin@tusolucionfinanciera.cl',
            'name' => 'Admin TSF',
            'role' => 'admin',
            'active' => true,
        ]);

        $tempKeyPath = tempnam(sys_get_temp_dir(), 'sa_err_test');
        file_put_contents($tempKeyPath, json_encode(['mock' => 'key']));
        config(['services.sheets.service_account_path' => $tempKeyPath]);
        config(['services.sheets.spreadsheet_id' => 'test-spreadsheet-123']);

        // Mock que lanza excepción intencional durante la lectura
        $mockService = $this->createPartialMock(GoogleSheetsReaderService::class, ['getAccessTokenFromServiceAccount']);
        $mockService->expects($this->once())->method('getAccessTokenFromServiceAccount')->willThrowException(new RuntimeException('Error simulado de red con Google Sheets API'));
        $this->app->instance(GoogleSheetsReaderService::class, $mockService);

        $response = $this->actingAs($admin)->post('/admin/sync');
        $response->assertSessionHasErrors('error');

        // Verificar que tras el fallo el bloqueo quedó liberado (un nuevo bloqueo puede ser adquirido de inmediato)
        $newLock = Cache::lock(GoogleSheetsReaderService::SYNC_LOCK_KEY, GoogleSheetsReaderService::SYNC_LOCK_TTL);
        $this->assertTrue($newLock->get(), 'El bloqueo debió liberarse en el bloque finally tras el error de sincronización.');
        $newLock->release();

        @unlink($tempKeyPath);
    }

    public function test_lock_is_released_when_command_sync_encounters_failure(): void
    {
        $tempKeyPath = tempnam(sys_get_temp_dir(), 'sa_cmd_err');
        file_put_contents($tempKeyPath, json_encode(['mock' => 'key']));
        config(['services.sheets.service_account_path' => $tempKeyPath]);
        config(['services.sheets.spreadsheet_id' => 'test-spreadsheet-123']);

        $mockService = $this->createPartialMock(GoogleSheetsReaderService::class, ['getAccessTokenFromServiceAccount']);
        $mockService->expects($this->once())->method('getAccessTokenFromServiceAccount')->willThrowException(new RuntimeException('Falla de autenticación en cuenta de servicio'));
        $this->app->instance(GoogleSheetsReaderService::class, $mockService);

        $exitCode = Artisan::call('crm:sync-sheets');
        $this->assertEquals(1, $exitCode);

        // Verificar que tras el fallo del comando el bloqueo quedó liberado
        $newLock = GoogleSheetsReaderService::getSharedLock();
        $this->assertTrue($newLock->get(), 'El comando debió liberar el bloqueo en el bloque finally tras un fallo.');
        $newLock->release();

        @unlink($tempKeyPath);
    }

    public function test_real_database_lock_driver_prevents_simultaneous_execution_between_two_independent_processes(): void
    {
        $lockKey = GoogleSheetsReaderService::SYNC_LOCK_KEY;

        // Proceso 1 adquiere el bloqueo en la tabla cache_locks real de base de datos
        $lockProcess1 = Cache::store('database')->lock($lockKey, 600);
        $this->assertTrue($lockProcess1->get(), 'Proceso 1 debió adquirir el bloqueo en la base de datos');

        // Proceso 2 (proceso independiente) intenta adquirir el mismo bloqueo
        $lockProcess2 = Cache::store('database')->lock($lockKey, 600);
        $this->assertFalse($lockProcess2->get(), 'Proceso 2 no debe adquirir el bloqueo mientras Proceso 1 lo mantiene');

        // Proceso 1 renueva su bloqueo exitosamente con DatabaseLock::refresh()
        $this->assertTrue($lockProcess1->refresh(600), 'Proceso 1 debe poder renovar su propio bloqueo');

        // Proceso 2 no puede renovar un bloqueo que no posee
        $this->assertFalse($lockProcess2->refresh(600), 'Proceso 2 no puede renovar un bloqueo ajeno');

        // Proceso 1 libera el bloqueo
        $lockProcess1->release();

        // Ahora Proceso 2 puede adquirir el bloqueo liberado
        $this->assertTrue($lockProcess2->get(), 'Proceso 2 debe poder adquirir el bloqueo tras ser liberado por Proceso 1');
        $lockProcess2->release();
    }

    public function test_sync_command_aborts_and_logs_audit_when_lock_refresh_fails_during_execution(): void
    {
        $tempKeyPath = tempnam(sys_get_temp_dir(), 'sa_abort_test');
        file_put_contents($tempKeyPath, json_encode(['mock' => 'key']));
        config(['services.sheets.service_account_path' => $tempKeyPath]);
        config(['services.sheets.spreadsheet_id' => 'test-spreadsheet-123']);

        $mockService = $this->createPartialMock(GoogleSheetsReaderService::class, ['getAccessTokenFromServiceAccount', 'fetchSheetValues']);
        $mockService->expects($this->atLeastOnce())->method('getAccessTokenFromServiceAccount')->willReturn('mock-token');

        // Simular que en la segunda hoja, el bloqueo de base de datos fue robado o expiró
        $sheetCount = 0;
        $mockService->expects($this->atLeastOnce())->method('fetchSheetValues')->willReturnCallback(function () use (&$sheetCount) {
            $sheetCount++;
            if ($sheetCount === 2) {
                // Otro proceso roba o elimina el bloqueo de la tabla cache_locks
                Cache::store('database')->lock(GoogleSheetsReaderService::SYNC_LOCK_KEY, 600)->forceRelease();
            }
            return [['id', 'name'], ['m-1', 'Test']];
        });
        $this->app->instance(GoogleSheetsReaderService::class, $mockService);

        $exitCode = Artisan::call('crm:sync-sheets');

        $this->assertEquals(1, $exitCode, 'El comando debe fallar con código 1 si el bloqueo se pierde durante la ejecución');
        $this->assertStringContainsString('Se perdió la propiedad del bloqueo', Artisan::output());

        $this->assertDatabaseHas('audit_events', [
            'action' => 'sync.lock_lost',
        ]);

        @unlink($tempKeyPath);
    }

    public function test_sync_dataset_rolls_back_and_fails_run_if_heartbeat_throws_lock_lost_exception(): void
    {
        $service = new GoogleSheetsReaderService();

        $sheetsData = [
            'Hoja 2' => [
                ['id', 'full_name', 'email', 'phone'],
                ['lead-rollback-1', 'Nombre Rollback', 'rollback@test.cl', '+56900000000'],
            ],
            'Hoja 3' => [
                ['id', 'full_name', 'email', 'phone'],
                ['lead-rollback-2', 'Nombre Rollback 2', 'rollback2@test.cl', '+56900000001'],
            ],
        ];

        $callCount = 0;
        $failingHeartbeat = function () use (&$callCount) {
            $callCount++;
            if ($callCount >= 2) {
                throw new RuntimeException('Simulación: el bloqueo expiró y no pudo renovarse');
            }
        };

        try {
            $service->syncDataset($sheetsData, $failingHeartbeat);
            $this->fail('syncDataset debió lanzar RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('bloqueo expiró', $e->getMessage());
        }

        // Ningún prospecto de la transacción debió quedar persistido
        $this->assertDatabaseMissing('leads', ['meta_id' => 'lead-rollback-1']);
        $this->assertDatabaseMissing('leads', ['meta_id' => 'lead-rollback-2']);

        // El registro de SyncRun debe quedar con estado 'failed'
        $this->assertDatabaseHas('sync_runs', [
            'status' => SyncRun::STATUS_FAILED,
        ]);
    }

    public function test_executes_sync_successfully_with_mocked_sheets_service(): void
    {
        $tempKeyPath = tempnam(sys_get_temp_dir(), 'sa_test');
        file_put_contents($tempKeyPath, json_encode([
            'client_email' => 'sa@test.iam.gserviceaccount.com',
            'private_key' => 'mock-key',
        ]));

        config(['services.sheets.service_account_path' => $tempKeyPath]);
        config(['services.sheets.spreadsheet_id' => 'test-spreadsheet-123']);

        $mockService = $this->createPartialMock(GoogleSheetsReaderService::class, ['getAccessTokenFromServiceAccount', 'fetchSheetValues']);
        $mockService->expects($this->atLeastOnce())->method('getAccessTokenFromServiceAccount')->willReturn('mock-access-token');
        $mockService->expects($this->atLeastOnce())->method('fetchSheetValues')->willReturn([
            ['id', 'full_name', 'email', 'phone'],
            ['m-cron-1', 'Lead de Cron', 'cron@test.cl', '+56999999999'],
        ]);

        $this->app->instance(GoogleSheetsReaderService::class, $mockService);

        $exitCode = Artisan::call('crm:sync-sheets');
        $output = Artisan::output();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Sincronización completada exitosamente', $output);

        $this->assertDatabaseHas('leads', [
            'meta_id' => 'm-cron-1',
            'email' => 'cron@test.cl',
        ]);

        $this->assertDatabaseHas('sync_runs', [
            'status' => SyncRun::STATUS_COMPLETED,
        ]);

        @unlink($tempKeyPath);
    }

    public function test_database_lock_expiration_permits_acquisition_by_new_process_and_invalidates_original_owner(): void
    {
        $lockKey = 'crm_sync_expiration_test_lock';

        // 1. Proceso 1 adquiere el bloqueo con TTL de 2 segundos en la tabla cache_locks
        $lockProcess1 = Cache::store('database')->lock($lockKey, 2);
        $this->assertTrue($lockProcess1->get(), 'Proceso 1 debió adquirir el bloqueo');

        // 2. Proceso 2 intenta adquirir y es rechazado inmediatamente
        $lockProcess2 = Cache::store('database')->lock($lockKey, 600);
        $this->assertFalse($lockProcess2->get(), 'Proceso 2 no debe adquirir mientras el bloqueo siga vigente');

        // 3. Simular el paso del tiempo / expiración del bloqueo en la base de datos
        DB::table('cache_locks')->where('key', Cache::store('database')->getPrefix() . $lockKey)->update([
            'expiration' => time() - 10,
        ]);

        // 4. Proceso 1 intenta renovar el bloqueo expirado y refresh() retorna false
        $this->assertFalse($lockProcess1->refresh(600), 'Proceso 1 debe fallar al renovar porque el bloqueo expiró');

        // 5. Proceso 2 intenta adquirir el bloqueo y lo obtiene exitosamente
        $this->assertTrue($lockProcess2->get(), 'Proceso 2 debe adquirir el bloqueo porque el anterior expiró');

        // 6. Proceso 1 ya no puede liberar el bloqueo (porque ahora pertenece a Proceso 2)
        $this->assertFalse($lockProcess1->release(), 'Proceso 1 no puede liberar un bloqueo que ahora pertenece a Proceso 2');

        // 7. Proceso 2 sí puede liberarlo
        $this->assertTrue($lockProcess2->release(), 'Proceso 2 debe poder liberar su propio bloqueo');
    }
}
