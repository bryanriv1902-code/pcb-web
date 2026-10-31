<?php
// Funciones compartidas: carpeta privada, ajustes, pedidos, límites y avisos.
// Este archivo no hace nada si se abre directamente.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

const PCB_PRECIO_CENTAVOS = 24999;          // $249.99
const PCB_MONEDA = 'usd';
const PCB_PRODUCTO = 'Página web profesional — pago único';
const PCB_DIAS_PRUEBA = 3;

const PCB_ESTADOS = [
  'solicitado'     => 'Solicitado',
  'en_desarrollo'  => 'En desarrollo',
  'en_prueba'      => 'En prueba',
  'pendiente_pago' => 'Pendiente de pago',
  'entregado'      => 'Entregado',
  'descartado'     => 'No continuó',
];

date_default_timezone_set('America/New_York');
define('PCB_RAIZ', dirname(__DIR__));

/** Carpeta privada: fuera de public_html si se puede; si no, _privado/ protegida. */
function pcb_privado(): string {
  static $dir = null;
  if ($dir !== null) return $dir;
  $fuera = dirname(PCB_RAIZ) . '/pcb_privado';
  if (is_dir($fuera) || (is_writable(dirname(PCB_RAIZ)) && @mkdir($fuera, 0750, true))) {
    $dir = $fuera;
  } else {
    $dir = PCB_RAIZ . '/_privado';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
  }
  foreach (['pedidos', 'limites'] as $sub) { if (!is_dir("$dir/$sub")) @mkdir("$dir/$sub", 0750, true); }
  return $dir;
}

function pcb_json($arr, int $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: no-store');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function pcb_h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function pcb_texto($v, int $max): string {
  $v = is_scalar($v) ? (string) $v : '';
  $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v));
  return mb_substr($v, 0, $max);
}

