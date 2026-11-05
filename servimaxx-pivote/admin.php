<?php
require_once __DIR__ . '/lib.php';
exigir_admin();
$tipos = plantillas_tipo();
$msg = '';
$nueva_clave = null;

function num_o_null($v) { $v = str_replace(',', '.', trim((string)$v)); return $v === '' ? null : (float)$v; }
function nueva_api_key() { return bin2hex(random_bytes(16)); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $a = $_POST['accion'] ?? '';
    try {
        switch ($a) {
        case 'crear_cliente':
        case 'editar_cliente':
            $nombre = trim($_POST['nombre'] ?? '');
            $usuario = strtolower(trim($_POST['usuario'] ?? ''));
            $clave = (string)($_POST['clave'] ?? '');
            if ($nombre === '' || !preg_match('/^[a-z0-9._-]{3,60}$/', $usuario)) throw new Exception('Nombre y usuario válido (mín. 3 letras, sin espacios) son obligatorios.');
            $p = [$nombre, trim($_POST['rut'] ?? '') ?: null, $usuario, trim($_POST['email'] ?? '') ?: null, trim($_POST['telefono'] ?? '') ?: null];
            if ($a === 'crear_cliente') {
                if (strlen($clave) < 6) throw new Exception('La clave debe tener al menos 6 caracteres.');
                q('INSERT INTO clientes (nombre, rut, usuario, email, telefono, clave_hash, creado) VALUES (?,?,?,?,?,?,?)',
                  array_merge($p, [password_hash($clave, PASSWORD_DEFAULT), ahora()]));
                $msg = 'Cliente creado. Usuario: ' . $usuario;
            } else {
                $cid = (int)$_POST['id'];
                q('UPDATE clientes SET nombre=?, rut=?, usuario=?, email=?, telefono=?, activo=? WHERE id=?',
                  array_merge($p, [isset($_POST['activo']) ? 1 : 0, $cid]));
                if ($clave !== '') {
                    if (strlen($clave) < 6) throw new Exception('La clave debe tener al menos 6 caracteres.');
                    q('UPDATE clientes SET clave_hash=? WHERE id=?', [password_hash($clave, PASSWORD_DEFAULT), $cid]);
                }
                $msg = 'Cliente actualizado.';
            }
            break;

        case 'crear_equipo':
        case 'editar_equipo':
            $nombre = trim($_POST['nombre'] ?? '');
            $tipo = $_POST['tipo'] ?? 'generico';
            $cid = (int)($_POST['cliente_id'] ?? 0);
            if ($nombre === '' || !isset($tipos[$tipo]) || !q('SELECT id FROM clientes WHERE id=?', [$cid])->fetch())
                throw new Exception('Completa nombre, cliente y tipo.');
            $p = [$cid, $nombre, trim($_POST['ubicacion'] ?? '') ?: null,
                  num_o_null($_POST['centro_lat'] ?? ''), num_o_null($_POST['centro_lon'] ?? ''), num_o_null($_POST['radio_m'] ?? '')];
            if ($tipo !== 'pivote') { $p[3] = $p[4] = $p[5] = null; }
            if ($a === 'crear_equipo') {
                q('INSERT INTO equipos (cliente_id, nombre, ubicacion, centro_lat, centro_lon, radio_m, tipo, api_key, creado) VALUES (?,?,?,?,?,?,?,?,?)',
                  array_merge($p, [$tipo, nueva_api_key(), ahora()]));
                $eid = (int)db()->lastInsertId();
                crear_variables_tipo($eid, $tipo);
                header('Location: admin.php?equipo=' . $eid . '&creado=1'); exit;
            }
            $eid = (int)$_POST['id'];
            q('UPDATE equipos SET cliente_id=?, nombre=?, ubicacion=?, centro_lat=?, centro_lon=?, radio_m=?, activo=? WHERE id=?',
              array_merge($p, [isset($_POST['activo']) ? 1 : 0, $eid]));
            header('Location: admin.php?equipo=' . $eid . '&ok=1'); exit;

        case 'nueva_key':
            $eid = (int)$_POST['id'];
            q('UPDATE equipos SET api_key=? WHERE id=?', [nueva_api_key(), $eid]);
            header('Location: admin.php?equipo=' . $eid . '&ok=1'); exit;

        case 'borrar_equipo':
            $eid = (int)$_POST['id'];
            if (($_POST['confirmar'] ?? '') !== 'BORRAR') throw new Exception('Escribe BORRAR para confirmar.');
            q('DELETE FROM lecturas WHERE equipo_id=?', [$eid]);
            q('DELETE FROM alarmas WHERE equipo_id=?', [$eid]);
            q('DELETE FROM variables WHERE equipo_id=?', [$eid]);
            q('DELETE FROM equipos WHERE id=?', [$eid]);
            $msg = 'Equipo y sus datos eliminados.';
            break;

        case 'borrar_cliente':
            $cid = (int)$_POST['id'];
            if (($_POST['confirmar'] ?? '') !== 'BORRAR') throw new Exception('Escribe BORRAR para confirmar.');
            $cli = q('SELECT nombre FROM clientes WHERE id=?', [$cid])->fetch();
            if (!$cli) throw new Exception('Ese cliente ya no existe.');
            db()->beginTransaction();
            foreach (q('SELECT id FROM equipos WHERE cliente_id=?', [$cid])->fetchAll(PDO::FETCH_COLUMN) as $eid) {
                foreach (['lecturas', 'alarmas', 'variables'] as $t) q("DELETE FROM $t WHERE equipo_id=?", [$eid]);
                // Tablas del control del pivote (pueden no existir todavía)
                foreach (['pivote_control', 'pivote_ordenes', 'pivote_enlace'] as $t) {
                    try { q("DELETE FROM $t WHERE equipo_id=?", [$eid]); } catch (PDOException $ex) {}
                }
            }
            q('DELETE FROM equipos WHERE cliente_id=?', [$cid]);
            q('DELETE FROM clientes WHERE id=?', [$cid]);
            db()->commit();
            $msg = 'Cliente "' . $cli['nombre'] . '" eliminado, con todos sus equipos y datos.';
            break;

        case 'guardar_variables':
            $eid = (int)$_POST['id'];
            foreach ((array)($_POST['v'] ?? []) as $vid => $v) {
                q('UPDATE variables SET nombre=?, unidad=?, minimo=?, maximo=?, decimales=?, grafico=?, orden=? WHERE id=? AND equipo_id=?',
                  [trim($v['nombre']) ?: 'Variable', trim($v['unidad']), num_o_null($v['minimo']), num_o_null($v['maximo']),
                   max(0, min(6, (int)$v['decimales'])), isset($v['grafico']) ? 1 : 0, (int)$v['orden'], (int)$vid, $eid]);
            }
            $clave = strtolower(trim($_POST['nueva_clave'] ?? ''));
            if ($clave !== '') {
                if (!preg_match('/^[a-z][a-z0-9_]{0,29}$/', $clave)) throw new Exception('Clave de variable inválida (minúsculas, sin espacios).');
                q('INSERT INTO variables (equipo_id, clave, nombre, unidad, orden) VALUES (?,?,?,?,?)',
                  [$eid, $clave, trim($_POST['nueva_nombre'] ?? '') ?: ucfirst($clave), trim($_POST['nueva_unidad'] ?? ''), 99]);
            }
            header('Location: admin.php?equipo=' . $eid . '&ok=1'); exit;

        case 'borrar_variable':
            $eid = (int)$_POST['id'];
            $vr = q('SELECT clave FROM variables WHERE id=? AND equipo_id=?', [(int)$_POST['vid'], $eid])->fetch();
            if ($vr) {
                q('DELETE FROM lecturas WHERE equipo_id=? AND clave=?', [$eid, $vr['clave']]);
                q('DELETE FROM variables WHERE id=?', [(int)$_POST['vid']]);
            }
            header('Location: admin.php?equipo=' . $eid . '&ok=1'); exit;

        case 'limpiar':
            $dias = max(30, (int)$_POST['dias']);
            $n = q('DELETE FROM lecturas WHERE fecha < ?', [date('Y-m-d H:i:s', time() - $dias * 86400)])->rowCount();
            $msg = "Se borraron $n lecturas con más de $dias días.";
            break;
        }
    } catch (PDOException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        $msg = 'Error: ' . (str_contains($ex->getMessage(), 'UNIQUE') || $ex->getCode() == 23000 ? 'ese usuario ya existe.' : 'no se pudo guardar.');
    } catch (Exception $ex) {
        $msg = 'Error: ' . $ex->getMessage();
    }
}

