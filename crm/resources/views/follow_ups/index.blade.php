@extends('layouts.app')

@section('title', 'Seguimientos')
@section('crumb', 'Agenda Comercial')

@section('content')
<div class="heading">
    <div>
        <h1>Agenda de seguimientos</h1>
        <p>Organiza tus próximos contactos comerciales y no pierdas oportunidades.</p>
    </div>
</div>

<div class="panel">
    <div style="display: flex; gap: 8px; border-bottom: 1px solid var(--line); margin-bottom: 16px;">
        <a href="{{ route('follow_ups.index', ['tab' => 'today']) }}" class="btn {{ $currentTab === 'today' ? 'btn-primary' : '' }}" style="border-radius: 8px 8px 0 0; border-bottom: none;">Para Hoy</a>
        <a href="{{ route('follow_ups.index', ['tab' => 'upcoming']) }}" class="btn {{ $currentTab === 'upcoming' ? 'btn-primary' : '' }}" style="border-radius: 8px 8px 0 0; border-bottom: none;">Próximos</a>
        <a href="{{ route('follow_ups.index', ['tab' => 'overdue']) }}" class="btn {{ $currentTab === 'overdue' ? 'btn-primary' : '' }}" style="border-radius: 8px 8px 0 0; border-bottom: none;">Vencidos</a>
        <a href="{{ route('follow_ups.index', ['tab' => 'completed']) }}" class="btn {{ $currentTab === 'completed' ? 'btn-primary' : '' }}" style="border-radius: 8px 8px 0 0; border-bottom: none;">Completados</a>
    </div>

    @if($followUps->isEmpty())
        <div style="text-align: center; padding: 48px; color: var(--muted);">
            <div style="font-size: 32px; margin-bottom: 8px;">◷</div>
            <b>Sin seguimientos en esta sección</b>
            <p style="font-size: 13px; margin-top: 4px;">Puedes programar un seguimiento desde la ficha del prospecto.</p>
        </div>
    @else
        <table>
            <thead>
                <tr>
                    <th>Prospecto</th>
                    <th>Fecha y hora</th>
                    <th>Nota / Compromiso</th>
                    <th>Asesor asignado</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                @foreach($followUps as $fu)
                <tr>
                    <td>
                        <a href="{{ route('leads.show', $fu->lead_id) }}" style="font-weight: 700; color: var(--royal); text-decoration: none;">
                            {{ $fu->lead->name ?: 'Prospecto #' . $fu->lead_id }}
                        </a>
                        <div style="font-size: 11px; color: var(--muted);">{{ $fu->lead->phone ?: $fu->lead->email }}</div>
                    </td>
                    <td>
                        <span style="font-weight: 600; color: {{ $fu->isOverdue() ? 'var(--danger)' : 'inherit' }}">
                            {{ $fu->due_at->format('d/m/Y H:i') }}
                        </span>
                        @if($fu->isOverdue())
                            <div style="font-size: 10px; color: var(--danger); font-weight: 700;">VENCIDO</div>
                        @elseif($fu->isCompleted())
                            <div style="font-size: 10px; color: var(--success); font-weight: 700;">Completado: {{ $fu->completed_at->format('d/m/Y H:i') }}</div>
                        @endif
                    </td>
                    <td>{{ $fu->note ?: '—' }}</td>
                    <td>{{ $fu->assignedUser->name ?: $fu->assignedUser->email }}</td>
                    <td>
                        @if($fu->isPending())
                            <form method="POST" action="{{ route('follow_ups.complete', $fu->id) }}" style="display:inline;">
                                @csrf
                                <button type="submit" class="btn btn-sm">✓ Marcar hecho</button>
                            </form>
                        @else
                            <span style="color: var(--success); font-size: 12px; font-weight: 600;">✓ Finalizado</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div style="margin-top: 16px;">
            {{ $followUps->links() }}
        </div>
    @endif
</div>
@endsection