/* ---------- Ajustes (clave del panel, correo de aviso, Stripe) ---------- */
function pcb_ajustes(): array {
  $f = pcb_privado() . '/ajustes.json';
  $a = is_file($f) ? json_decode((string) file_get_contents($f), true) : [];
  return is_array($a) ? $a : [];
}
function pcb_guardar_ajustes(array $a): bool {
  $f = pcb_privado() . '/ajustes.json';
  $ok = @file_put_contents($f, json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
  @chmod($f, 0640);
  return $ok;
}

/* ---------- Pedidos (un archivo por pedido) ---------- */
function pcb_ruta_pedido(string $id): ?string {
  if (!preg_match('/^PCB-\d{8}-[A-Z0-9]{4}$/', $id)) return null;
  return pcb_privado() . '/pedidos/' . $id . '.json';
}
function pcb_leer_pedido(string $id): ?array {
  $f = pcb_ruta_pedido($id);
  if (!$f || !is_file($f)) return null;
  $p = json_decode((string) file_get_contents($f), true);
  return is_array($p) ? $p : null;
}
function pcb_guardar_pedido(array $p): bool {
  $f = pcb_ruta_pedido($p['id'] ?? '');
  if (!$f) return false;
  return @file_put_contents($f, json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}
function pcb_todos_pedidos(): array {
  $out = [];
  foreach (glob(pcb_privado() . '/pedidos/PCB-*.json') ?: [] as $f) {
    $p = json_decode((string) file_get_contents($f), true);
    if (is_array($p)) $out[] = $p;
  }
  usort($out, function ($a, $b) { return strcmp($b['fecha'] ?? '', $a['fecha'] ?? ''); });
  return $out;
}
function pcb_pedido_por_token(string $token): ?array {
  if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
  foreach (pcb_todos_pedidos() as $p) { if (hash_equals($p['token'] ?? '', $token)) return $p; }
  return null;
}
function pcb_nuevo_id(): string {
  $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  do {
    $s = '';
    for ($i = 0; $i < 4; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
    $id = 'PCB-' . date('Ymd') . '-' . $s;
  } while (is_file(pcb_ruta_pedido($id)));
  return $id;
}
function pcb_historial(array &$p, string $texto) {
  $p['historial'][] = ['fecha' => date('c'), 'texto' => $texto];
}

/* ---------- Límite de envíos por IP ---------- */
function pcb_limite(string $clave, int $porMinuto, int $porDia): bool {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  $f = pcb_privado() . '/limites/' . $clave . '_' . md5($ip) . '.json';
  $now = time();
  $t = is_file($f) ? json_decode((string) @file_get_contents($f), true) : [];
  if (!is_array($t)) $t = [];
  $t = array_values(array_filter($t, function ($x) use ($now) { return $x > $now - 86400; }));
  $min = array_filter($t, function ($x) use ($now) { return $x > $now - 60; });
  if (count($min) >= $porMinuto || count($t) >= $porDia) return false;
  $t[] = $now;
  @file_put_contents($f, json_encode($t), LOCK_EX);
  return true;
}

/* ---------- Mismo origen ---------- */
function pcb_mismo_origen(): bool {
  $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
  $src = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
  if ($src === '') return true;
  return strtolower((string) parse_url($src, PHP_URL_HOST)) === preg_replace('/:\d+$/', '', $host);
}

/* ---------- Dirección pública del sitio ---------- */
function pcb_url_base(): string {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $raizWeb = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/x/x'))), '/');
  return ($https ? 'https' : 'http') . '://' . $host . $raizWeb;
}

/* ---------- Aviso por correo al dueño (si está configurado) ---------- */
function pcb_aviso(string $asunto, string $cuerpo): void {
  $to = trim((string) (pcb_ajustes()['email_aviso'] ?? ''));
  if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || !function_exists('mail')) return;
  $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
  $headers = 'From: PCB Soluciones Digitales <no-reply@' . $host . ">\r\nContent-Type: text/plain; charset=utf-8";
  @mail($to, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpo, $headers);
}

/* ---------- Correo de confirmación al cliente ---------- */
function pcb_correo_cliente(array $p): void {
  $to = (string) ($p['email'] ?? '');
  if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !function_exists('mail')) return;
  $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
  $enlace = pcb_url_base() . '/pago/?p=' . $p['token'];
  $cuerpo = "Hola {$p['nombre']}:\n\n"
    . "Recibimos tu solicitud de página web para {$p['negocio']}.\n\n"
    . "Tu número de pedido es: {$p['id']}\n\n"
    . "Nuestro objetivo es enviarte la primera versión en 48 horas. La pruebas gratis durante 3 días y solo pagas \$249.99 si decides quedarte con ella.\n\n"
    . "Puedes seguir tu pedido y, cuando esté listo, pagarlo de forma segura aquí:\n$enlace\n\n"
    . "También puedes entrar en la sección «Pagar mi página» de nuestra web con tu número de pedido y este correo.\n\n"
    . "PCB Soluciones Digitales\n";
  $headers = 'From: PCB Soluciones Digitales <no-reply@' . $host . ">\r\nContent-Type: text/plain; charset=utf-8";
  $aviso = trim((string) (pcb_ajustes()['email_aviso'] ?? ''));
  if ($aviso !== '' && filter_var($aviso, FILTER_VALIDATE_EMAIL)) $headers .= "\r\nReply-To: " . $aviso;
  @mail($to, '=?UTF-8?B?' . base64_encode('Tu pedido ' . $p['id'] . ' — PCB Soluciones Digitales') . '?=', $cuerpo, $headers);
}

/* ---------- Stripe ---------- */
function pcb_stripe_modo(): string {
  $k = (string) (pcb_ajustes()['stripe_secret'] ?? '');
  if (strpos($k, 'sk_live_') === 0 || strpos($k, 'rk_live_') === 0) return 'real';
  if (strpos($k, 'sk_test_') === 0 || strpos($k, 'rk_test_') === 0) return 'prueba';
  return 'demo';
}
function pcb_stripe_post(string $ruta, array $campos): array {
  $k = (string) (pcb_ajustes()['stripe_secret'] ?? '');
  $ch = curl_init('https://api.stripe.com/v1/' . $ruta);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($campos),
    CURLOPT_USERPWD => $k . ':',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
  ]);
  $raw = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $j = json_decode((string) $raw, true);
  return ['code' => $code, 'body' => is_array($j) ? $j : []];
}

/* ---------- Recibos (talonario con número consecutivo) ---------- */
const PCB_CANALES = ['web' => 'Página web', 'whatsapp' => 'WhatsApp', 'telefono' => 'Llamada telefónica', 'facebook' => 'Facebook', 'persona' => 'En persona'];
const PCB_METODOS = ['tarjeta' => 'Tarjeta (Stripe)', 'zelle' => 'Zelle', 'efectivo' => 'Efectivo', 'transferencia' => 'Transferencia bancaria', 'otro' => 'Otro'];

