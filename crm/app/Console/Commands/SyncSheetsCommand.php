<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\SyncRun;
use App\Services\GoogleSheetsReaderService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncSheetsCommand extends Command implements Isolatable
{
    protected $signature = 'crm:sync-sheets {--force : Forzar ejecución ignorando el bloqueo de superposición}';

    protected $description = 'Ejecuta la sincronización programada de Google Sheets con protección contra superposiciones.';

    public function handle(GoogleSheetsReaderService $sheetsService): int
    {
        $this->info('[' . date('Y-m-d H:i:s') . '] Iniciando verificación de sincronización programada...');

        // 1. Protección contra ejecuciones superpuestas compartida con sincronización manual
        $lockKey = GoogleSheetsReaderService::SYNC_LOCK_KEY;
        $lockDurationSeconds = GoogleSheetsReaderService::SYNC_LOCK_TTL;

        $lock = Cache::lock($lockKey, $lockDurationSeconds);

        if (!$this->option('force') && !$lock->get()) {
            $this->warn('ADVERTENCIA: Ya existe una instancia de sincronización en ejecución (manual o programada). Omitiendo tarea para evitar colisiones.');
            AuditEvent::log('sync.skipped_overlap', null, null, [
                'reason' => 'Otra instancia de sincronización ya está ejecutándose.',
            ]);
            return self::SUCCESS;
        }

        try {
            // 2. Comprobar configuración de cuenta de servicio e ID de planilla
            $serviceAccountPath = (string) config('services.sheets.service_account_path');
            $spreadsheetId = (string) config('services.sheets.spreadsheet_id');

            if (empty($serviceAccountPath) || !file_exists($serviceAccountPath)) {
                $this->error('ERROR: No se encontró el archivo de la cuenta de servicio en: ' . $serviceAccountPath);
                return self::FAILURE;
            }

            if (empty($spreadsheetId)) {
                $this->error('ERROR: El ID de la planilla (GOOGLE_SHEETS_SPREADSHEET_ID) no está configurado.');
                return self::FAILURE;
            }

            // 3. Autenticación y obtención de token de solo lectura
            $this->line('Obteniendo token de acceso con permisos exclusivos de solo lectura...');
            $token = $sheetsService->getAccessTokenFromServiceAccount($serviceAccountPath);

            // 4. Lectura segura exclusivamente de las hojas autorizadas (Hoja 2 a 5)
            $sheetsData = [];
            foreach (GoogleSheetsReaderService::ALLOWED_SHEETS as $sheetName) {
                $this->line("Consultando '$sheetName' vía Google Sheets API v4...");
                $sheetsData[$sheetName] = $sheetsService->fetchSheetValues($token, $spreadsheetId, $sheetName);
            }

            // 5. Procesamiento idempotente
            $this->line('Procesando datos y aplicando reglas de asignación y persistencia...');
            $syncRun = $sheetsService->syncDataset($sheetsData);

            $this->info("✓ Sincronización completada exitosamente (ID de ejecución #{$syncRun->id}).");
            $this->table(
                ['Métrica', 'Cantidad'],
                [
                    ['Filas leídas', $syncRun->rows_read],
                    ['Filas procesadas/guardadas', $syncRun->rows_saved],
                    ['Filas rechazadas (conflictos/duplicados)', $syncRun->rows_rejected],
                    ['Duración (segundos)', $syncRun->finished_at ? $syncRun->finished_at->diffInSeconds($syncRun->started_at) : 0],
                ]
            );

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('ERROR CRÍTICO DURANTE LA SINCRONIZACIÓN: ' . $e->getMessage());
            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
