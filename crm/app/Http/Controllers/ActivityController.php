<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\AuditEvent;
use App\Models\Lead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use TSF\Security\LeadAccess;

class ActivityController extends Controller
{
    public function store(Request $request, int $leadId): RedirectResponse
    {
        $user = Auth::user();
        $lead = Lead::with('management')->findOrFail($leadId);

        $assignedUserId = $lead->management?->assigned_user_id;
        if (!LeadAccess::allowed($user->active, $user->role, $user->id, $assignedUserId)) {
            abort(403, 'No tienes autorización para registrar gestiones sobre este prospecto.');
        }

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in([
                Activity::TYPE_NOTE,
                Activity::TYPE_CALL,
                Activity::TYPE_MEETING,
                Activity::TYPE_OTHER,
            ])],
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $activity = Activity::create([
            'lead_id' => $lead->id,
            'actor_user_id' => $user->id,
            'type' => $validated['type'],
            'note' => trim($validated['note']),
        ]);

        AuditEvent::log('crm.activity_added', $user->id, $lead->id, [
            'activity_id' => $activity->id,
            'type' => $activity->type,
        ]);

        return redirect()->route('leads.show', $lead->id)->with('status', 'Gestión registrada correctamente.');
    }
}
