<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadManagement;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use TSF\Security\LeadAccess;

class LeadManagementController extends Controller
{
    public function updateStatus(Request $request, int $id): RedirectResponse
    {
        $user = Auth::user();
        $lead = Lead::with('management')->findOrFail($id);

        $assignedUserId = $lead->management?->assigned_user_id;
        if (!LeadAccess::allowed($user->active, $user->role, $user->id, $assignedUserId)) {
            abort(403, 'No tienes autorización para modificar este prospecto.');
        }

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(LeadManagement::VALID_STATUSES)],
        ]);

        $oldStatus = $lead->management?->status ?? 'Nuevo';
        $newStatus = $validated['status'];

        if ($oldStatus !== $newStatus) {
            DB::transaction(function () use ($lead, $user, $oldStatus, $newStatus) {
                $management = $lead->management ?: new LeadManagement(['lead_id' => $lead->id]);
                $management->status = $newStatus;
                $management->save();

                Activity::create([
                    'lead_id' => $lead->id,
                    'actor_user_id' => $user->id,
                    'type' => Activity::TYPE_STATUS_CHANGE,
                    'note' => "Estado comercial actualizado de '$oldStatus' a '$newStatus'.",
                ]);

                AuditEvent::log('crm.status_changed', $user->id, $lead->id, [
                    'from' => $oldStatus,
                    'to' => $newStatus,
                ]);
            });
        }

        return redirect()->route('leads.show', $lead->id)->with('status', 'Estado comercial actualizado con éxito.');
    }

    public function assignAdvisor(Request $request, int $id): RedirectResponse
    {
        $user = Auth::user();
        if (!$user->isAdmin()) {
            abort(403, 'Solo el administrador puede reasignar prospectos.');
        }

        $lead = Lead::with('management')->findOrFail($id);

        $validated = $request->validate([
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $newAdvisorId = $validated['assigned_user_id'] ? (int) $validated['assigned_user_id'] : null;
        $newAdvisor = $newAdvisorId ? User::find($newAdvisorId) : null;

        DB::transaction(function () use ($lead, $user, $newAdvisorId, $newAdvisor) {
            $management = $lead->management ?: new LeadManagement(['lead_id' => $lead->id]);
            $oldAdvisorId = $management->assigned_user_id;
            $management->assigned_user_id = $newAdvisorId;
            $management->save();

            $advisorName = $newAdvisor ? $newAdvisor->name : 'Sin asignar';

            Activity::create([
                'lead_id' => $lead->id,
                'actor_user_id' => $user->id,
                'type' => Activity::TYPE_NOTE,
                'note' => "Asignación comercial actualizada a: {$advisorName}.",
            ]);

            AuditEvent::log('crm.lead_reassigned', $user->id, $lead->id, [
                'from_user_id' => $oldAdvisorId,
                'to_user_id' => $newAdvisorId,
            ]);
        });

        return redirect()->route('leads.show', $lead->id)->with('status', 'Asignación comercial actualizada.');
    }
}
