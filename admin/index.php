<?php
// Panel de PCB Soluciones Digitales: pedidos, estados, enlaces de pago y ajustes.
require dirname(__DIR__) . '/api/_nucleo.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('PCBPANEL');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$aj = pcb_ajustes();
$hayClave = !empty($aj['admin_hash']);
$aviso = ''; $error = '';
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$accion = (string) ($_POST['accion'] ?? '');

function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_ok(): bool { return hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? '')); }
function wa_cliente(string $tel): string {
  $d = preg_replace('/\D/', '', $tel);
  if (strlen($d) === 10) $d = '1' . $d; // número de EE. UU. sin código de país
  return strlen($d) >= 11 ? $d : '';
}

/* ---------- Crear contraseña (código de un solo uso) ---------- */
if (!$hayClave && $post && $accion === 'crear') {
  $codigo = (string) (@include __DIR__ . '/_codigo.php');
  $clave = (string) ($_POST['clave'] ?? '');
  if (!pcb_limite('setup', 5, 20)) $error = 'Demasiados intentos. Espera unos minutos.';
  elseif ($codigo === '' || !hash_equals(strtoupper($codigo), strtoupper(trim((string) ($_POST['codigo'] ?? ''))))) $error = 'El código de configuración no es correcto.';
  elseif (mb_strlen($clave) < 10) $error = 'La contraseña debe tener al menos 10 caracteres.';
  elseif ($clave !== (string) ($_POST['clave2'] ?? '')) $error = 'Las contraseñas no coinciden.';
  else {
    $aj['admin_hash'] = password_hash($clave, PASSWORD_DEFAULT);
    if (pcb_guardar_ajustes($aj)) { session_regenerate_id(true); $_SESSION['ok'] = true; $hayClave = true; $aviso = 'Contraseña creada. ¡Bienvenido a tu panel!'; }
    else $error = 'No se pudo guardar la contraseña en el servidor.';
  }
}

/* ---------- Entrar / salir ---------- */
if ($hayClave && $post && $accion === 'entrar') {
  if (!pcb_limite('login', 5, 40)) $error = 'Demasiados intentos. Espera unos minutos.';
  elseif (password_verify((string) ($_POST['clave'] ?? ''), $aj['admin_hash'])) { session_regenerate_id(true); $_SESSION['ok'] = true; }
  else $error = 'Contraseña incorrecta.';
}
if (($_GET['salir'] ?? '') === '1') { $_SESSION = []; session_destroy(); header('Location: ./'); exit; }
$dentro = $hayClave && !empty($_SESSION['ok']);

