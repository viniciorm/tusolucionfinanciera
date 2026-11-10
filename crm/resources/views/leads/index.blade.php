@extends('layouts.app')

@section('title', 'Mis Leads')
@section('crumb', 'Listado de Prospectos')

@section('content')
<div class="heading">
    <div>
        <h1>Mis leads</h1>
        <p>Prospectos disponibles en tu alcance comercial con búsqueda y filtros avanzados.</p>
    </div>
</div>

<div class="panel">
    <form method="GET" action="{{ route('leads.index') }}" class="filters">
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="⌕  Buscar por nombre, correo o teléfono..." style="min-width: 260px; flex: 1;">
        
        <select name="status">
            <option value="">Todos los estados comerciales</option>
            @foreach($statuses as $st)
                <option value="{{ $st }}" {{ ($filters['status'] ?? '') === $st ? 'selected' : '' }}>{{ $st }}</option>
            @endforeach
        </select>

        @if($sheets->isNotEmpty())
        <select name="sheet">
            <option value="">Todas las hojas</option>
            @foreach($sheets as $sh)
                <option value="{{ $sh }}" {{ ($filters['sheet'] ?? '') === $sh ? 'selected' : '' }}>{{ $sh }}</option>
            @endforeach
        </select>
        @endif

        @if($campaigns->isNotEmpty())
        <select name="campaign">
            <option value="">Todas las campañas</option>
            @foreach($campaigns as $camp)
                <option value="{{ $camp }}" {{ ($filters['campaign'] ?? '') === $camp ? 'selected' : '' }}>{{ $camp }}</option>
            @endforeach
        </select>
        @endif

        <input type="date" name="from_date" value="{{ $filters['from_date'] ?? '' }}" title="Desde fecha">
        <input type="date" name="to_date" value="{{ $filters['to_date'] ?? '' }}" title="Hasta fecha">

        <button type="submit" class="btn btn-primary">Filtrar</button>
        @if(!empty(array_filter($filters)))
            <a href="{{ route('leads.index') }}" class="btn">Limpiar</a>
        @endif
    </form>

    @if($leads->isEmpty())
        <div style="text-align: center; padding: 48px; color: var(--muted);">
            <div style="font-size: 32px; margin-bottom: 8px;">♧</div>
            <b>No se encontraron prospectos</b>
            <p style="font-size: 13px; margin-top: 4px;">No hay leads que coincidan con los filtros aplicados o pendientes de sincronización.</p>
        </div>
    @else
        <table>
            <thead>
                <tr>
                    <th>Prospecto</th>
                    <th>Contacto</th>
                    <th>Origen</th>
                    <th>Estado Comercial</th>
                    <th>Asignación</th>
                    <th>Recepción</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($leads as $lead)
                <tr>
                    <td>
                        <a href="{{ route('leads.show', $lead->id) }}" style="font-weight: 700; color: var(--ink); text-decoration: none; font-size: 14px;">
                            {{ $lead->name ?: 'Sin nombre registrado' }}
                        </a>
                        <div style="font-size: 11px; color: var(--muted);">ID Meta: {{ $lead->meta_id }}</div>
                    </td>
                    <td>
                        <div>{{ $lead->phone ?: '—' }}</div>
                        <div style="font-size: 11px; color: var(--muted);">{{ $lead->email ?: '—' }}</div>
                    </td>
                    <td>
                        <div><b>{{ $lead->source_sheet }}</b></div>
                        <div style="font-size: 11px; color: var(--muted);">{{ $lead->campaign ?: ($lead->form ?: 'General') }}</div>
                    </td>
                    <td>
                        @php
                            $currentStatus = $lead->management?->status ?? 'Nuevo';
                            $statusClass = 'status-' . str_replace(' ', '-', $currentStatus);
                        @endphp
                        <span class="status-badge {{ $statusClass }}">
                            ● {{ $currentStatus }}
                        </span>
                    </td>
                    <td>
                        @if($lead->management?->assignedUser)
                            <span style="font-weight: 500;">{{ $lead->management->assignedUser->name ?: $lead->management->assignedUser->email }}</span>
                        @else
                            <span style="color: #94a3b8; font-style: italic;">Sin asignar</span>
                        @endif
                    </td>
                    <td>
                        <span style="font-size: 12px; color: #475569;">
                            {{ $lead->received_at ? $lead->received_at->format('d/m/Y H:i') : ($lead->received_at_raw ?: '—') }}
                        </span>
                    </td>
                    <td style="text-align: right;">
                        <a href="{{ route('leads.show', $lead->id) }}" class="btn btn-sm">Abrir ficha →</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div style="margin-top: 16px;">
            {{ $leads->links() }}
        </div>
    @endif
</div>
@endsection
