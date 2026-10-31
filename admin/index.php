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

$vista = (string) ($_GET['v'] ?? 'pedidos');
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
  <link rel="stylesheet" href="../assets/panel.css?v=20261031">
</head>
<body class="panel">
<header class="bar">
  <a href="./"><img src="../assets/img/logo-pcb-220.webp" width="100" height="35" alt="PCB Soluciones Digitales"></a>
  <?php if ($dentro): ?>
  <nav>
    <a href="?v=pedidos" class="<?= $vista === 'pedidos' ? 'on' : '' ?>">Pedidos</a>
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
        <div><dt>Pago</dt><dd>Confirmado por Stripe el <?= pcb_h(date('m/d/Y H:i', strtotime($p['pago']['fecha']))) ?> (<?= pcb_h($p['pago']['modo'] ?? '') ?>)</dd></div>
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
