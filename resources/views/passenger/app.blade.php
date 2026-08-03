<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>MajesGo — Pide tu taxi</title>

    <link rel="manifest" href="/app.webmanifest">
    <meta name="theme-color" content="#00C853">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="MajesGo">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        :root{
            --verde:#00C853; --verde-d:#00a344; --amarillo:#FFC107; --negro:#0D0D0D;
            --panel:#15181c; --panel-2:#1c2026; --line:#2a2f36; --muted:#8a94a0; --text:#F5F7FA;
        }
        *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
        html,body{margin:0;height:100%;font-family:'Poppins',system-ui,sans-serif;background:var(--negro);color:var(--text);overscroll-behavior:none}
        #app{height:100dvh;width:100%;position:relative;overflow:hidden}

        /* Mapa */
        #map{position:absolute;inset:0;z-index:1;background:#0b1a10}
        .leaflet-container{background:#0b1a10;font-family:inherit}
        .leaflet-control-attribution{font-size:9px;opacity:.6}

        /* Marcadores */
        .pin{width:26px;height:26px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:2.5px solid #fff;box-shadow:0 3px 8px rgba(0,0,0,.4)}
        .pin.o{background:var(--verde)} .pin.d{background:#ff5252}
        .car{font-size:30px;filter:drop-shadow(0 4px 6px rgba(0,0,0,.5));transition:transform .1s}
        .medot{width:16px;height:16px;background:#2b8fff;border:3px solid #fff;border-radius:50%;box-shadow:0 0 0 6px rgba(43,143,255,.25)}

        /* Topbar flotante */
        .topbar{position:absolute;top:0;left:0;right:0;z-index:20;display:flex;align-items:center;justify-content:space-between;
            padding:calc(env(safe-area-inset-top) + 12px) 14px 12px;pointer-events:none}
        .topbar .brand,.topbar .iconbtn{pointer-events:auto}
        .brand{display:flex;align-items:center;gap:7px;background:rgba(13,13,13,.72);backdrop-filter:blur(8px);padding:7px 13px;border-radius:999px}
        .brand b{font-size:16px} .brand .g{color:var(--verde)}
        .iconbtn{pointer-events:auto;width:42px;height:42px;border-radius:50%;background:rgba(13,13,13,.72);backdrop-filter:blur(8px);
            border:0;color:#fff;display:grid;place-items:center;cursor:pointer}
        .iconbtn svg{width:22px;height:22px}

        /* Botón "mi ubicación" */
        .locbtn{position:absolute;right:14px;z-index:18;bottom:var(--sheet-h,300px);margin-bottom:14px;transition:bottom .25s;
            width:46px;height:46px;border-radius:50%;background:var(--panel);border:1px solid var(--line);color:var(--verde);display:grid;place-items:center;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.4)}
        .locbtn svg{width:24px;height:24px}

        /* Bottom sheet */
        .sheet{position:absolute;left:0;right:0;bottom:0;z-index:22;background:var(--panel);
            border-radius:22px 22px 0 0;box-shadow:0 -10px 40px rgba(0,0,0,.5);
            padding:8px 18px calc(env(safe-area-inset-bottom) + 18px);max-height:82dvh;overflow-y:auto}
        .grab{width:42px;height:5px;border-radius:3px;background:#3a414a;margin:6px auto 12px}
        .sheet h2{margin:0 0 3px;font-size:19px}
        .sheet .sub{color:var(--muted);font-size:13px;margin-bottom:14px}

        .fieldrow{display:flex;align-items:center;gap:11px;background:var(--panel-2);border:1px solid var(--line);border-radius:13px;padding:12px 13px;margin-bottom:10px}
        .fieldrow .dot{width:11px;height:11px;border-radius:50%;flex:none}
        .fieldrow .dot.o{background:var(--verde)} .fieldrow .dot.d{background:#ff5252}
        .fieldrow input{flex:1;background:none;border:0;color:#fff;font-family:inherit;font-size:14.5px;outline:none;min-width:0}
        .fieldrow input::placeholder{color:#6b7480}
        .fieldrow small{color:var(--muted);font-size:11px}

        .sugg{position:relative}
        .suggbox{position:absolute;left:0;right:0;top:100%;margin-top:-6px;background:var(--panel-2);border:1px solid var(--line);border-radius:0 0 13px 13px;z-index:5;max-height:210px;overflow-y:auto}
        .suggbox div{padding:11px 14px;border-bottom:1px solid var(--line);font-size:13.5px;cursor:pointer}
        .suggbox div:hover{background:#232830}
        .suggbox .t{font-weight:600}.suggbox .s{color:var(--muted);font-size:11.5px}

        .routeinfo{display:flex;gap:10px;margin:4px 0 14px}
        .chip{flex:1;background:var(--panel-2);border:1px solid var(--line);border-radius:12px;padding:10px;text-align:center}
        .chip .v{font-size:17px;font-weight:800}.chip .l{color:var(--muted);font-size:11px}

        .prow{display:flex;align-items:center;justify-content:space-between;background:var(--panel-2);border:1px solid var(--line);border-radius:14px;padding:8px 10px;margin-bottom:12px}
        .prow .lbl{font-size:12px;color:var(--muted);padding-left:6px}
        .stepper{display:flex;align-items:center;gap:6px}
        .stepper button{width:40px;height:40px;border-radius:11px;border:0;background:#2a3038;color:#fff;font-size:22px;font-weight:700;cursor:pointer}
        .stepper .price{min-width:104px;text-align:center;font-size:22px;font-weight:800}
        .hintprice{font-size:11.5px;color:var(--muted);text-align:center;margin:-6px 0 12px}

        .pay{display:flex;gap:10px;margin-bottom:16px}
        .pay button{flex:1;padding:12px;border-radius:13px;border:1px solid var(--line);background:var(--panel-2);color:var(--text);font-family:inherit;font-weight:600;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px}
        .pay button.on{border-color:var(--verde);background:rgba(0,200,83,.12);color:#fff}

        .btn{width:100%;border:0;cursor:pointer;font-family:inherit;font-weight:700;font-size:16px;padding:15px;border-radius:14px;background:var(--verde);color:#fff;display:flex;align-items:center;justify-content:center;gap:9px;transition:.15s}
        .btn:hover{background:var(--verde-d)} .btn:disabled{opacity:.5;cursor:default}
        .btn.amber{background:var(--amarillo);color:#3a2e00}
        .btn.ghost{background:#2a3038;color:#fff} .btn.ghost:hover{background:#333a43}
        .btn.danger{background:#3a1f1f;color:#ff7a7a}
        .btn.sm{font-size:14px;padding:12px}

        /* Buscando */
        .searching{text-align:center;padding:14px 0 6px}
        .radar{width:96px;height:96px;margin:6px auto 14px;position:relative}
        .radar span{position:absolute;inset:0;border-radius:50%;border:2px solid var(--verde);opacity:0;animation:pulse 2s ease-out infinite}
        .radar span:nth-child(2){animation-delay:.6s}.radar span:nth-child(3){animation-delay:1.2s}
        .radar b{position:absolute;inset:0;display:grid;place-items:center;font-size:38px}
        @keyframes pulse{0%{transform:scale(.4);opacity:.8}100%{transform:scale(1);opacity:0}}

        /* Conductor asignado */
        .drv{display:flex;align-items:center;gap:13px;margin-bottom:14px}
        .drv .av{width:56px;height:56px;border-radius:16px;background:linear-gradient(135deg,var(--verde),var(--verde-d));display:grid;place-items:center;font-size:24px;font-weight:800;flex:none}
        .drv .nm{font-size:17px;font-weight:700}
        .drv .car2{color:var(--muted);font-size:13px}
        .drv .rate{margin-left:auto;text-align:right}
        .drv .rate b{font-size:16px}.drv .rate small{display:block;color:var(--muted);font-size:11px}
        .statusband{background:var(--panel-2);border-left:3px solid var(--verde);border-radius:10px;padding:11px 14px;margin-bottom:13px;font-weight:600;font-size:14.5px}
        .statusband small{display:block;color:var(--muted);font-weight:500;font-size:12px;margin-top:2px}
        .acts{display:flex;gap:10px}
        .acts .btn{font-size:14px;padding:13px}

        .demo{display:inline-flex;align-items:center;gap:5px;background:rgba(255,193,7,.16);color:var(--amarillo);font-size:11px;font-weight:600;padding:4px 10px;border-radius:999px;margin-bottom:10px}

        /* Estrellas */
        .stars{display:flex;justify-content:center;gap:8px;margin:8px 0 18px}
        .stars span{font-size:38px;cursor:pointer;filter:grayscale(1);opacity:.5;transition:.1s}
        .stars span.on{filter:none;opacity:1}
        .fare-big{text-align:center;margin:6px 0 14px}
        .fare-big .n{font-size:40px;font-weight:800}.fare-big .l{color:var(--muted);font-size:13px}

        /* Auth overlay */
        .overlay{position:absolute;inset:0;z-index:40;background:radial-gradient(1000px 500px at 30% -10%,#15351f,#0d0d0d 60%);
            display:flex;flex-direction:column;justify-content:flex-end;padding:24px 22px calc(env(safe-area-inset-bottom) + 26px)}
        .overlay .hero{flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;gap:8px}
        .overlay .hero .big{font-size:56px}
        .overlay .hero h1{font-size:26px;margin:6px 0 0}.overlay .hero h1 .g{color:var(--verde)}
        .overlay .hero p{color:var(--muted);font-size:14px;margin:0}
        .authcard label{display:block;font-size:12.5px;color:var(--muted);margin:0 0 6px 2px}
        .authcard input{width:100%;padding:14px;border-radius:13px;border:1px solid var(--line);background:var(--panel-2);color:#fff;font-family:inherit;font-size:15px;margin-bottom:12px}
        .authcard input:focus{outline:0;border-color:var(--verde)}
        .toggle{text-align:center;color:var(--muted);font-size:13.5px;margin-top:14px}
        .toggle a{color:var(--verde);font-weight:600}
        .err{background:#3a1f1f;color:#ff9d9d;border-radius:11px;padding:10px 13px;font-size:13px;margin-bottom:12px;display:none}

        /* Historial */
        .drawer{position:absolute;inset:0;z-index:45;background:var(--negro);transform:translateX(100%);transition:transform .25s;display:flex;flex-direction:column}
        .drawer.open{transform:none}
        .drawer .dhead{display:flex;align-items:center;gap:12px;padding:calc(env(safe-area-inset-top) + 14px) 16px 14px;border-bottom:1px solid var(--line)}
        .drawer .dhead h2{margin:0;font-size:18px}
        .drawer .dbody{flex:1;overflow-y:auto;padding:14px 16px}
        .hitem{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:13px 15px;margin-bottom:11px}
        .hitem .top{display:flex;justify-content:space-between;font-size:12px;color:var(--muted)}
        .hitem .rt{font-size:14px;margin:5px 0}
        .hitem .pr{font-weight:700;color:var(--verde)}
        .toast{position:fixed;left:50%;bottom:calc(env(safe-area-inset-bottom) + 20px);transform:translateX(-50%);z-index:60;background:#fff;color:#111;padding:12px 18px;border-radius:12px;font-weight:600;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,.4);opacity:0;pointer-events:none;transition:.2s;max-width:88%;text-align:center}
        .toast.show{opacity:1}
        .hidden{display:none !important}
        .spin{width:20px;height:20px;border:2.5px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:sp .7s linear infinite;display:inline-block}
        @keyframes sp{to{transform:rotate(360deg)}}
    </style>
</head>
<body>
<div id="app">
    <div id="map"></div>

    <div class="topbar">
        <div class="brand"><span style="font-size:18px">📍</span><b>Majes<span class="g">Go</span></b></div>
        <button class="iconbtn" id="btnMenu" title="Menú" aria-label="Menú">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
    </div>

    <button class="locbtn hidden" id="btnLoc" title="Mi ubicación">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3.2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="8.5"/></svg>
    </button>

    <!-- Bottom sheet: su contenido cambia según el estado -->
    <div class="sheet hidden" id="sheet"><div class="grab"></div><div id="sheetBody"></div></div>

    <!-- Auth -->
    <div class="overlay" id="auth">
        <div class="hero">
            <div class="big">🚕</div>
            <h1>Majes<span class="g">Go</span></h1>
            <p>Tu taxi en un toque · {{ $city }}</p>
        </div>
        <div class="authcard">
            <div class="err" id="authErr"></div>
            <div id="fName" class="hidden">
                <label>¿Cómo te llamas?</label>
                <input id="inName" type="text" placeholder="Tu nombre" autocomplete="name">
            </div>
            <label>Celular</label>
            <input id="inPhone" type="tel" inputmode="numeric" placeholder="9XXXXXXXX" autocomplete="tel">
            <label>Clave</label>
            <input id="inPass" type="password" placeholder="Tu clave" autocomplete="current-password">
            <button class="btn" id="btnAuth">Ingresar</button>
            <div class="toggle" id="authToggle">¿Primera vez? <a href="#" id="lnkToggle">Crea tu cuenta</a></div>
        </div>
    </div>

    <!-- Historial -->
    <div class="drawer" id="drawer">
        <div class="dhead">
            <button class="iconbtn" id="btnBack" style="background:#1c2026"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M15 18l-6-6 6-6"/></svg></button>
            <h2>Mis viajes</h2>
            <button class="btn ghost sm" id="btnLogout" style="margin-left:auto;width:auto;padding:9px 15px">Salir</button>
        </div>
        <div class="dbody" id="histBody"><p class="sub" style="text-align:center;color:var(--muted)">Cargando…</p></div>
    </div>

    <div class="toast" id="toast"></div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
window.MG = {
    center: [{{ $centerLat }}, {{ $centerLng }}],
    currency: @json($currency),
    csrf: document.querySelector('meta[name=csrf-token]').content,
};
</script>
<script src="/js/passenger.js?v=2"></script>
</body>
</html>
