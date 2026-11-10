<?php

namespace App\Console\Commands;

use App\Services\AdvisorAssignmentResolver;
use Illuminate\Console\Command;

class ReconcileLeadsCommand extends Command
{
    protected $signature = 'crm:reconcile-leads {--limit= : Número máximo de prospectos a procesar}';

    protected $description = 'Ejecuta la reconciliación explícita de prospectos pendientes sin sobrescribir asignaciones manuales ni inventar equivalencias.';

    public function handle(): int
    {
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info('[' . date('Y-m-d H:i:s') . '] Iniciando reconciliación explícita de prospectos pendientes...');

        $result = AdvisorAssignmentResolver::reconcilePendingLeads($limit);

        $this->info("✓ Proceso de reconciliación finalizado.");
        $this->table(
            ['Métrica', 'Cantidad'],
            [
                ['Total evaluados', $result['total_processed']],
                ['Reconciliados con asesor habilitado', $result['reconciled']],
                ['Asignaciones manuales previas preservadas', $result['skipped_manual']],
                ['Pendientes sin resolver (sin asesor habilitado/ambiguos)', $result['unresolved']],
            ]
        );

        return self::SUCCESS;
    }
}