$clientes = q('SELECT c.*, (SELECT COUNT(*) FROM equipos e WHERE e.cliente_id = c.id) n FROM clientes c ORDER BY nombre')->fetchAll();
$url_api = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'servimaxx.cl')
         . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/monitoreo/admin.php'), '/') . '/api.php';

cabecera('Administrar');

// ------------------------------------------------------------------ Detalle de un equipo
if (isset($_GET['equipo'])) {
    $e = q('SELECT * FROM equipos WHERE id=?', [(int)$_GET['equipo']])->fetch();
    if (!$e) { echo '<p>Equipo no encontrado.</p>'; pie(); exit; }
    $vars = q('SELECT * FROM variables WHERE equipo_id=? ORDER BY orden, id', [$e['id']])->fetchAll();
    echo '<a class="volver" href="admin.php">← Administrar</a>';
    echo '<div class="titulo"><h1>' . h($e['nombre']) . '</h1><p class="sub">' . h($tipos[$e['tipo']]['titulo']) . ' · <a href="equipo.php?id=' . (int)$e['id'] . '">Ver panel</a></p></div>';
    if (isset($_GET['creado'])) echo '<p class="ok">Equipo creado. Copia la clave API en el ESP32.</p>';
    if (isset($_GET['ok'])) echo '<p class="ok">Cambios guardados.</p>';
    if ($msg) echo '<p class="err">' . h($msg) . '</p>';

    $ejemplo = $url_api . '?key=' . $e['api_key'];
    foreach (array_slice($vars, 0, 3) as $v) $ejemplo .= '&' . $v['clave'] . '=' . ($v['clave'] === 'ph' ? '7.1' : '20');
    echo '<div class="card"><h2>Conexión del equipo</h2>'
       . '<label>Clave API (va en el ESP32)<input readonly value="' . h($e['api_key']) . '" onclick="this.select()"></label>'
       . '<label>URL de prueba (ábrela en el navegador)<input readonly value="' . h($ejemplo) . '" onclick="this.select()"></label>'
       . '<form method="post" onsubmit="return confirm(\'El ESP32 dejará de enviar hasta que cargues la nueva clave. ¿Seguir?\')">'
       . csrf_campo() . '<input type="hidden" name="accion" value="nueva_key"><input type="hidden" name="id" value="' . (int)$e['id'] . '">'
       . '<button class="sec">Generar nueva clave</button></form></div>';

    echo '<form class="card" method="post">' . csrf_campo() . '<h2>Datos del equipo</h2>'
       . '<input type="hidden" name="accion" value="editar_equipo"><input type="hidden" name="id" value="' . (int)$e['id'] . '">'
       . '<input type="hidden" name="tipo" value="' . h($e['tipo']) . '"><div class="form2">'
       . '<label>Nombre<input name="nombre" required value="' . h($e['nombre']) . '"></label>'
       . '<label>Cliente<select name="cliente_id">';
    foreach ($clientes as $c) echo '<option value="' . (int)$c['id'] . '"' . ($c['id'] == $e['cliente_id'] ? ' selected' : '') . '>' . h($c['nombre']) . '</option>';
    echo '</select></label><label>Ubicación<input name="ubicacion" value="' . h($e['ubicacion']) . '"></label>';
    if ($e['tipo'] === 'pivote')
        echo '<label>Centro latitud<input name="centro_lat" inputmode="decimal" value="' . h($e['centro_lat']) . '"></label>'
           . '<label>Centro longitud<input name="centro_lon" inputmode="decimal" value="' . h($e['centro_lon']) . '"></label>'
           . '<label>Largo del pivote (m)<input name="radio_m" inputmode="decimal" value="' . h($e['radio_m']) . '"></label>';
    echo '<label class="check"><input type="checkbox" name="activo"' . ($e['activo'] ? ' checked' : '') . '> Activo</label></div><button>Guardar</button></form>';

    echo '<form class="card" method="post">' . csrf_campo() . '<h2>Variables y límites de alarma</h2>'
       . '<p class="sub">Deja mín./máx. vacío si no quieres alarma. Las variables nuevas que envíe el equipo aparecen solas.</p>'
       . '<input type="hidden" name="accion" value="guardar_variables"><input type="hidden" name="id" value="' . (int)$e['id'] . '">'
       . '<div class="scroll"><table class="tabla vars"><tr><th>Clave</th><th>Nombre</th><th>Unidad</th><th>Mín.</th><th>Máx.</th><th>Dec.</th><th>Gráf.</th><th>Orden</th></tr>';
    foreach ($vars as $v) {
        $n = 'v[' . (int)$v['id'] . ']';
        echo '<tr><td><code>' . h($v['clave']) . '</code></td>'
           . '<td><input name="' . $n . '[nombre]" value="' . h($v['nombre']) . '"></td>'
           . '<td><input name="' . $n . '[unidad]" value="' . h($v['unidad']) . '" class="corto"></td>'
           . '<td><input name="' . $n . '[minimo]" value="' . h($v['minimo']) . '" class="corto" inputmode="decimal"></td>'
           . '<td><input name="' . $n . '[maximo]" value="' . h($v['maximo']) . '" class="corto" inputmode="decimal"></td>'
           . '<td><input name="' . $n . '[decimales]" value="' . (int)$v['decimales'] . '" class="mini" inputmode="numeric"></td>'
           . '<td><input type="checkbox" name="' . $n . '[grafico]"' . ($v['grafico'] ? ' checked' : '') . '></td>'
           . '<td><input name="' . $n . '[orden]" value="' . (int)$v['orden'] . '" class="mini" inputmode="numeric"></td></tr>';
    }
    echo '<tr><td><input name="nueva_clave" placeholder="nueva" class="corto"></td><td><input name="nueva_nombre" placeholder="Nombre"></td>'
       . '<td><input name="nueva_unidad" placeholder="Unidad" class="corto"></td><td colspan="5"></td></tr></table></div><button>Guardar variables</button></form>';

    if ($vars) {
        echo '<form class="card" method="post" onsubmit="return confirm(\'Se borrará la variable y todo su historial. ¿Seguir?\')">' . csrf_campo()
           . '<h2>Quitar variable</h2><input type="hidden" name="accion" value="borrar_variable"><input type="hidden" name="id" value="' . (int)$e['id'] . '">'
           . '<div class="fila"><select name="vid">';
        foreach ($vars as $v) echo '<option value="' . (int)$v['id'] . '">' . h($v['nombre'] . ' (' . $v['clave'] . ')') . '</option>';
        echo '</select><button class="peligro">Quitar</button></div></form>';
    }

    echo '<form class="card" method="post">' . csrf_campo() . '<h2>Eliminar equipo</h2><p class="sub">Borra el equipo y todo su historial. No se puede deshacer.</p>'
       . '<input type="hidden" name="accion" value="borrar_equipo"><input type="hidden" name="id" value="' . (int)$e['id'] . '">'
       . '<div class="fila"><input name="confirmar" placeholder="Escribe BORRAR"><button class="peligro">Eliminar</button></div></form>';
    pie(); exit;
}

