<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mapeo explícito y verificable entre valores de origen (Sheets assigned_to) y usuarios internos.
 * 
 * Reglas de seguridad:
 * 1. Jamás inventa asignaciones históricas ni equivalencias ambiguas.
 * 2. Si no hay valor de origen o no existe un mapeo explícito, queda pendiente de conciliación.
 * 3. Si el usuario mapeado no existe, no está activo o no tiene rol 'advisor', no se asigna.
 * 4. Pao y Cris NO están habilitadas por defecto hasta validar aislamiento.
 * 5. La reconciliación respeta estrictamente asignaciones manuales previas (nunca las sobrescribe).
 */
class AdvisorAssignmentResolver
{
    /**
     * Mapeo estático base de alias de origen normalizados a correos autorizados.
     * En esta fase inicial, Pao y Cris están inhabilitadas por defecto.
     */
    private const BASE_EXPLICIT_MAPPING = [
        // 'pao' => 'pao@tusolucionfinanciera.cl',
        // 'cris' => 'cris@tusolucionfinanciera.cl',
    ];

    /**
     * Devuelve el mapeo explícito configurado (desde config o base estática).
     */
    public static function getKnownMappings(): array
    {
        $configMappings = config('services.advisors.mappings', []);
        return array_merge(self::BASE_EXPLICIT_MAPPING, is_array($configMappings) ? $configMappings : []);
    }

    /**
     * Resuelve un asesor a partir del texto de origen de la planilla.
     */
    public static function resolve(?string $sourceAssignedTo): ?User
    {
        if (empty($sourceAssignedTo)) {
            return null;
        }

        $normalized = mb_strtolower(trim($sourceAssignedTo), 'UTF-8');
        $mappings = self::getKnownMappings();

        $targetEmail = $mappings[$normalized] ?? null;
        if (!$targetEmail) {
            return null;
        }

        return User::where('email', $targetEmail)
            ->where('role', 'advisor')
            ->where('active', true)
            ->first();
    }

    /**
     * Ejecuta una reconciliación explícita para leads ya importados que se encuentran
     * pendientes de conciliación.
     *
     * Reglas estrictas:
     * - Si el lead ya cuenta con una asignación manual en CRM (assigned_user_id !== null),
     *   se PRESERVA intacta dicha asignación manual y se marca como resuelto.
     * - Solo se asigna si existe una coincidencia explícita con un asesor activo y habilitado.
     * - No inventa equivalencias: cualquier origen ambiguo o sin mapeo permanece pendiente.
     */
    public static function reconcilePendingLeads(?int $limit = null, ?int $actorUserId = null): array
    {
        $query = Lead::with('management')
            ->where('reconciliation_pending', true)
            ->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $leads = $query->get();

        $reconciledCount = 0;
        $skippedManualCount = 0;
        $unresolvedCount = 0;

        foreach ($leads as $lead) {
            DB::transaction(function () use ($lead, $actorUserId, &$reconciledCount, &$skippedManualCount, &$unresolvedCount): void {
                $management = $lead->management;

                // 1. REGLA: Si ya tiene una asignación manual previa en CRM, NO SOBRESCRIBIR
                if ($management && $management->assigned_user_id !== null) {
                    $lead->reconciliation_pending = false;
                    $lead->save();

                    AuditEvent::log('leads.reconciliation_preserved_manual', $actorUserId, $lead->id, [
                        'assigned_user_id' => $management->assigned_user_id,
                        'source_assigned_to' => $lead->source_assigned_to,
                    ]);

                    $skippedManualCount++;
                    return;
                }

                // 2. Intentar resolución con mapeo explícito
                $advisor = self::resolve($lead->source_assigned_to);

                if ($advisor) {
                    // Asignación explícita al asesor habilitado
                    if ($management) {
                        $management->assigned_user_id = $advisor->id;
                        $management->save();
                    }

                    $lead->reconciliation_pending = false;
                    $lead->save();

                    Activity::create([
                        'lead_id' => $lead->id,
                        'actor_user_id' => $actorUserId ?? $advisor->id,
                        'type' => Activity::TYPE_NOTE,
                        'note' => "Asignación reconciliada explícitamente con asesor {$advisor->name} ({$advisor->email}) a partir del origen '{$lead->source_assigned_to}'.",
                    ]);

                    AuditEvent::log('leads.reconciled', $actorUserId, $lead->id, [
                        'assigned_user_id' => $advisor->id,
                        'source_assigned_to' => $lead->source_assigned_to,
                    ]);

                    $reconciledCount++;
                } else {
                    // No hay mapeo explícito ni asesor activo: permanece pendiente
                    $unresolvedCount++;
                }
            });
        }

        return [
            'reconciled' => $reconciledCount,
            'skipped_manual' => $skippedManualCount,
            'unresolved' => $unresolvedCount,
            'total_processed' => $leads->count(),
        ];
    }
}
