<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadManagement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use TSF\Security\LeadAccess;

class LeadController extends Controller
{
    public function index(Request $request): View
    {
        $user = Auth::user();
        $query = Lead::forUser($user)->with(['management.assignedUser']);

        // Búsqueda por texto (nombre, correo, teléfono)
        if ($search = trim((string) $request->query('q', ''))) {
            $digits = preg_replace('/\D+/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
                if ($digits !== '') {
                    $q->orWhere('phone_search', 'like', "%{$digits}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                }
            });
        }

        // Filtro por estado comercial
        if ($status = $request->query('status')) {
            $query->whereHas('management', function ($q) use ($status) {
                $q->where('status', $status);
            });
        }

        // Filtro por hoja de origen
        if ($sheet = $request->query('sheet')) {
            $query->where('source_sheet', $sheet);
        }

        // Filtro por campaña
        if ($campaign = $request->query('campaign')) {
            $query->where('campaign', $campaign);
        }

        // Filtro por formulario
        if ($form = $request->query('form')) {
            $query->where('form', $form);
        }

        // Filtro por fecha de recepción
        if ($fromDate = $request->query('from_date')) {
            $query->whereDate('received_at', '>=', $fromDate);
        }
        if ($toDate = $request->query('to_date')) {
            $query->whereDate('received_at', '<=', $toDate);
        }

        $leads = $query->latest('received_at')->paginate(20)->withQueryString();

        // Opciones para filtros
        $sheets = Lead::forUser($user)->distinct()->pluck('source_sheet')->filter()->values();
        $campaigns = Lead::forUser($user)->distinct()->pluck('campaign')->filter()->values();
        $forms = Lead::forUser($user)->distinct()->pluck('form')->filter()->values();

        return view('leads.index', [
            'leads' => $leads,
            'sheets' => $sheets,
            'campaigns' => $campaigns,
            'forms' => $forms,
            'statuses' => LeadManagement::VALID_STATUSES,
            'filters' => $request->all(),
        ]);
    }

    public function show(int $id): View
    {
        $user = Auth::user();
        $lead = Lead::with(['management.assignedUser', 'activities.actor', 'followUps.assignedUser'])->findOrFail($id);

        $assignedUserId = $lead->management?->assigned_user_id;

        // Comprobación estricta de autorización
        if (!LeadAccess::allowed($user->active, $user->role, $user->id, $assignedUserId)) {
            abort(403, 'No tienes autorización para consultar la ficha de este prospecto.');
        }

        $advisors = $user->isAdmin()
            ? User::where('active', true)->where('role', 'advisor')->orderBy('name')->get()
            : collect();

        return view('leads.show', [
            'lead' => $lead,
            'user' => $user,
            'advisors' => $advisors,
            'statuses' => LeadManagement::VALID_STATUSES,
        ]);
    }
}
