<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>MajesGo Conductor</title>

    <link rel="manifest" href="/driver.webmanifest">
    <meta name="theme-color" content="#FFC107">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="MajesGo Conductor">
    <link rel="apple-touch-icon" href="/icons/driver-apple-180.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/driver-192.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        :root{
            --verde:#00C853; --verde-d:#00a344; --amarillo:#FFC107; --amarillo-d:#e0a800; --negro:#0D0D0D;
            --panel:#15181c; --panel-2:#1c2026; --line:#2a2f36; --muted:#8a94a0; --text:#F5F7FA;
        }
        *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
        html,body{margin:0;height:100%;font-family:'Poppins',system-ui,sans-serif;background:var(--negro);color:var(--text);overscroll-behavior:none}
        #app{height:100dvh;width:100%;position:relative;overflow:hidden}

        #map{position:absolute;inset:0;z-index:1;background:#0b1a10}
        .leaflet-container{background:#0b1a10;font-family:inherit}
        .leaflet-control-attribution{font-size:9px;opacity:.6}

        .pin{width:26px;height:26px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:2.5px solid #fff;box-shadow:0 3px 8px rgba(0,0,0,.4)}
        .pin.o{background:var(--verde)} .pin.d{background:#ff5252}
        .car{font-size:30px;filter:drop-shadow(0 4px 6px rgba(0,0,0,.5))}
        .medot{width:16px;height:16px;background:var(--amarillo);border:3px solid #fff;border-radius:50%;box-shadow:0 0 0 6px rgba(255,193,7,.25)}
        .mapmode{position:absolute;top:calc(env(safe-area-inset-top) + 66px);left:14px;z-index:19;width:40px;height:40px;border-radius:50%;border:0;background:rgba(13,13,13,.72);backdrop-filter:blur(8px);font-size:17px;cursor:pointer;display:grid;place-items:center;box-shadow:0 2px 8px rgba(0,0,0,.3)}
        /* Zonas locales en el mapa del conductor (mismo criterio que el pasajero) */
        .zonemk{pointer-events:none}
        .zonemk .zpin{display:block;position:absolute;left:-7px;top:-20px;width:14px;height:20px;filter:drop-shadow(0 2px 3px rgba(0,0,0,.5))}
        .zonemk .zpin path{fill:#3d7bd6} .zonemk .zpin circle{fill:#fff}
        .zonemk .zname{display:block;position:absolute;left:0;transform:translateX(-50%);top:6px;white-space:nowrap;max-width:140px;overflow:hidden;text-overflow:ellipsis;font-size:11px;font-weight:700;color:#dfe7ff;background:rgba(20,26,40,.72);border:1px solid rgba(138,180,255,.4);padding:2px 8px;border-radius:9px;box-shadow:0 1px 4px rgba(0,0,0,.45)}
        .zonemk.zprimary .zpin path{fill:#009d4f}
        #app.lightmap .zonemk .zname{color:#173a70;background:rgba(255,255,255,.9);border-color:rgba(60,110,200,.45)}
        /* durante la oferta: ocultar todas las zonas y resaltar solo la del recojo */
        #app.offering .zonemk{display:none!important}
        .offerzone{pointer-events:none}
        .offerzone .ozlabel{position:absolute;left:0;top:-54px;transform:translateX(-50%);white-space:nowrap;max-width:170px;overflow:hidden;text-overflow:ellipsis;font-size:12.5px;font-weight:800;color:#fff;background:#009d4f;padding:4px 12px;border-radius:12px;box-shadow:0 3px 12px rgba(0,0,0,.55);border:1.5px solid rgba(255,255,255,.9)}
        .offerzone .ozlabel.dest{background:#e23b3b}
        /* ---- Home rediseñado del conductor ---- */
        .dstatetxt{font-weight:700;font-size:15px;margin:2px 0 12px;color:#fff}
        /* ⚠ El COLOR indica el ESTADO, no la acción: rojo = desconectado, verde = en línea.
           Por eso el deslizador para CONECTARSE es rojo (todavía no recibe viajes) y al conectarse
           todo el bloque pasa a verde. El deslizador para desconectarse queda gris/neutro:
           mientras está en línea, el único color del panel debe ser el verde. */
        .slide{position:relative;height:60px;border-radius:16px;background:linear-gradient(90deg,#3a1414,#2c1212);border:1px solid rgba(255,90,90,.42);overflow:hidden;display:flex;align-items:center;justify-content:center;user-select:none}
        .slide .slidetext{color:#ff9d9d;font-weight:700;font-size:15px;pointer-events:none}
        .slide .knob{position:absolute;left:4px;top:4px;width:52px;height:52px;border-radius:13px;background:#ff4d4d;display:grid;place-items:center;cursor:grab;touch-action:none;box-shadow:0 3px 10px rgba(0,0,0,.4);z-index:2}
        .slide .knob svg{width:24px;height:24px}
        /* Variante "desliza para desconectarte" (neutra, gesto deliberado para no desconectar por error) */
        .onlinebar + .slide.off,.offlinebar + .slide{margin-top:10px}
        .slide.off{background:linear-gradient(90deg,#2b2f36,#23272e);border-color:rgba(255,255,255,.13)}
        .slide.off .slidetext{color:#aeb6c0}
        .slide.off .knob{background:#454d57}
        .onlinebar,.offlinebar{display:flex;align-items:center;gap:10px;height:56px;padding:0 8px 0 18px;border-radius:16px;color:#fff;font-weight:800;font-size:15px}
        .onlinebar{background:linear-gradient(90deg,rgba(0,200,83,.22),rgba(0,200,83,.08));border:1px solid rgba(0,200,83,.4)}
        .offlinebar{background:linear-gradient(90deg,rgba(255,82,82,.2),rgba(255,82,82,.06));border:1px solid rgba(255,82,82,.42)}
        .onlinebar .odot,.offlinebar .odot{width:11px;height:11px;border-radius:50%;flex:none}
        .onlinebar .odot{background:var(--verde);box-shadow:0 0 0 4px rgba(0,200,83,.25);animation:odotp 1.6s infinite}
        .offlinebar .odot{background:#ff5252;box-shadow:0 0 0 4px rgba(255,82,82,.22)}
        .offlinebar span:last-child{font-size:14px}
        @keyframes odotp{0%,100%{opacity:1}50%{opacity:.4}}
        .onlinebar .offbtn{margin-left:auto;background:#2a2f37;border:0;color:#cfd6de;font-weight:700;font-size:13px;padding:9px 14px;border-radius:11px;cursor:pointer}
        .essrow{display:grid;grid-template-columns:1.45fr 1fr;gap:9px;margin-top:12px}
        .statgrid3{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:9px}
        .statcell{background:var(--panel-2,#1a1e24);border:1px solid rgba(255,255,255,.06);border-radius:14px;padding:12px;position:relative}
        .statcell.earn{background:linear-gradient(120deg,#12351f,#0e2c1a);border-color:rgba(0,200,83,.28)}
        .statcell.saldocell{border-color:rgba(255,193,7,.28)}
        .statcell .sv{font-weight:800;font-size:18px;color:#fff;line-height:1.1}
        .statcell.earn .sv{font-size:26px}
        .statcell .sv.g{color:#37e08a} .statcell .sv.a{color:#ffca3a}
        .statcell .sl{color:#8b94a0;font-size:11.5px;margin-top:3px}
        .minibtn{margin-top:8px;background:var(--amarillo);color:#3a2e00;border:0;font-weight:700;font-size:11.5px;padding:6px 10px;border-radius:9px;cursor:pointer}
        /* auto del conductor con anillo tipo radar */
        .medriver{position:relative}
        .medriver .car{position:absolute;left:-19px;top:-32px;width:38px;height:64px;filter:drop-shadow(0 3px 5px rgba(0,0,0,.55));z-index:2;transform-origin:50% 50%;transition:transform .5s ease-out}
        .medriver .car svg{display:block;width:100%;height:100%}
        .medriver .radar{position:absolute;left:-48px;top:-48px;width:96px;height:96px;border-radius:50%;background:radial-gradient(circle,rgba(0,200,83,.28),rgba(0,200,83,.06) 58%,transparent 72%)}
        /* anillo fijo: marca dónde está el conductor aunque la onda esté a mitad de camino */
        .medriver .radar::before{content:"";position:absolute;inset:5px;border-radius:50%;border:2px solid rgba(0,200,83,.72);box-shadow:0 0 10px rgba(0,200,83,.45)}
        .medriver .radar::after{content:"";position:absolute;inset:0;border-radius:50%;border:2px solid rgba(0,200,83,.55);animation:radarpulse 2.3s ease-out infinite}
        @keyframes radarpulse{0%{transform:scale(.35);opacity:.85}100%{transform:scale(1);opacity:0}}
        #app.lightmap #map,#app.lightmap #navmap{background:#e6e9e4}
        #app.lightmap .leaflet-container{background:#e6e9e4}
        .pulsebtn{animation:btnpulse 1.15s ease-out infinite}
        @keyframes btnpulse{0%{box-shadow:0 0 0 0 rgba(255,193,7,.8)}70%{box-shadow:0 0 0 16px rgba(255,193,7,0)}100%{box-shadow:0 0 0 0 rgba(255,193,7,0)}}

        .topbar{position:absolute;top:0;left:0;right:0;z-index:20;display:flex;align-items:center;justify-content:space-between;
            padding:calc(env(safe-area-inset-top) + 12px) 14px 12px;pointer-events:none}
        .topbar .brand,.topbar .iconbtn,.topbar .saldo{pointer-events:auto}
        .brand{display:flex;align-items:center;gap:7px;background:rgba(13,13,13,.72);backdrop-filter:blur(8px);padding:7px 13px;border-radius:999px}
        .brand b{font-size:15px} .brand .g{color:var(--verde)}
        .brand .tag{font-size:9px;font-weight:700;color:#3a2e00;background:var(--amarillo);padding:2px 7px;border-radius:999px;margin-left:2px}
        .rgt{display:flex;align-items:center;gap:9px}
        .saldo{display:flex;flex-direction:column;align-items:flex-end;background:rgba(13,13,13,.72);backdrop-filter:blur(8px);padding:6px 13px;border-radius:14px;cursor:pointer}
        .saldo b{font-size:15px;line-height:1;color:var(--amarillo)} .saldo small{font-size:9px;color:var(--muted)}
        .iconbtn{pointer-events:auto;width:42px;height:42px;border-radius:50%;background:rgba(13,13,13,.72);backdrop-filter:blur(8px);
            border:0;color:#fff;display:grid;place-items:center;cursor:pointer}
        .iconbtn svg{width:22px;height:22px}

        .sheet{position:absolute;left:0;right:0;bottom:0;z-index:22;background:var(--panel);
            border-radius:22px 22px 0 0;box-shadow:0 -10px 40px rgba(0,0,0,.5);
            padding:0 18px calc(env(safe-area-inset-bottom) + 18px);max-height:82dvh;overflow-y:auto;
            transition:transform .28s cubic-bezier(.4,0,.2,1);will-change:transform}
        .grab{width:100%;height:28px;margin:0 0 6px;cursor:grab;touch-action:none;position:sticky;top:0;background:var(--panel);z-index:3}
        .grab::after{content:"";position:absolute;left:50%;top:11px;transform:translateX(-50%);width:42px;height:5px;border-radius:3px;background:#3a414a}
        .sheet h2{margin:0 0 3px;font-size:19px}
        .sheet .sub{color:var(--muted);font-size:13px;margin-bottom:14px}

        .routeinfo{display:flex;gap:10px;margin:4px 0 14px}
        .chip{flex:1;background:var(--panel-2);border:1px solid var(--line);border-radius:12px;padding:10px;text-align:center}
        .chip .v{font-size:17px;font-weight:800}.chip .l{color:var(--muted);font-size:11px}
        .chip .v.g{color:var(--verde)}.chip .v.a{color:var(--amarillo)}

        .btn{width:100%;border:0;cursor:pointer;font-family:inherit;font-weight:700;font-size:16px;padding:15px;border-radius:14px;background:var(--verde);color:#fff;display:flex;align-items:center;justify-content:center;gap:9px;transition:.15s}
        .btn:hover{background:var(--verde-d)} .btn:disabled{opacity:.5;cursor:default}
        .btn.amber{background:var(--amarillo);color:#3a2e00}
        .btn.amber:hover{background:var(--amarillo-d)}
        .btn.ghost{background:#2a3038;color:#fff} .btn.ghost:hover{background:#333a43}
        .btn.danger{background:#3a1f1f;color:#ff7a7a}
        .btn.sm{font-size:14px;padding:12px}
        .btn.block{background:#2a1f2f;color:#d9a7ff}

        /* Toggle conectarse */
        .connectrow{text-align:center;padding:6px 0 2px}
        .bigswitch{width:110px;height:110px;border-radius:50%;margin:6px auto 12px;border:0;cursor:pointer;font-family:inherit;
            display:grid;place-items:center;font-weight:800;font-size:15px;color:#fff;box-shadow:0 8px 26px rgba(0,0,0,.45);position:relative}
        .bigswitch .ic{font-size:34px;line-height:1;margin-bottom:3px}
        .bigswitch.off{background:radial-gradient(circle at 50% 35%,#2a3038,#1a1e23)}
        .bigswitch.on{background:radial-gradient(circle at 50% 35%,var(--verde),var(--verde-d))}
        .pulse{position:absolute;inset:0;border-radius:50%;border:2px solid var(--verde);animation:pp 2s ease-out infinite;opacity:0}
        @keyframes pp{0%{transform:scale(1);opacity:.7}100%{transform:scale(1.5);opacity:0}}
        .statetxt{font-size:15px;font-weight:700}
        .statesub{color:var(--muted);font-size:12.5px;margin-top:2px}

        /* Alerta de saldo */
        .warn{display:flex;gap:9px;align-items:flex-start;background:rgba(255,193,7,.1);border:1px solid rgba(255,193,7,.35);border-radius:12px;padding:11px 13px;margin-bottom:12px;font-size:12.5px;color:#ffd98a}
        .warn.red{background:rgba(255,82,82,.1);border-color:rgba(255,82,82,.4);color:#ff9d9d}

        /* Tarjeta pasajero */
        .drv{display:flex;align-items:center;gap:13px;margin-bottom:14px}
        .drv .av{width:52px;height:52px;border-radius:15px;background:linear-gradient(135deg,var(--amarillo),var(--amarillo-d));display:grid;place-items:center;font-size:22px;font-weight:800;color:#3a2e00;flex:none}
        .drv .nm{font-size:16px;font-weight:700}
        .drv .car2{color:var(--muted);font-size:12.5px}
        .drv .rate{margin-left:auto;text-align:right}
        .drv .rate b{font-size:15px}.drv .rate small{display:block;color:var(--muted);font-size:11px}

        .statusband{background:var(--panel-2);border-left:3px solid var(--amarillo);border-radius:10px;padding:11px 14px;margin-bottom:13px;font-weight:600;font-size:14.5px}
        .statusband small{display:block;color:var(--muted);font-weight:500;font-size:12px;margin-top:2px}

        .addr{display:flex;gap:10px;align-items:flex-start;margin-bottom:9px}
        .addr .dot{width:11px;height:11px;border-radius:50%;flex:none;margin-top:4px}
        .addr .dot.o{background:var(--verde)} .addr .dot.d{background:#ff5252}
        .addr .tx{font-size:13.5px;line-height:1.3}
        .addr .tx small{display:block;color:var(--muted);font-size:11px}
        .acts{display:flex;gap:10px;margin-top:4px}

        /* Solicitud entrante */
        /* solo la tarjeta abajo; el mapa arriba queda CLARO y visible (recojo+ruta+zona) */
        .reqwrap{position:absolute;left:0;right:0;bottom:0;z-index:38;display:flex;align-items:flex-end;padding:0}
        .reqcard{background:var(--panel);width:100%;border-radius:22px 22px 0 0;padding:16px 18px calc(env(safe-area-inset-bottom) + 18px);box-shadow:0 -12px 44px rgba(0,0,0,.6);animation:slideup .25s ease}
        @keyframes slideup{from{transform:translateY(40px);opacity:.4}to{transform:none;opacity:1}}
        .reqhead{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
        .reqhead .ping{display:inline-flex;align-items:center;gap:6px;color:var(--verde);font-weight:700;font-size:13px}
        .reqhead .ping i{width:9px;height:9px;border-radius:50%;background:var(--verde);animation:blink 1s infinite}
        @keyframes blink{50%{opacity:.3}}
        /* Volver de la ficha de un viaje a la lista */
        .backlist{background:none;border:0;color:var(--verde);font-weight:700;font-size:13px;padding:4px 0;
                  cursor:pointer;-webkit-tap-highlight-color:transparent}
        /* Lista de viajes disponibles: los ve todos y elige cuál tomar */
        .sortrow{display:flex;gap:8px;margin:0 0 10px}
        .sortb{flex:1;min-height:36px;border:1px solid var(--line);background:#12151a;color:var(--muted);
               border-radius:10px;font-size:12.5px;font-weight:700;cursor:pointer;-webkit-tap-highlight-color:transparent}
        .sortb.on{background:rgba(0,230,118,.12);border-color:rgba(0,230,118,.4);color:#7DE9AC}
        /* alto tope: si hay muchos viajes la lista rueda sola y el mapa sigue visible arriba */
        .reqlist{display:flex;flex-direction:column;gap:8px;max-height:46vh;overflow-y:auto;-webkit-overflow-scrolling:touch}
        .reqrow{display:flex;align-items:center;gap:11px;width:100%;text-align:left;padding:11px 12px;
                background:var(--panel-2);border:1px solid var(--line);border-radius:13px;color:#F5F7FA;
                cursor:pointer;-webkit-tap-highlight-color:transparent}
        .reqrow:active{transform:scale(.99);border-color:rgba(0,230,118,.45)}
        .rr-money{flex:none;min-width:78px;display:flex;flex-direction:column;align-items:flex-start}
        .rr-money b{font-size:19px;font-weight:800;line-height:1.1}
        .rr-money small{color:var(--muted);font-size:10.5px;margin-top:2px}
        .rr-tx{flex:1;min-width:0;display:flex;flex-direction:column;gap:4px}
        .rr-tx .rr-o,.rr-tx .rr-d{display:block;font-size:13px;line-height:1.25;
                                  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .rr-tx .rr-d{color:#cfd6de}
        .rr-tx small{display:block;color:var(--muted);font-size:10.5px;font-weight:500;
                     overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .rr-go{flex:none;color:var(--muted);font-size:22px;line-height:1}
        .bar{height:4px;background:#2a3038;border-radius:3px;overflow:hidden;margin-bottom:12px}
        .bar i{display:block;height:100%;background:var(--amarillo);width:100%;transition:width 1s linear}
        .fare{text-align:center;margin:6px 0 12px}
        .fare .n{font-size:40px;font-weight:800}.fare .n .cur{font-size:20px;color:var(--muted)}
        .fare .l{color:var(--muted);font-size:12.5px}
        .earnnote{text-align:center;font-size:11.5px;color:var(--muted);margin:-6px 0 12px}
        .earnnote.lock{color:#7DE9AC;background:rgba(0,230,118,.09);border:1px solid rgba(0,230,118,.22);border-radius:10px;padding:6px 10px;margin:-6px 0 12px}
        /* Desglose del precio: viaje A→B + acercamiento del conductor hasta el pasajero */
        .breakdown{border:1px solid var(--line);border-radius:11px;padding:9px 11px;margin:0 0 12px;font-size:12.5px}
        .breakdown div{display:flex;justify-content:space-between;align-items:baseline;gap:10px;padding:3px 0}
        .breakdown span{color:var(--muted)}
        .breakdown b{color:#F5F7FA;white-space:nowrap}
        .breakdown .tot{border-top:1px solid var(--line);margin-top:4px;padding-top:6px}
        .breakdown .tot span{color:#F5F7FA;font-weight:600}
        .breakdown .tot b{color:#00E676;font-size:14.5px}
        /* Aviso ámbar: con contraoferta el precio todavía no está cerrado */
        .earnnote.warn{color:#FFD97A;background:rgba(255,193,7,.10);border:1px solid rgba(255,193,7,.28);border-radius:10px;padding:6px 10px;margin:-6px 0 12px}
        /* Contraoferta: importes cerrados que el conductor puede pedir de más */
        .bumps{margin:2px 0 12px}
        .bumps .bl{color:var(--muted);font-size:11.5px;text-align:center;margin-bottom:7px}
        .bumps .brow{display:flex;gap:8px;justify-content:center}
        .bump{flex:1;max-width:150px;min-height:44px;border:1px solid var(--line);background:#12151a;color:#F5F7FA;
              border-radius:11px;font-size:14px;font-weight:700;cursor:pointer;-webkit-tap-highlight-color:transparent}
        .bump:active{transform:scale(.97)}
        .bump.on{background:rgba(255,193,7,.16);border-color:var(--amarillo);color:#FFD97A}
        .vehbox{margin-bottom:14px}
        .vehimg{width:100%;height:170px;object-fit:cover;border-radius:12px;border:1px solid var(--line);display:block;background:#12151a}
        .vehempty{width:100%;height:110px;border-radius:12px;border:1px dashed var(--line);background:#12151a;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;font-size:30px;color:var(--muted)}
        .vehempty span{font-size:12.5px}
        .vehnote{font-size:11.5px;color:var(--muted);text-align:center;margin:8px 0 10px}
        .vehacts{display:flex;gap:10px}
        .vehacts .btn{flex:1}
        .vehbox{margin-bottom:20px}
        .vehimg.face{height:200px;object-fit:cover;object-position:center 30%}
        .ptitle{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:13.5px;font-weight:600;margin:0 0 8px}
        .pchip{font-size:11px;font-weight:700;border-radius:999px;padding:4px 9px;white-space:nowrap}
        .pchip.wait{color:#ffd98a;background:rgba(255,193,7,.12);border:1px solid rgba(255,193,7,.3)}
        .pchip.ok{color:#7DE9AC;background:rgba(0,230,118,.1);border:1px solid rgba(0,230,118,.25)}
        .pchip.bad{color:#ff9a9a;background:rgba(255,82,82,.11);border:1px solid rgba(255,82,82,.3)}
        .preason{font-size:12px;line-height:1.45;color:#ff9a9a;background:rgba(255,82,82,.08);border:1px solid rgba(255,82,82,.22);border-radius:10px;padding:9px 11px;margin:9px 0 2px}
        .photoblock{font-size:12.5px;line-height:1.45;color:#ffd98a;background:rgba(255,193,7,.08);border:1px dashed rgba(255,193,7,.4);border-radius:12px;padding:11px 13px;margin-bottom:14px}
        .btn.ghost.danger{color:#ff8a80;border-color:rgba(255,138,128,.35)}

        .stars{display:flex;justify-content:center;gap:8px;margin:8px 0 18px}
        .stars span{font-size:36px;cursor:pointer;filter:grayscale(1);opacity:.5;transition:.1s}
        .stars span.on{filter:none;opacity:1}

        /* Auth overlay */
        .overlay{position:absolute;inset:0;z-index:40;background:radial-gradient(1000px 500px at 30% -10%,#3a2e00,#0d0d0d 60%);
            display:flex;flex-direction:column;justify-content:flex-end;padding:24px 22px calc(env(safe-area-inset-bottom) + 26px)}
        .overlay .hero{flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;gap:8px}
        .overlay .hero .big{font-size:52px}
        .overlay .hero h1{font-size:24px;margin:6px 0 0}.overlay .hero h1 .g{color:var(--verde)}
        .overlay .hero .tag{font-size:11px;font-weight:700;color:#3a2e00;background:var(--amarillo);padding:3px 12px;border-radius:999px}
        .overlay .hero p{color:var(--muted);font-size:13.5px;margin:4px 0 0}
        .authcard label{display:block;font-size:12.5px;color:var(--muted);margin:0 0 6px 2px}
        .authcard input{width:100%;padding:14px;border-radius:13px;border:1px solid var(--line);background:var(--panel-2);color:#fff;font-family:inherit;font-size:15px;margin-bottom:12px}
        .authcard input:focus{outline:0;border-color:var(--amarillo)}
        .hint{text-align:center;color:var(--muted);font-size:12px;margin-top:14px}
        .err{background:#3a1f1f;color:#ff9d9d;border-radius:11px;padding:10px 13px;font-size:13px;margin-bottom:12px;display:none}

        /* Drawer (saldo/menu) */
        .drawer{position:absolute;inset:0;z-index:45;background:var(--negro);transform:translateX(100%);transition:transform .25s;display:flex;flex-direction:column}
        .drawer.open{transform:none}
        .drawer .dhead{display:flex;align-items:center;gap:12px;padding:calc(env(safe-area-inset-top) + 14px) 16px 14px;border-bottom:1px solid var(--line)}
        .drawer .dhead h2{margin:0;font-size:18px}
        .drawer .dbody{flex:1;overflow-y:auto;padding:14px 16px}
        .balcard{background:linear-gradient(135deg,#1e2228,#15181c);border:1px solid var(--line);border-radius:18px;padding:18px;margin-bottom:16px;text-align:center}
        .balcard .n{font-size:36px;font-weight:800;color:var(--amarillo)}
        .balcard .l{color:var(--muted);font-size:12px}
        .balcard .canr{margin-top:8px;font-size:12px;font-weight:600}
        .balcard .canr.ok{color:var(--verde)} .balcard .canr.no{color:#ff7a7a}
        .seg{font-weight:700;font-size:13.5px;margin:16px 0 9px;color:var(--muted)}
        .tiers{display:flex;gap:8px;margin-bottom:10px}
        .tiers button{flex:1;padding:11px 0;border-radius:12px;border:1px solid var(--line);background:var(--panel-2);color:#fff;font-family:inherit;font-weight:700;font-size:14px;cursor:pointer}
        .tiers button.on{border-color:var(--amarillo);background:rgba(255,193,7,.12)}
        .field{width:100%;padding:13px;border-radius:12px;border:1px solid var(--line);background:var(--panel-2);color:#fff;font-family:inherit;font-size:14.5px;margin-bottom:10px}
        .pay2{display:flex;gap:10px;margin-bottom:10px}
        .pay2 button{flex:1;padding:11px;border-radius:12px;border:1px solid var(--line);background:var(--panel-2);color:var(--text);font-family:inherit;font-weight:600;font-size:13.5px;cursor:pointer}
        .pay2 button.on{border-color:var(--amarillo);background:rgba(255,193,7,.12)}
        .mv{display:flex;justify-content:space-between;align-items:center;padding:11px 0;border-bottom:1px solid var(--line)}
        .mv .t{font-size:13.5px}.mv .t small{display:block;color:var(--muted);font-size:11px}
        .mv .a{font-weight:700;font-size:14px}.mv .a.pos{color:var(--verde)}.mv .a.neg{color:#ff7a7a}
        .mv .a small{display:block;color:var(--muted);font-weight:500;font-size:11px;text-align:right}
        .hitem{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:13px 15px;margin-bottom:11px}
        .hitem .top{display:flex;justify-content:space-between;font-size:12px;color:var(--muted)}
        .hitem .rt{font-size:13.5px;margin:5px 0}
        .hitem .pr{font-weight:700;color:var(--amarillo)}
        .pend{background:rgba(255,193,7,.08);border:1px dashed rgba(255,193,7,.4);border-radius:12px;padding:10px 13px;font-size:12.5px;color:#ffd98a;margin-bottom:10px}
        .pend .vlink{display:inline-block;margin-top:6px;color:#ffd98a;text-decoration:underline;font-weight:600}

        /* ===== Pantalla de pago de la recarga ===== */
        .paypanel{z-index:50}
        .payamt{background:linear-gradient(135deg,#1e2228,#15181c);border:1px solid var(--line);border-radius:18px;padding:15px;margin-bottom:14px;text-align:center}
        .payamt .n{font-size:31px;font-weight:800;color:var(--amarillo);line-height:1.1}
        .payamt .l{color:var(--muted);font-size:12px;margin-top:3px}
        .paystep{display:flex;align-items:center;gap:9px;font-weight:700;font-size:13.5px;margin:18px 0 10px}
        .paystep i{width:22px;height:22px;flex:0 0 22px;border-radius:50%;background:var(--amarillo);color:#111;font-style:normal;font-size:12px;font-weight:800;display:grid;place-items:center}
        .paycard{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:4px 14px;margin-bottom:10px}
        .payrow{display:flex;align-items:center;gap:10px;padding:11px 0;border-bottom:1px solid var(--line)}
        .payrow:last-child{border-bottom:0}
        .payrow .pd{min-width:0;flex:1}
        .payrow .pl{color:var(--muted);font-size:11.5px;margin-bottom:2px}
        .payrow .pv{font-size:15px;font-weight:600;word-break:break-all}
        .payrow .pv.big{font-size:19px;font-weight:800;letter-spacing:.4px}
        .copybtn{flex:0 0 auto;padding:9px 13px;border-radius:10px;border:1px solid var(--line);background:var(--panel-2);color:var(--text);font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer}
        .copybtn.done{border-color:var(--verde);color:var(--verde)}
        .paynote{color:var(--muted);font-size:12px;line-height:1.45;margin:-2px 0 4px}
        .vouch{border:1px dashed var(--line);border-radius:14px;padding:16px;text-align:center;margin-bottom:10px;background:var(--panel-2)}
        .vouch .ic{font-size:26px}
        .vouch .tx{color:var(--muted);font-size:12.5px;margin-top:5px;line-height:1.4}
        .vouchprev{position:relative;border-radius:14px;overflow:hidden;border:1px solid var(--line);margin-bottom:10px}
        .vouchprev img{display:block;width:100%;max-height:230px;object-fit:contain;background:#0b0d10}
        .vouchprev .rm{position:absolute;top:8px;right:8px;background:rgba(10,12,16,.82);border:1px solid var(--line);color:#fff;border-radius:10px;padding:7px 11px;font-family:inherit;font-size:12px;font-weight:600;cursor:pointer}
        .chk{display:flex;align-items:flex-start;gap:10px;padding:12px 13px;border-radius:12px;border:1px solid var(--line);background:var(--panel-2);margin-bottom:12px;cursor:pointer;font-size:13px;line-height:1.4}
        .chk input{width:19px;height:19px;flex:0 0 19px;margin-top:1px;accent-color:var(--verde)}
        .btn.amber[disabled]{opacity:.45;cursor:not-allowed}

        /* ===== Modo navegación ===== */
        .navmode{position:absolute;inset:0;z-index:44;background:#0b1a10}
        #navmap{position:absolute;inset:0;z-index:1;background:#0b1a10}
        .navtop{position:absolute;top:0;left:0;right:0;z-index:6;display:flex;align-items:stretch;gap:9px;padding:calc(env(safe-area-inset-top) + 10px) 12px 10px}
        .navinstr{flex:1;background:rgba(13,13,13,.85);backdrop-filter:blur(8px);border-radius:16px;padding:11px 16px;display:flex;flex-direction:column;justify-content:center;min-width:0}
        .navinstr .d{font-size:25px;font-weight:800;line-height:1.05}
        .navinstr .t{font-size:12px;color:var(--muted);margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .navclose{width:46px;border:0;border-radius:16px;background:rgba(13,13,13,.85);backdrop-filter:blur(8px);color:#fff;font-size:19px;cursor:pointer;flex:none}
        .navspeed{width:64px;flex:none;background:rgba(13,13,13,.85);backdrop-filter:blur(8px);border-radius:16px;display:flex;flex-direction:column;align-items:center;justify-content:center}
        .navspeed b{font-size:21px;font-weight:800;line-height:1;color:var(--amarillo)}
        .navspeed small{font-size:9px;color:var(--muted);margin-top:1px}
        .navarrow{width:44px;height:44px;transform-origin:50% 50%;transition:transform .3s ease;will-change:transform}
        .navrecenter{position:absolute;right:14px;bottom:104px;z-index:6;width:50px;height:50px;border-radius:50%;border:0;background:rgba(13,13,13,.88);color:var(--amarillo);font-size:23px;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.4)}
        .navbottom{position:absolute;left:0;right:0;bottom:0;z-index:6;padding:14px 16px calc(env(safe-area-inset-bottom) + 16px);background:linear-gradient(to top,rgba(11,26,16,.96) 55%,transparent)}
        .navmeta{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;font-size:12.5px;color:#cdd4dc}
        .navmeta b{color:#fff}

        /* ===== Chat ===== */
        .chat{position:absolute;inset:0;z-index:50;background:var(--negro);transform:translateX(100%);transition:transform .25s;display:flex;flex-direction:column}
        .chat.open{transform:none}
        .chead{display:flex;align-items:center;gap:12px;padding:calc(env(safe-area-inset-top) + 14px) 16px 14px;border-bottom:1px solid var(--line);background:var(--panel)}
        .chead .ctitle{font-weight:700;font-size:16px}
        .chead .csub{font-size:11.5px;color:var(--muted)}
        .cbody{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:8px}
        .bub{max-width:78%;padding:9px 13px;border-radius:15px;font-size:14px;line-height:1.35;word-wrap:break-word;overflow-wrap:anywhere}
        .bub .tm{display:block;font-size:9.5px;opacity:.6;margin-top:3px;text-align:right}
        .bub.me{align-self:flex-end;background:var(--amarillo);color:#3a2e00;border-bottom-right-radius:5px}
        .bub.them{align-self:flex-start;background:var(--panel-2);color:var(--text);border-bottom-left-radius:5px}
        .cempty{margin:auto;color:var(--muted);font-size:13px;text-align:center;padding:0 30px}
        .cinput{display:flex;gap:9px;padding:12px 14px calc(env(safe-area-inset-bottom) + 12px);border-top:1px solid var(--line);background:var(--panel)}
        .cinput input{flex:1;padding:12px 14px;border-radius:22px;border:1px solid var(--line);background:var(--panel-2);color:#fff;font-family:inherit;font-size:14px}
        .cinput input:focus{outline:0;border-color:var(--amarillo)}
        .cinput button{width:46px;height:46px;border-radius:50%;border:0;background:var(--amarillo);color:#3a2e00;font-size:18px;cursor:pointer;flex:none}
        .undot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#ff5252;margin-left:5px;vertical-align:middle}

        .toast{position:fixed;left:50%;bottom:calc(env(safe-area-inset-bottom) + 20px);transform:translateX(-50%);z-index:60;background:#fff;color:#111;padding:12px 18px;border-radius:12px;font-weight:600;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,.4);opacity:0;pointer-events:none;transition:.2s;max-width:88%;text-align:center}
        .toast.show{opacity:1}
        /* Modal de cancelación del pasajero */
        .modal{position:fixed;inset:0;z-index:70;background:rgba(3,5,8,.72);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:22px;animation:mfade .18s ease}
        @keyframes mfade{from{opacity:0}to{opacity:1}}
        .modalcard{width:100%;max-width:380px;background:var(--panel);border:1px solid var(--line);border-radius:20px;padding:22px 20px calc(env(safe-area-inset-bottom) + 20px);box-shadow:0 20px 60px rgba(0,0,0,.6);text-align:center;animation:mpop .22s cubic-bezier(.2,.8,.3,1.2)}
        @keyframes mpop{from{transform:scale(.9);opacity:.4}to{transform:scale(1);opacity:1}}
        .modalcard .micon{width:62px;height:62px;margin:2px auto 12px;border-radius:50%;background:rgba(255,82,82,.14);display:grid;place-items:center;font-size:30px}
        .modalcard h2{margin:0 0 6px;font-size:20px}
        .modalcard .msub{margin:0 0 16px;color:var(--muted);font-size:13.5px;line-height:1.4}
        .modalcard .reasonlbl{text-align:left;font-size:12px;color:var(--muted);font-weight:600;margin:0 0 8px 2px}
        .modalcard .reasons{display:flex;flex-direction:column;gap:9px;margin-bottom:18px}
        .modalcard .reason{width:100%;text-align:left;padding:13px 14px;border-radius:12px;border:1px solid var(--line);background:var(--panel-2);color:var(--text);font-family:inherit;font-size:14px;cursor:pointer;transition:.12s}
        .modalcard .reason:hover{border-color:#3a4150}
        .modalcard .reason.on{border-color:var(--verde);background:rgba(0,200,83,.14);color:#fff;font-weight:600}
        .modalcard .reason.on::after{content:"✓";float:right;color:var(--verde);font-weight:800}
        .hidden{display:none !important}
        .spin{width:20px;height:20px;border:2.5px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:sp .7s linear infinite;display:inline-block}
        @keyframes sp{to{transform:rotate(360deg)}}
    
        /* ===== Puntos de referencia del mapa (grifos, mercados, hoteles...) ===== */
        .poi{background:none!important;border:0!important}
        .poichip{display:grid;place-items:center;width:22px;height:22px;border-radius:50%;
            background:#eef1f5;box-shadow:0 2px 6px rgba(0,0,0,.55)}
        .poi.p1 .poichip{border:2px solid var(--amarillo,#FFC107)}
        .poichip svg{width:13px;height:13px}
        .poilbl{position:absolute;top:24px;left:50%;transform:translateX(-50%);
            font-size:10px;font-weight:700;color:#e8ecf1;white-space:nowrap;pointer-events:none;
            text-shadow:0 1px 3px #000,0 1px 7px #000,0 0 9px #000}
        /* en el mapa claro el texto blanco no se lee: se invierte */
        #app.lightmap .poilbl{color:#1d2129;text-shadow:0 1px 3px #fff,0 1px 7px #fff,0 0 9px #fff}
    </style>
</head>
<body>
<div id="app">
    <div id="map"></div>
    <button class="mapmode" id="btnMapMode" title="Modo claro / oscuro" aria-label="Modo claro u oscuro">🌙</button>

    <div class="topbar">
        <div class="brand"><span style="font-size:16px">🚕</span><b>Majes<span class="g">Go</span></b><span class="tag">CONDUCTOR</span></div>
        <div class="rgt">
            <button class="iconbtn" id="btnBell" title="Avisos" aria-label="Avisos">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
            </button>
            <button class="iconbtn" id="btnMenu" title="Menú" aria-label="Menú">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
        </div>
    </div>

    <!-- Bottom sheet principal -->
    <div class="sheet hidden" id="sheet"><div class="grab"></div><div id="sheetBody"></div></div>

    <!-- Solicitud entrante -->
    <div class="reqwrap hidden" id="reqwrap"><div class="reqcard" id="reqcard"></div></div>

    <!-- Auth -->
    <div class="overlay" id="auth">
        <div class="hero">
            <div class="big">🚕</div>
            <h1>Majes<span class="g">Go</span></h1>
            <span class="tag">CONDUCTOR</span>
            <p>Conéctate y empieza a recibir viajes · {{ $city }}</p>
        </div>
        <div class="authcard">
            <div class="err" id="authErr"></div>
            <label>Celular</label>
            <input id="inPhone" type="tel" inputmode="numeric" placeholder="9XXXXXXXX" autocomplete="tel">
            <label>Clave</label>
            <input id="inPass" type="password" placeholder="Tu clave" autocomplete="current-password">
            <button class="btn amber" id="btnAuth">Ingresar</button>
            <div class="hint">Tu cuenta de conductor la crea la central. Si no tienes acceso, comunícate con el administrador.</div>
        </div>
    </div>

    <!-- Modo navegación (pantalla completa) -->
    <div class="navmode hidden" id="navmode">
        <div id="navmap"></div>
        <div class="navtop">
            <button class="navclose" id="navClose" aria-label="Cerrar navegación">✕</button>
            <div class="navinstr"><div class="d" id="navDist">—</div><div class="t" id="navTo">Hacia el pasajero</div></div>
            <div class="navspeed"><b id="navKmh">0</b><small>km/h</small></div>
        </div>
        <button class="navrecenter hidden" id="navRecenter" aria-label="Centrar mapa">◎</button>
        <div class="navbottom">
            <div class="navmeta"><span id="navPax">—</span><span id="navFare"></span></div>
            <div id="navAction"></div>
        </div>
    </div>

    <!-- Drawer: saldo, recargas, historial -->
    <div class="drawer" id="drawer">
        <div class="dhead">
            <button class="iconbtn" id="btnBack" style="background:#1c2026"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M15 18l-6-6 6-6"/></svg></button>
            <h2>Mi cuenta</h2>
            <button class="btn ghost sm" id="btnLogout" style="margin-left:auto;width:auto;padding:9px 15px">Salir</button>
        </div>
        <div class="dbody" id="drawerBody"><p class="sub" style="text-align:center;color:var(--muted)">Cargando…</p></div>
    </div>

    <!-- Pago de la recarga: datos para Yape/Plin/transferencia + comprobante (lo llena driver.js) -->
    <div class="drawer paypanel" id="payPanel">
        <div class="dhead">
            <button class="iconbtn" id="payBack" style="background:#1c2026"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M15 18l-6-6 6-6"/></svg></button>
            <h2>Pagar mi recarga</h2>
        </div>
        <div class="dbody" id="payBody"></div>
    </div>

    <!-- Chat con el pasajero -->
    <div class="chat" id="chat">
        <div class="chead">
            <button class="iconbtn" id="chatBack" style="background:#1c2026"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M15 18l-6-6 6-6"/></svg></button>
            <div><div class="ctitle" id="chatTitle">Chat con el pasajero</div><div class="csub" id="chatSub"></div></div>
            {{-- Denunciar al pasajero sin salir del chat: es donde el conductor está cuando algo se pone feo --}}
            <button class="iconbtn" id="chatReport" title="Denunciar al pasajero" aria-label="Denunciar al pasajero" style="margin-left:auto;background:#1c2026;color:#ff8a80"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l9 16H3l9-16z"/><path d="M12 9v4.2"/><circle cx="12" cy="16.4" r=".9" fill="currentColor" stroke="none"/></svg></button>
        </div>
        <div class="cbody" id="chatBody"></div>
        <div class="cinput"><input id="chatIn" placeholder="Escribe un mensaje…" maxlength="500" autocomplete="off"><button id="chatSend" aria-label="Enviar">➤</button></div>
    </div>

    <!-- Modal: el pasajero canceló la carrera (contenido lo llena driver.js) -->
    <div class="modal hidden" id="cancelModal"></div>

    <!-- Modal de denuncia al pasajero (lo llena driver.js) -->
    <div class="modal hidden" id="reportModal"></div>

    <div class="toast" id="toast"></div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-rotate@0.2.8/dist/leaflet-rotate-src.js"></script>
<script>
window.MG = {
    center: [{{ $centerLat }}, {{ $centerLng }}],
    currency: @json($currency),
    csrf: document.querySelector('meta[name=csrf-token]').content,
    vapidPublic: @json(config('services.webpush.public_key')),
    alertSound: @json($alertSound),
    // Motivos de denuncia contra el pasajero. Vienen del modelo para que la app y el
    // panel de la central hablen siempre de lo mismo.
    reportReasons: @json(\App\Models\UserReport::REASONS_ON_PASSENGER),
};
</script>
<script src="/js/pois.js?v=5"></script>
<script src="/js/majesgo-car.js?v=2"></script>
<script src="/js/driver.js?v=33"></script>
<script src="/js/native.js?v=1"></script>
</body>
</html>
