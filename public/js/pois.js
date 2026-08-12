/*
 * Puntos de referencia sobre el mapa (grifos, mercados, hoteles, bancos…).
 *
 * Lo usan la app del pasajero y la del conductor. El mapa de MajesGo no es Google Maps,
 * es Leaflet con imágenes de CARTO, así que los puntos los dibujamos nosotros con datos
 * de OpenStreetMap. Eso además nos deja elegir QUÉ se muestra: mostrar todo lo que trae
 * OSM deja el mapa ilegible (en El Pedregal la mitad son colegios y nidos).
 *
 * Reglas de dibujo:
 *   - Aparecen por importancia según el zoom: de lejos solo las referencias fuertes.
 *   - Separación mínima en pantalla, para que nunca se amontonen los iconos.
 *   - El nombre solo se escribe si no choca con otro ya escrito.
 */
(function (global) {
  'use strict';

  // Iconos vectoriales: se ven nítidos en cualquier pantalla y no cargan nada de afuera.
  var ICONS = {
    grifo:         '<path d="M4 21V5a2 2 0 0 1 2-2h5a2 2 0 0 1 2 2v16M3 21h11M6 8h5"/><path d="M16 8l2.5 2.5V17a1.8 1.8 0 0 0 3.5 0v-7l-3-3"/>',
    mercado:       '<path d="M4 8h16l-1.2 11.2a2 2 0 0 1-2 1.8H7.2a2 2 0 0 1-2-1.8L4 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
    hotel:         '<path d="M3 18v-9M3 13h13a4 4 0 0 1 4 4v1M21 18v-3"/><circle cx="7.5" cy="10.5" r="2"/>',
    banco:         '<path d="M3 10l9-5 9 5M5 10v8M10 10v8M14 10v8M19 10v8M3 20h18"/>',
    farmacia:      '<path d="M12 7v10M7 12h10"/><rect x="3.5" y="3.5" width="17" height="17" rx="4"/>',
    salud:         '<path d="M12 6v12M6 12h12"/>',
    terminal:      '<rect x="4" y="4" width="16" height="12" rx="2"/><path d="M4 10h16M7 20v-2M17 20v-2"/><circle cx="8" cy="16" r="0.6"/><circle cx="16" cy="16" r="0.6"/>',
    municipalidad: '<path d="M3 10l9-6 9 6M5 10v9M19 10v9M9 19v-6h6v6M3 20h18"/>',
    policia:       '<path d="M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6l7-3z"/>',
    comida:        '<path d="M6 3v8a2 2 0 0 0 4 0V3M8 11v10"/><path d="M17 3c-1.5 1.5-2 3-2 5s.7 3 2 3v10"/>',
    iglesia:       '<path d="M12 2v7M9.5 4.5h5M5 21V11l7-4 7 4v10M5 21h14M10 21v-5h4v5"/>',
    colegio:       '<path d="M3 9l9-4 9 4-9 4-9-4z"/><path d="M7 11v4c0 1.5 2.2 3 5 3s5-1.5 5-3v-4"/>',
    taller:        '<path d="M15.5 3.5a5 5 0 0 0-6.6 6.6L3 16v5h5l5.9-5.9a5 5 0 0 0 6.6-6.6l-3.2 3.2-2.8-2.8 3-3z"/>',
    otro:          '<circle cx="12" cy="10" r="3"/><path d="M12 21s-6.5-6-6.5-10.5a6.5 6.5 0 0 1 13 0C18.5 15 12 21 12 21z"/>'
  };

  var COLORS = {
    grifo: '#e0492f', mercado: '#e07b1f', hotel: '#2f6fe0', banco: '#1f8a4c',
    farmacia: '#d1315e', salud: '#d1315e', terminal: '#6a4fd8', municipalidad: '#4a5568',
    policia: '#2f4f8a', comida: '#c2761b', iglesia: '#7a6a55', colegio: '#3f7d8a',
    taller: '#6b7280', otro: '#6b7280'
  };

  function svg(cat) {
    var d = ICONS[cat] || ICONS.otro;
    return '<svg viewBox="0 0 24 24" fill="none" stroke="' + (COLORS[cat] || COLORS.otro) +
      '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + d + '</svg>';
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /**
   * Decide qué se dibuja y qué lleva nombre, para que nada se encime.
   *
   * Reparte por la pantalla, no por el mapa: descarta lo que caiga a menos de `spacing`
   * píxeles de algo ya colocado, y escribe el nombre solo si su caja no pisa otra ya escrita.
   * Devuelve [{x, y, item, label}] — label es '' si no hubo sitio para el texto.
   */
  /**
   * Espacio ocupado en la vista actual, COMPARTIDO entre capas.
   *
   * Las zonas y los puntos de referencia se dibujan por separado y en el mismo instante;
   * sin esto, una etiqueta de zona podía caer justo encima de la de un comercio.
   * Cada capa reemplaza SOLO lo suyo al redibujarse (el mapa lanza el evento varias veces
   * por gesto, y si acumulara sus propias marcas se bloquearía a sí misma y no dibujaría nada).
   */
  var frame = { key: '', layers: {} };

  function viewKey(map) {
    var c = map.getCenter(), sz = map.getSize();
    return map.getZoom() + ':' + c.lat.toFixed(5) + ':' + c.lng.toFixed(5) + ':' + sz.x + 'x' + sz.y;
  }

  function place(map, items, opts) {
    opts = opts || {};
    var id = opts.layer || 'default';
    var sx = (opts.spacing || [34, 30])[0], sy = (opts.spacing || [34, 30])[1];
    var max = opts.max || 100, maxLabels = opts.maxLabels == null ? 999 : opts.maxLabels;

    var key = viewKey(map);
    if (frame.key !== key) { frame = { key: key, layers: {} }; }
    frame.layers[id] = { spots: [], boxes: [] };   // esta capa se redibuja entera

    // lo que ocupan las OTRAS capas en esta misma vista
    var spots = [], boxes = [];
    for (var k in frame.layers) {
      if (k === id) continue;
      spots = spots.concat(frame.layers[k].spots);
      boxes = boxes.concat(frame.layers[k].boxes);
    }

    var mine = frame.layers[id];
    var bounds = map.getBounds();
    var placed = [], nLabels = 0;

    for (var i = 0; i < items.length && placed.length < max; i++) {
      var it = items[i];
      var lat = it.y != null ? it.y : it.lat, lng = it.x != null ? it.x : it.lng;
      if (!bounds.contains([lat, lng])) continue;

      var pt = map.latLngToContainerPoint([lat, lng]);
      var taken = spots.concat(mine.spots);
      var clash = false;
      for (var j = 0; j < taken.length; j++) {
        if (Math.abs(pt.x - taken[j].x) < sx && Math.abs(pt.y - taken[j].y) < sy) { clash = true; break; }
      }
      if (clash) continue;

      var label = '';
      if (opts.labels && nLabels < maxLabels) {
        var txt = String(opts.text ? opts.text(it) : (it.name || ''));
        var lim = opts.maxChars || 22;
        if (txt.length > lim) txt = txt.slice(0, lim - 1).trim() + '…';
        var w = txt.length * 6.4 + 8;
        var off = opts.labelOffset == null ? 22 : opts.labelOffset;
        var box = { x1: pt.x - w / 2, x2: pt.x + w / 2, y1: pt.y + off, y2: pt.y + off + 16 };
        var free = boxes.concat(mine.boxes).every(function (b) {
          return box.x2 < b.x1 || box.x1 > b.x2 || box.y2 < b.y1 || box.y1 > b.y2;
        });
        if (free && txt) { mine.boxes.push(box); label = txt; nLabels++; }
      }

      mine.spots.push({ x: pt.x, y: pt.y });
      placed.push({ x: pt.x, y: pt.y, item: it, label: label });
    }

    return placed;
  }

  /**
   * Conecta la capa a un mapa de Leaflet. Devuelve un objeto con destroy() por si
   * hay que soltarla (el modo navegación del conductor usa su propio mapa).
   */
  function attach(map, opts) {
    opts = opts || {};
    var minZoom = opts.minZoom || 15;   // más lejos que esto, el mapa se ve mejor limpio
    var maxItems = opts.maxItems || 90; // techo duro: nunca dibujar de más
    var layer = L.layerGroup().addTo(map);
    var data = [];

    function draw() {
      layer.clearLayers();
      if (!data.length) return;

      var z = map.getZoom();
      if (z < minZoom) return;                                   // vista lejana: sin ruido

      var maxPrio = z >= 18 ? 3 : (z >= 17 ? 2 : 1);             // aparecen por importancia
      var placed = place(map, data.filter(function (p) { return p.p <= maxPrio; }), {
        layer: 'pois',
        max: maxItems,
        spacing: [34, 30],
        labels: z >= 17,
        maxLabels: 999,
        text: function (p) { return p.n; },
        maxChars: 22,
        labelOffset: 22
      });

      placed.forEach(function (o) {
        var p = o.item;
        var label = o.label ? '<span class="poilbl">' + esc(o.label) + '</span>' : '';
        L.marker([p.y, p.x], {
          interactive: false,               // no debe robarle el toque al mapa ni a los marcadores del viaje
          keyboard: false,
          zIndexOffset: -500,               // siempre por debajo del conductor, el pasajero y los pines
          icon: L.divIcon({
            className: 'poi p' + p.p,
            iconSize: [22, 22],
            iconAnchor: [11, 11],
            html: '<span class="poichip">' + svg(p.c) + '</span>' + label
          })
        }).addTo(layer);
      });
    }

    function load() {
      fetch('/api/map-pois', { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : { pois: [] }; })
        .then(function (d) { data = (d && d.pois) || []; draw(); })
        .catch(function () { /* sin puntos de referencia el mapa funciona igual */ });
    }

    map.on('zoomend moveend', draw);
    load();

    return {
      redraw: draw,
      destroy: function () { map.off('zoomend moveend', draw); layer.remove(); }
    };
  }

  global.MGPois = { attach: attach, place: place };
})(window);