// ------------------------------------------------------------------ Editar cliente
if (isset($_GET['cliente'])) {
    $c = q('SELECT * FROM clientes WHERE id=?', [(int)$_GET['cliente']])->fetch();
    if ($c) {
        echo '<a class="volver" href="admin.php">← Administrar</a>';
        echo '<form class="card" method="post">' . csrf_campo() . '<h2>Editar cliente</h2>'
           . '<input type="hidden" name="accion" value="editar_cliente"><input type="hidden" name="id" value="' . (int)$c['id'] . '"><div class="form2">'
           . '<label>Nombre / razón social<input name="nombre" required value="' . h($c['nombre']) . '"></label>'
           . '<label>RUT<input name="rut" value="' . h($c['rut']) . '"></label>'
           . '<label>Usuario<input name="usuario" required value="' . h($c['usuario']) . '"></label>'
           . '<label>Nueva clave (vacío = no cambia)<input type="password" name="clave" autocomplete="new-password"></label>'
           . '<label>Correo de alarmas<input type="email" name="email" value="' . h($c['email']) . '"></label>'
           . '<label>Teléfono<input name="telefono" value="' . h($c['telefono']) . '"></label>'
           . '<label class="check"><input type="checkbox" name="activo"' . ($c['activo'] ? ' checked' : '') . '> Activo (si lo desactivas no puede entrar y sus equipos dejan de registrar)</label>'
           . '</div><button>Guardar</button></form>';
        $n = (int)q('SELECT COUNT(*) FROM equipos WHERE cliente_id=?', [$c['id']])->fetchColumn();
        echo '<form class="card" method="post">' . csrf_campo() . '<h2>Eliminar cliente</h2>'
           . '<p class="sub">Borra el cliente' . ($n ? ($n > 1 ? ", sus $n equipos" : ', su equipo') . ' y todo su historial' : '')
           . '. No se puede deshacer. Si solo quieres que no entre, desmarca "Activo" arriba.</p>'
           . '<input type="hidden" name="accion" value="borrar_cliente"><input type="hidden" name="id" value="' . (int)$c['id'] . '">'
           . '<div class="fila"><input name="confirmar" placeholder="Escribe BORRAR" autocomplete="off"><button class="peligro">Eliminar cliente</button></div></form>';
        pie(); exit;
    }
}

