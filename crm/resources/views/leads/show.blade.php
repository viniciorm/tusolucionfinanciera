@extends('layouts.app')

@section('title', $lead->name ?: 'Ficha de prospecto')
@section('crumb', 'Ficha de prospecto #' . $lead->id)

@section('content')
<div class="heading">
    <div>
        <div style="margin-bottom: 6px;">
            <a href="{{ route('leads.index') }}" style="color: var(--royal); text-decoration: none; font-size: 13px; font-weight: 500;">← Volver al listado</a>
        </div>
        <h1>{{ $lead->name ?: 'Prospecto sin nombre' }}</h1>
        <p>Origen: {{ $lead->source_sheet }} · ID Meta: <code>{{ $lead->meta_id }}</code></p>
    </div>
    <div style="display: flex; gap: 8px;">
        @if($lead->phone)
            <a href="tel:{{ $lead->phone }}" class="btn">☎ Llamar</a>
            <a href="https://wa.me/{{ $lead->phone_search }}" target="_blank" rel="noopener" class="btn btn-primary">WhatsApp</a>
        @endif
    </div>
</div>

<div style="display: grid; grid-template-columns: 1.6fr 1fr; gap: 20px;">
    <!-- Columna Izquierda: Datos, Respuestas y Gestión -->
    <div>
        <!-- Información de contacto y origen -->
        <div class="panel">
            <div class="panelhead">
                <h2>Datos del prospecto</h2>
                <span class="badge">Recepción: {{ $lead->received_at ? $lead->received_at->format('d/m/Y H:i') : ($lead->received_at_raw ?: '—') }}</span>
            </div>

            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; font-size: 13px;">
                <div>
                    <span style="color: var(--muted); font-size: 11px; text-transform: uppercase;">Nombre completo</span>
                    <div style="font-weight: 600; font-size: 14px;">{{ $lead->name ?: '—' }}</div>
                </div>
                <div>
                    <span style="color: var(--muted); font-size: 11px; text-transform: uppercase;">Teléfono</span>
                    <div style="font-weight: 600; font-size: 14px;">{{ $lead->phone ?: '—' }}</div>
                </div>
                <div>
                    <span style="color: var(--muted); font-size: 11px; text-transform: uppercase;">Correo electrónico</span>
                    <div style="font-weight: 600; font-size: 14px;">{{ $lead->email ?: '—' }}</div>
                </div>
                <div>
                    <span style="color: var(--muted); font-size: 11px; text-transform: uppercase;">Campaña / Formulario</span>
                    <div style="font-weight: 600;">{{ $lead->campaign ?: '—' }} / {{ $lead->form ?: '—' }}</div>
                </div>
                <div>
                    <span style="color: var(--muted); font-size: 11px; text-transform: uppercase;">Estado notificación (Sheets)</span>
                    <div>{{ $lead->notification_status ?: 'Sin estado de notificación' }}</div>
                </div>
                <div>
                    <span style="color: var(--muted); font-size: 11px; text-transform: uppercase;">Asignación de origen</span>
                    <div>{{ $lead->source_assigned_to ?: 'Sin asignación original' }}</div>
                </div>
            </div>
        </div>

        <!-- Respuestas originales del formulario Meta -->
        <div class="panel">
            <div class="panelhead">
                <h2>Respuestas del formulario Meta</h2>
                <small style="color: var(--muted);">Datos íntegros sin alterar</small>
            </div>

            @if(empty($lead->original))
                <p style="color: var(--muted); font-size: 13px;">No hay respuestas originales registradas para este prospecto.</p>
            @else
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    @foreach($lead->original as $header => $val)
                    <div style="padding: 8px 12px; background: #f8fafc; border: 1px solid var(--line); border-radius: 6px; display: flex; justify-content: space-between; align-items: center; gap: 12px; font-size: 12px;">
                        <b style="color: #475569; max-width: 40%;">{{ $header }}</b>
                        <span style="color: #1e293b; text-align: right; word-break: break-word;">{{ $val !== '' ? $val : '—' }}</span>
                    </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Registro de nueva gestión comercial -->
        <div class="panel">
            <div class="panelhead">
                <h2>Registrar gestión comercial</h2>
                <small style="color: var(--muted);">Notas de llamada, seguimiento o reuniones</small>
            </div>

            <form method="POST" action="{{ route('activities.store', $lead->id) }}">
                @csrf
                <div style="display: flex; gap: 10px; margin-bottom: 12px;">
                    <select name="type" required style="width: 160px;">
                        <option value="call">☎ Llamada</option>
                        <option value="note">✎ Nota interna</option>
                        <option value="meeting">▦ Reunión</option>
                        <option value="other">Otro</option>
                    </select>
                </div>
                <div style="margin-bottom: 12px;">
                    <textarea name="note" rows="3" required placeholder="Describe el resultado del contacto o acuerdos con el cliente..." style="width: 100%;"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Guardar gestión</button>
            </form>
        </div>
    </div>

    <!-- Columna Derecha: Control de Estado, Asignación y Agenda -->
    <div>
        <!-- Estado comercial CRM -->
        <div class="panel">
            <div class="panelhead">
                <h2>Estado comercial</h2>
            </div>
            @php
                $currentStatus = $lead->management?->status ?? 'Nuevo';
                $statusClass = 'status-' . str_replace(' ', '-', $currentStatus);
            @endphp
            <div style="margin-bottom: 14px;">
                <span class="status-badge {{ $statusClass }}" style="font-size: 13px; padding: 6px 12px;">
                    ● {{ $currentStatus }}
                </span>
            </div>

            <form method="POST" action="{{ route('leads.status', $lead->id) }}">
                @csrf
                <label style="display: block; font-size: 11px; color: var(--muted); margin-bottom: 4px; font-weight: 600;">CAMBIAR ESTADO</label>
                <div style="display: flex; gap: 8px;">
                    <select name="status" style="flex: 1;" required>
                        @foreach($statuses as $st)
                            <option value="{{ $st }}" {{ $currentStatus === $st ? 'selected' : '' }}>{{ $st }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-primary">Actualizar</button>
                </div>
            </form>
        </div>

        @if($user->isAdmin())
        <!-- Reasignación de asesor (Admin) -->
        <div class="panel">
            <div class="panelhead">
                <h2>Asignación comercial</h2>
            </div>
            <form method="POST" action="{{ route('leads.assign', $lead->id) }}">
                @csrf
                <label style="display: block; font-size: 11px; color: var(--muted); margin-bottom: 4px; font-weight: 600;">ASESOR ASIGNADO</label>
                <div style="display: flex; gap: 8px;">
                    <select name="assigned_user_id" style="flex: 1;">
                        <option value="">-- Sin asignar --</option>
                        @foreach($advisors as $adv)
                            <option value="{{ $adv->id }}" {{ $lead->management?->assigned_user_id === $adv->id ? 'selected' : '' }}>
                                {{ $adv->name ?: $adv->email }}
                            </option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn">Guardar</button>
                </div>
            </form>
        </div>
        @endif

        <!-- Agendar seguimiento -->
        <div class="panel">
            <div class="panelhead">
                <h2>Agendar seguimiento</h2>
            </div>
            <form method="POST" action="{{ route('follow_ups.store', $lead->id) }}">
                @csrf
                <div style="margin-bottom: 10px;">
                    <label style="display: block; font-size: 11px; color: var(--muted); margin-bottom: 4px; font-weight: 600;">FECHA Y HORA</label>
                    <input type="datetime-local" name="due_at" required style="width: 100%;">
                </div>
                <div style="margin-bottom: 10px;">
                    <label style="display: block; font-size: 11px; color: var(--muted); margin-bottom: 4px; font-weight: 600;">NOTA RECORDATORIA</label>
                    <input type="text" name="note" placeholder="Ej: Llamar para revisar propuesta APV" style="width: 100%;">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">Programar seguimiento</button>
            </form>
        </div>

        <!-- Historial y Timeline -->
        <div class="panel">
            <div class="panelhead">
                <h2>Historial de gestiones</h2>
            </div>

            @if($lead->activities->isEmpty())
                <p style="color: var(--muted); font-size: 12px;">Sin gestiones previas registradas.</p>
            @else
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    @foreach($lead->activities as $act)
                    <div style="border-left: 3px solid var(--ink); padding-left: 10px; font-size: 12px;">
                        <div style="display: flex; justify-content: space-between; color: var(--muted); font-size: 11px;">
                            <b>{{ $act->actor->name ?: $act->actor->email }}</b>
                            <span>{{ $act->created_at->format('d/m/Y H:i') }}</span>
                        </div>
                        <div style="color: #1e293b; margin-top: 2px;">{{ $act->note }}</div>
                    </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