/* ---------- Acciones dentro del panel ---------- */
if ($dentro && $post && !in_array($accion, ['entrar', 'crear'], true)) {
  if (!csrf_ok()) { $error = 'La sesión caducó. Vuelve a intentarlo.'; }
  elseif ($accion === 'pedido') {
    $p = pcb_leer_pedido((string) ($_POST['id'] ?? ''));
    if ($p) {
      $nuevo = (string) ($_POST['estado'] ?? '');
      if (isset(PCB_ESTADOS[$nuevo]) && $nuevo !== $p['estado']) {
        if ($nuevo === 'en_prueba' && empty($p['prueba_inicio'])) $p['prueba_inicio'] = date('c');
        pcb_historial($p, 'Estado: ' . PCB_ESTADOS[$p['estado']] . ' → ' . PCB_ESTADOS[$nuevo]);
        $p['estado'] = $nuevo;
      }
      $url = trim((string) ($_POST['prueba_url'] ?? ''));
      $p['prueba_url'] = ($url === '' || filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url)) ? mb_substr($url, 0, 300) : $p['prueba_url'];
      $p['notas'] = pcb_texto($_POST['notas'] ?? '', 2000);
      pcb_guardar_pedido($p);
      $aviso = 'Pedido ' . $p['id'] . ' actualizado.';
    }
  } elseif ($accion === 'ajustes') {
    $email = trim((string) ($_POST['email_aviso'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'El correo de aviso no es válido.';
    else {
      $aj['email_aviso'] = $email;
      $sk = trim((string) ($_POST['stripe_secret'] ?? ''));
      $wh = trim((string) ($_POST['stripe_webhook_secret'] ?? ''));
      if ($sk !== '') {
        if (!preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $sk)) $error = 'La clave secreta de Stripe no tiene el formato correcto (empieza por sk_live_ o sk_test_).';
        else $aj['stripe_secret'] = $sk;
      }
      if ($wh !== '') {
        if (!preg_match('/^whsec_[A-Za-z0-9]+$/', $wh)) $error = 'El secreto de confirmación de Stripe debe empezar por whsec_.';
        else $aj['stripe_webhook_secret'] = $wh;
      }
      if (!empty($_POST['quitar_stripe'])) { unset($aj['stripe_secret'], $aj['stripe_webhook_secret']); }
      $aj['stripe_tax'] = !empty($_POST['stripe_tax']);
      if (!$error) { pcb_guardar_ajustes($aj); $aviso = 'Ajustes guardados.'; }
    }
  } elseif ($accion === 'recibo_crear') {
    $txt = function ($k, $max) { return pcb_texto($_POST[$k] ?? '', $max); };
    $fecha = function ($k) { $v = trim((string) ($_POST[$k] ?? '')); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : ''; };
    $pedidoId = $txt('pedido_id', 20);
    $ped = $pedidoId !== '' ? pcb_leer_pedido($pedidoId) : null;
    $canal = (string) ($_POST['canal'] ?? '');
    $metodo = (string) ($_POST['metodo'] ?? '');
    $precio = round((float) str_replace([',', '$'], '', (string) ($_POST['precio'] ?? '0')), 2);
    $pct = round(max(0, min(20, (float) str_replace(',', '.', (string) ($_POST['impuesto_pct'] ?? '0')))), 3);
    $cliente = [
      'nombre'    => $txt('nombre', 80),
      'negocio'   => $txt('negocio', 120),
      'telefono'  => preg_replace('/[^0-9+\s()-]/', '', $txt('telefono', 24)),
      'email'     => $txt('email', 160),
      'direccion' => $txt('direccion', 200),
    ];
    if ($pedidoId !== '' && !$ped) $error = 'No se encontró el pedido ' . $pedidoId . '.';
    elseif ($cliente['nombre'] === '') $error = 'Escribe el nombre del cliente.';
    elseif ($cliente['email'] !== '' && !filter_var($cliente['email'], FILTER_VALIDATE_EMAIL)) $error = 'El correo del cliente no es válido.';
    elseif (!isset(PCB_CANALES[$canal])) $error = 'Elige cómo llegó el cliente.';
    elseif (!isset(PCB_METODOS[$metodo])) $error = 'Elige el método de pago.';
    elseif ($precio <= 0) $error = 'El precio debe ser mayor que cero.';
    elseif ($fecha('fecha_pago') === '') $error = 'Escribe la fecha de pago.';
    elseif ($ped && !empty($ped['recibo']) && ($rv = pcb_leer_recibo($ped['recibo'])) && ($rv['estado'] ?? '') !== 'anulado') $error = 'Este pedido ya tiene el recibo ' . $ped['recibo'] . '. Anúlalo primero si necesitas emitir otro.';
    else {
      $impuesto = round($precio * $pct / 100, 2);
      $registro = $fecha('dominio_registro');
      $incluye = array_values(array_filter(array_map(function ($l) { return pcb_texto($l, 140); }, preg_split('/\r?\n/', (string) ($_POST['incluye'] ?? '')))));
      // Venta que no pasó por la web (WhatsApp, teléfono…): se registra también como pedido para tener todo en un solo lugar
      if (!$ped) {
        $ped = [
          'id' => pcb_nuevo_id(), 'token' => bin2hex(random_bytes(16)), 'fecha' => date('c'), 'estado' => 'entregado',
          'negocio' => $cliente['negocio'] !== '' ? $cliente['negocio'] : $cliente['nombre'], 'sector' => $txt('sector', 80),
          'servicios' => '', 'nombre' => $cliente['nombre'], 'telefono' => $cliente['telefono'], 'email' => $cliente['email'],
          'preferencias' => '', 'prueba_url' => '', 'prueba_inicio' => '', 'notas' => '', 'canal' => $canal,
          'pago' => ['confirmado' => false], 'historial' => [],
        ];
        pcb_historial($ped, 'Venta registrada desde el panel (' . PCB_CANALES[$canal] . ')');
      }
      $r = [
        'numero' => pcb_nuevo_numero_recibo(), 'token' => bin2hex(random_bytes(16)), 'fecha_emision' => date('c'), 'estado' => 'emitido',
        'pedido_id' => $ped['id'], 'canal' => $canal, 'cliente' => $cliente,
        'pagina' => [
          'codigo' => $txt('codigo_pagina', 60) !== '' ? $txt('codigo_pagina', 60) : $ped['id'],
          'dominio' => $txt('dominio', 120),
          'dominio_registro' => $registro,
          'dominio_renovacion' => $registro !== '' ? date('Y-m-d', strtotime($registro . ' +1 year')) : '',
          'fecha_entrega' => $fecha('fecha_entrega'),
        ],
        'concepto' => $txt('concepto', 140) !== '' ? $txt('concepto', 140) : PCB_PRODUCTO,
        'precio' => $precio, 'impuesto_pct' => $pct, 'impuesto' => $impuesto, 'total' => round($precio + $impuesto, 2),
        'pago' => ['metodo' => $metodo, 'referencia' => $txt('referencia', 80), 'fecha' => $fecha('fecha_pago')],
        'incluye' => $incluye, 'notas' => $txt('notas_recibo', 600), 'historial' => [],
      ];
      pcb_historial($r, 'Recibo emitido');
      if (!pcb_guardar_recibo($r)) $error = 'No se pudo guardar el recibo en el servidor.';
      else {
        $ped['recibo'] = $r['numero'];
        if (empty($ped['pago']['confirmado'])) {
          $ped['pago'] = ['confirmado' => true, 'fecha' => $r['pago']['fecha'] . 'T12:00:00', 'modo' => 'manual', 'metodo' => $metodo, 'total' => $r['total']];
        }
        pcb_historial($ped, 'Recibo ' . $r['numero'] . ' emitido · ' . pcb_dinero($r['total']) . ' · ' . PCB_METODOS[$metodo]);
        pcb_guardar_pedido($ped);
        header('Location: ?v=recibos&hecho=' . rawurlencode($r['numero']), true, 303);
        exit;
      }
    }
  } elseif ($accion === 'recibo_anular') {
    $r = pcb_leer_recibo((string) ($_POST['numero'] ?? ''));
    $motivo = pcb_texto($_POST['motivo'] ?? '', 200);
    if (!$r) $error = 'No se encontró el recibo.';
    elseif ($motivo === '') $error = 'Escribe el motivo para anular el recibo.';
    else {
      $r['estado'] = 'anulado'; $r['anulado'] = ['fecha' => date('c'), 'motivo' => $motivo];
      pcb_historial($r, 'Recibo anulado: ' . $motivo);
      pcb_guardar_recibo($r);
      if ($ped = pcb_leer_pedido($r['pedido_id'] ?? '')) { pcb_historial($ped, 'Recibo ' . $r['numero'] . ' anulado: ' . $motivo); pcb_guardar_pedido($ped); }
      $aviso = 'Recibo ' . $r['numero'] . ' anulado. El número queda registrado y no se vuelve a usar.';
    }
  } elseif ($accion === 'recibo_correo') {
    $r = pcb_leer_recibo((string) ($_POST['numero'] ?? ''));
    if (!$r) $error = 'No se encontró el recibo.';
    elseif (($r['estado'] ?? '') === 'anulado') $error = 'Este recibo está anulado.';
    elseif (!filter_var($r['cliente']['email'] ?? '', FILTER_VALIDATE_EMAIL)) $error = 'Este recibo no tiene un correo del cliente.';
    elseif (!pcb_limite('correo_recibo', 5, 60)) $error = 'Demasiados envíos seguidos. Espera unos minutos.';
    elseif (pcb_correo_recibo($r)) { pcb_historial($r, 'Enviado por correo a ' . $r['cliente']['email']); pcb_guardar_recibo($r); $aviso = 'Recibo enviado a ' . $r['cliente']['email'] . '.'; }
    else $error = 'El servidor no pudo enviar el correo. Envía el enlace por WhatsApp.';
  } elseif ($accion === 'clave') {
    $nueva = (string) ($_POST['nueva'] ?? '');
    if (!password_verify((string) ($_POST['actual'] ?? ''), $aj['admin_hash'])) $error = 'La contraseña actual no es correcta.';
    elseif (mb_strlen($nueva) < 10) $error = 'La nueva contraseña debe tener al menos 10 caracteres.';
    else { $aj['admin_hash'] = password_hash($nueva, PASSWORD_DEFAULT); pcb_guardar_ajustes($aj); $aviso = 'Contraseña cambiada.'; }
  }
}

/* ---------- Descarga para Excel ---------- */
if ($dentro && ($_GET['excel'] ?? '') === '1') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="pedidos-pcb-' . date('Y-m-d') . '.csv"');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['Pedido', 'Fecha', 'Estado', 'Negocio', 'Sector', 'Servicios', 'Nombre', 'Teléfono', 'Email', 'Preferencias', 'Página de prueba', 'Pago confirmado', 'Fecha de pago', 'Notas']);
  foreach (pcb_todos_pedidos() as $p) {
    fputcsv($out, [$p['id'], $p['fecha'], PCB_ESTADOS[$p['estado']] ?? $p['estado'], $p['negocio'], $p['sector'], $p['servicios'], $p['nombre'], $p['telefono'], $p['email'], $p['preferencias'], $p['prueba_url'], !empty($p['pago']['confirmado']) ? 'Sí' : 'No', $p['pago']['fecha'] ?? '', $p['notas']]);
  }
  exit;
}

