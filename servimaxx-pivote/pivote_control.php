<?php
// =====================================================================
//  Recibe los botones del panel "Control del pivote" (equipo.php).
//  GET  pivote_control.php?id=EQUIPO      -> estado actual (JSON)
//  POST pivote_control.php  JSON {id, accion, valor, confirmar}
//       cabecera X-CSRF con el token de la sesión
//  Las órdenes que pueden poner en movimiento la máquina responden 428
//  la primera vez; el panel muestra el aviso y las repite con confirmar.
// =====================================================================
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/pivote_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function salir_json($codigo, $datos) { http_response_code($codigo); echo json_encode($datos); exit; }

if (!usuario()) salir_json(401, ['ok' => false, 'error' => 'la sesión expiró, vuelve a entrar']);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $e = equipo_permitido((int)($_GET['id'] ?? 0));
        if (!$e || $e['tipo'] !== 'pivote') salir_json(404, ['ok' => false, 'error' => 'pivote no encontrado']);
        salir_json(200, ['ok' => true, 'estado' => pivote_estado($e)]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') salir_json(405, ['ok' => false, 'error' => 'método no permitido']);
    if (!hash_equals(csrf(), (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) salir_json(400, ['ok' => false, 'error' => 'recarga la página e intenta otra vez']);

    $d = json_decode(file_get_contents('php://input'), true);
    if (!is_array($d)) salir_json(400, ['ok' => false, 'error' => 'datos inválidos']);
    $e = equipo_permitido((int)($d['id'] ?? 0));
    if (!$e || $e['tipo'] !== 'pivote') salir_json(404, ['ok' => false, 'error' => 'pivote no encontrado']);

    [$codigo, $resp] = pivote_ordenar($e, (string)($d['accion'] ?? ''), $d['valor'] ?? null, ($d['confirmar'] ?? false) === true);
    salir_json($codigo, $resp);
} catch (Throwable $ex) {
    error_log('pivote_control: ' . $ex->getMessage());
    salir_json(500, ['ok' => false, 'error' => 'error interno']);
}
