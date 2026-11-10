@extends('layouts.app')

@section('title', 'Resumen')
@section('crumb', 'Tu espacio de trabajo')

@section('content')
<div class="heading">
    <div>
        <h1>Hola, {{ $user->name ?: 'Asesor' }}</h1>
        <p>Resumen actualizado de tu gestión comercial y próximos pasos.</p>
    </div>
    <div>
        <a href="{{ route('leads.index') }}" class="btn btn-primary">Ver mis leads →</a>
    </div>
</div>

<div class="metrics">
    <div class="metric">
        <div class="metric-head">Leads autorizados <span>♧</span></div>
        <strong>{{ number_format($totalLeads) }}</strong>
        <small>Total en tu alcance</small>
    </div>
    <div class="metric">
        <div class="metric-head">Nuevos sin gestionar <span>✧</span></div>
        <strong>{{ number_format($statusCounts['Nuevo'] ?? 0) }}</strong>
        <small>Pendientes de primer contacto</small>
    </div>
    <div class="metric">
        <div class="metric-head">Contactados <span>☎</span></div>
        <strong>{{ number_format($statusCounts['Contactado'] ?? 0) }}</strong>
        <small>En proceso inicial</small>
    </div>
    <div class="metric">
        <div class="metric-head">En seguimiento <span>◷</span></div>
        <strong>{{ number_format($statusCounts['En seguimiento'] ?? 0) }}</strong>
        <small>Con gestión activa</small>
    </div>
    <div class="metric">
        <div class="metric-head">Reuniones agendadas <span>▦</span></div>
        <strong>{{ number_format($statusCounts['Reunión agendada'] ?? 0) }}</strong>
        <small>Citas coordinadas</small>
    </div>
    <div class="metric">
        <div class="metric-head">Convertidos <span>✓</span></div>
        <strong>{{ number_format($statusCounts['Convertido'] ?? 0) }}</strong>
        <small>Cierres exitosos</small>
    </div>
    <div class="metric">
        <div class="metric-head">Seguimientos pendientes <span>◌</span></div>
        <strong>{{ number_format($pendingFollowUps) }}</strong>
        <small>En agenda</small>
    </div>
    <div class="metric">
        <div class="metric-head">Seguimientos vencidos <span style="color:var(--danger)">!</span></div>
        <strong style="color: {{ $overdueFollowUps > 0 ? 'var(--danger)' : 'inherit' }}">{{ number_format($overdueFollowUps) }}</strong>
        <small>Requieren atención inmediata</small>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px;">
    <!-- Próximos seguimientos -->
    <div class="panel">
        <div class="panelhead">
            <div>
                <h2>Próximos seguimientos</h2>
                <p>Gestiones programadas en tu agenda</p>
            </div>
            <a href="{{ route('follow_ups.index') }}" class="btn btn-sm">Ver agenda completa →</a>
        </div>

        @if($upcomingFollowUps->isEmpty())
            <div style="text-align: center; padding: 30px; color: var(--muted);">
                <div style="font-size: 24px; margin-bottom: 8px;">✓</div>
                <b>No hay seguimientos pendientes</b>
                <p style="font-size: 12px; margin-top: 4px;">Puedes programar un seguimiento desde la ficha de cualquier prospecto.</p>
            </div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Prospecto</th>
                        <th>Fecha programada</th>
                        <th>Nota</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($upcomingFollowUps as $fu)
                    <tr>
                        <td>
                            <a href="{{ route('leads.show', $fu->lead_id) }}" style="font-weight: 600; color: var(--royal); text-decoration: none;">
                                {{ $fu->lead->name ?: 'Sin nombre' }}
                            </a>
                            <div style="font-size: 11px; color: var(--muted);">{{ $fu->lead->phone ?: $fu->lead->email }}</div>
                        </td>
                        <td>
                            <span style="font-weight: 600; color: {{ $fu->isOverdue() ? 'var(--danger)' : 'inherit' }}">
                                {{ $fu->due_at->format('d/m/Y H:i') }}
                            </span>
                            @if($fu->isOverdue())
                                <div style="font-size: 10px; color: var(--danger); font-weight: 700;">VENCIDO</div>
                            @endif
                        </td>
                        <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            {{ $fu->note ?: '—' }}
                        </td>
                        <td>
                            <form method="POST" action="{{ route('follow_ups.complete', $fu->id) }}" style="display:inline;">
                                @csrf
                                <button type="submit" class="btn btn-sm" title="Marcar completado">✓ Hecho</button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <!-- Actividad comercial reciente -->
    <div class="panel">
        <div class="panelhead">
            <div>
                <h2>Actividad reciente</h2>
                <p>Últimas gestiones y cambios registrados</p>
            </div>
        </div>

        @if($recentActivities->isEmpty())
            <div style="text-align: center; padding: 30px; color: var(--muted);">
                <div style="font-size: 24px; margin-bottom: 8px;">⌁</div>
                <b>Sin actividad registrada aún</b>
                <p style="font-size: 12px; margin-top: 4px;">Las notas, llamadas y cambios de estado aparecerán aquí.</p>
            </div>
        @else
            <div style="display: flex; flex-direction: column; gap: 12px;">
                @foreach($recentActivities as $act)
                <div style="display: flex; gap: 12px; padding: 10px; border-bottom: 1px solid var(--line); font-size: 12px;">
                    <div style="font-size: 16px;">
                        @if($act->type === 'call') ☎
                        @elseif($act->type === 'status_change') ⇄
                        @elseif($act->type === 'meeting') ▦
                        @else ✎
                        @endif
                    </div>
                    <div style="flex: 1;">
                        <div>
                            <b>{{ $act->actor->name ?: $act->actor->email }}</b> sobre
                            <a href="{{ route('leads.show', $act->lead_id) }}" style="color: var(--royal); font-weight: 600; text-decoration: none;">
                                {{ $act->lead->name ?: 'Prospecto #' . $act->lead_id }}
                            </a>
                        </div>
                        <div style="color: #334155; margin-top: 2px;">{{ $act->note }}</div>
                        <div style="color: #94a3b8; font-size: 10px; margin-top: 2px;">{{ $act->created_at->diffForHumans() }}</div>
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
