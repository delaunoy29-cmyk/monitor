<?php
// =====================================================================
//  API para la TARJETA DE RELÉS del pivote (firmware tarjeta_pivote_4g.ino)
//
//  POST pivote_api.php   JSON:
//    {"key":"CLAVE_API_DEL_PIVOTE", "ack":12, "marcha":1, "rssi":-71,
//     "evento":"sin_senal", "aviso":"texto corto", "version":"1.0"}
//      ack     número de la última orden que la tarjeta ya aplicó
//      marcha  1 si el relé de marcha está activado
//      evento  (opcional) la tarjeta detuvo el pivote por su cuenta:
//              "sin_senal" o "corte_luz"
//  Respuesta: lo que hay que aplicar a los relés (ver pivote_tarjeta()).
//  La clave es la misma que usa el equipo en api.php (Administrar > equipo).
// =====================================================================
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/pivote_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder_tarjeta($codigo, $datos) { http_response_code($codigo); echo json_encode($datos); exit; }

$d = json_decode(file_get_contents('php://input'), true);
if (!is_array($d)) $d = $_POST + $_GET;
$key = (string)($d['key'] ?? '');
if ($key === '') responder_tarjeta(401, ['ok' => 0, 'error' => 'falta key']);

try {
    $eq = q("SELECT e.* FROM equipos e JOIN clientes c ON c.id = e.cliente_id
             WHERE e.api_key = ? AND e.activo = 1 AND c.activo = 1 AND e.tipo = 'pivote'", [$key])->fetch();
    if (!$eq) responder_tarjeta(401, ['ok' => 0, 'error' => 'key inválida o el equipo no es un pivote']);
    responder_tarjeta(200, pivote_tarjeta($eq, $d));
} catch (Throwable $ex) {
    error_log('pivote_api: ' . $ex->getMessage());
    responder_tarjeta(500, ['ok' => 0, 'error' => 'error interno']);
}
