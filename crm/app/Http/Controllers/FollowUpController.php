<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\FollowUp;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use TSF\Security\LeadAccess;

class FollowUpController extends Controller
{
    public function index(Request $request): View
    {
        $user = Auth::user();
        $tab = $request->query('tab', 'today');

        $query = FollowUp::whereHas('lead', function ($q) use ($user) {
            $q->forUser($user);
        })->with(['lead', 'assignedUser']);

        if ($user->isAdvisor()) {
            $query->where('assigned_user_id', $user->id);
        }

        $now = now();
        $todayStart = $now->copy()->startOfDay();
        $todayEnd = $now->copy()->endOfDay();

        match ($tab) {
            'upcoming' => $query->pending()->where('due_at', '>', $todayEnd)->orderBy('due_at', 'asc'),
            'overdue' => $query->pending()->where('due_at', '<', $todayStart)->orderBy('due_at', 'asc'),
            'completed' => $query->completed()->latest('completed_at'),
            default => $query->pending()->whereBetween('due_at', [$todayStart, $todayEnd])->orderBy('due_at', 'asc'),
        };

        $followUps = $query->paginate(20)->withQueryString();

        return view('follow_ups.index', [
            'followUps' => $followUps,
            'currentTab' => $tab,
        ]);
    }

    public function store(Request $request, int $leadId): RedirectResponse
    {
        $user = Auth::user();
        $lead = Lead::with('management')->findOrFail($leadId);

        $assignedUserId = $lead->management?->assigned_user_id;
        if (!LeadAccess::allowed($user->active, $user->role, $user->id, $assignedUserId)) {
            abort(403, 'No tienes autorización para agendar seguimientos sobre este prospecto.');
        }

        $validated = $request->validate([
            'due_at' => ['required', 'date', 'after:now'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $targetUserId = $assignedUserId ?: $user->id;

        $followUp = FollowUp::create([
            'lead_id' => $lead->id,
            'assigned_user_id' => $targetUserId,
            'due_at' => Carbon::parse($validated['due_at']),
            'note' => isset($validated['note']) ? trim($validated['note']) : null,
        ]);

        AuditEvent::log('crm.follow_up_created', $user->id, $lead->id, [
            'follow_up_id' => $followUp->id,
            'due_at' => $followUp->due_at->toIso8601String(),
        ]);

        return redirect()->route('leads.show', $lead->id)->with('status', 'Seguimiento agendado con éxito.');
    }

    public function complete(Request $request, int $id): RedirectResponse
    {
        $user = Auth::user();
        $followUp = FollowUp::with('lead.management')->findOrFail($id);

        $assignedUserId = $followUp->lead->management?->assigned_user_id;
        if (!LeadAccess::allowed($user->active, $user->role, $user->id, $assignedUserId)) {
            abort(403, 'No tienes autorización para completar este seguimiento.');
        }

        $followUp->completed_at = now();
        $followUp->save();

        AuditEvent::log('crm.follow_up_completed', $user->id, $followUp->lead_id, [
            'follow_up_id' => $followUp->id,
        ]);

        return back()->with('status', 'Seguimiento marcado como completado.');
    }
}
