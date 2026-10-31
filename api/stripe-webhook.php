<?php
// Confirmación de pagos desde Stripe.
// Un pago solo se marca como confirmado si llega aquí un evento con firma válida.
// La vuelta del navegador después de pagar NUNCA confirma el pago por sí sola.
require __DIR__ . '/_nucleo.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pcb_json(['ok' => false], 405);

$secreto = (string) (pcb_ajustes()['stripe_webhook_secret'] ?? '');
$payload = (string) file_get_contents('php://input');
$firma = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
if ($secreto === '' || $firma === '' || $payload === '') pcb_json(['ok' => false, 'reason' => 'no_config'], 400);

// Verificación de la firma (esquema v1 de Stripe: HMAC-SHA256 de "t.payload")
$t = null; $firmas = [];
foreach (explode(',', $firma) as $parte) {
  $kv = explode('=', trim($parte), 2);
  if (count($kv) !== 2) continue;
  if ($kv[0] === 't') $t = (int) $kv[1];
  if ($kv[0] === 'v1') $firmas[] = $kv[1];
}
if (!$t || !$firmas || abs(time() - $t) > 300) pcb_json(['ok' => false, 'reason' => 'firma'], 400);
$esperada = hash_hmac('sha256', $t . '.' . $payload, $secreto);
$valida = false;
foreach ($firmas as $f) { if (hash_equals($esperada, $f)) { $valida = true; break; } }
if (!$valida) pcb_json(['ok' => false, 'reason' => 'firma'], 400);

$ev = json_decode($payload, true);
$tipo = $ev['type'] ?? '';
if (!in_array($tipo, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
  pcb_json(['ok' => true, 'ignorado' => $tipo]);
}

$s = $ev['data']['object'] ?? [];
$id = (string) ($s['metadata']['pedido_id'] ?? ($s['client_reference_id'] ?? ''));
$p = pcb_leer_pedido($id);
if (!$p) pcb_json(['ok' => true, 'aviso' => 'pedido_no_encontrado']);

$pagado = ($s['payment_status'] ?? '') === 'paid';
// se compara el precio antes de impuestos, por si se cobra impuesto de venta
$importeOk = (int) ($s['amount_subtotal'] ?? ($s['amount_total'] ?? 0)) === PCB_PRECIO_CENTAVOS && strtolower((string) ($s['currency'] ?? '')) === PCB_MONEDA;

if ($pagado && $importeOk && empty($p['pago']['confirmado'])) {
  $p['pago'] = [
    'confirmado' => true,
    'fecha'      => date('c'),
    'sesion'     => (string) ($s['id'] ?? ''),
    'importe'    => number_format(PCB_PRECIO_CENTAVOS / 100, 2) . ' ' . strtoupper(PCB_MONEDA),
    'modo'       => !empty($ev['livemode']) ? 'real' : 'prueba',
    'email'      => (string) ($s['customer_details']['email'] ?? ''),
  ];
  pcb_historial($p, 'Pago confirmado por Stripe (' . $p['pago']['modo'] . ')');
  pcb_guardar_pedido($p);
  pcb_aviso('Pago confirmado — ' . $p['negocio'],
    "El pedido {$p['id']} ({$p['negocio']}) ha pagado \$249.99.\nModo: {$p['pago']['modo']}\n\nHaz la entrega definitiva y márcalo como Entregado en el panel.\n");
} elseif ($pagado && !$importeOk) {
  pcb_historial($p, 'Atención: llegó un pago con un importe distinto al esperado. Revísalo en Stripe.');
  pcb_guardar_pedido($p);
}

pcb_json(['ok' => true]);
