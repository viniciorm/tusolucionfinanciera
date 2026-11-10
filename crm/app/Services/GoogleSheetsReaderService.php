<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadManagement;
use App\Models\SyncRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use TSF\Sync\SheetBatchValidator;
use TSF\Sync\SheetLeadNormalizer;

class GoogleSheetsReaderService
{
    public const ALLOWED_SHEETS = ['Hoja 2', 'Hoja 3', 'Hoja 4', 'Hoja 5'];
    public const GOOGLE_TOKEN_URI = 'https://oauth2.googleapis.com/token';
    public const SHEETS_API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';
    public const READONLY_SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

    public function __construct(
        private readonly ?SheetLeadNormalizer $normalizer = null,
        private readonly ?SheetBatchValidator $batchValidator = null,
    ) {}

    public function getValidator(): SheetBatchValidator
    {
        $normalizer = $this->normalizer ?? new SheetLeadNormalizer();
        return $this->batchValidator ?? new SheetBatchValidator($normalizer);
    }

    /**
     * Genera un Bearer Token usando la Cuenta de Servicio con alcance estricto de solo lectura.
     */
    public function getAccessTokenFromServiceAccount(string $serviceAccountPath): string
    {
        if (!file_exists($serviceAccountPath)) {
            throw new RuntimeException("El archivo de credenciales de la cuenta de servicio no existe en la ruta configurada.");
        }

        $content = file_get_contents($serviceAccountPath);
        $credentials = json_decode($content, true);

        if (!$credentials || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            throw new RuntimeException("Credenciales de cuenta de servicio inválidas o incompletas.");
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $credentials['client_email'],
            'scope' => self::READONLY_SCOPE, // ESTRICTO: SOLO LECTURA
            'aud' => self::GOOGLE_TOKEN_URI,
            'exp' => $now + 3600,
            'iat' => $now,
        ];

        $encode = static fn ($data) => rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signingInput = implode('.', [$encode($header), $encode($claims)]);

        $privateKey = openssl_pkey_get_private($credentials['private_key']);
        if (!$privateKey) {
            throw new RuntimeException("Clave privada de cuenta de servicio inválida.");
        }

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException("No se pudo firmar el JWT de la cuenta de servicio.");
        }

        $jwt = $signingInput . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        $response = Http::asForm()->timeout(15)->post(self::GOOGLE_TOKEN_URI, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (!$response->successful()) {
            throw new RuntimeException("Google rechazó la autenticación de la cuenta de servicio: " . $response->body());
        }

        $data = $response->json();
        return (string) ($data['access_token'] ?? '');
    }

    /**
     * Consulta una hoja específica vía Google Sheets API v4 (GET - Solo lectura).
     */
    public function fetchSheetValues(string $accessToken, string $spreadsheetId, string $sheetName): array
    {
        if (!in_array($sheetName, self::ALLOWED_SHEETS, true)) {
            throw new InvalidArgumentException("La hoja '$sheetName' no está autorizada para lectura.");
        }

        $url = self::SHEETS_API_BASE . '/' . urlencode($spreadsheetId) . '/values/' . urlencode($sheetName) . '!A1:ZZ';

        $response = Http::withToken($accessToken)->timeout(20)->get($url);

        if (!$response->successful()) {
            throw new RuntimeException("Error al consultar Google Sheets para '$sheetName': " . $response->status());
        }

        $data = $response->json();
        return $data['values'] ?? [];
    }