if ($dentro && ($_GET['excel'] ?? '') === 'recibos') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="recibos-pcb-' . date('Y-m-d') . '.csv"');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['Recibo', 'Fecha de emisión', 'Estado', 'Pedido', 'Canal', 'Cliente', 'Negocio', 'Teléfono', 'Email', 'Código de la página', 'Dominio', 'Renovación del dominio', 'Concepto', 'Precio', 'Impuesto %', 'Impuesto', 'Total', 'Método de pago', 'Referencia', 'Fecha de pago', 'Motivo de anulación']);
  foreach (pcb_todos_recibos() as $r) {
    fputcsv($out, [$r['numero'], pcb_fecha_us($r['fecha_emision']), $r['estado'] === 'anulado' ? 'Anulado' : 'Emitido', $r['pedido_id'], PCB_CANALES[$r['canal']] ?? $r['canal'],
      $r['cliente']['nombre'], $r['cliente']['negocio'], $r['cliente']['telefono'], $r['cliente']['email'], $r['pagina']['codigo'], $r['pagina']['dominio'], pcb_fecha_us($r['pagina']['dominio_renovacion']),
      $r['concepto'], number_format($r['precio'], 2, '.', ''), $r['impuesto_pct'], number_format($r['impuesto'], 2, '.', ''), number_format($r['total'], 2, '.', ''),
      PCB_METODOS[$r['pago']['metodo']] ?? $r['pago']['metodo'], $r['pago']['referencia'], pcb_fecha_us($r['pago']['fecha']), $r['anulado']['motivo'] ?? '']);
  }
  exit;
}

