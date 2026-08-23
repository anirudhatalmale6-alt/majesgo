<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Panel') · MajesGo</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0D0D0D">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <meta name="apple-mobile-web-app-title" content="MajesGo">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
    <link rel="icon" type="image/png" sizes="64x64" href="/icons/favicon-64.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{
            --verde:#00C853; --verde-d:#00a344; --amarillo:#FFC107; --negro:#0D0D0D;
            --blanco:#F5F7FA; --gris:#263238; --line:#e6e9ee; --muted:#7a8694;
            --sidebar:#0D0D0D; --sidebar-2:#15181c;
        }
        *{box-sizing:border-box}
        body{margin:0;font-family:'Poppins',system-ui,sans-serif;background:var(--blanco);color:#1c2430;font-size:14px}
        a{text-decoration:none;color:inherit}
        .app{display:flex;min-height:100vh}

        /* Sidebar */
        .side{width:246px;background:var(--sidebar);color:#cfd6de;display:flex;flex-direction:column;position:fixed;inset:0 auto 0 0;z-index:40;transition:transform .25s ease}
        .scrim{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:30;opacity:0;transition:opacity .25s}
        .menu-btn{display:none;background:none;border:0;cursor:pointer;padding:8px;margin-right:6px;color:#1c2430}
        .menu-btn svg{width:24px;height:24px}
        .side .brand{padding:20px 18px 14px}
        .side .brand .mg-logo{width:170px;height:auto}
        .side nav{padding:8px 12px;display:flex;flex-direction:column;gap:3px;margin-top:6px}
        .side nav a{display:flex;align-items:center;gap:11px;padding:11px 13px;border-radius:11px;color:#aeb7c2;font-weight:500;transition:.15s}
        .side nav a svg{width:19px;height:19px;flex:none}
        .side nav a:hover{background:#1c2026;color:#fff}
        .side nav a.on{background:linear-gradient(90deg,rgba(0,200,83,.18),rgba(0,200,83,.02));color:#fff;box-shadow:inset 3px 0 0 var(--verde)}
        .side nav a.on svg{color:var(--verde)}
        .side .foot{margin-top:auto;padding:16px;color:#6b7480;font-size:12px;border-top:1px solid #1c2026}

        /* Main */
        .main{flex:1;margin-left:246px;display:flex;flex-direction:column;min-width:0}
        .top{height:64px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 26px;position:sticky;top:0;z-index:10}
        .top h1{font-size:18px;font-weight:700;margin:0}
        .top .who{display:flex;align-items:center;gap:12px}
        .top .who .av{width:36px;height:36px;border-radius:50%;background:var(--verde);color:#fff;display:grid;place-items:center;font-weight:700}
        .top .who .nm{font-size:13px;font-weight:600;line-height:1.15}
        .top .who .nm small{display:block;color:var(--muted);font-weight:500}
        .content{padding:26px;max-width:1180px;width:100%}

        /* Bits */
        .btn{display:inline-flex;align-items:center;gap:7px;border:0;cursor:pointer;font-family:inherit;font-weight:600;font-size:13.5px;padding:10px 16px;border-radius:10px;background:var(--verde);color:#fff;transition:.15s}
        .btn:hover{background:var(--verde-d)}
        .btn.amber{background:var(--amarillo);color:#3a2e00}
        .btn.amber:hover{filter:brightness(.96)}
        .btn.ghost{background:#eef1f5;color:#39424e}
        .btn.ghost:hover{background:#e4e8ee}
        .btn.danger{background:#ffe9e9;color:#c0322b}
        .btn.sm{padding:7px 11px;font-size:12.5px;border-radius:8px}

        .grid{display:grid;gap:16px}
        .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px}
        .card h3{margin:0 0 4px;font-size:15px}

        .stats{grid-template-columns:repeat(4,1fr)}
        .stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px 18px;position:relative;overflow:hidden}
        .stat .ic{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;margin-bottom:10px}
        .stat .v{font-size:26px;font-weight:800;line-height:1}
        .stat .l{color:var(--muted);font-size:12.5px;margin-top:4px;font-weight:500}
        .ic.g{background:rgba(0,200,83,.12);color:var(--verde)}
        .ic.y{background:rgba(255,193,7,.16);color:#b98900}
        .ic.b{background:rgba(38,50,56,.08);color:var(--gris)}
        .ic.r{background:rgba(224,64,64,.12);color:#e04040}

        table{width:100%;border-collapse:collapse}
        th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);font-weight:600;padding:11px 12px;border-bottom:1px solid var(--line)}
        td{padding:12px;border-bottom:1px solid #f0f2f5;vertical-align:middle}
        tr:last-child td{border-bottom:0}
        tbody tr:hover{background:#fafbfc}

        .badge{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;padding:4px 9px;border-radius:999px}
        .badge.on{background:rgba(0,200,83,.13);color:var(--verde-d)}
        .badge.busy{background:rgba(255,193,7,.18);color:#a17400}
        .badge.off{background:#eef1f5;color:#6b7480}
        .badge.sus{background:#fff3e0;color:#c77700}
        .badge.blk{background:#ffe9e9;color:#c0322b}
        .dot{width:7px;height:7px;border-radius:50%;background:currentColor;display:inline-block}

        .avci{width:34px;height:34px;border-radius:10px;background:var(--gris);color:#fff;display:grid;place-items:center;font-weight:700;font-size:13px;flex:none}
        .money{font-weight:700}
        .flash{background:rgba(0,200,83,.1);border:1px solid rgba(0,200,83,.3);color:var(--verde-d);padding:11px 15px;border-radius:11px;margin-bottom:16px;font-weight:600}
        .errs{background:#ffefef;border:1px solid #ffcfcf;color:#c0322b;padding:11px 15px;border-radius:11px;margin-bottom:16px;font-size:13px}
        .field{margin-bottom:14px}
        .field label{display:block;font-weight:600;font-size:12.5px;margin-bottom:6px;color:#39424e}
        .input{width:100%;padding:10px 12px;border:1px solid #d7dce3;border-radius:10px;font-family:inherit;font-size:14px;background:#fff}
        .input:focus{outline:0;border-color:var(--verde);box-shadow:0 0 0 3px rgba(0,200,83,.12)}
        .row{display:flex;gap:14px;flex-wrap:wrap}
        .row>.field{flex:1;min-width:150px}
        .muted{color:var(--muted)}
        .between{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
        .pagi{display:flex;gap:6px;margin-top:16px}
        .pagi a,.pagi span{padding:7px 11px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:#fff}
        .pagi .on{background:var(--verde);color:#fff;border-color:var(--verde)}
        @media(max-width:820px){
            .side{transform:translateX(-100%)}
            .side.open{transform:translateX(0)}
            .scrim.show{display:block;opacity:1}
            .main{margin-left:0}
            .stats{grid-template-columns:repeat(2,1fr)}
            .menu-btn{display:inline-flex;align-items:center}
            .top{padding:0 14px}
            .content{padding:18px 14px}
            .top .who .nm{display:none}
        }
    </style>
</head>
<body>
<div class="app">
    <div class="scrim" id="scrim" onclick="toggleMenu(false)"></div>
    <aside class="side" id="side">
        <div class="brand">@include('admin.partials.logo')</div>
        <nav>
            <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard')?'on':'' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
                Inicio
            </a>
            <a href="{{ route('admin.drivers.index') }}" class="{{ request()->routeIs('admin.drivers.*')?'on':'' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 17h14M6 17l1.5-5A2 2 0 0 1 9.4 10.6h5.2a2 2 0 0 1 1.9 1.4L18 17"/><circle cx="7.5" cy="17.5" r="1.5"/><circle cx="16.5" cy="17.5" r="1.5"/></svg>
                Conductores
            </a>
            <a href="{{ route('admin.passengers.index') }}" class="{{ request()->routeIs('admin.passengers.*')?'on':'' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg>
                Pasajeros
            </a>
            @php($pendingPhotos = \App\Models\DriverPhoto::pending()->count())
            <a href="{{ route('admin.photos.index') }}" class="{{ request()->routeIs('admin.photos.*')?'on':'' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="6" width="18" height="14" rx="2"/><circle cx="12" cy="13" r="3.2"/><path d="M8 6l1.5-2h5L16 6"/></svg>
                Fotos
                @if($pendingPhotos)
                    <span style="margin-left:auto;background:#FFC107;color:#3a2c00;font-size:11px;font-weight:800;border-radius:999px;padding:2px 8px">{{ $pendingPhotos }}</span>
                @endif
            </a>
            <a href="{{ route('admin.recharges.index') }}" class="{{ request()->routeIs('admin.recharges.*')?'on':'' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                Recargas
            </a>
            <a href="{{ route('admin.pois.index') }}" class="{{ request()->routeIs('admin.pois.*')?'on':'' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 20l-5.5 2V6L9 4l6 2 5.5-2v16L15 22l-6-2z"/><path d="M9 4v16M15 6v16"/></svg>
                Referencias
            </a>
            <a href="{{ route('admin.places.index') }}" class="{{ request()->routeIs('admin.places.*')?'on':'' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s-7-4.35-7-11a7 7 0 0 1 14 0c0 6.65-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                Zonas locales
            </a>
            @php($pendingReports = \App\Models\UserReport::pending()->count())
            <a href="{{ route('admin.reports.index') }}" class="{{ request()->routeIs('admin.reports.*')?'on':'' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l9 16H3l9-16z"/><path d="M12 9v4.5"/><circle cx="12" cy="16.4" r=".9" fill="currentColor" stroke="none"/></svg>
                Denuncias
                @if($pendingReports)
                    <span style="margin-left:auto;background:#e04040;color:#fff;font-size:11px;font-weight:800;border-radius:999px;padding:2px 8px">{{ $pendingReports }}</span>
                @endif
            </a>
            <a href="#" class="dim" onclick="return false" style="opacity:.5">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
                Reportes <small style="margin-left:auto;font-size:10px">pronto</small>
            </a>
            <a href="{{ route('admin.settings.edit') }}" class="{{ request()->routeIs('admin.settings.*')?'on':'' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 7 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3.5 14H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 8"/></svg>
                Configuración
            </a>
        </nav>
        <div class="foot">MajesGo · Panel de administración<br>Tu taxi en un toque.</div>
    </aside>

    <div class="main">
        <header class="top">
            <div style="display:flex;align-items:center;min-width:0">
                <button class="menu-btn" onclick="toggleMenu()" aria-label="Menú">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <h1>@yield('title', 'Panel')</h1>
            </div>
            <div class="who">
                <div class="nm" style="text-align:right">{{ auth()->user()->name ?? 'Admin' }}<small>{{ auth()->user()->email ?? '' }}</small></div>
                <div class="av">{{ strtoupper(substr(auth()->user()->name ?? 'A',0,1)) }}</div>
                <form method="POST" action="{{ route('admin.logout') }}">@csrf
                    <button class="btn ghost sm" title="Salir">Salir</button>
                </form>
            </div>
        </header>
        <main class="content">
            @if(session('ok'))<div class="flash">✓ {{ session('ok') }}</div>@endif
            @if($errors->any())<div class="errs">@foreach($errors->all() as $e)<div>• {{ $e }}</div>@endforeach</div>@endif
            @yield('content')
        </main>
    </div>
</div>
<script>
    // token CSRF disponible para formularios inline (POST vía fetch si hiciera falta)
    window.CSRF = document.querySelector('meta[name=csrf-token]').content;
    function toggleMenu(force){
        var s=document.getElementById('side'), sc=document.getElementById('scrim');
        var open = force===undefined ? !s.classList.contains('open') : force;
        s.classList.toggle('open', open);
        sc.classList.toggle('show', open);
    }
    // cerrar el menú al tocar un enlace del sidebar (en móvil)
    document.querySelectorAll('#side nav a').forEach(function(a){
        a.addEventListener('click', function(){ if(window.innerWidth<=820) toggleMenu(false); });
    });
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
    }
</script>
@stack('scripts')
</body>
</html>
