<?php
// =====================================================================
//  SERVIMAXX Monitoreo - Control del pivote
//
//  Guarda lo que se le pide al pivote (marcha, sentido, presión,
//  velocidad, parada) y el historial de órdenes. Lo usan equipo.php
//  (dibuja el panel) y pivote_control.php (recibe los botones).
//
//  IMPORTANTE: por ahora las órdenes NO llegan al equipo. Todavía no
//  sabemos cómo se comunica la tarjeta iControl Remote (módem XBee).
//  Todo lo que habla con el equipo está en pivote_enviar_orden(), al
//  final de este archivo: es lo único que habrá que cambiar.
// =====================================================================
if (!function_exists('q')) { http_response_code(404); exit; } // no se abre directo

const PIVOTE_POSICIONES = [0, 90, 180, 270];

function pivote_pedido_inicial() {
    return [
        'estado'       => 'parado',      // parado | calendario | marcha
        'autoReinicio' => false,         // vuelve a arrancar solo cuando vuelve la luz
        'autoArranque' => false,         // arranca solo a la hora programada
        'sentido'      => 'avance',      // avance | retroceso
        'presion'      => 'seco',        // seco | mojado
        'velFuente'    => 'tablero',     // tablero | sectores | app
        'velActiva'    => false,
        'velPorcentaje'=> 50,
        'pararEn'      => null,          // grados, o null = no parar
        'posiciones'   => PIVOTE_POSICIONES,
    ];
}

// Crea las tablas la primera vez (el usuario de la base tiene todos los privilegios).
function pivote_tablas() {
    static $listo = false;
    if ($listo) return;
    $sqlite = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $auto = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT AUTO_INCREMENT PRIMARY KEY';
    $motor = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    db()->exec("CREATE TABLE IF NOT EXISTS pivote_control (
        equipo_id INT PRIMARY KEY, pedido TEXT NOT NULL, actualizado DATETIME NOT NULL)$motor");
    db()->exec("CREATE TABLE IF NOT EXISTS pivote_ordenes (
        id $auto, equipo_id INT NOT NULL, accion VARCHAR(30) NOT NULL, valor VARCHAR(255) NOT NULL,
        texto VARCHAR(255) NOT NULL, confirmada TINYINT NOT NULL DEFAULT 0, usuario VARCHAR(120) NOT NULL,
        estado VARCHAR(15) NOT NULL, detalle VARCHAR(255) DEFAULT NULL, fecha DATETIME NOT NULL)$motor");
    // Enlace con la tarjeta de relés del pivote (pivote_api.php)
    db()->exec("CREATE TABLE IF NOT EXISTS pivote_enlace (
        equipo_id INT PRIMARY KEY, visto DATETIME NOT NULL, ultima_orden BIGINT NOT NULL DEFAULT 0,
        reporte TEXT DEFAULT NULL)$motor");
    $listo = true;
}

function pivote_pedido($equipo_id) {
    pivote_tablas();
    $f = q('SELECT pedido FROM pivote_control WHERE equipo_id = ?', [$equipo_id])->fetch();
    $p = pivote_pedido_inicial();
    if ($f && is_array($g = json_decode($f['pedido'], true))) $p = array_merge($p, array_intersect_key($g, $p));
    return $p;
}

