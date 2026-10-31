<?php
// Página de pago del cliente: pago/?p=<código privado del pedido>
// El botón de pago solo se activa cuando el pedido está "Pendiente de pago".
require dirname(__DIR__) . '/api/_nucleo.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$token = (string) ($_GET['p'] ?? $_POST['p'] ?? '');
$p = pcb_pedido_por_token($token);
$modo = pcb_stripe_modo();
$error = '';

if ($p && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['accion'] ?? '') === 'pagar') {
  if (!pcb_mismo_origen()) { $error = 'No se pudo iniciar el pago. Recarga la página.'; }
  elseif (($p['estado'] ?? '') !== 'pendiente_pago' || !empty($p['pago']['confirmado'])) { $error = 'Este pedido no tiene un pago pendiente.'; }
  elseif ($modo === 'demo') { $error = 'Los pagos todavía no están activados. No se ha realizado ningún cobro.'; }
  elseif (!pcb_limite('pago', 5, 40)) { $error = 'Demasiados intentos seguidos. Espera unos minutos.'; }
  else {
    $base = pcb_url_base();
    $vuelta = $base . '/pago/?p=' . $p['token'];
    $campos = [
      'mode' => 'payment',
      'line_items[0][quantity]' => 1,
      'line_items[0][price_data][currency]' => PCB_MONEDA,
      'line_items[0][price_data][unit_amount]' => PCB_PRECIO_CENTAVOS,
      'line_items[0][price_data][product_data][name]' => PCB_PRODUCTO,
      'line_items[0][price_data][product_data][description]' => 'Página web para ' . $p['negocio'],
      'client_reference_id' => $p['id'],
      'metadata[pedido_id]' => $p['id'],
      'payment_intent_data[metadata][pedido_id]' => $p['id'],
      'success_url' => $vuelta . '&vuelta=1',
      'cancel_url' => $vuelta . '&cancelado=1',
      'locale' => 'es',
    ];
    if (filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL)) $campos['customer_email'] = $p['email'];
    if (!empty(pcb_ajustes()['stripe_tax'])) {
      // Stripe Tax: pide la dirección de facturación y suma el impuesto de venta donde aplique
      $campos['automatic_tax[enabled]'] = 'true';
      $campos['billing_address_collection'] = 'required';
      $campos['line_items[0][price_data][tax_behavior]'] = 'exclusive';
    }
    $r = pcb_stripe_post('checkout/sessions', $campos);
    if ($r['code'] === 200 && !empty($r['body']['url'])) {
      pcb_historial($p, 'El cliente abrió el pago seguro de Stripe');
      pcb_guardar_pedido($p);
      header('Location: ' . $r['body']['url'], true, 303);
      exit;
    }
    $error = 'No se pudo abrir el pago seguro en este momento. Inténtalo de nuevo en unos minutos.';
  }
}

$estado = $p['estado'] ?? '';
$pagado = !empty($p['pago']['confirmado']);
$finPrueba = '';
if ($p && !empty($p['prueba_inicio'])) {
  $finPrueba = date('m/d/Y', strtotime($p['prueba_inicio'] . ' +' . PCB_DIAS_PRUEBA . ' days'));
}
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Tu pedido · PCB Soluciones Digitales</title>
  <link rel="icon" href="../favicon.png" type="image/png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap">
  <link rel="stylesheet" href="../assets/panel.css?v=20261031">
</head>
<body class="pago">
  <header class="bar"><a href="../"><img src="../assets/img/logo-pcb-220.webp" width="100" height="35" alt="PCB Soluciones Digitales"></a></header>
  <main class="pago__main">
<?php if (!$p): ?>
    <section class="box">
      <h1>Enlace no válido</h1>
      <p class="muted">No encontramos este pedido. Revisa que el enlace esté completo o escríbenos para enviártelo de nuevo.</p>
      <a class="btn" href="../#contacto">Contactar</a>
    </section>
