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

$tipo = ($in['tipo'] ?? '') === 'contacto' ? 'contacto' : 'pedido';
$campo = function (string $k, int $max) use ($in) { return pcb_texto($in[$k] ?? '', $max); };
$resumen = $tipo === 'contacto'
  ? ['tipo' => 'contacto', 'nombre' => $campo('nombre', 80), 'contacto' => $campo('contacto', 160), 'mensaje' => $campo('mensaje', 1500)]
  : ['tipo' => 'pedido', 'nombre' => $campo('nombre', 80), 'negocio' => $campo('negocio', 120), 'telefono' => $campo('telefono', 24),
     'email' => $campo('email', 160), 'servicios' => $campo('servicios', 1500), 'preferencias' => $campo('preferencias', 1500)];

// Anti-spam 1: campo trampa invisible (solo lo llenan los robots) → se descarta en silencio
if ($campo('sitio', 200) !== '') { pcb_guardar_spam($resumen, 'campo trampa'); pcb_json(['ok' => true]); }

// Anti-spam 2: el navegador marca la hora en que se abrió la página. Sin esa marca
// (robots que envían directo sin abrir la web) se descarta; si es muy rápido, se pide reintentar.
$t = (int) ($in['t'] ?? 0);
$ahora = time() * 1000;
if ($t <= 0 || $t > $ahora + 86400000) { pcb_guardar_spam($resumen, 'envío sin abrir la página'); pcb_json(['ok' => true]); }
if (($ahora - $t) < 3000) pcb_json(['ok' => false, 'reason' => 'fast']);

if (empty($in['acepto'])) pcb_json(['ok' => false, 'reason' => 'consent']);
if (!pcb_limite('form', 4, 30)) pcb_json(['ok' => false, 'reason' => 'rate'], 429);

// Anti-spam 3: contenido (correo falso con nuestro dominio, frases de estafas de SEO, muchos enlaces…)
$chequeo = $tipo === 'contacto'
  ? pcb_puntaje_spam([$resumen['mensaje'], $resumen['contacto']], $resumen['contacto'], [$resumen['nombre']])
  : pcb_puntaje_spam([$resumen['servicios'], $resumen['preferencias']], $resumen['email'], [$resumen['nombre'], $resumen['negocio']]);
if ($chequeo['spam']) {
  pcb_guardar_spam($resumen, implode(', ', $chequeo['motivos']) . ' (' . $chequeo['puntos'] . ' puntos)');
  pcb_json(['ok' => true]); // el robot cree que funcionó y no insiste
}

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
if (strlen(preg_replace('/\D/', '', $p['telefono'])) < 10) pcb_json(['ok' => false, 'reason' => 'phone']);
if (!filter_var($p['email'], FILTER_VALIDATE_EMAIL)) pcb_json(['ok' => false, 'reason' => 'email']);
pcb_historial($p, 'Solicitud recibida desde la web');

if (!pcb_guardar_pedido($p)) pcb_json(['ok' => false, 'reason' => 'store'], 500);

pcb_aviso('Nueva solicitud de página web — ' . $p['negocio'],
  "Pedido: {$p['id']}\nNegocio: {$p['negocio']}\nSector: {$p['sector']}\nServicios: {$p['servicios']}\n" .
  "Contacto: {$p['nombre']} · {$p['telefono']} · {$p['email']}\nPreferencias: {$p['preferencias']}\n\n" .
  "Gestiónalo en el panel: " . pcb_url_base() . "/admin/\n");

pcb_correo_cliente($p);

pcb_json(['ok' => true, 'id' => $p['id']]);