$vista = (string) ($_GET['v'] ?? 'pedidos');
if ($dentro && !empty($_GET['hecho'])) $aviso = 'Recibo ' . pcb_texto($_GET['hecho'], 30) . ' creado. Ya puedes enviárselo al cliente.';
$base = pcb_url_base();
$modo = pcb_stripe_modo();
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Panel · PCB Soluciones Digitales</title>
  <link rel="icon" href="../favicon.png" type="image/png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap">
  <link rel="stylesheet" href="../assets/panel.css?v=20261031b">
</head>
<body class="panel">
<header class="bar">
  <a href="./"><img src="../assets/img/logo-pcb-220.webp" width="100" height="35" alt="PCB Soluciones Digitales"></a>
  <?php if ($dentro): ?>
  <nav>
    <a href="?v=pedidos" class="<?= $vista === 'pedidos' ? 'on' : '' ?>">Pedidos</a>
    <a href="?v=recibos" class="<?= $vista === 'recibos' ? 'on' : '' ?>">Recibos</a>
    <a href="?v=mensajes" class="<?= $vista === 'mensajes' ? 'on' : '' ?>">Mensajes</a>
    <a href="?v=ajustes" class="<?= $vista === 'ajustes' ? 'on' : '' ?>">Ajustes</a>
    <a href="?salir=1">Salir</a>
  </nav>
  <?php endif; ?>
</header>
<main class="panel__main">
<?php if ($aviso): ?><p class="okbar"><?= pcb_h($aviso) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert"><?= pcb_h($error) ?></p><?php endif; ?>

<?php if (!$hayClave): ?>
  <section class="box narrow">
    <h1>Configura tu panel</h1>
    <p class="muted">Escribe el código de configuración que te dimos y crea tu contraseña. El código solo sirve una vez.</p>
    <form method="post" class="stack">
      <input type="hidden" name="accion" value="crear">
      <label>Código de configuración<input name="codigo" required autocomplete="off"></label>
      <label>Nueva contraseña (mínimo 10 caracteres)<input name="clave" type="password" required minlength="10" autocomplete="new-password"></label>
      <label>Repite la contraseña<input name="clave2" type="password" required minlength="10" autocomplete="new-password"></label>
      <button class="btn">Crear contraseña</button>
    </form>
  </section>

<?php elseif (!$dentro): ?>
  <section class="box narrow">
    <h1>Entrar al panel</h1>
    <form method="post" class="stack">
      <input type="hidden" name="accion" value="entrar">
      <label>Contraseña<input name="clave" type="password" required autocomplete="current-password" autofocus></label>
      <button class="btn">Entrar</button>
    </form>
  </section>

<?php elseif ($vista === 'recibos'):
    $recibos = pcb_todos_recibos();
    $todosPed = pcb_todos_pedidos();
    $pp = !empty($_GET['pedido']) ? pcb_leer_pedido((string) $_GET['pedido']) : null;
    $reenvio = $post && $accion === 'recibo_crear' && $error;
    // Valor del campo: lo que se escribió (si hubo un error) o el dato del pedido elegido
    $val = function (string $k, $def = '') use ($reenvio) { return $reenvio ? (string) ($_POST[$k] ?? '') : (string) $def; };
    $hoy = date('Y-m-d');
    $pagoPed = $pp['pago'] ?? [];
    $pctPed = (!empty($pagoPed['subtotal']) && !empty($pagoPed['impuesto'])) ? round($pagoPed['impuesto'] / $pagoPed['subtotal'] * 100, 3) : 0;
    $incluyeDef = "Diseño de página web profesional\nAdaptación a celulares y computadoras\nFormulario de contacto y botón de WhatsApp\nInteligencia artificial para atender a sus clientes\nDominio propio durante el primer año\nPublicación de la página";