    /**
     * Ejecuta una sincronización completa e idempotente a partir de matrices de datos.
     */
    public function syncDataset(array $sheetsData): SyncRun
    {
        $syncRun = SyncRun::create([
            'status' => SyncRun::STATUS_RUNNING,
            'started_at' => now(),
            'rows_read' => 0,
            'rows_saved' => 0,
            'rows_rejected' => 0,
            'summary' => [],
        ]);

        $totalRead = 0;
        $totalSaved = 0;
        $totalRejected = 0;
        $sheetSummaries = [];

        $validator = $this->getValidator();

        try {
            DB::beginTransaction();

            foreach ($sheetsData as $sheetName => $rawRows) {
                if (!in_array($sheetName, self::ALLOWED_SHEETS, true)) {
                    continue; // Excluir Hoja 1 y cualquier hoja no autorizada
                }

                if (empty($rawRows) || !is_array($rawRows)) {
                    $sheetSummaries[$sheetName] = ['read' => 0, 'saved' => 0, 'rejected' => 0];
                    continue;
                }

                $headers = array_shift($rawRows) ?? [];
                $rows = $rawRows;
                $rowCount = count($rows);
                $totalRead += $rowCount;

                $validationResult = $validator->validate($sheetName, $headers, $rows);
                $validLeads = $validationResult['valid'];
                $rejectedRows = $validationResult['rejected'];

                $sheetSaved = 0;
                $sheetRejected = count($rejectedRows);

                foreach ($validLeads as $leadData) {
                    $sourceKey = $leadData['source_key'];

                    $lead = Lead::where('source_key', $sourceKey)->first();

                    if (!$lead) {
                        // 1. Prospecto nuevo: resolver asignación verificable o marcar pendiente de conciliación
                        $resolvedAdvisor = AdvisorAssignmentResolver::resolve($leadData['source_assigned_to']);
                        $initialAssignedUserId = $resolvedAdvisor ? $resolvedAdvisor->id : null;
                        $reconciliationPending = ($initialAssignedUserId === null);

                        $lead = Lead::create([
                            'source_key' => $sourceKey,
                            'source_sheet' => $leadData['source_sheet'],
                            'meta_id' => $leadData['meta_id'],
                            'name' => $leadData['name'] ?: null,
                            'email' => $leadData['email'] ?: null,
                            'phone' => $leadData['phone'] ?: null,
                            'phone_search' => $leadData['phone_search'] ?: null,
                            'received_at_raw' => $leadData['received_at_raw'] ?: null,
                            'received_at' => $this->parseReceivedAt($leadData['received_at_raw']),
                            'campaign' => $leadData['campaign'] ?: null,
                            'form' => $leadData['form'] ?: null,
                            'notification_status' => $leadData['notification_status'] ?: null,
                            'source_assigned_to' => $leadData['source_assigned_to'] ?: null, // Dato original sin alterar
                            'reconciliation_pending' => $reconciliationPending,
                            'original' => $leadData['original'],
                            'last_seen_at' => now(),
                        ]);

                        // Crear gestión comercial con estado inicial 'Nuevo' y asignación interna verificada
                        LeadManagement::create([
                            'lead_id' => $lead->id,
                            'status' => LeadManagement::STATUS_NUEVO,
                            'assigned_user_id' => $initialAssignedUserId,
                        ]);

                        $sheetSaved++;
                    } else {
                        // 2. Prospecto existente: ACTUALIZAR CAMPOS DE ORIGEN QUE HAYAN CAMBIADO
                        if ($lead->name !== ($leadData['name'] ?: null)) {
                            $lead->name = $leadData['name'] ?: null;
                        }
                        if ($lead->email !== ($leadData['email'] ?: null)) {
                            $lead->email = $leadData['email'] ?: null;
                        }
                        if ($lead->phone !== ($leadData['phone'] ?: null)) {
                            $lead->phone = $leadData['phone'] ?: null;
                            $lead->phone_search = $leadData['phone_search'] ?: null;
                        }
                        if ($lead->campaign !== ($leadData['campaign'] ?: null)) {
                            $lead->campaign = $leadData['campaign'] ?: null;
                        }
                        if ($lead->form !== ($leadData['form'] ?: null)) {
                            $lead->form = $leadData['form'] ?: null;
                        }
                        if ($lead->notification_status !== ($leadData['notification_status'] ?: null)) {
                            $lead->notification_status = $leadData['notification_status'] ?: null;
                        }
                        if ($lead->source_assigned_to !== ($leadData['source_assigned_to'] ?: null)) {
                            $lead->source_assigned_to = $leadData['source_assigned_to'] ?: null;
                        }
                        if ($lead->original !== $leadData['original']) {
                            $lead->original = $leadData['original'];
                        }

                        $lead->last_seen_at = now();
                        $lead->save();

                        // REGLA CRÍTICA ESTRICTA:
                        // NUNCA sobrescribir lead_management.status, lead_management.assigned_user_id,
                        // ni actividades o notas creadas en el CRM.
                        $sheetSaved++;
                    }
                }

                $totalSaved += $sheetSaved;
                $totalRejected += $sheetRejected;

                $sheetSummaries[$sheetName] = [
                    'read' => $rowCount,
                    'saved' => $sheetSaved,
                    'rejected' => $sheetRejected,
                    'rejections' => array_map(static fn($r) => ['row' => $r['row'], 'errors' => $r['errors']], $rejectedRows),
                ];
            }

            DB::commit();

            $syncRun->update([
                'status' => SyncRun::STATUS_COMPLETED,
                'rows_read' => $totalRead,
                'rows_saved' => $totalSaved,
                'rows_rejected' => $totalRejected,
                'summary' => $sheetSummaries,
                'finished_at' => now(),
            ]);

            AuditEvent::log('sync.completed', null, null, [
                'sync_run_id' => $syncRun->id,
                'read' => $totalRead,
                'saved' => $totalSaved,
                'rejected' => $totalRejected,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            $syncRun->update([
                'status' => SyncRun::STATUS_FAILED,
                'summary' => ['error' => $e->getMessage()],
                'finished_at' => now(),
            ]);

            AuditEvent::log('sync.failed', null, null, [
                'sync_run_id' => $syncRun->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $syncRun;
    }

    private function parseReceivedAt(?string $raw): ?Carbon
    {
        if (empty($raw)) {
            return null;
        }
        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