function pivote_ordenes($equipo_id, $n = 15) {
    pivote_tablas();
    return q('SELECT id, accion, texto, confirmada, usuario, estado, detalle, fecha FROM pivote_ordenes
              WHERE equipo_id = ? ORDER BY id DESC LIMIT ' . (int)$n, [$equipo_id])->fetchAll();
}

function pivote_enlace($equipo_id) {
    pivote_tablas();
    $f = q('SELECT visto, ultima_orden, reporte FROM pivote_enlace WHERE equipo_id = ?', [$equipo_id])->fetch();
    if (!$f) return null;
    $seg = time() - strtotime($f['visto']);
    return ['visto' => $f['visto'], 'hace' => $seg, 'conectado' => $seg <= PIVOTE_SEG_CONECTADA,
            'reporte' => json_decode((string)$f['reporte'], true) ?: null];
}

function pivote_estado($e) {
    $enlace = pivote_enlace((int)$e['id']);
    return ['id' => (int)$e['id'], 'pedido' => pivote_pedido((int)$e['id']),
            'conectado' => (bool)($enlace['conectado'] ?? false), 'enlace' => $enlace,
            'ordenes' => pivote_ordenes((int)$e['id'])];
}

function pivote_guardar_pedido($id, $p) {
    $json = json_encode($p);
    if (q('UPDATE pivote_control SET pedido = ?, actualizado = ? WHERE equipo_id = ?', [$json, ahora(), $id])->rowCount() === 0)
        q('INSERT INTO pivote_control (equipo_id, pedido, actualizado) VALUES (?,?,?)', [$id, $json, ahora()]);
}

function pivote_grados($g) { return rtrim(rtrim(number_format((float)$g, 1, ',', ''), '0'), ',') . '°'; }

// Valida una orden. Devuelve [error, texto para el historial, aviso si puede mover la máquina].
function pivote_revisar($p, $accion, $valor) {
    $sino = fn($v) => is_bool($v);
    $N = ['parado' => 'Detenido', 'calendario' => 'Calendario', 'marcha' => 'En marcha',
          'avance' => 'Avance', 'retroceso' => 'Retroceso', 'seco' => 'Seco', 'mojado' => 'Mojado',
          'tablero' => 'Tablero', 'sectores' => 'Sectores', 'app' => 'Desde la app'];
    switch ($accion) {
        case 'estado':
            if (!in_array($valor, ['parado', 'calendario', 'marcha'], true)) return ['valor no válido'];
            $aviso = $valor === 'marcha' ? 'El pivote va a ARRANCAR y empezar a moverse.'
                   : ($valor === 'calendario' ? 'El pivote podrá arrancar solo a las horas programadas.' : null);
            return [null, 'Estado: ' . $N[$valor], $aviso];
        case 'autoReinicio':
            if (!$sino($valor)) return ['valor no válido'];
            return [null, 'Auto-reinicio ' . ($valor ? 'activado' : 'desactivado'),
                    $valor ? 'El pivote volverá a arrancar solo cuando vuelva la luz después de un corte.' : null];
        case 'autoArranque':
            if (!$sino($valor)) return ['valor no válido'];
            return [null, 'Auto-arranque ' . ($valor ? 'activado' : 'desactivado'),
                    $valor ? 'El pivote podrá arrancar solo, sin que nadie lo pida.' : null];
        case 'sentido':
            if (!in_array($valor, ['avance', 'retroceso'], true)) return ['valor no válido'];
            $aviso = $valor === $p['sentido'] ? null : 'El pivote cambiará de sentido a ' . strtoupper($N[$valor])
                   . ($p['estado'] === 'marcha' ? ' mientras está en marcha.' : '.');
            return [null, 'Sentido: ' . $N[$valor], $aviso];
        case 'presion':
            if (!in_array($valor, ['seco', 'mojado'], true)) return ['valor no válido'];
            return [null, 'Riego: ' . $N[$valor], null];
        case 'velFuente':
            if (!in_array($valor, ['tablero', 'sectores', 'app'], true)) return ['valor no válido'];
            return [null, 'Velocidad mandada por: ' . $N[$valor], null];
        case 'velActiva':
            if (!$sino($valor)) return ['valor no válido'];
            return [null, 'Control de velocidad ' . ($valor ? 'activado' : 'desactivado'), null];
        case 'velPorcentaje':
            if (!is_int($valor) || $valor < 1 || $valor > 100) return ['la velocidad va de 1 a 100 %'];
            return [null, "Velocidad: $valor %", null];
        case 'pararEn':
            if ($valor !== null && (!is_numeric($valor) || !in_array((float)$valor, array_map('floatval', $p['posiciones']), true)))
                return ['esa posición no está en la lista'];
            return [null, $valor === null ? 'Sin parada programada' : 'Parar en ' . pivote_grados($valor), null];
        case 'posiciones':
            if (!is_array($valor) || count($valor) > 8) return ['máximo 8 posiciones'];
            foreach ($valor as $g) if ($g < 0 || $g >= 360) return ['las posiciones van de 0 a 359,9°'];
            return [null, 'Posiciones de parada: ' . ($valor ? implode(', ', array_map('pivote_grados', $valor)) : 'ninguna'), null];
    }
    return ['orden desconocida'];
}

// Registra una orden. Devuelve [código HTTP, respuesta].
function pivote_ordenar($e, $accion, $valor, $confirmar) {
    $id = (int)$e['id'];
    $p = pivote_pedido($id);
    if ($accion === 'posiciones') {
        if (!is_array($valor)) $valor = null;
        else {
            $valor = array_values(array_unique(array_map(fn($g) => round((float)$g, 1), array_filter($valor, 'is_numeric'))));
            sort($valor);
        }
    }
    if ($accion === 'pararEn' && is_numeric($valor)) $valor = round((float)$valor, 1);
    [$error, $texto, $aviso] = pivote_revisar($p, (string)$accion, $valor) + [null, null, null];
    if ($error) return [400, ['ok' => false, 'error' => $error]];
    if ($aviso && $confirmar !== true) return [428, ['ok' => false, 'requiereConfirmacion' => true, 'aviso' => $aviso]];

    $p[$accion] = $valor;
    if ($accion === 'posiciones' && $p['pararEn'] !== null && !in_array((float)$p['pararEn'], $valor, true)) $p['pararEn'] = null;
    try {
        [$estado, $detalle] = pivote_enviar_orden($e, $accion, $valor, $p);
    } catch (Throwable $ex) {
        [$estado, $detalle] = ['fallida', $ex->getMessage()];
    }
    $u = usuario();
    q('INSERT INTO pivote_ordenes (equipo_id, accion, valor, texto, confirmada, usuario, estado, detalle, fecha) VALUES (?,?,?,?,?,?,?,?,?)',
      [$id, $accion, json_encode($valor), $texto, $aviso ? 1 : 0, $u['nombre'] ?? '?', $estado, $detalle, ahora()]);
    if ($estado === 'fallida') return [502, ['ok' => false, 'error' => $detalle, 'estado' => pivote_estado($e)]];
    pivote_guardar_pedido($id, $p);
    return [200, ['ok' => true, 'estado' => pivote_estado($e)]];
}

// ---------------------------------------------------------------------
//  Panel (se dibuja dentro de equipo.php para los equipos tipo pivote)
// ---------------------------------------------------------------------
function pivote_panel($e) {
    $ini = json_encode(pivote_estado($e));
    $ico = [
        'parado'     => '<rect x="6" y="6" width="12" height="12" rx="1.5"/>',
        'calendario' => '<rect x="4" y="5" width="16" height="15" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M4 10h16M9 3v4M15 3v4" stroke="currentColor" stroke-width="2"/>',
        'marcha'     => '<path d="M8 5v14l11-7z"/>',
        'avance'     => '<path d="M20 12a8 8 0 1 1-2.3-5.6M20 4v5h-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>',
        'retroceso'  => '<path d="M4 12a8 8 0 1 0 2.3-5.6M4 4v5h5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>',
        'seco'       => '<path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>',
        'mojado'     => '<path d="M12 3s6 7 6 11a6 6 0 0 1-12 0c0-4 6-11 6-11z"/>',
        'tablero'    => '<rect x="4" y="4" width="16" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="9" cy="10" r="2"/><circle cx="15" cy="10" r="2"/><path d="M8 16h8" stroke="currentColor" stroke-width="2"/>',
        'sectores'   => '<circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 12V4A8 8 0 0 1 20 12z"/>',
        'app'        => '<rect x="7" y="2" width="10" height="20" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="18" r="1.3"/>',
    ];
    $svg = fn($k) => '<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor">' . $ico[$k] . '</svg>';
    $grupo = function ($accion, $titulo, $ops) use ($svg) {
        $h = '<div class="pv-seg" role="group" aria-label="' . h($titulo) . '" data-accion="' . $accion . '">';
        foreach ($ops as $v => $t) $h .= '<button type="button" data-valor="' . $v . '">' . $svg($v) . '<span>' . h($t) . '</span></button>';
        return $h . '</div>';
    };
    ?>
<style>
.pv{--pv-on:var(--acento)}
.pv .pv-aviso{background:rgba(234,179,8,.14);color:#92580a;border-radius:8px;padding:10px 12px;font-size:13px;margin:0 0 14px}
.pv .pv-aviso.ok{background:var(--ok-suave);color:var(--on)!important}
@media (prefers-color-scheme:dark){.pv .pv-aviso{color:#facc15} .pv{--pv-txt-on:#04221a}}
.pv-seg button[aria-pressed=true],.pv-pos button[aria-pressed=true]{color:var(--pv-txt-on,#fff)!important}
.pv-seg[data-accion=estado] button[data-valor=parado][aria-pressed=true]{color:#fff!important}
.pv h3{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--txt2);margin:20px 0 8px;padding-bottom:6px;border-bottom:1px solid var(--borde)}
.pv-seg{display:flex;flex-wrap:wrap;gap:8px}
.pv-seg button,.pv-sw{display:inline-flex;align-items:center;gap:8px;min-height:46px;padding:10px 14px;border-radius:10px;
  background:var(--fondo);color:var(--txt);border:1px solid var(--borde);font-weight:600;flex:1 1 0;justify-content:center;min-width:0}
.pv-seg button svg{width:20px;height:20px;flex:none}
.pv-seg button[aria-pressed=true]{background:var(--pv-on);border-color:var(--pv-on);color:#fff}
.pv-seg[data-accion=estado] button[data-valor=parado][aria-pressed=true]{background:var(--mal);border-color:var(--mal)}
.pv-fila{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.pv-sw{flex:1 1 140px;justify-content:space-between;white-space:nowrap}
.pv-sw i{width:38px;height:22px;border-radius:11px;background:var(--off);position:relative;flex:none;transition:background .15s}
.pv-sw i::after{content:"";position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:left .15s}
.pv-sw[aria-checked=true] i{background:var(--pv-on)} .pv-sw[aria-checked=true] i::after{left:19px}
.pv-vel{display:flex;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap}
.pv-vel .pv-sw{flex:0 0 auto}
.pv-vel label{flex:1 1 200px;display:flex;align-items:center;gap:10px;margin:0;color:var(--txt)}
.pv-vel input[type=range]{flex:1;margin:0;padding:0;accent-color:var(--acento);height:32px;background:none;border:0}
.pv-vel output{font-size:20px;font-weight:600;font-variant-numeric:tabular-nums;min-width:56px;text-align:right}
.pv-pos{display:flex;flex-wrap:wrap;gap:8px}
.pv-pos button{min-width:72px;min-height:44px;background:var(--fondo);color:var(--txt);border:1px solid var(--borde);font-variant-numeric:tabular-nums}
.pv-pos button[aria-pressed=true]{background:var(--pv-on);border-color:var(--pv-on);color:#fff}
.pv-pos .pv-edit{background:transparent;border-style:dashed;color:var(--txt2);font-weight:500}
.pv-editar{display:flex;gap:8px;margin-top:8px} .pv-editar[hidden]{display:none} .pv-editar input{margin:0}
.pv-log{list-style:none;margin:0;padding:0;font-size:14px}
.pv-log li{display:flex;gap:10px;align-items:baseline;padding:7px 0;border-top:1px solid var(--borde)}
.pv-log li:first-child{border:0}
.pv-log time{font-size:12px;color:var(--txt2);white-space:nowrap;font-variant-numeric:tabular-nums}
.pv-log span{flex:1;min-width:0}
.pv-log em{font-style:normal;font-size:11px;font-weight:600;padding:2px 8px;border-radius:99px;background:rgba(234,179,8,.15);color:#92580a;white-space:nowrap}
@media (prefers-color-scheme:dark){.pv-log em{color:#facc15}}
.pv-log em.entregada{background:var(--ok-suave);color:var(--on)} .pv-log em.reemplazada{background:var(--fondo);color:var(--txt2)} .pv-log em.tarjeta{background:var(--acento-suave);color:var(--acento)} .pv-log em.fallida{background:var(--mal-suave);color:var(--mal)}
.pv-msg{font-size:13px;color:var(--mal);margin:10px 0 0} .pv-msg:empty{display:none}
.pv button:disabled{opacity:.45}
.pv-dlg{border:0;border-radius:14px;padding:0;max-width:min(420px,calc(100vw - 32px));background:var(--card);color:var(--txt);box-shadow:0 20px 60px rgba(0,0,0,.35)}
.pv-dlg::backdrop{background:rgba(0,0,0,.55)}
.pv-dlg .pv-dlg-c{padding:20px 20px 8px}
.pv-dlg h2{color:var(--mal);font-size:18px}
.pv-dlg p{margin:0 0 10px}
.pv-dlg .pv-dlg-b{display:flex;gap:8px;justify-content:flex-end;padding:8px 20px 20px}
.pv-dlg .pv-dlg-b button{min-height:44px}
@media (max-width:520px){.pv-seg button{flex-basis:calc(50% - 4px)} .pv-seg[data-accion=estado] button,.pv-seg[data-accion=velFuente] button{flex-basis:100%;justify-content:flex-start}}
</style>
<div class="card pv" id="pv" data-csrf="<?= h(csrf()) ?>">
  <h2>Control del pivote</h2>
  <p class="pv-aviso" id="pv-conexion"></p>

  <h3>Estado</h3>
  <?= $grupo('estado', 'Estado', ['parado' => 'Detenido', 'calendario' => 'Calendario', 'marcha' => 'En marcha']) ?>
  <div class="pv-fila">
    <button type="button" class="pv-sw" role="switch" data-accion="autoReinicio"><span>Auto-reinicio</span><i></i></button>
    <button type="button" class="pv-sw" role="switch" data-accion="autoArranque"><span>Auto-arranque</span><i></i></button>
  </div>

  <h3>Sentido</h3>
  <?= $grupo('sentido', 'Sentido', ['avance' => 'Avance', 'retroceso' => 'Retroceso']) ?>

  <h3>Riego</h3>
  <?= $grupo('presion', 'Riego', ['seco' => 'Seco', 'mojado' => 'Mojado']) ?>

  <h3>Velocidad</h3>
  <?= $grupo('velFuente', 'Quién manda la velocidad', ['tablero' => 'Tablero', 'sectores' => 'Sectores', 'app' => 'Desde la app']) ?>
  <div class="pv-vel">
    <button type="button" class="pv-sw" role="switch" data-accion="velActiva"><span>Activar</span><i></i></button>
    <label>Velocidad <input type="range" id="pv-vel" min="1" max="100" step="1"><output id="pv-vel-txt"></output></label>
  </div>

  <h3>Parar en posición</h3>
  <div class="pv-pos" id="pv-pos"></div>
  <div class="pv-editar" id="pv-editar" hidden>
    <input id="pv-editar-txt" inputmode="decimal" placeholder="Ej: 0; 90; 163,6; 252" aria-label="Posiciones en grados, separadas por punto y coma">
    <button type="button" id="pv-editar-ok">Guardar</button>
  </div>
  <p class="pv-msg" id="pv-msg" role="status"></p>

  <h3>Últimas órdenes</h3>
  <ul class="pv-log" id="pv-log"></ul>
</div>
<dialog class="pv-dlg" id="pv-dlg">
  <div class="pv-dlg-c"><h2>⚠ Confirmar orden</h2><p id="pv-dlg-txt"></p><p class="sub">Verifica que no haya personas, vehículos ni animales en el recorrido del pivote.</p></div>
  <div class="pv-dlg-b"><button type="button" class="sec" value="no">Cancelar</button><button type="button" class="peligro" value="si">Sí, confirmar</button></div>
</dialog>
<script>
(() => {
  let E = <?= $ini ?>;
  const $ = id => document.getElementById(id), raiz = $('pv'), msg = $('pv-msg'), vel = $('pv-vel');
  const gr = g => (Math.round(g * 10) / 10).toLocaleString('es-CL') + '°';
  const hora = f => new Date(f.replace(' ', 'T')).toLocaleString('es-CL', {day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false});
  const ESTADOS = {pendiente: 'Pendiente', entregada: 'Entregada', fallida: 'Falló', tarjeta: 'Tarjeta', reemplazada: 'No aplicada'};
  const el = (tag, txt, cls) => { const x = document.createElement(tag); if (txt != null) x.textContent = txt; if (cls) x.className = cls; return x; };
  let ocupado = false;

  function pintar() {
    const p = E.pedido;
    const en = E.enlace, rp = en?.reporte || {}, cx = $('pv-conexion');
    const hace = s => s < 60 ? `hace ${s} s` : s < 3600 ? `hace ${Math.round(s / 60)} min` : `hace ${Math.round(s / 3600)} h`;
    cx.classList.toggle('ok', !!E.conectado);
    cx.textContent = E.conectado
      ? `Tarjeta conectada (${hace(en.hace)}). Relé de marcha: ${rp.marcha ? 'ACTIVADO' : 'apagado'}`
        + (rp.angulo != null ? ` · posición ${gr(rp.angulo)}` : '') + (rp.aviso ? ` · ${rp.aviso}` : '') + '.'
      : en ? `La tarjeta del pivote no se conecta desde ${hace(en.hace)}. Las órdenes quedan pendientes hasta que vuelva.`
      : 'Todavía no hay tarjeta conectada: los botones guardan lo que pides, pero la orden aún no llega al pivote.';
    raiz.querySelectorAll('.pv-seg').forEach(g => g.querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed', p[g.dataset.accion] === b.dataset.valor)));
    raiz.querySelectorAll('.pv-sw').forEach(b => b.setAttribute('aria-checked', !!p[b.dataset.accion]));
    if (document.activeElement !== vel) vel.value = p.velPorcentaje;
    $('pv-vel-txt').textContent = vel.value + ' %';
    const pos = $('pv-pos'); pos.innerHTML = '';
    const boton = (txt, v) => { const b = el('button', txt); b.type = 'button'; b.dataset.valor = JSON.stringify(v);
      b.setAttribute('aria-pressed', v === null ? p.pararEn === null : p.pararEn !== null && +v === +p.pararEn); pos.append(b); };
    p.posiciones.forEach(g => boton(gr(g), g));
    boton('No parar', null);
    const ed = el('button', 'Editar', 'pv-edit'); ed.type = 'button'; ed.dataset.editar = '1'; pos.append(ed);
    const log = $('pv-log'); log.innerHTML = '';
    if (!E.ordenes.length) log.append(el('li', 'Todavía no se ha dado ninguna orden.', 'sub'));
    E.ordenes.forEach(o => {
      const li = el('li'), em = el('em', ESTADOS[o.estado] || o.estado, o.estado);
      if (o.detalle) em.title = o.detalle;
      li.append(el('time', hora(o.fecha)), el('span', o.texto + ' · ' + o.usuario), em); log.append(li);
    });
    raiz.querySelectorAll('button,input').forEach(b => b.disabled = ocupado);
    if (!ocupado) vel.disabled = !(p.velFuente === 'app' && p.velActiva);
  }

  function confirmar(texto) {
    return new Promise(ok => {
      const d = $('pv-dlg'); $('pv-dlg-txt').textContent = texto;
      d.querySelectorAll('.pv-dlg-b button').forEach(b => b.onclick = () => { d.close(); ok(b.value === 'si'); });
      d.oncancel = () => ok(false);
      d.showModal();
    });
  }

  async function enviar(accion, valor, confirmado = false) {
    ocupado = true; msg.textContent = ''; pintar();
    try {
      const r = await fetch('pivote_control.php', { method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'X-CSRF': raiz.dataset.csrf},
        body: JSON.stringify({id: E.id, accion, valor, confirmar: confirmado}) });
      const d = await r.json().catch(() => ({error: 'respuesta inválida del servidor'}));
      if (r.status === 428 && d.requiereConfirmacion) {
        ocupado = false; pintar();
        if (await confirmar(d.aviso)) return enviar(accion, valor, true);
        return;
      }
      if (d.estado) E = d.estado;
      if (!r.ok) msg.textContent = 'No se pudo: ' + (d.error || ('error ' + r.status));
    } catch { msg.textContent = 'Sin conexión con la plataforma. Revisa internet y vuelve a intentar.'; }
    ocupado = false; pintar();
  }

  raiz.addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b || b.disabled) return;
    const g = b.closest('.pv-seg');
    if (g) { if (E.pedido[g.dataset.accion] !== b.dataset.valor) enviar(g.dataset.accion, b.dataset.valor); return; }
    if (b.classList.contains('pv-sw')) return enviar(b.dataset.accion, !E.pedido[b.dataset.accion]);
    if (b.dataset.editar) { const ed = $('pv-editar'); ed.hidden = !ed.hidden;
      $('pv-editar-txt').value = E.pedido.posiciones.map(g => String(g).replace('.', ',')).join('; '); return; }
    if (b.closest('#pv-pos')) return enviar('pararEn', JSON.parse(b.dataset.valor));
  });
  vel.oninput = () => $('pv-vel-txt').textContent = vel.value + ' %';
  vel.onchange = () => { if (+vel.value !== E.pedido.velPorcentaje) enviar('velPorcentaje', +vel.value); };
  $('pv-editar-ok').onclick = () => {
    const lista = $('pv-editar-txt').value.split(/[;\s]+/).map(s => s.trim().replace(',', '.')).filter(Boolean);
    if (lista.some(s => isNaN(+s))) { msg.textContent = 'Escribe solo números de 0 a 359,9 separados por punto y coma.'; return; }
    $('pv-editar').hidden = true;
    enviar('posiciones', lista.map(Number));
  };
  pintar();
  // Actualiza cada 15 s para ver cuándo la tarjeta recoge las órdenes.
  setInterval(async () => {
    if (ocupado || document.hidden || document.querySelector('dialog[open]')) return;
    try { const r = await fetch('pivote_control.php?id=' + E.id, {credentials: 'same-origin'}); if (r.ok) { const d = await r.json(); if (d.estado && !ocupado) { E = d.estado; pintar(); } } } catch {}
  }, 15000);
})();
</script>
<?php
}

// =====================================================================
//  CONEXIÓN CON EL EQUIPO: tarjeta de relés del pivote
//
//  La tarjeta (LilyGO 4G + relés, firmware tarjeta_pivote_4g.ino) se
//  conecta cada pocos segundos a pivote_api.php con la clave API del
//  equipo. Recibe todo lo pedido, lo aplica a los relés y confirma el
//  número de la última orden aplicada; esas órdenes pasan a "Entregada".
// =====================================================================
const PIVOTE_SEG_CONECTADA = 60;   // sin noticias de la tarjeta en este tiempo = desconectada
const PIVOTE_TOLERANCIA_GRADOS = 2.0;

function pivote_enviar_orden($equipo, $accion, $valor, $pedido_completo) {
    $en = pivote_enlace((int)$equipo['id']);
    if ($en && $en['conectado']) return ['pendiente', 'Esperando que la tarjeta la recoja (unos segundos).'];
    return ['pendiente', 'La tarjeta no está conectada; la recogerá cuando se conecte.'];
}

// Paradas que decide la tarjeta por su cuenta (posición alcanzada, sin señal, corte de luz).
// Se dejan como "Detenido" en lo pedido para que no vuelva a arrancar sola.
function pivote_evento_tarjeta($eq, $evento, $angulo) {
    $id = (int)$eq['id'];
    $p = pivote_pedido($id);
    $TEXTOS = ['posicion' => 'Detenido al llegar a ' . pivote_grados($p['pararEn'] ?? 0) . ($angulo !== null ? ' (GPS: ' . pivote_grados($angulo) . ')' : ''),
               'sin_senal' => 'Detenido por la tarjeta: estuvo sin conexión con la plataforma',
               'corte_luz' => 'Detenido por la tarjeta: se reinició (corte de luz) sin auto-reinicio'];
    if (!isset($TEXTOS[$evento])) return;
    if ($p['estado'] === 'parado') return;
    q("UPDATE pivote_ordenes SET estado = 'reemplazada', detalle = 'La tarjeta detuvo el pivote antes de aplicarla' WHERE equipo_id = ? AND estado = 'pendiente'", [$id]);
    $p['estado'] = 'parado';
    if ($evento === 'posicion') $p['pararEn'] = null;
    q('INSERT INTO pivote_ordenes (equipo_id, accion, valor, texto, confirmada, usuario, estado, detalle, fecha) VALUES (?,?,?,?,?,?,?,?,?)',
      [$id, 'estado', '"parado"', $TEXTOS[$evento], 0, 'Tarjeta del pivote', 'tarjeta', null, ahora()]);
    pivote_guardar_pedido($id, $p);
}

// Último ángulo que mandó el GPS de la última torre (api.php), con su antigüedad en segundos.
function pivote_ultimo_angulo($equipo_id) {
    $f = q("SELECT valor, fecha FROM lecturas WHERE equipo_id = ? AND clave = 'angulo' ORDER BY fecha DESC LIMIT 1", [$equipo_id])->fetch();
    return $f ? [round((float)$f['valor'], 1), time() - strtotime($f['fecha'])] : [null, null];
}

// Diferencia de ángulos con signo, entre -180 y 180.
function pivote_dif($a, $b) { return fmod(fmod($a - $b, 360) + 540, 360) - 180; }

// ¿Llegó (o pasó) a la posición? Con el GPS cada 60 s puede saltarse unos grados,
// así que también cuenta si la posición quedó entre la lectura anterior y la actual.
function pivote_llego($previo, $actual, $meta) {
    if (abs(pivote_dif($actual, $meta)) <= PIVOTE_TOLERANCIA_GRADOS) return true;
    if ($previo === null) return false;
    $avance = pivote_dif($actual, $previo);
    $hasta = pivote_dif($meta, $previo);
    return abs($avance) < 30 && $avance * $hasta > 0 && abs($hasta) <= abs($avance);
}

// Atiende una conexión de la tarjeta. $d = JSON recibido. Devuelve la respuesta (array).
function pivote_tarjeta($eq, $d) {
    $id = (int)$eq['id'];
    pivote_tablas();
    [$angulo, $edad] = pivote_ultimo_angulo($id);
    $previo = pivote_enlace($id)['reporte']['angulo'] ?? null;
    if (!empty($d['evento'])) pivote_evento_tarjeta($eq, (string)$d['evento'], $angulo);

    // Parar en posición: lo decide la plataforma con el GPS de la última torre (dato de menos de 3 min).
    $p = pivote_pedido($id);
    if ($p['estado'] === 'marcha' && $p['pararEn'] !== null && $angulo !== null && $edad >= -120 && $edad <= 180
        && pivote_llego($previo, $angulo, (float)$p['pararEn'])) pivote_evento_tarjeta($eq, 'posicion', $angulo);

    $ack = (int)($d['ack'] ?? 0);
    if ($ack > 0) q("UPDATE pivote_ordenes SET estado = 'entregada', detalle = ? WHERE equipo_id = ? AND id <= ? AND estado = 'pendiente'",
                    ['Aplicada por la tarjeta ' . date('d-m H:i:s'), $id, $ack]);

    $reporte = ['marcha' => !empty($d['marcha']), 'rssi' => isset($d['rssi']) ? (int)$d['rssi'] : null,
                'angulo' => $angulo, 'aviso' => isset($d['aviso']) ? mb_substr((string)$d['aviso'], 0, 80) : null,
                'version' => isset($d['version']) ? mb_substr((string)$d['version'], 0, 20) : null];
    $json = json_encode($reporte);
    if (q('UPDATE pivote_enlace SET visto = ?, ultima_orden = ?, reporte = ? WHERE equipo_id = ?', [ahora(), $ack, $json, $id])->rowCount() === 0)
        q('INSERT INTO pivote_enlace (equipo_id, visto, ultima_orden, reporte) VALUES (?,?,?,?)', [$id, ahora(), $ack, $json]);

    $p = pivote_pedido($id);
    $ultima = (int)(q('SELECT MAX(id) m FROM pivote_ordenes WHERE equipo_id = ?', [$id])->fetch()['m'] ?? 0);
    // Respuesta corta y plana para que la tarjeta la lea sin librería JSON.
    return [
        'ok' => 1, 'orden' => $ultima,
        'marcha' => $p['estado'] === 'marcha' ? 1 : 0,          // "calendario" todavía no lo maneja la tarjeta: queda detenido
        'retroceso' => $p['sentido'] === 'retroceso' ? 1 : 0,
        'mojado' => $p['presion'] === 'mojado' ? 1 : 0,
        'velremoto' => ($p['velFuente'] === 'app' && $p['velActiva']) ? 1 : 0,
        'velpct' => (int)$p['velPorcentaje'],
        'autoreinicio' => $p['autoReinicio'] ? 1 : 0,
        'pararen' => $p['pararEn'] === null ? -1 : (float)$p['pararEn'],
        'angulo' => $angulo === null ? -1 : $angulo, 'angulo_edad' => $edad === null ? -1 : $edad,
        'tolerancia' => PIVOTE_TOLERANCIA_GRADOS,
    ];
}
