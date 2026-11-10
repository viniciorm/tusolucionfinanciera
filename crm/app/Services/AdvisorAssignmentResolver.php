<?php

namespace App\Services;

use App\Models\User;

/**
 * Mapeo explícito y verificable entre valores de origen (Sheets assigned_to) y usuarios internos.
 * 
 * Reglas de seguridad:
 * 1. Jamás inventa asignaciones históricas.
 * 2. Si no hay valor de origen o no existe un mapeo explícito, retorna null (queda pendiente de conciliación).
 * 3. Si el usuario mapeado no existe, no está activo o no tiene rol 'advisor', retorna null.
 * 4. Pao y Cris NO están habilitados en esta etapa.
 */
class AdvisorAssignmentResolver
{
    /**
     * Mapeo explícito de alias de origen normalizados a correos autorizados en la base de datos.
     * En esta fase inicial, Pao y Cris no están activos ni habilitados.
     */
    private const EXPLICIT_MAPPING = [
        // 'pao' => 'pao@tusolucionfinanciera.cl', // Inhabilitado hasta probar aislamiento
        // 'cris' => 'cris@tusolucionfinanciera.cl', // Inhabilitado hasta probar aislamiento
    ];

    public static function resolve(?string $sourceAssignedTo): ?User
    {
        if (empty($sourceAssignedTo)) {
            return null;
        }

        $normalized = mb_strtolower(trim($sourceAssignedTo), 'UTF-8');

        // Si no hay mapeo explícito para este alias, no inferir ni inventar
        $targetEmail = self::EXPLICIT_MAPPING[$normalized] ?? null;
        if (!$targetEmail) {
            return null;
        }

        // Solo resolver si el usuario existe, tiene rol 'advisor' y está explícitamente activo
        return User::where('email', $targetEmail)
            ->where('role', 'advisor')
            ->where('active', true)
            ->first();
    }
}
