<?php
// Recibe las solicitudes de página web y los mensajes de contacto.
// Guarda en la carpeta privada y avisa por correo al dueño.
require __DIR__ . '/_nucleo.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pcb_json(['ok' => false, 'reason' => 'method'], 405);
if (!pcb_mismo_origen()) pcb_json(['ok' => false, 'reason' => 'origin'], 403);

$raw = file_get_contents('php://input');
if (strlen((string) $raw) > 20000) pcb_json(['ok' => false, 'reason' => 'size'], 413);
$in = json_decode((string) $raw, true);
if (!is_array($in)) $in = $_POST;

// Anti-spam: campo trampa y tiempo mínimo en la página
if (pcb_texto($in['sitio'] ?? '', 200) !== '') pcb_json(['ok' => true]); // se descarta en silencio
$t = (int) ($in['t'] ?? 0);
if ($t > 0 && (time() * 1000 - $t) < 3000) pcb_json(['ok' => false, 'reason' => 'fast']);
if (empty($in['acepto'])) pcb_json(['ok' => false, 'reason' => 'consent']);
if (!pcb_limite('form', 4, 30)) pcb_json(['ok' => false, 'reason' => 'rate'], 429);

$tipo = ($in['tipo'] ?? '') === 'contacto' ? 'contacto' : 'pedido';

if ($tipo === 'contacto') {
  $m = [
    'fecha'    => date('c'),
    'nombre'   => pcb_texto($in['nombre'] ?? '', 80),
    'contacto' => pcb_texto($in['contacto'] ?? '', 160),
    'mensaje'  => pcb_texto($in['mensaje'] ?? '', 1500),
    'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
  ];
  if ($m['nombre'] === '' || $m['contacto'] === '' || $m['mensaje'] === '') pcb_json(['ok' => false, 'reason' => 'fields']);
  $ok = @file_put_contents(pcb_privado() . '/mensajes.jsonl', json_encode($m, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX) !== false;
  pcb_aviso('Nuevo mensaje de contacto — ' . $m['nombre'],
    "Nombre: {$m['nombre']}\nContacto: {$m['contacto']}\n\n{$m['mensaje']}\n");
  pcb_json(['ok' => $ok]);
}

// Pedido de página web
$p = [
  'id'           => pcb_nuevo_id(),
  'token'        => bin2hex(random_bytes(16)),
  'fecha'        => date('c'),
  'estado'       => 'solicitado',
  'negocio'      => pcb_texto($in['negocio'] ?? '', 120),
  'sector'       => pcb_texto($in['sector'] ?? '', 80),
  'servicios'    => pcb_texto($in['servicios'] ?? '', 1500),
  'nombre'       => pcb_texto($in['nombre'] ?? '', 80),
  'telefono'     => preg_replace('/[^0-9+\s()-]/', '', pcb_texto($in['telefono'] ?? '', 24)),
  'email'        => pcb_texto($in['email'] ?? '', 160),
  'preferencias' => pcb_texto($in['preferencias'] ?? '', 1500),
  'prueba_url'   => '',
  'prueba_inicio'=> '',
  'notas'        => '',
  'pago'         => ['confirmado' => false],
  'historial'    => [],
];
if ($p['negocio'] === '' || $p['sector'] === '' || $p['servicios'] === '' || $p['nombre'] === '' || $p['telefono'] === '') {
  pcb_json(['ok' => false, 'reason' => 'fields']);
}
if (!filter_var($p['email'], FILTER_VALIDATE_EMAIL)) pcb_json(['ok' => false, 'reason' => 'email']);
pcb_historial($p, 'Solicitud recibida desde la web');

if (!pcb_guardar_pedido($p)) pcb_json(['ok' => false, 'reason' => 'store'], 500);

pcb_aviso('Nueva solicitud de página web — ' . $p['negocio'],
  "Pedido: {$p['id']}\nNegocio: {$p['negocio']}\nSector: {$p['sector']}\nServicios: {$p['servicios']}\n" .
  "Contacto: {$p['nombre']} · {$p['telefono']} · {$p['email']}\nPreferencias: {$p['preferencias']}\n\n" .
  "Gestiónalo en el panel: " . pcb_url_base() . "/admin/\n");

pcb_correo_cliente($p);

pcb_json(['ok' => true, 'id' => $p['id']]);
