<?php
// "Pagar mi página": el cliente escribe su número de pedido y su correo
// y recibe el enlace a su página de pago privada.
require __DIR__ . '/_nucleo.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pcb_json(['ok' => false, 'reason' => 'method'], 405);
if (!pcb_mismo_origen()) pcb_json(['ok' => false, 'reason' => 'origin'], 403);

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
if (pcb_texto($in['sitio'] ?? '', 200) !== '') pcb_json(['ok' => false, 'reason' => 'nomatch']);
if (!pcb_limite('buscar', 6, 40)) pcb_json(['ok' => false, 'reason' => 'rate'], 429);

$id = strtoupper(preg_replace('/\s+/', '', pcb_texto($in['pedido'] ?? '', 30)));
$email = strtolower(pcb_texto($in['email'] ?? '', 160));
$p = pcb_leer_pedido($id);

// Misma respuesta si no existe el pedido o el correo no coincide (no se revela nada)
if (!$p || $email === '' || !hash_equals(strtolower((string) ($p['email'] ?? '')), $email)) {
  pcb_json(['ok' => false, 'reason' => 'nomatch']);
}
pcb_json(['ok' => true, 'url' => 'pago/?p=' . $p['token'], 'estado' => $p['estado'], 'pagado' => !empty($p['pago']['confirmado'])]);
