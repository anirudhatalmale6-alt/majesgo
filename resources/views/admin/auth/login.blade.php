<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar · MajesGo</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0D0D0D">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="MajesGo">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
    <link rel="icon" type="image/png" sizes="64x64" href="/icons/favicon-64.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--verde:#00C853;--verde-d:#00a344;--amarillo:#FFC107;--negro:#0D0D0D}
        *{box-sizing:border-box}
        body{margin:0;font-family:'Poppins',system-ui,sans-serif;min-height:100vh;display:grid;place-items:center;
            background:radial-gradient(1200px 600px at 20% -10%,#15351f 0,#0d0d0d 55%,#0a0a0a 100%);color:#fff;padding:20px}
        .wrap{width:100%;max-width:400px}
        .top{text-align:center;margin-bottom:22px}
        .top .mg-logo{width:210px;height:auto}
        .top p{color:#8a94a0;margin:8px 0 0;font-size:13.5px}
        .card{background:#fff;color:#1c2430;border-radius:20px;padding:26px 24px;box-shadow:0 24px 60px rgba(0,0,0,.4)}
        .card h2{margin:0 0 3px;font-size:19px}
        .card .sub{color:#7a8694;font-size:13px;margin-bottom:18px}
        label{display:block;font-weight:600;font-size:12.5px;margin-bottom:6px;color:#39424e}
        .input{width:100%;padding:12px 13px;border:1px solid #d7dce3;border-radius:11px;font-family:inherit;font-size:15px;margin-bottom:14px}
        .input:focus{outline:0;border-color:var(--verde);box-shadow:0 0 0 3px rgba(0,200,83,.14)}
        .btn{width:100%;border:0;cursor:pointer;font-family:inherit;font-weight:700;font-size:15px;padding:13px;border-radius:12px;
            background:var(--verde);color:#fff;transition:.15s;display:flex;align-items:center;justify-content:center;gap:8px}
        .btn:hover{background:var(--verde-d)}
        .errs{background:#ffefef;border:1px solid #ffcfcf;color:#c0322b;padding:10px 13px;border-radius:11px;margin-bottom:14px;font-size:13px}
        .rem{display:flex;align-items:center;gap:8px;font-size:13px;color:#4a5561;margin-bottom:16px}
        .foot{text-align:center;color:#6b7480;font-size:12px;margin-top:18px}
        .chip{display:inline-flex;align-items:center;gap:6px;background:rgba(255,193,7,.16);color:#b98900;font-size:11.5px;font-weight:600;padding:5px 11px;border-radius:999px;margin-bottom:16px}
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        @include('admin.partials.logo', ['dark' => true])
        <p>Tu taxi en un toque · Majes - El Pedregal</p>
    </div>
    <div class="card">
        <div class="chip">🔒 Panel de administración</div>
        <h2>Bienvenido</h2>
        <div class="sub">Ingresa con tu cuenta de administrador.</div>

        @if($errors->any())
            <div class="errs">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('admin.login.attempt') }}">
            @csrf
            <label>Correo electrónico</label>
            <input class="input" type="email" name="email" value="{{ old('email') }}" placeholder="tucorreo@ejemplo.com" required autofocus>
            <label>Contraseña</label>
            <input class="input" type="password" name="password" placeholder="••••••••" required>
            <div class="rem"><input type="checkbox" name="remember" id="r"><label for="r" style="margin:0;font-weight:500">Mantener sesión iniciada</label></div>
            <button class="btn">Ingresar →</button>
        </form>
    </div>
    <div class="foot">© {{ date('Y') }} MajesGo · Majes, Arequipa</div>
</div>
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
    }
</script>
</body>
</html>