?>
  <div class="head">
    <h1>Recibos</h1>
    <?php if ($recibos): ?><a class="btn btn--line" href="?excel=recibos">Descargar para Excel</a><?php endif; ?>
  </div>

  <section class="box">
    <h2>Nuevo recibo</h2>
    <p class="muted small">Sirve para cualquier venta: de la web, WhatsApp, teléfono o en persona. Cada recibo recibe un número consecutivo que no se repite.</p>
    <form method="get" class="stack rc-load">
      <input type="hidden" name="v" value="recibos">
      <label>Cargar los datos de un pedido (opcional)
        <select name="pedido" onchange="this.form.submit()">
          <option value="">— Venta nueva (WhatsApp, teléfono, en persona…) —</option>
          <?php foreach ($todosPed as $o): ?>
            <option value="<?= pcb_h($o['id']) ?>" <?= $pp && $pp['id'] === $o['id'] ? 'selected' : '' ?>><?= pcb_h($o['id'] . ' · ' . $o['negocio'] . (!empty($o['recibo']) ? ' · recibo ' . $o['recibo'] : '')) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <noscript><button class="btn btn--line">Cargar</button></noscript>
    </form>

    <form method="post" class="stack rc-form" data-rc>
      <input type="hidden" name="accion" value="recibo_crear"><input type="hidden" name="csrf" value="<?= pcb_h(csrf()) ?>">
      <input type="hidden" name="pedido_id" value="<?= pcb_h($val('pedido_id', $pp['id'] ?? '')) ?>">
      <?php if ($pp): ?><p class="okbar small">Datos cargados del pedido <?= pcb_h($pp['id']) ?>. Revísalos y completa lo que falte.</p><?php endif; ?>

      <fieldset><legend>Cliente</legend>
        <div class="rc-2">
          <label>Nombre del cliente *<input name="nombre" required maxlength="80" value="<?= pcb_h($val('nombre', $pp['nombre'] ?? '')) ?>"></label>
          <label>Negocio<input name="negocio" maxlength="120" value="<?= pcb_h($val('negocio', $pp['negocio'] ?? '')) ?>"></label>
          <label>Teléfono<input name="telefono" type="tel" maxlength="24" value="<?= pcb_h($val('telefono', $pp['telefono'] ?? '')) ?>"></label>
          <label>Correo<input name="email" type="email" maxlength="160" value="<?= pcb_h($val('email', $pp['email'] ?? '')) ?>"></label>
          <label>Dirección (opcional)<input name="direccion" maxlength="200" value="<?= pcb_h($val('direccion')) ?>" placeholder="Ciudad, estado"></label>
          <?php if (!$pp): ?><label>Sector (opcional)<input name="sector" maxlength="80" value="<?= pcb_h($val('sector')) ?>" placeholder="Ej.: Limpieza"></label><?php endif; ?>
          <label>¿Cómo llegó el cliente? *
            <select name="canal" required><?php $cv = $val('canal', $pp ? ($pp['canal'] ?? 'web') : 'whatsapp'); foreach (PCB_CANALES as $k => $l): ?><option value="<?= $k ?>" <?= $cv === $k ? 'selected' : '' ?>><?= pcb_h($l) ?></option><?php endforeach; ?></select>
          </label>
        </div>
      </fieldset>

      <fieldset><legend>Página web</legend>
        <div class="rc-2">
          <label>Código de la página<input name="codigo_pagina" maxlength="60" value="<?= pcb_h($val('codigo_pagina')) ?>" placeholder="Si lo dejas vacío, se usa el número de pedido"></label>
          <label>Dominio<input name="dominio" maxlength="120" value="<?= pcb_h($val('dominio', $pp && !empty($pp['prueba_url']) ? preg_replace('#^https?://(www\.)?#i', '', rtrim($pp['prueba_url'], '/')) : '')) ?>" placeholder="tunegocio.com"></label>
          <label>Fecha de registro del dominio<input name="dominio_registro" type="date" value="<?= pcb_h($val('dominio_registro')) ?>"></label>
          <label>Fecha de entrega<input name="fecha_entrega" type="date" value="<?= pcb_h($val('fecha_entrega', $hoy)) ?>"></label>
        </div>
        <p class="muted small">La renovación del dominio se calcula sola: un año después del registro.</p>
        <label>Incluido (una línea por cada cosa)<textarea name="incluye" rows="6"><?= pcb_h($val('incluye', $incluyeDef)) ?></textarea></label>
      </fieldset>

      <fieldset><legend>Pago</legend>
        <div class="rc-2">
          <label>Concepto<input name="concepto" maxlength="140" value="<?= pcb_h($val('concepto', PCB_PRODUCTO)) ?>"></label>
          <label>Precio (USD) *<input name="precio" inputmode="decimal" required value="<?= pcb_h($val('precio', number_format(PCB_PRECIO_CENTAVOS / 100, 2, '.', ''))) ?>" data-rc-precio></label>
          <label>Impuesto de venta (%)<input name="impuesto_pct" inputmode="decimal" value="<?= pcb_h($val('impuesto_pct', (string) $pctPed)) ?>" data-rc-pct>
            <span class="muted small rc-hint">0 si no cobraste impuesto · 7 para Rhode Island</span></label>
          <label>Método de pago *
            <select name="metodo" required><?php $mv = $val('metodo', $pagoPed['metodo'] ?? (!empty($pagoPed['confirmado']) ? 'tarjeta' : 'zelle')); foreach (PCB_METODOS as $k => $l): ?><option value="<?= $k ?>" <?= $mv === $k ? 'selected' : '' ?>><?= pcb_h($l) ?></option><?php endforeach; ?></select>
          </label>
          <label>Referencia o confirmación<input name="referencia" maxlength="80" value="<?= pcb_h($val('referencia', $pagoPed['referencia'] ?? '')) ?>" placeholder="Nº de confirmación de Zelle, Stripe…"></label>
          <label>Fecha de pago *<input name="fecha_pago" type="date" required value="<?= pcb_h($val('fecha_pago', !empty($pagoPed['fecha']) ? date('Y-m-d', strtotime($pagoPed['fecha'])) : $hoy)) ?>"></label>
        </div>
        <p class="rc-total">Total del recibo: <b data-rc-total>—</b></p>
        <label>Nota para el cliente (opcional)<textarea name="notas_recibo" rows="2" maxlength="600"><?= pcb_h($val('notas_recibo')) ?></textarea></label>
      </fieldset>

      <button class="btn">Crear recibo</button>
    </form>
  </section>

  <h2 class="rc-list-title">Recibos emitidos</h2>
  <?php if (!$recibos): ?><p class="muted">Todavía no has emitido recibos.</p><?php endif; ?>
  <?php foreach ($recibos as $r):
    $link = $base . '/recibo/?r=' . $r['token'];
    $anul = ($r['estado'] ?? '') === 'anulado';
    $waR = wa_cliente($r['cliente']['telefono'] ?? '');
    $msgR = "Hola {$r['cliente']['nombre']}, gracias por tu compra. Aquí tienes tu recibo {$r['numero']} de PCB Soluciones Digitales: $link";
  ?>
    <article id="<?= pcb_h($r['numero']) ?>" class="box rc-item<?= $anul ? ' is-void' : '' ?><?= ($_GET['hecho'] ?? '') === $r['numero'] ? ' is-new' : '' ?>">
      <div class="order__top">
        <div>
          <p class="meta"><?= pcb_h($r['numero']) ?> · <?= pcb_h(pcb_fecha_us($r['fecha_emision'])) ?> · Pedido <?= pcb_h($r['pedido_id']) ?></p>
          <h2><?= pcb_h($r['cliente']['negocio'] !== '' ? $r['cliente']['negocio'] : $r['cliente']['nombre']) ?> <span class="muted">· <?= pcb_h($r['cliente']['nombre']) ?></span></h2>
        </div>
        <p class="state <?= $anul ? 'state--descartado' : 'state--entregado' ?>"><?= $anul ? 'Anulado' : 'Emitido' ?> · <?= pcb_h(pcb_dinero($r['total'])) ?></p>
      </div>
      <p class="muted small"><?= pcb_h(PCB_METODOS[$r['pago']['metodo']] ?? '') ?> · pagado el <?= pcb_h(pcb_fecha_us($r['pago']['fecha'])) ?> · <?= pcb_h(PCB_CANALES[$r['canal']] ?? '') ?><?= $r['pagina']['dominio'] !== '' ? ' · ' . pcb_h($r['pagina']['dominio']) : '' ?><?= $anul ? ' · Motivo: ' . pcb_h($r['anulado']['motivo'] ?? '') : '' ?></p>
      <div class="row rc-actions">
        <a class="btn" href="<?= pcb_h($link) ?>" target="_blank" rel="noopener">Ver / descargar PDF</a>
        <?php if (!$anul): ?>
          <?php if ($waR): ?><a class="btn btn--line" target="_blank" rel="noopener" href="https://wa.me/<?= $waR ?>?text=<?= rawurlencode($msgR) ?>">Enviar por WhatsApp</a><?php endif; ?>
          <button type="button" class="btn btn--line" data-copy="<?= pcb_h($link) ?>">Copiar enlace</button>
          <?php if (filter_var($r['cliente']['email'] ?? '', FILTER_VALIDATE_EMAIL)): ?>
            <form method="post"><input type="hidden" name="accion" value="recibo_correo"><input type="hidden" name="numero" value="<?= pcb_h($r['numero']) ?>"><input type="hidden" name="csrf" value="<?= pcb_h(csrf()) ?>"><button class="btn btn--line">Enviar por correo</button></form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php if (!$anul): ?>
      <details class="hist"><summary>Anular este recibo</summary>
        <form method="post" class="stack rc-void">
          <input type="hidden" name="accion" value="recibo_anular"><input type="hidden" name="numero" value="<?= pcb_h($r['numero']) ?>"><input type="hidden" name="csrf" value="<?= pcb_h(csrf()) ?>">
          <label>Motivo<input name="motivo" required maxlength="200" placeholder="Ej.: datos del cliente equivocados"></label>
          <button class="btn btn--line">Anular recibo</button>
        </form>
      </details>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>

