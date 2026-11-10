<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\GoogleSheetsReaderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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
        $lock = Cache::lock(GoogleSheetsReaderService::SYNC_LOCK_KEY, GoogleSheetsReaderService::SYNC_LOCK_TTL);
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
        $lock = Cache::lock(GoogleSheetsReaderService::SYNC_LOCK_KEY, GoogleSheetsReaderService::SYNC_LOCK_TTL);
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
        $newLock = Cache::lock(GoogleSheetsReaderService::SYNC_LOCK_KEY, GoogleSheetsReaderService::SYNC_LOCK_TTL);
        $this->assertTrue($newLock->get(), 'El comando debió liberar el bloqueo en el bloque finally tras un fallo.');
        $newLock->release();

        @unlink($tempKeyPath);
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
}