<?php else: ?>
    <?php if ($modo === 'demo'): ?>
      <p class="banner banner--demo"><strong>Modo demostración.</strong> Los pagos aún no están activados: no se realizará ningún cobro.</p>
    <?php elseif ($modo === 'prueba'): ?>
      <p class="banner banner--demo"><strong>Modo de prueba de Stripe.</strong> Solo se aceptan tarjetas de prueba: no hay cobros reales.</p>
    <?php endif; ?>

    <section class="box">
      <p class="eyebrow">Pedido <?= pcb_h($p['id']) ?></p>
      <h1>Página web para <?= pcb_h($p['negocio']) ?></h1>
      <p class="state state--<?= pcb_h($estado) ?>"><?= pcb_h(PCB_ESTADOS[$estado] ?? $estado) ?><?= $pagado ? ' · Pago confirmado' : '' ?></p>

      <?php if (!empty($p['prueba_url']) && in_array($estado, ['en_prueba', 'pendiente_pago', 'entregado'], true)): ?>
        <p><a class="btn btn--line" href="<?= pcb_h($p['prueba_url']) ?>" target="_blank" rel="noopener">Ver mi página de prueba</a></p>
        <?php if ($estado === 'en_prueba' && $finPrueba): ?><p class="muted">Tu prueba gratuita termina el <?= pcb_h($finPrueba) ?>.</p><?php endif; ?>
      <?php endif; ?>

      <?php if ($error): ?><p class="alert"><?= pcb_h($error) ?></p><?php endif; ?>
      <?php if (!empty($_GET['cancelado']) && !$pagado): ?><p class="alert">No se completó el pago. Puedes intentarlo de nuevo cuando quieras.</p><?php endif; ?>

      <div class="price"><span>$249.99</span> <em>Pago único</em></div>
      <p class="muted small" style="margin:-.6rem 0 1rem">Más impuesto de venta donde aplique. Si el impuesto aplica en tu estado, se suma al pagar y lo verás antes de confirmar.</p>

      <div class="incl">
        <div>
          <h2>Incluido</h2>
          <ul>
            <li>Diseño de tu página profesional</li>
            <li>Adaptación a celulares y computadoras</li>
            <li>Formulario de contacto y botón de WhatsApp</li>
            <li>Tu dominio propio (tunegocio.com) durante el primer año</li>
            <li>Publicación de la página</li>
            <li>Entrega definitiva con acceso y archivos tras el pago</li>
          </ul>
        </div>
        <div>
          <h2>No incluido</h2>
          <ul class="no">
            <li>Renovación del dominio desde el segundo año: entre $5 y $20 al año</li>
            <li>Cambios después de la entrega: se cotizan aparte</li>
          </ul>
        </div>
      </div>

      <?php if ($pagado): ?>
        <p class="ok">✓ Recibimos tu pago. Te contactaremos para hacer la entrega definitiva de tu página.</p>
      <?php elseif (!empty($_GET['vuelta'])): ?>
        <p class="wait">Estamos confirmando tu pago con Stripe. Esto puede tardar unos segundos; <a href="?p=<?= pcb_h($p['token']) ?>">recarga esta página</a> para ver la confirmación.</p>
      <?php elseif ($estado === 'pendiente_pago'): ?>
        <form method="post">
          <input type="hidden" name="p" value="<?= pcb_h($p['token']) ?>">
          <input type="hidden" name="accion" value="pagar">
          <button class="btn btn--big" type="submit" <?= $modo === 'demo' ? 'disabled aria-disabled="true"' : '' ?>>Pagar $249.99 de forma segura</button>
        </form>
        <p class="muted small">Te llevaremos a la página de pago de Stripe. Nunca pedimos ni guardamos los datos de tu tarjeta.</p>
      <?php else: ?>
        <button class="btn btn--big" type="button" disabled aria-disabled="true">Pago aún no disponible</button>
        <p class="muted small">
          <?php if ($estado === 'solicitado' || $estado === 'en_desarrollo'): ?>Estamos preparando tu página. Primero la pruebas gratis; el pago se activa solo si decides quedarte con ella.
          <?php elseif ($estado === 'en_prueba'): ?>Prueba tu página con calma. Si decides quedarte con ella, avísanos y activaremos tu pago.
          <?php elseif ($estado === 'descartado'): ?>Este pedido está cerrado. No tienes nada pendiente de pago.
          <?php else: ?>No tienes ningún pago pendiente.<?php endif; ?>
        </p>
      <?php endif; ?>
    </section>
<?php endif; ?>
  </main>
</body>
</html>