// ------------------------------------------------------------------ Vista principal
echo '<div class="titulo"><h1>Administrar</h1><p class="sub">Clientes, equipos y alarmas</p></div>';
if ($msg) echo '<p class="' . (str_starts_with($msg, 'Error') ? 'err' : 'ok') . '">' . h($msg) . '</p>';

echo '<div class="dos">';
echo '<form class="card" method="post">' . csrf_campo() . '<h2>Nuevo cliente</h2><input type="hidden" name="accion" value="crear_cliente">'
   . '<label>Nombre / razón social<input name="nombre" required></label>'
   . '<label>RUT<input name="rut"></label>'
   . '<label>Usuario (para entrar)<input name="usuario" required pattern="[a-zA-Z0-9._\-]{3,60}"></label>'
   . '<label>Clave<input type="password" name="clave" required minlength="6" autocomplete="new-password"></label>'
   . '<label>Correo de alarmas<input type="email" name="email"></label>'
   . '<label>Teléfono<input name="telefono"></label><button>Crear cliente</button></form>';

echo '<form class="card" method="post">' . csrf_campo() . '<h2>Nuevo equipo</h2><input type="hidden" name="accion" value="crear_equipo">';
if (!$clientes) echo '<p class="sub">Primero crea un cliente.</p>';
echo '<label>Cliente<select name="cliente_id" required>';
foreach ($clientes as $c) echo '<option value="' . (int)$c['id'] . '">' . h($c['nombre']) . '</option>';
echo '</select></label><label>Tipo<select name="tipo" id="tipo">';
foreach ($tipos as $k => $t) echo '<option value="' . $k . '">' . h($t['titulo']) . '</option>';
echo '</select></label><label>Nombre (ej. "Galpón 3", "Pivote norte")<input name="nombre" required></label>'
   . '<label>Ubicación<input name="ubicacion"></label>'
   . '<div id="campos-pivote" hidden><label>Centro latitud<input name="centro_lat" inputmode="decimal" placeholder="-36.6231"></label>'
   . '<label>Centro longitud<input name="centro_lon" inputmode="decimal" placeholder="-72.1305"></label>'
   . '<label>Largo del pivote (m)<input name="radio_m" inputmode="decimal" placeholder="400"></label></div>'
   . '<button' . ($clientes ? '' : ' disabled') . '>Crear equipo</button></form>';
