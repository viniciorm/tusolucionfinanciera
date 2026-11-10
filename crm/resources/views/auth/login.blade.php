<!doctype html>
<html lang="es-CL">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso al CRM | Tu Solución Financiera</title>
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
        }
        * { box-sizing: border-box; }
        body { margin: 0; font: 14px/1.5 'Inter', system-ui, -apple-system, sans-serif; color: #1e293b; background: var(--bg); min-height: 100vh; display: grid; place-items: center; padding: 20px; }
        .login-card { max-width: 440px; width: 100%; background: white; padding: 36px; border: 1px solid var(--line); border-radius: 16px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); text-align: center; }
        .brand-logo { max-width: 120px; height: auto; border-radius: 8px; margin-bottom: 12px; }
        h1 { font-size: 22px; color: var(--ink); margin: 12px 0 6px; letter-spacing: -0.4px; }
        p { color: var(--muted); font-size: 13px; margin: 0 0 24px; }
        .btn-google { display: flex; align-items: center; justify-content: center; gap: 12px; width: 100%; padding: 12px 16px; border: 1px solid #cbd5e1; border-radius: 8px; background: white; color: #1e293b; font-weight: 600; font-size: 14px; text-decoration: none; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .btn-google:hover { background: #f8fafc; border-color: #94a3b8; }
        .btn-google svg { width: 18px; height: 18px; }
        .notice { display: flex; gap: 10px; text-align: left; background: #fff9e9; border: 1px solid #f3e5bd; padding: 14px; border-radius: 8px; margin-top: 24px; font-size: 12px; color: #854d0e; }
        .notice strong { display: block; margin-bottom: 2px; }
        .sub { margin-top: 14px; font-size: 12px; color: #94a3b8; }
        .alert-danger { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 10px 14px; border-radius: 8px; font-size: 12px; margin-bottom: 18px; text-align: left; }
    </style>
</head>
<body>
    <div class="login-card">
        <img class="brand-logo" src="{{ asset('assets/logo.png') }}" alt="Tu Solución Financiera" onerror="this.onerror=null;this.src='https://tusolucionfinanciera.cl/assets/logo.png'">
        <div><small style="color: var(--muted); font-weight: 500;">CRM Privado · Tu Solución Financiera</small></div>
        <h1>Te damos la bienvenida</h1>
        <p>Espacio privado para la gestión de prospectos y seguimientos comerciales.</p>

        @if($errors->any())
            <div class="alert-danger">
                @foreach($errors->all() as $err)
                    <div>• {{ $err }}</div>
                @endforeach
            </div>
        @endif

        <a href="{{ route('auth.google') }}" class="btn-google">
            <svg viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
            </svg>
            Continuar con Google
        </a>

        <p class="sub">El acceso está habilitado únicamente para cuentas previamente autorizadas.</p>

        <div class="notice">
            <div style="font-size: 16px;">🔒</div>
            <div>
                <strong>Acceso controlado</strong>
                <span>Se verificará la identidad de tu cuenta Google corporativa. No se permite registro público.</span>
            </div>
        </div>
    </div>
</body>
</html>
