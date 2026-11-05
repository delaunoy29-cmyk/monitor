<?php
require_once __DIR__ . '/lib.php';
exigir_login();

$e = equipo_permitido((int)($_GET['id'] ?? 0));
if (!$e) { http_response_code(404); exit('Equipo no encontrado'); }
$id = (int)$e['id'];

// Marcar alarmas como vistas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vistas'])) {
    verificar_csrf();
    q('UPDATE alarmas SET vista = 1 WHERE equipo_id = ?', [$id]);
    header('Location: equipo.php?id=' . $id . '&r=' . urlencode($_GET['r'] ?? '24h')); exit;
}

$rangos = ['24h' => [86400, 'raw', '24 horas'], '7d' => [604800, 15, '7 días'], '30d' => [2592000, 13, '30 días']];
$r = isset($rangos[$_GET['r'] ?? '']) ? $_GET['r'] : '24h';
[$seg, $agrupar, $rtxt] = $rangos[$r];
$desde = date('Y-m-d H:i:s', time() - $seg);

// Exportar CSV
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9]+/i', '_', $e['nombre']) . '_' . $r . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['fecha', 'variable', 'valor'], ';');
    foreach (q('SELECT fecha, clave, valor FROM lecturas WHERE equipo_id = ? AND fecha >= ? ORDER BY fecha, clave', [$id, $desde]) as $l)
        fputcsv($out, [$l['fecha'], $l['clave'], str_replace('.', ',', $l['valor'])], ';');
    exit;
}