echo '</div>';
echo '<script>const t=document.getElementById("tipo"),p=document.getElementById("campos-pivote");t.onchange=()=>p.hidden=t.value!=="pivote";</script>';

echo '<div class="card"><h2>Clientes y equipos</h2>';
if (!$clientes) echo '<p class="sub">Aún no hay clientes.</p>';
$equipos = q('SELECT * FROM equipos ORDER BY nombre')->fetchAll();
foreach ($clientes as $c) {
    echo '<div class="cli"><div class="fila"><b>' . h($c['nombre']) . '</b>' . ($c['activo'] ? '' : ' <span class="estado off">inactivo</span>')
       . '<span class="sub">usuario: ' . h($c['usuario']) . ' · ' . (int)$c['n'] . ' equipos</span>'
       . '<a href="?cliente=' . (int)$c['id'] . '">Editar</a></div><ul>';
    foreach ($equipos as $e) if ($e['cliente_id'] == $c['id'])
        echo '<li><a href="?equipo=' . (int)$e['id'] . '">' . h($e['nombre']) . '</a> <span class="tipo t-' . h($e['tipo']) . '">'
           . h($tipos[$e['tipo']]['titulo'] ?? $e['tipo']) . '</span> <span class="estado ' . (en_linea($e) ? 'on' : 'off') . '">'
           . (en_linea($e) ? 'en línea' : h(hace($e['ultimo_dato']))) . '</span>' . ($e['activo'] ? '' : ' (inactivo)') . '</li>';
    echo '</ul></div>';
}
echo '</div>';

$tot = q('SELECT COUNT(*) n FROM lecturas')->fetch()['n'];
echo '<form class="card" method="post">' . csrf_campo() . '<h2>Mantención</h2><p class="sub">' . number_format($tot, 0, ',', '.')
   . ' lecturas guardadas. Borra las antiguas para mantener el hosting liviano.</p><input type="hidden" name="accion" value="limpiar">'
   . '<div class="fila"><select name="dias"><option value="90">Más de 90 días</option><option value="180">Más de 180 días</option><option value="365" selected>Más de 1 año</option></select>'
   . '<button class="sec" onclick="return confirm(\'¿Borrar lecturas antiguas?\')">Limpiar</button></div></form>';
pie();
