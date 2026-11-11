<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\LeadManagement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class StatsController extends Controller
{
    public function index(Request $request): View
    {
        $user = Auth::user();
        $period = $request->query('period', 'all');

        $leadsQuery = Lead::forUser($user);

        if ($period === 'month') {
            $leadsQuery->where('received_at', '>=', now()->startOfMonth());
        } elseif ($period === 'week') {
            $leadsQuery->where('received_at', '>=', now()->startOfWeek());
        }

        $totalReceived = (clone $leadsQuery)->count();
        $withNotificationStatus = (clone $leadsQuery)->whereNotNull('notification_status')->count();
        $assignedCount = (clone $leadsQuery)->whereHas('management', function ($q) {
            $q->whereNotNull('assigned_user_id');
        })->count();

        $convertedCount = (clone $leadsQuery)->whereHas('management', function ($q) {
            $q->where('status', LeadManagement::STATUS_CONVERTIDO);
        })->count();

        // Gestiones realizadas
        $activitiesQuery = Activity::whereHas('lead', function ($q) use ($user) {
            $q->forUser($user);
        });

        if ($period === 'month') {
            $activitiesQuery->where('created_at', '>=', now()->startOfMonth());
        } elseif ($period === 'week') {
            $activitiesQuery->where('created_at', '>=', now()->startOfWeek());
        }

        $activitiesCount = $activitiesQuery->count();

        // Leads por campaña
        $byCampaign = (clone $leadsQuery)
            ->whereNotNull('campaign')
            ->selectRaw('campaign, count(*) as count')
            ->groupBy('campaign')
            ->orderByDesc('count')
            ->pluck('count', 'campaign')
            ->toArray();

        // Tasa de conversión real calculada
        $conversionRate = $totalReceived > 0 ? round(($convertedCount / $totalReceived) * 100, 1) : 0;

        return view('stats.index', [
            'period' => $period,
            'totalReceived' => $totalReceived,
            'withNotificationStatus' => $withNotificationStatus,
            'assignedCount' => $assignedCount,
            'convertedCount' => $convertedCount,
            'activitiesCount' => $activitiesCount,
            'conversionRate' => $conversionRate,
            'byCampaign' => $byCampaign,
        ]);
    }
}