$vars = q('SELECT * FROM variables WHERE equipo_id = ? ORDER BY orden, id', [$id])->fetchAll();
$series = [];
foreach ($vars as $v) {
    if (!$v['grafico']) continue;
    if ($agrupar === 'raw') {
        $filas = q('SELECT fecha f, valor v FROM lecturas WHERE equipo_id = ? AND clave = ? AND fecha >= ? ORDER BY fecha',
                   [$id, $v['clave'], $desde])->fetchAll();
    } else {
        $filas = q("SELECT MIN(fecha) f, AVG(valor) v FROM lecturas WHERE equipo_id = ? AND clave = ? AND fecha >= ?
                    GROUP BY SUBSTR(fecha, 1, $agrupar) ORDER BY f", [$id, $v['clave'], $desde])->fetchAll();
    }
    $series[] = [
        'clave' => $v['clave'], 'nombre' => $v['nombre'], 'unidad' => $v['unidad'],
        'min' => $v['minimo'] === null ? null : (float)$v['minimo'],
        'max' => $v['maximo'] === null ? null : (float)$v['maximo'],
        'dec' => (int)$v['decimales'],
        'x' => array_map(fn($f) => strtotime($f['f']) * 1000, $filas),
        'y' => array_map(fn($f) => round((float)$f['v'], 4), $filas),
    ];
}

// Pivote: recorrido GPS
$mapa = null;
if ($e['tipo'] === 'pivote') {
    $lat = q('SELECT fecha, valor FROM lecturas WHERE equipo_id = ? AND clave = ? AND fecha >= ? ORDER BY fecha', [$id, 'lat', $desde])->fetchAll(PDO::FETCH_KEY_PAIR);
    $lon = q('SELECT fecha, valor FROM lecturas WHERE equipo_id = ? AND clave = ? AND fecha >= ? ORDER BY fecha', [$id, 'lon', $desde])->fetchAll(PDO::FETCH_KEY_PAIR);
    $ruta = [];
    foreach ($lat as $f => $la) if (isset($lon[$f])) $ruta[] = [(float)$la, (float)$lon[$f]];
    if (count($ruta) > 800) $ruta = array_values(array_filter($ruta, fn($k) => $k % (int)ceil(count($ruta) / 800) === 0, ARRAY_FILTER_USE_KEY));
    $mapa = ['centro' => $e['centro_lat'] !== null ? [(float)$e['centro_lat'], (float)$e['centro_lon']] : null,
             'radio' => $e['radio_m'] !== null ? (float)$e['radio_m'] : null, 'ruta' => $ruta];
}

$alarmas = q('SELECT * FROM alarmas WHERE equipo_id = ? ORDER BY fecha DESC LIMIT 30', [$id])->fetchAll();
$sin_ver = count(array_filter($alarmas, fn($a) => !$a['vista']));
$tipos = plantillas_tipo();

$head = '<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>'
      . '<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>';
if ($mapa) $head .= '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">'
      . '<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>';

cabecera($e['nombre'], $head);
$linea = en_linea($e);
echo '<a class="volver" href="index.php">← Equipos</a>';
echo '<div class="titulo"><h1>' . h($e['nombre']) . '</h1><p class="sub">'
   . h($tipos[$e['tipo']]['titulo'] ?? $e['tipo']) . (es_admin() ? ' · ' . h($e['cliente']) : '')
   . ($e['ubicacion'] ? ' · ' . h($e['ubicacion']) : '')
   . ' · <span class="estado ' . ($linea ? 'on' : 'off') . '">' . ($linea ? 'En línea' : 'Sin conexión') . '</span> '
   . h(hace($e['ultimo_dato'])) . '</p></div>';

// Pivote: panel de control (pivote_lib.php)
if ($e['tipo'] === 'pivote') { require_once __DIR__ . '/pivote_lib.php'; pivote_panel($e); }

// Valores actuales
echo '<div class="valores grandes">';
foreach ($vars as $v) {
    $mal = fuera_rango($v['ultimo_valor'] === null ? null : (float)$v['ultimo_valor'], $v);
    $lim = [];
    if ($v['minimo'] !== null) $lim[] = 'mín ' . fmt($v['minimo'], $v['decimales']);
    if ($v['maximo'] !== null) $lim[] = 'máx ' . fmt($v['maximo'], $v['decimales']);
    echo '<div class="val card' . ($mal ? ' mal' : '') . '"><span>' . h($v['nombre']) . '</span><b>'
       . fmt($v['ultimo_valor'], $v['decimales']) . '<small> ' . h($v['unidad']) . '</small></b>'
       . ($lim ? '<em>' . implode(' · ', $lim) . '</em>' : '') . '</div>';
}
echo '</div>';

// Selector de rango
echo '<div class="rango">';
foreach ($rangos as $k => $rg) echo '<a class="' . ($k === $r ? 'act' : '') . '" href="?id=' . $id . '&r=' . $k . '">' . $rg[2] . '</a>';
echo '<a class="csv" href="?id=' . $id . '&r=' . $r . '&csv=1">Descargar CSV</a></div>';

if ($mapa) echo '<div class="card"><h2>Posición del pivote</h2><div id="mapa"></div>'
   . ($mapa['ruta'] ? '' : '<p class="sub">Sin datos GPS en este período.</p>') . '</div>';

foreach ($series as $i => $s) {
    echo '<div class="card graf"><h2>' . h($s['nombre']) . ($s['unidad'] ? ' <small>(' . h($s['unidad']) . ')</small>' : '') . '</h2>';
    echo $s['x'] ? '<div class="lienzo"><canvas id="g' . $i . '"></canvas></div>' : '<p class="sub">Sin datos en ' . $rtxt . '.</p>';
    echo '</div>';
}

// Alarmas
echo '<div class="card"><div class="fila"><h2>Alarmas</h2>';
if ($sin_ver) echo '<form method="post">' . csrf_campo() . '<button name="vistas" value="1" class="sec">Marcar ' . $sin_ver . ' como vistas</button></form>';
echo '</div>';
if (!$alarmas) echo '<p class="sub">Sin alarmas registradas.</p>';
else {
    echo '<table class="tabla"><tr><th>Fecha</th><th>Detalle</th></tr>';
    foreach ($alarmas as $a)
        echo '<tr class="' . ($a['vista'] ? '' : 'nueva') . '"><td>' . h(date('d-m H:i', strtotime($a['fecha']))) . '</td><td>' . h($a['mensaje']) . '</td></tr>';
    echo '</table>';
}
echo '</div>';
?>
<script>
const SERIES = <?= json_encode($series) ?>;
const MAPA = <?= json_encode($mapa) ?>;
const css = getComputedStyle(document.documentElement);
const C = n => css.getPropertyValue(n).trim();
Chart.defaults.color = C('--txt2'); Chart.defaults.borderColor = C('--borde');
Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
SERIES.forEach((s, i) => {
  const el = document.getElementById('g' + i); if (!el) return;
  const ds = [{ data: s.x.map((x, k) => ({x, y: s.y[k]})), borderColor: C('--acento'), backgroundColor: C('--acento-suave'),
               borderWidth: 2, pointRadius: 0, tension: .25, fill: true }];
  const lim = (v, t) => v === null ? null : { data: [{x: s.x[0], y: v}, {x: s.x[s.x.length - 1], y: v}], label: t,
               borderColor: C('--mal'), borderDash: [5, 4], borderWidth: 1, pointRadius: 0, fill: false };
  [lim(s.min, 'Mínimo'), lim(s.max, 'Máximo')].forEach(l => l && ds.push(l));
  new Chart(el, { type: 'line', data: { datasets: ds },
    options: { responsive: true, maintainAspectRatio: false, animation: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { display: false }, tooltip: { filter: it => it.datasetIndex === 0,
        callbacks: { label: it => it.parsed.y.toLocaleString('es-CL', {maximumFractionDigits: s.dec}) + ' ' + s.unidad } } },
      scales: { x: { type: 'time', time: { tooltipFormat: 'dd-MM HH:mm', displayFormats: { hour: 'HH:mm', day: 'dd-MM' } },
                     grid: { display: false } },
                y: { ticks: { callback: v => v.toLocaleString('es-CL') } } } } });
});
if (MAPA && window.L) {
  const m = L.map('mapa');
  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    { maxZoom: 19, attribution: 'Imágenes © Esri' }).addTo(m);
  const pts = MAPA.ruta, cen = MAPA.centro || (pts.length ? pts[pts.length - 1] : [-36.62, -72.13]);
  if (MAPA.centro && MAPA.radio) L.circle(MAPA.centro, { radius: MAPA.radio, color: '#fff', weight: 1, fill: false, dashArray: '4 4' }).addTo(m);
  if (pts.length) {
    L.polyline(pts, { color: '#38bdf8', weight: 3, opacity: .7 }).addTo(m);
    const fin = pts[pts.length - 1];
    if (MAPA.centro) L.polyline([MAPA.centro, fin], { color: '#facc15', weight: 4 }).addTo(m);
    L.circleMarker(fin, { radius: 8, color: '#fff', fillColor: '#16a34a', fillOpacity: 1 }).addTo(m).bindPopup('Posición actual');
  }
  if (MAPA.centro) L.circleMarker(MAPA.centro, { radius: 5, color: '#fff', fillColor: '#334155', fillOpacity: 1 }).addTo(m).bindPopup('Centro');
  MAPA.radio ? m.fitBounds(L.latLng(cen).toBounds(MAPA.radio * 2.4)) : (pts.length > 1 ? m.fitBounds(pts) : m.setView(cen, 16));
}
// Recarga cada 2 min, pero no mientras se confirma una orden del pivote o se escribe algo.
(function recargar() { setTimeout(() => document.querySelector('dialog[open]') || document.activeElement?.matches('input')
  ? recargar() : location.reload(), 120000); })();
</script>
<?php pie();
