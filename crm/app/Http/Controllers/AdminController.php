<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\GoogleSheetsReaderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        return view('admin.index', [
            'users' => $users,
            'recentSyncs' => $recentSyncs,
            'recentAudits' => $recentAudits,
            'sheetsConfigured' => $sheetsConfigured,
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

        try {
            $token = $this->sheetsService->getAccessTokenFromServiceAccount($serviceAccountPath);

            $sheetsData = [];
            foreach (GoogleSheetsReaderService::ALLOWED_SHEETS as $sheetName) {
                $sheetsData[$sheetName] = $this->sheetsService->fetchSheetValues($token, $spreadsheetId, $sheetName);
            }

            $syncRun = $this->sheetsService->syncDataset($sheetsData);

            return redirect()->route('admin.index')->with('status', "Sincronización completada. Leídos: {$syncRun->rows_read}, Guardados: {$syncRun->rows_saved}, Rechazados: {$syncRun->rows_rejected}");
        } catch (Throwable $e) {
            return back()->withErrors(['error' => 'Error durante la sincronización: ' . $e->getMessage()]);
        }
    }
}
