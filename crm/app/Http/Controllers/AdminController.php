<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\AdvisorAssignmentResolver;
use App\Services\GoogleSheetsReaderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class AdminController extends Controller
{
    public function __construct(private readonly GoogleSheetsReaderService $sheetsService) {}

    public function index(): View
    {
        $users = User::orderBy('id')->get();
        $recentSyncs = SyncRun::latest('started_at')->limit(10)->get();
        $recentAudits = AuditEvent::with(['actor', 'lead'])->latest('id')->limit(30)->get();

        $sheetsConfigured = !empty(config('services.sheets.service_account_path'))
            && file_exists((string) config('services.sheets.service_account_path'))
            && !empty(config('services.sheets.spreadsheet_id'));

        $pendingReconciliationCount = Lead::where('reconciliation_pending', true)->count();

        return view('admin.index', [
            'users' => $users,
            'recentSyncs' => $recentSyncs,
            'recentAudits' => $recentAudits,
            'sheetsConfigured' => $sheetsConfigured,
            'pendingReconciliationCount' => $pendingReconciliationCount,
        ]);
    }

    public function createUser(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'name' => ['nullable', 'string', 'max:191'],
            'role' => ['required', Rule::in(['admin', 'advisor'])],
            'active' => ['nullable', 'boolean'],
        ]);

        $normalizedEmail = mb_strtolower(trim($validated['email']), 'UTF-8');

        $user = User::create([
            'email' => $normalizedEmail,
            'name' => isset($validated['name']) ? trim($validated['name']) : null,
            'role' => $validated['role'],
            'active' => (bool) ($request->input('active', false)),
            'google_subject' => null, // Espera primer login de Google para vincular
        ]);

        AuditEvent::log('admin.user_created', Auth::id(), null, [
            'created_user_id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'active' => $user->active,
        ]);

        return redirect()->route('admin.index')->with('status', "Usuario {$user->email} autorizado exitosamente.");
    }

    public function toggleUserStatus(Request $request, int $id): RedirectResponse
    {
        $target = User::findOrFail($id);

        if ($target->id === Auth::id()) {
            return back()->withErrors(['error' => 'No puedes desactivar tu propia cuenta de administrador.']);
        }

        $target->active = !$target->active;
        $target->save();

        AuditEvent::log('admin.user_status_toggled', Auth::id(), null, [
            'target_user_id' => $target->id,
            'active' => $target->active,
        ]);

        $statusStr = $target->active ? 'activada' : 'desactivada';
        return redirect()->route('admin.index')->with('status', "Cuenta de {$target->email} {$statusStr}.");
    }

    public function triggerSync(): RedirectResponse
    {
        $serviceAccountPath = (string) config('services.sheets.service_account_path');
        $spreadsheetId = (string) config('services.sheets.spreadsheet_id');

        if (empty($serviceAccountPath) || !file_exists($serviceAccountPath) || empty($spreadsheetId)) {
            return back()->withErrors(['error' => 'La cuenta de servicio o el ID de la planilla no están configurados en el archivo privado .env']);
        }

        $lock = GoogleSheetsReaderService::getSharedLock();

        if (!$lock->get()) {
            AuditEvent::log('sync.manual_skipped_overlap', Auth::id(), null, [
                'reason' => 'Ya existe una sincronización en curso (manual o programada).',
            ]);
            return back()->withErrors(['error' => 'Ya existe una sincronización en curso (manual o programada). Por favor espera a que finalice.']);
        }

        try {
            $token = $this->sheetsService->getAccessTokenFromServiceAccount($serviceAccountPath);

            $sheetsData = [];
            foreach (GoogleSheetsReaderService::ALLOWED_SHEETS as $sheetName) {
                $this->assertAndRefreshLock($lock);
                $sheetsData[$sheetName] = $this->sheetsService->fetchSheetValues($token, $spreadsheetId, $sheetName);
            }

            $syncRun = $this->sheetsService->syncDataset($sheetsData, function () use ($lock): void {
                $this->assertAndRefreshLock($lock);
            });

            AuditEvent::log('sync.manual_completed', Auth::id(), null, [
                'sync_run_id' => $syncRun->id,
                'rows_read' => $syncRun->rows_read,
                'rows_saved' => $syncRun->rows_saved,
                'rows_rejected' => $syncRun->rows_rejected,
            ]);

            return redirect()->route('admin.index')->with('status', "Sincronización manual completada. Leídos: {$syncRun->rows_read}, Guardados: {$syncRun->rows_saved}, Rechazados: {$syncRun->rows_rejected}");
        } catch (Throwable $e) {
            AuditEvent::log('sync.manual_failed', Auth::id(), null, [
                'error' => $e->getMessage(),
            ]);
            return back()->withErrors(['error' => 'Error durante la sincronización: ' . $e->getMessage()]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Renueva el bloqueo atómico y verifica que la propiedad continúe perteneciendo a este proceso.
     * Si la renovación falla, aborta inmediatamente.
     *
     * @throws RuntimeException si se perdió la propiedad del bloqueo
     */
    protected function assertAndRefreshLock($lock): void
    {
        if (method_exists($lock, 'refresh')) {
            $refreshed = $lock->refresh(GoogleSheetsReaderService::SYNC_LOCK_TTL);
            if (!$refreshed) {
                AuditEvent::log('sync.lock_lost', Auth::id(), null, [
                    'reason' => 'Falló la renovación del bloqueo en DatabaseLock. El bloqueo expiró o fue adquirido por otro proceso.',
                ]);
                throw new \RuntimeException('Se perdió la propiedad del bloqueo de sincronización durante la ejecución. Operación abortada para evitar colisiones.');
            }
            return;
        }

        if (method_exists($lock, 'isOwnedByCurrentProcess') && !$lock->isOwnedByCurrentProcess()) {
            AuditEvent::log('sync.lock_lost', Auth::id(), null, [
                'reason' => 'El proceso actual ya no es propietario del bloqueo.',
            ]);
            throw new \RuntimeException('Se perdió la propiedad del bloqueo de sincronización durante la ejecución. Operación abortada.');
        }
    }

    public function reconcileLeads(): RedirectResponse
    {
        try {
            $result = AdvisorAssignmentResolver::reconcilePendingLeads(actorUserId: Auth::id());

            $msg = "Reconciliación de prospectos completada. Reconciliados: {$result['reconciled']}, Asignaciones manuales preservadas: {$result['skipped_manual']}, Pendientes sin resolver: {$result['unresolved']}.";

            return redirect()->route('admin.index')->with('status', $msg);
        } catch (Throwable $e) {
            return back()->withErrors(['error' => 'Error durante la reconciliación: ' . $e->getMessage()]);
        }
    }
}