<?php elseif ($vista === 'mensajes'): ?>
  <h1>Mensajes de contacto</h1>
  <?php
    $f = pcb_privado() . '/mensajes.jsonl';
    $msgs = is_file($f) ? array_reverse(array_filter(array_map(function ($l) { return json_decode($l, true); }, file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)))) : [];
  ?>
  <?php if (!$msgs): ?><p class="muted">Todavía no hay mensajes.</p><?php endif; ?>
  <?php foreach ($msgs as $m): ?>
    <article class="box">
      <p class="meta"><?= pcb_h(date('m/d/Y H:i', strtotime($m['fecha'] ?? 'now'))) ?></p>
      <h2><?= pcb_h($m['nombre'] ?? '') ?> · <span class="muted"><?= pcb_h($m['contacto'] ?? '') ?></span></h2>
      <p><?= nl2br(pcb_h($m['mensaje'] ?? '')) ?></p>
    </article>
  <?php endforeach; ?>

<?php elseif ($vista === 'ajustes'): ?>
  <h1>Ajustes</h1>
  <section class="box">
    <h2>Avisos y pagos</h2>
    <p class="muted">Estado de los pagos: <strong><?= $modo === 'real' ? 'Activados (cobros reales)' : ($modo === 'prueba' ? 'Modo de prueba de Stripe (sin cobros reales)' : 'Modo demostración (sin pagos)') ?></strong></p>
    <form method="post" class="stack">
      <input type="hidden" name="accion" value="ajustes"><input type="hidden" name="csrf" value="<?= pcb_h(csrf()) ?>">
      <label>Correo donde recibes los avisos de nuevas solicitudes y pagos
        <input name="email_aviso" type="email" value="<?= pcb_h($aj['email_aviso'] ?? '') ?>" placeholder="tucorreo@ejemplo.com"></label>
      <label>Clave secreta de Stripe <?= !empty($aj['stripe_secret']) ? '<span class="tag">guardada</span>' : '' ?>
        <input name="stripe_secret" type="password" autocomplete="off" placeholder="<?= !empty($aj['stripe_secret']) ? 'Déjalo vacío para mantener la actual' : 'sk_live_… o sk_test_…' ?>"></label>
      <label>Secreto de confirmación de pagos de Stripe <?= !empty($aj['stripe_webhook_secret']) ? '<span class="tag">guardado</span>' : '' ?>
        <input name="stripe_webhook_secret" type="password" autocomplete="off" placeholder="<?= !empty($aj['stripe_webhook_secret']) ? 'Déjalo vacío para mantener el actual' : 'whsec_…' ?>"></label>
      <p class="muted small">En Stripe, crea un destino de eventos con esta dirección y el evento <code>checkout.session.completed</code>:<br><code class="copy"><?= pcb_h($base . '/api/stripe-webhook.php') ?></code></p>
      <label class="row"><input type="checkbox" name="stripe_tax" value="1" <?= !empty($aj['stripe_tax']) ? 'checked' : '' ?>> Sumar el impuesto de venta automáticamente con Stripe Tax (actívalo antes en tu cuenta de Stripe y registra Rhode Island)</label>
      <?php if (!empty($aj['stripe_secret'])): ?><label class="row"><input type="checkbox" name="quitar_stripe" value="1"> Quitar las claves de Stripe (volver a modo demostración)</label><?php endif; ?>
      <button class="btn">Guardar ajustes</button>
    </form>
  </section>
  <section class="box">
    <h2>Cambiar contraseña</h2>
    <form method="post" class="stack">
      <input type="hidden" name="accion" value="clave"><input type="hidden" name="csrf" value="<?= pcb_h(csrf()) ?>">
      <label>Contraseña actual<input name="actual" type="password" required autocomplete="current-password"></label>
      <label>Nueva contraseña<input name="nueva" type="password" required minlength="10" autocomplete="new-password"></label>
      <button class="btn btn--line">Cambiar contraseña</button>
    </form>
  </section>

