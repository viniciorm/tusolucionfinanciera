<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadManagement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = Auth::user();

        // Query base para leads autorizados
        $leadsQuery = Lead::forUser($user);

        $totalLeads = (clone $leadsQuery)->count();

        // Métricas por estado comercial
        $statusCounts = (clone $leadsQuery)
            ->join('lead_management', 'leads.id', '=', 'lead_management.lead_id')
            ->selectRaw('lead_management.status, count(*) as count')
            ->groupBy('lead_management.status')
            ->pluck('count', 'status')
            ->toArray();

        // Seguimientos
        $followUpsBase = FollowUp::whereHas('lead', function ($q) use ($user) {
            $q->forUser($user);
        });

        if ($user->isAdvisor()) {
            $followUpsBase->where('assigned_user_id', $user->id);
        }

        $pendingFollowUps = (clone $followUpsBase)->pending()->count();
        $overdueFollowUps = (clone $followUpsBase)->pending()->where('due_at', '<', now())->count();

        $upcomingFollowUps = (clone $followUpsBase)
            ->with(['lead', 'assignedUser'])
            ->pending()
            ->orderBy('due_at', 'asc')
            ->limit(5)
            ->get();

        // Actividades recientes
        $recentActivities = Activity::whereHas('lead', function ($q) use ($user) {
            $q->forUser($user);
        })
            ->with(['lead', 'actor'])
            ->latest()
            ->limit(8)
            ->get();

        return view('dashboard.index', [
            'user' => $user,
            'totalLeads' => $totalLeads,
            'statusCounts' => $statusCounts,
            'pendingFollowUps' => $pendingFollowUps,
            'overdueFollowUps' => $overdueFollowUps,
            'upcomingFollowUps' => $upcomingFollowUps,
            'recentActivities' => $recentActivities,
        ]);
    }
}
