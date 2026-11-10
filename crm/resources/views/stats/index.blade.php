@extends('layouts.app')

@section('title', 'Estadísticas')
@section('crumb', 'Rendimiento Comercial')

@section('content')
<div class="heading">
    <div>
        <h1>Estadísticas comerciales</h1>
        <p>Métricas consolidadas calculadas exclusivamente sobre datos reales del CRM.</p>
    </div>
    <form method="GET" action="{{ route('stats.index') }}">
        <select name="period" onchange="this.form.submit()">
            <option value="all" {{ $period === 'all' ? 'selected' : '' }}>Histórico completo</option>
            <option value="month" {{ $period === 'month' ? 'selected' : '' }}>Este mes</option>
            <option value="week" {{ $period === 'week' ? 'selected' : '' }}>Esta semana</option>
        </select>
    </form>
</div>

<div class="metrics">
    <div class="metric">
        <div class="metric-head">Leads recibidos <span>♧</span></div>
        <strong>{{ number_format($totalReceived) }}</strong>
        <small>Ingresados desde Sheets</small>
    </div>
    <div class="metric">
        <div class="metric-head">Con estado notificación <span>◔</span></div>
        <strong>{{ number_format($withNotificationStatus) }}</strong>
        <small>Procesados por Apps Script</small>
    </div>
    <div class="metric">
        <div class="metric-head">Leads asignados <span>👤</span></div>
        <strong>{{ number_format($assignedCount) }}</strong>
        <small>Con asesor asignado</small>
    </div>
    <div class="metric">
        <div class="metric-head">Gestiones realizadas <span>☎</span></div>
        <strong>{{ number_format($activitiesCount) }}</strong>
        <small>Llamadas, notas y reuniones</small>
    </div>
    <div class="metric">
        <div class="metric-head">Conversiones <span>✓</span></div>
        <strong>{{ number_format($convertedCount) }}</strong>
        <small>En estado 'Convertido'</small>
    </div>
    <div class="metric">
        <div class="metric-head">Tasa de conversión <span>↗</span></div>
        <strong>{{ $conversionRate }}%</strong>
        <small>Convertidos / Recibidos</small>
    </div>
</div>

<div class="panel">
    <div class="panelhead">
        <div>
            <h2>Distribución por campaña</h2>
            <p>Volumen de prospectos según el origen publicitario</p>
        </div>
    </div>

    @if(empty($byCampaign))
        <div style="text-align: center; padding: 32px; color: var(--muted);">
            <b>Sin datos de campaña disponibles</b>
        </div>
    @else
        <table>
            <thead>
                <tr>
                    <th>Campaña</th>
                    <th>Volumen de leads</th>
                    <th>Participación</th>
                </tr>
            </thead>
            <tbody>
                @foreach($byCampaign as $camp => $cnt)
                <tr>
                    <td><b>{{ $camp }}</b></td>
                    <td>{{ number_format($cnt) }}</td>
                    <td>
                        @php $pct = $totalReceived > 0 ? round(($cnt / $totalReceived) * 100, 1) : 0; @endphp
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="width: 100px; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                                <div style="width: {{ $pct }}%; height: 100%; background: var(--gold);"></div>
                            </div>
                            <span>{{ $pct }}%</span>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