<?php else: $pedidos = pcb_todos_pedidos(); ?>
  <div class="head">
    <h1>Pedidos</h1>
    <a class="btn btn--line" href="?excel=1">Descargar para Excel</a>
  </div>
  <?php if ($modo !== 'real'): ?><p class="banner banner--demo">Pagos en <?= $modo === 'prueba' ? 'modo de prueba de Stripe' : 'modo demostración' ?>. Configúralos en <a href="?v=ajustes">Ajustes</a>.</p><?php endif; ?>
  <?php
    $cuenta = array_count_values(array_map(function ($p) { return $p['estado']; }, $pedidos));
  ?>
  <ul class="counts">
    <?php foreach (PCB_ESTADOS as $k => $lbl): ?><li class="state state--<?= $k ?>"><?= pcb_h($lbl) ?> <b><?= (int) ($cuenta[$k] ?? 0) ?></b></li><?php endforeach; ?>
  </ul>
  <?php if (!$pedidos): ?><p class="muted">Todavía no hay solicitudes. Aparecerán aquí en cuanto alguien envíe el formulario de la web.</p><?php endif; ?>

  <?php foreach ($pedidos as $p):
    $enlacePago = $base . '/pago/?p=' . $p['token'];
    $wa = wa_cliente($p['telefono'] ?? '');
    $msg = $p['estado'] === 'pendiente_pago'
      ? "Hola {$p['nombre']}, aquí tienes tu enlace de pago seguro para quedarte con tu página web ($249.99, pago único): $enlacePago"
      : "Hola {$p['nombre']}, aquí puedes seguir tu pedido de página web: $enlacePago";
  ?>
    <article class="box order">
      <div class="order__top">
        <div>
          <p class="meta"><?= pcb_h($p['id']) ?> · <?= pcb_h(date('m/d/Y H:i', strtotime($p['fecha']))) ?></p>
          <h2><?= pcb_h($p['negocio']) ?> <span class="muted">· <?= pcb_h($p['sector']) ?></span></h2>
        </div>
        <p class="state state--<?= pcb_h($p['estado']) ?>"><?= pcb_h(PCB_ESTADOS[$p['estado']] ?? $p['estado']) ?><?= !empty($p['pago']['confirmado']) ? ' · Pagado' : '' ?></p>
      </div>
      <dl class="grid">
        <div><dt>Contacto</dt><dd><?= pcb_h($p['nombre']) ?><br><a href="tel:<?= pcb_h(preg_replace('/[^0-9+]/', '', $p['telefono'])) ?>"><?= pcb_h($p['telefono']) ?></a><br><a href="mailto:<?= pcb_h($p['email']) ?>"><?= pcb_h($p['email']) ?></a></dd></div>
        <div><dt>Servicios</dt><dd><?= nl2br(pcb_h($p['servicios'])) ?></dd></div>
        <div><dt>Preferencias</dt><dd><?= $p['preferencias'] !== '' ? nl2br(pcb_h($p['preferencias'])) : '—' ?></dd></div>
        <?php if (!empty($p['pago']['confirmado'])): ?>
        <div><dt>Pago</dt><dd><?php if (($p['pago']['modo'] ?? '') === 'manual'): ?>Registrado el <?= pcb_h(date('m/d/Y', strtotime($p['pago']['fecha']))) ?> · <?= pcb_h(PCB_METODOS[$p['pago']['metodo'] ?? ''] ?? '') ?><?php else: ?>Confirmado por Stripe el <?= pcb_h(date('m/d/Y H:i', strtotime($p['pago']['fecha']))) ?> (<?= pcb_h($p['pago']['modo'] ?? '') ?>)<?php endif; ?><?= !empty($p['recibo']) ? '<br>Recibo ' . pcb_h($p['recibo']) : '' ?></dd></div>
        <?php endif; ?>
      </dl>
      <form method="post" class="stack order__form">
        <input type="hidden" name="accion" value="pedido"><input type="hidden" name="id" value="<?= pcb_h($p['id']) ?>"><input type="hidden" name="csrf" value="<?= pcb_h(csrf()) ?>">
        <div class="cols">
          <label>Estado
            <select name="estado"><?php foreach (PCB_ESTADOS as $k => $lbl): ?><option value="<?= $k ?>" <?= $k === $p['estado'] ? 'selected' : '' ?>><?= pcb_h($lbl) ?></option><?php endforeach; ?></select>
          </label>
          <label>Enlace de la página de prueba
            <input name="prueba_url" type="url" value="<?= pcb_h($p['prueba_url']) ?>" placeholder="https://…"></label>
        </div>
        <label>Notas internas<textarea name="notas" rows="2"><?= pcb_h($p['notas']) ?></textarea></label>
        <?php if ($p['estado'] === 'en_prueba' && !empty($p['prueba_inicio'])): ?>
          <p class="muted small">Prueba iniciada el <?= pcb_h(date('m/d/Y', strtotime($p['prueba_inicio']))) ?> · termina el <?= pcb_h(date('m/d/Y', strtotime($p['prueba_inicio'] . ' +' . PCB_DIAS_PRUEBA . ' days'))) ?>.</p>
        <?php endif; ?>
        <div class="row">
          <button class="btn">Guardar</button>
          <button type="button" class="btn btn--line" data-copy="<?= pcb_h($enlacePago) ?>"><?= $p['estado'] === 'pendiente_pago' ? 'Copiar enlace de pago' : 'Copiar enlace del cliente' ?></button>
          <?php if ($wa): ?><a class="btn btn--line" target="_blank" rel="noopener" href="https://wa.me/<?= $wa ?>?text=<?= rawurlencode($msg) ?>">Enviar por WhatsApp</a><?php endif; ?>
          <?php if (!empty($p['recibo'])): ?><a class="btn btn--line" href="?v=recibos#<?= pcb_h($p['recibo']) ?>">Ver recibo</a>
          <?php elseif (!empty($p['pago']['confirmado']) || in_array($p['estado'], ['pendiente_pago', 'entregado'], true)): ?><a class="btn btn--line" href="?v=recibos&amp;pedido=<?= rawurlencode($p['id']) ?>">Crear recibo</a><?php endif; ?>
        </div>
        <?php if ($p['estado'] !== 'pendiente_pago' && empty($p['pago']['confirmado'])): ?>
          <p class="muted small">El cliente solo podrá pagar cuando pongas el estado en «Pendiente de pago».</p>
        <?php endif; ?>
      </form>
      <details class="hist"><summary>Historial</summary><ul><?php foreach (array_reverse($p['historial'] ?? []) as $h): ?><li><span class="muted"><?= pcb_h(date('m/d/Y H:i', strtotime($h['fecha']))) ?></span> <?= pcb_h($h['texto']) ?></li><?php endforeach; ?></ul></details>
    </article>
  <?php endforeach; ?>
<?php endif; ?>
</main>
<script>
(function () {
  var f = document.querySelector('[data-rc]'); if (!f) return;
  var pr = f.querySelector('[data-rc-precio]'), pc = f.querySelector('[data-rc-pct]'), out = f.querySelector('[data-rc-total]');
  function num(v) { v = parseFloat(String(v).replace(/[$,\s]/g, '').replace(',', '.')); return isFinite(v) ? v : 0; }
  function calc() {
    var p = num(pr.value), t = Math.round(p * num(pc.value)) / 100, tot = p + t;
    out.textContent = '$' + tot.toFixed(2) + (t > 0 ? '  (incluye $' + t.toFixed(2) + ' de impuesto)' : '');
  }
  pr.addEventListener('input', calc); pc.addEventListener('input', calc); calc();
})();
document.querySelectorAll('[data-copy]').forEach(function (b) {
  b.addEventListener('click', function () {
    var t = b.getAttribute('data-copy'), old = b.textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () {
      b.textContent = '¡Copiado!'; setTimeout(function () { b.textContent = old; }, 1600);
    }).catch(function () { window.prompt('Copia el enlace:', t); });
  });
});
</script>
</body>
</html>
