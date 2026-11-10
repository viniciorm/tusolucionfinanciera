<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\SyncRun;
use App\Services\GoogleSheetsReaderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

    public function test_prevents_overlapping_executions_when_lock_is_active(): void
    {
        $lock = Cache::lock('crm_sync_sheets_execution_lock', 600);
        $lock->get();

        $exitCode = Artisan::call('crm:sync-sheets');
        $output = Artisan::output();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Ya existe una instancia de sincronización en ejecución', $output);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'sync.skipped_overlap',
        ]);

        $lock->release();
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

        if ($exitCode !== 0) {
            echo "\nARTISAN ERROR OUTPUT:\n" . $output . "\n";
        }

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
