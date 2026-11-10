@extends('layouts.app')

@section('title', 'Administración')
@section('crumb', 'Panel de Control y Administración')

@section('content')
<div class="heading">
    <div>
        <h1>Administración del CRM</h1>
        <p>Control de acceso, vinculación de identidades Google, sincronización y auditoría.</p>
    </div>
</div>

<!-- Sincronización -->
<div class="panel">
    <div class="panelhead">
        <div>
            <h2>Sincronización con Google Sheets</h2>
            <p>Lectura segura de solo lectura de Hoja 2 a Hoja 5 (Hoja 1 excluida).</p>
        </div>
        <div>
            @if($sheetsConfigured)
                <form method="POST" action="{{ route('admin.sync') }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn btn-primary">↻ Sincronizar ahora</button>
                </form>
            @else
                <span class="badge" style="background: #fef2f2; color: #991b1b; border-color: #fecaca;">
                    Credenciales de Google Sheets pendientes en .env
                </span>
            @endif
        </div>
    </div>

    @if($recentSyncs->isEmpty())
        <p style="color: var(--muted); font-size: 13px;">No se registran ejecuciones de sincronización todavía.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Fecha inicio</th>
                    <th>Estado</th>
                    <th>Leídos</th>
                    <th>Guardados</th>
                    <th>Rechazados</th>
                    <th>Duración</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recentSyncs as $sync)
                <tr>
                    <td>{{ $sync->started_at->format('d/m/Y H:i:s') }}</td>
                    <td>
                        <span class="status-badge {{ $sync->status === 'completed' ? 'status-Convertido' : 'status-No-interesado' }}">
                            ● {{ $sync->status }}
                        </span>
                    </td>
                    <td>{{ $sync->rows_read }}</td>
                    <td>{{ $sync->rows_saved }}</td>
                    <td>{{ $sync->rows_rejected }}</td>
                    <td>
                        {{ $sync->finished_at ? $sync->finished_at->diffInSeconds($sync->started_at) . 's' : 'En curso' }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

<!-- Control de Usuarios Autorizados -->
<div class="panel">
    <div class="panelhead">
        <div>
            <h2>Usuarios autorizados</h2>
            <p>Control de acceso por correo corporativo. La cuenta de Google se vinculará en el primer inicio de sesión.</p>
        </div>
    </div>

    <!-- Formulario para autorizar nuevo usuario -->
    <form method="POST" action="{{ route('admin.users.create') }}" style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; padding: 14px; background: #f8fafc; border: 1px solid var(--line); border-radius: 8px;">
        @csrf
        <input type="email" name="email" required placeholder="correo@ejemplo.cl" style="min-width: 220px; flex: 1;">
        <input type="text" name="name" placeholder="Nombre completo" style="min-width: 180px; flex: 1;">
        <select name="role" required>
            <option value="advisor">Asesor comercial</option>
            <option value="admin">Administrador</option>
        </select>
        <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500;">
            <input type="checkbox" name="active" value="1" checked> Activo
        </label>
        <button type="submit" class="btn btn-primary">+ Autorizar usuario</button>
    </form>

    <table>
        <thead>
            <tr>
                <th>Usuario</th>
                <th>Rol</th>
                <th>Estado</th>
                <th>Vinculación Google</th>
                <th>Creado</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            @foreach($users as $u)
            <tr>
                <td>
                    <b>{{ $u->name ?: 'Sin nombre' }}</b>
                    <div style="font-size: 11px; color: var(--muted);">{{ $u->email }}</div>
                </td>
                <td><span style="font-weight: 600; text-transform: uppercase; font-size: 11px;">{{ $u->role }}</span></td>
                <td>
                    <span class="status-badge {{ $u->active ? 'status-Convertido' : 'status-No-interesado' }}">
                        {{ $u->active ? 'Activo' : 'Inactivo' }}
                    </span>
                </td>
                <td>
                    @if($u->google_subject)
                        <span style="color: var(--success); font-weight: 600; font-size: 11px;">✓ Vinculado</span>
                    @else
                        <span style="color: #94a3b8; font-size: 11px; font-style: italic;">Pendiente de primer login</span>
                    @endif
                </td>
                <td>{{ $u->created_at->format('d/m/Y') }}</td>
                <td>
                    @if($u->id !== Auth::id())
                        <form method="POST" action="{{ route('admin.users.toggle', $u->id) }}" style="display:inline;">
                            @csrf
                            <button type="submit" class="btn btn-sm {{ $u->active ? 'btn-danger' : 'btn-primary' }}">
                                {{ $u->active ? 'Desactivar' : 'Activar' }}
                            </button>
                        </form>
                    @else
                        <span style="font-size: 11px; color: var(--muted);">(Tu sesión)</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

<!-- Registro de Auditoría -->
<div class="panel">
    <div class="panelhead">
        <div>
            <h2>Trazabilidad y auditoría</h2>
            <p>Últimos eventos críticos registrados por el sistema</p>
        </div>
    </div>

    @if($recentAudits->isEmpty())
        <p style="color: var(--muted); font-size: 13px;">Sin eventos de auditoría registrados.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Acción</th>
                    <th>Actor</th>
                    <th>Prospecto ID</th>
                    <th>Detalles</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recentAudits as $aud)
                <tr>
                    <td>{{ $aud->created_at->format('d/m/Y H:i:s') }}</td>
                    <td><code>{{ $aud->action }}</code></td>
                    <td>{{ $aud->actor ? ($aud->actor->name ?: $aud->actor->email) : 'Sistema / Anónimo' }}</td>
                    <td>{{ $aud->lead_id ? '#' . $aud->lead_id : '—' }}</td>
                    <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 11px; font-family: monospace;">
                        {{ json_encode($aud->details) }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