function pcb_dir_recibos(): string {
  $d = pcb_privado() . '/recibos';
  if (!is_dir($d)) @mkdir($d, 0750, true);
  return $d;
}
/** Siguiente número del talonario: PCB-R-AAAA-0001 (se reinicia cada año). Nunca se reutiliza un número. */
function pcb_nuevo_numero_recibo(): string {
  $f = pcb_dir_recibos() . '/contador.json';
  $fh = fopen($f, 'c+');
  if (!$fh) throw new RuntimeException('contador');
  flock($fh, LOCK_EX);
  $c = json_decode((string) stream_get_contents($fh), true);
  if (!is_array($c)) $c = [];
  $anio = date('Y');
  $n = (int) ($c[$anio] ?? 0) + 1;
  $c[$anio] = $n;
  ftruncate($fh, 0); rewind($fh);
  fwrite($fh, json_encode($c));
  fflush($fh); flock($fh, LOCK_UN); fclose($fh);
  return sprintf('PCB-R-%s-%04d', $anio, $n);
}
function pcb_ruta_recibo(string $num): ?string {
  if (!preg_match('/^PCB-R-\d{4}-\d{4,}$/', $num)) return null;
  return pcb_dir_recibos() . '/' . $num . '.json';
}
function pcb_guardar_recibo(array $r): bool {
  $f = pcb_ruta_recibo($r['numero'] ?? '');
  if (!$f) return false;
  return @file_put_contents($f, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}
function pcb_leer_recibo(string $num): ?array {
  $f = pcb_ruta_recibo($num);
  if (!$f || !is_file($f)) return null;
  $r = json_decode((string) file_get_contents($f), true);
  return is_array($r) ? $r : null;
}
function pcb_todos_recibos(): array {
  $out = [];
  foreach (glob(pcb_dir_recibos() . '/PCB-R-*.json') ?: [] as $f) {
    $r = json_decode((string) file_get_contents($f), true);
    if (is_array($r)) $out[] = $r;
  }
  usort($out, function ($a, $b) { return strcmp($b['numero'] ?? '', $a['numero'] ?? ''); });
  return $out;
}
function pcb_recibo_por_token(string $token): ?array {
  if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
  foreach (pcb_todos_recibos() as $r) { if (hash_equals($r['token'] ?? '', $token)) return $r; }
  return null;
}
function pcb_dinero($n): string { return '$' . number_format((float) $n, 2, '.', ','); }
function pcb_fecha_us(string $iso): string { return $iso !== '' && strtotime($iso) ? date('m/d/Y', strtotime($iso)) : '—'; }

/** Envía al cliente el enlace de su recibo. */
function pcb_correo_recibo(array $r): bool {
  $to = (string) ($r['cliente']['email'] ?? '');
  if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !function_exists('mail')) return false;
  $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
  $enlace = pcb_url_base() . '/recibo/?r=' . $r['token'];
  $cuerpo = "Hola {$r['cliente']['nombre']}:\n\n"
    . "Gracias por confiar en PCB Soluciones Digitales. Aquí tienes el recibo de tu compra.\n\n"
    . "Recibo: {$r['numero']}\nPedido: {$r['pedido_id']}\nTotal pagado: " . pcb_dinero($r['total']) . "\n\n"
    . "Puedes verlo, descargarlo en PDF o imprimirlo aquí:\n$enlace\n\n"
    . "Guarda este recibo como comprobante de tu pago.\n\n"
    . "PCB Soluciones Digitales\nWhatsApp: +1 (401) 616-7029\nhttps://pcbsolucionesdigitales.com\n";
  $headers = 'From: PCB Soluciones Digitales <no-reply@' . $host . ">\r\nContent-Type: text/plain; charset=utf-8";
  $aviso = trim((string) (pcb_ajustes()['email_aviso'] ?? ''));
  if ($aviso !== '' && filter_var($aviso, FILTER_VALIDATE_EMAIL)) $headers .= "\r\nReply-To: " . $aviso;
  return @mail($to, '=?UTF-8?B?' . base64_encode('Tu recibo ' . $r['numero'] . ' — PCB Soluciones Digitales') . '?=', $cuerpo, $headers);
}
