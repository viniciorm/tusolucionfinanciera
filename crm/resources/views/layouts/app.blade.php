<!doctype html>
<html lang="es-CL">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'CRM') | Tu Solución Financiera</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="{{ asset('brand.css') }}">
    <style>
        :root {
            --ink: #003A8C;
            --royal: #0057D9;
            --gold: #D4A857;
            --gold-dark: #B38A3C;
            --bg: #F5F6F7;
            --line: #e2e7ed;
            --muted: #68768a;
            --soft: #eaf1fb;
            --success: #198754;
            --danger: #dc3545;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font: 14px/1.5 'Inter', system-ui, -apple-system, sans-serif; color: #1e293b; background: var(--bg); }
        aside { width: 230px; position: fixed; inset: 0 auto 0 0; background: white; border-right: 1px solid var(--line); border-top: 4px solid var(--gold); padding: 24px 16px; display: flex; flex-direction: column; z-index: 10; }
        .brand { padding: 0 8px 24px; text-align: center; }
        .brand-logo { max-width: 130px; height: auto; border-radius: 8px; display: inline-block; }
        .brand small { display: block; margin-top: 6px; color: var(--muted); font-size: 11px; }
        .nav-label { font-size: 10px; letter-spacing: 1.2px; color: #94a3b8; font-weight: 700; margin: 0 12px 10px; text-transform: uppercase; }
        .nav a { display: flex; align-items: center; gap: 10px; text-decoration: none; padding: 10px 12px; border-radius: 8px; color: #475569; margin: 3px 0; font-weight: 500; }
        .nav a:hover { background: var(--soft); color: var(--royal); }
        .nav a.active { background: var(--soft); color: var(--ink); font-weight: 700; box-shadow: inset 3px 0 var(--gold); }
        .nav a .icon { width: 22px; text-align: center; font-size: 15px; }
        .sidebottom { margin-top: auto; background: var(--soft); padding: 12px; border-radius: 8px; font-size: 11px; color: #475569; }
        .sidebottom b { display: block; color: var(--ink); font-size: 12px; }
        .sidebottom form { margin-top: 8px; }
        .btn-logout { border: 0; background: transparent; color: var(--danger); font-size: 11px; font-weight: 600; cursor: pointer; padding: 0; text-decoration: underline; }
        main { margin-left: 230px; min-height: 100vh; }
        .top { height: 64px; background: #fff; border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; }
        .top b { font-size: 15px; color: var(--ink); }
        .badge { font-size: 11px; color: var(--gold-dark); background: #fff9e9; border: 1px solid #f3e5bd; padding: 4px 10px; border-radius: 20px; font-weight: 700; }
        .wrap { max-width: 1400px; margin: auto; padding: 28px 32px 70px; }
        .heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 22px; flex-wrap: wrap; }
        h1 { font-size: 24px; color: var(--ink); margin: 0 0 4px; letter-spacing: -0.5px; }
        h2 { font-size: 16px; color: var(--ink); margin: 0 0 4px; }
        p { margin: 0; color: var(--muted); }
        .btn { display: inline-flex; align-items: center; justify-content: center; border: 1px solid #cbd5e1; border-radius: 8px; background: white; color: #334155; padding: 8px 14px; font-weight: 600; text-decoration: none; cursor: pointer; font-size: 13px; }
        .btn:hover { background: #f8fafc; }
        .btn-primary { background: var(--gold); border-color: var(--gold); color: #1e293b; }
        .btn-primary:hover { background: var(--gold-dark); border-color: var(--gold-dark); color: #fff; }
        .btn-danger { background: var(--danger); border-color: var(--danger); color: #fff; }
        .btn-sm { padding: 4px 8px; font-size: 11px; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; }
        .alert-success { background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
        .alert-danger { background: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }
        .metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .metric, .panel { background: white; border: 1px solid var(--line); border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
        .metric { padding: 16px; }
        .metric-head { display: flex; justify-content: space-between; color: var(--muted); font-size: 12px; font-weight: 500; }
        .metric strong { display: block; font-size: 26px; line-height: 1.2; margin-top: 12px; color: var(--ink); }
        .metric small { color: #94a3b8; font-size: 11px; }
        .panel { padding: 20px; margin-bottom: 20px; }
        .panelhead { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 12px; flex-wrap: wrap; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; padding: 10px 12px; border-bottom: 2px solid var(--line); color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 12px; border-bottom: 1px solid var(--line); vertical-align: middle; }
        tr:hover td { background: #f8fafc; }
        .status-badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .status-Nuevo { background: #e0f2fe; color: #0369a1; }
        .status-Contactado { background: #fef3c7; color: #b45309; }
        .status-En-seguimiento { background: #f3e8ff; color: #7e22ce; }
        .status-Reunión-agendada { background: #dcfce7; color: #15803d; }
        .status-Convertido { background: #d1fae5; color: #047857; font-weight: 700; }
        .status-No-interesado { background: #f1f5f9; color: #64748b; }
        .filters { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
        input, select, textarea { border: 1px solid #cbd5e1; border-radius: 8px; background: white; padding: 8px 12px; font-size: 13px; color: #334155; font-family: inherit; }
        input:focus, select:focus, textarea:focus { outline: 2px solid var(--gold); border-color: var(--gold); }
        .mobile-nav { display: none; }
        @media(max-width: 800px) {
            aside { display: none; }
            main { margin-left: 0; }
            .wrap { padding: 18px 16px 80px; }
            .top { padding: 0 16px; height: 56px; }
            .mobile-nav { position: fixed; z-index: 50; bottom: 0; left: 0; right: 0; display: flex; background: white; border-top: 1px solid var(--line); justify-content: space-around; padding: 8px 4px max(8px, env(safe-area-inset-bottom)); }
            .mobile-nav a { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #64748b; font-size: 10px; padding: 4px; }
            .mobile-nav a.active { color: var(--ink); font-weight: 700; }
            .mobile-nav a span { font-size: 16px; }
        }
    </style>
</head>
<body>
    <aside>
        <div class="brand">
            <img class="brand-logo" src="{{ asset('assets/logo.png') }}" alt="Tu Solución Financiera" onerror="this.onerror=null;this.src='https://tusolucionfinanciera.cl/assets/logo.png'">
            <small>CRM · Gestión Privada</small>
        </div>
        <div class="nav-label">Espacio de trabajo</div>
        <nav class="nav">
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <span class="icon">⌂</span> Resumen
            </a>
            <a href="{{ route('leads.index') }}" class="{{ request()->routeIs('leads.*') ? 'active' : '' }}">
                <span class="icon">♧</span> Mis leads
            </a>
            <a href="{{ route('follow_ups.index') }}" class="{{ request()->routeIs('follow_ups.*') ? 'active' : '' }}">
                <span class="icon">◷</span> Seguimientos
            </a>
            <a href="{{ route('stats.index') }}" class="{{ request()->routeIs('stats.*') ? 'active' : '' }}">
                <span class="icon">▥</span> Estadísticas
            </a>
            @if(Auth::user()->isAdmin())
            <a href="{{ route('admin.index') }}" class="{{ request()->routeIs('admin.*') ? 'active' : '' }}">
                <span class="icon">⚙</span> Administración
            </a>
            @endif
        </nav>
        <div class="sidebottom">
            <b>{{ Auth::user()->name ?: 'Usuario CRM' }}</b>
            <span>{{ Auth::user()->email }} ({{ Auth::user()->role }})</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn-logout">Cerrar sesión</button>
            </form>
        </div>
    </aside>

    <main>
        <div class="top">
            <b>@yield('crumb', 'Espacio de trabajo')</b>
            <div>
                <span class="badge">● {{ strtoupper(Auth::user()->role) }} AUTORIZADO</span>
            </div>
        </div>
        <div class="wrap">
            @if(session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger">
                    @foreach($errors->all() as $err)
                        <div>{{ $err }}</div>
                    @endforeach
                </div>
            @endif

            @yield('content')
        </div>
    </main>

    <nav class="mobile-nav">
        <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <span>⌂</span>Resumen
        </a>
        <a href="{{ route('leads.index') }}" class="{{ request()->routeIs('leads.*') ? 'active' : '' }}">
            <span>♧</span>Leads
        </a>
        <a href="{{ route('follow_ups.index') }}" class="{{ request()->routeIs('follow_ups.*') ? 'active' : '' }}">
            <span>◷</span>Agenda
        </a>
        <a href="{{ route('stats.index') }}" class="{{ request()->routeIs('stats.*') ? 'active' : '' }}">
            <span>▥</span>Stats
        </a>
        @if(Auth::user()->isAdmin())
        <a href="{{ route('admin.index') }}" class="{{ request()->routeIs('admin.*') ? 'active' : '' }}">
            <span>⚙</span>Admin
        </a>
        @endif
    </nav>
</body>
</html>
