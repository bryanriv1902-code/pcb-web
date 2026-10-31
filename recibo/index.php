<?php
// Recibo de pago para el cliente: recibo/?r=<código privado del recibo>
// Se ve en pantalla y se descarga en PDF con "Descargar PDF" (imprimir → Guardar como PDF).
require dirname(__DIR__) . '/api/_nucleo.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$r = pcb_recibo_por_token((string) ($_GET['r'] ?? ''));
if (!$r) http_response_code(404);
$anulado = $r && ($r['estado'] ?? '') === 'anulado';
$c = $r['cliente'] ?? [];
$pg = $r['pagina'] ?? [];
$titulo = $r ? 'Recibo ' . $r['numero'] . (($c['negocio'] ?? '') !== '' ? ' - ' . $c['negocio'] : '') : 'Recibo no encontrado';
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= pcb_h($titulo) ?> · PCB Soluciones Digitales</title>
  <link rel="icon" href="../favicon.png" type="image/png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800;900&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap">
  <style>
    :root { --red:#E8122B; --red-d:#B8091F; --wine:#5C0812; --ink:#121214; --muted:#62626B; --line:#E4E4E9; --soft:#F6F6F8; --ok:#12804A; }
    * { box-sizing: border-box; margin: 0; }
    body { font-family: "DM Sans", system-ui, sans-serif; color: var(--ink); background: #E9E9EE; line-height: 1.45; -webkit-font-smoothing: antialiased; font-size: 14px; }
    .tools { position: sticky; top: 0; z-index: 5; display: flex; gap: .6rem; justify-content: center; flex-wrap: wrap; padding: 12px 16px; background: rgba(233,233,238,.92); backdrop-filter: blur(6px); }
    .tools button, .tools a { font: 600 15px "Outfit", sans-serif; border: 0; border-radius: 999px; padding: .75rem 1.4rem; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: .5rem; }
    .tools .pdf { background: var(--red); color: #fff; box-shadow: 0 8px 22px -10px rgba(232,18,43,.8); }
    .tools .pdf:hover { background: var(--red-d); }
    .tools .alt { background: #fff; color: var(--ink); box-shadow: inset 0 0 0 1.5px var(--line); }
    .tools svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }

    .sheet { position: relative; width: min(8.5in, 100% - 24px); margin: 8px auto 40px; background: #fff; border-radius: 6px; overflow: hidden;
      box-shadow: 0 30px 60px -30px rgba(0,0,0,.35); padding: .55in .6in .5in; }
    .sheet::before { /* corte diagonal de la marca */
      content: ""; position: absolute; top: 0; right: 0; width: 210px; height: 210px; pointer-events: none;
      background: linear-gradient(225deg, var(--red) 0 16%, transparent 16% 22%, var(--wine) 22% 26%, transparent 26%); opacity: .95; }
    .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 1.5rem; position: relative; }
    .top img { width: 170px; height: auto; }
    .doc { text-align: right; padding-right: 64px; }
    .doc h1 { font: 900 30px/1 "Outfit", sans-serif; letter-spacing: -.02em; text-transform: uppercase; }
    .doc h1 span { color: var(--red); }
    .doc p { margin-top: .45rem; color: var(--muted); font-size: 13px; }
    .doc p b { color: var(--ink); font-family: "Outfit", sans-serif; font-size: 15px; letter-spacing: .02em; }

    .totals { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
    .stamp { margin-left: 1.2rem; transform: rotate(-10deg); border: 3px solid var(--ok); color: var(--ok); border-radius: 10px;
      padding: .3rem .9rem .25rem; font: 900 26px/1 "Outfit", sans-serif; letter-spacing: .14em; opacity: .85; text-align: center; }
    .stamp small { display: block; font: 700 10px/1.3 "DM Sans", sans-serif; letter-spacing: .08em; }
    .void { position: absolute; inset: 0; display: grid; place-items: center; pointer-events: none; z-index: 3; }
    .void span { transform: rotate(-24deg); font: 900 120px/1 "Outfit", sans-serif; letter-spacing: .1em; color: rgba(160,10,30,.16); border: 10px solid rgba(160,10,30,.16); padding: .1em .3em; border-radius: 20px; }

    .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 1.2rem; margin-top: 1.6rem; }
    .lbl { font: 700 10.5px "Outfit", sans-serif; letter-spacing: .16em; text-transform: uppercase; color: var(--red); margin-bottom: .35rem; }
    .parties .card { background: var(--soft); border-radius: 10px; padding: .85rem 1rem; }
    .parties strong { font: 700 15px "Outfit", sans-serif; display: block; margin-bottom: .15rem; }
    .parties p { color: #35353B; font-size: 13px; }

    .facts { display: grid; grid-template-columns: repeat(3, 1fr); margin-top: 1.1rem; border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
    .facts div { padding: .6rem .85rem; border-right: 1px solid var(--line); border-bottom: 1px solid var(--line); }
    .facts div:nth-child(3n) { border-right: 0; }
    .facts div:nth-last-child(-n+3) { border-bottom: 0; }
    .facts dt { font-size: 10.5px; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); }
    .facts dd { font-weight: 600; margin-top: .1rem; word-break: break-word; }

    table { width: 100%; border-collapse: collapse; margin-top: 1.2rem; }
    th { font: 700 10.5px "Outfit", sans-serif; letter-spacing: .14em; text-transform: uppercase; color: #fff; background: var(--ink); text-align: left; padding: .6rem .85rem; }
    th:last-child, td:last-child { text-align: right; }
    th:first-child { border-radius: 8px 0 0 8px; } th:last-child { border-radius: 0 8px 8px 0; }
    td { padding: .75rem .85rem; border-bottom: 1px solid var(--line); vertical-align: top; }
    td small { display: block; color: var(--muted); font-size: 12px; margin-top: .15rem; }
    .sum { width: 320px; margin-left: auto; margin-top: .4rem; flex: none; }
    .sum div { display: flex; justify-content: space-between; padding: .38rem .85rem; color: #35353B; }
    .sum .total { margin-top: .35rem; background: linear-gradient(100deg, var(--wine), var(--red)); color: #fff; border-radius: 10px; padding: .7rem .95rem; align-items: baseline; }
    .sum .total span { font: 700 12px "Outfit", sans-serif; letter-spacing: .12em; text-transform: uppercase; }
    .sum .total b { font: 900 24px "Outfit", sans-serif; letter-spacing: -.01em; }

    .two { display: grid; grid-template-columns: 1fr 1fr; gap: 1.2rem; margin-top: 1.3rem; }
    .box { border: 1px solid var(--line); border-radius: 10px; padding: .85rem 1rem; }
    .kv { display: grid; grid-template-columns: auto 1fr; gap: .3rem .9rem; font-size: 13px; }
    .kv dt { color: var(--muted); }
    .kv dd { font-weight: 600; text-align: right; }
    .note { margin-top: .55rem; font-size: 11.5px; color: var(--muted); }
    .incl li { list-style: none; position: relative; padding-left: 1.3rem; margin: .22rem 0; font-size: 13px; }
    .incl ul { padding: 0; }
    .incl li::before { content: ""; position: absolute; left: 0; top: .32em; width: 11px; height: 6px; border-left: 2.4px solid var(--red); border-bottom: 2.4px solid var(--red); transform: rotate(-45deg); }

    .terms { margin-top: 1.2rem; font-size: 11.5px; color: var(--muted); border-top: 1px solid var(--line); padding-top: .8rem; display: grid; gap: .25rem; }
    .terms b { color: var(--ink); }
    .foot { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; margin-top: 1.1rem; }
    .thanks { font: 800 17px "Outfit", sans-serif; }
    .thanks span { color: var(--red); }
    .thanks { flex: 1; }
    .verify { font-size: 10.5px; color: var(--muted); text-align: right; word-break: break-all; max-width: 44%; }

    .miss { max-width: 520px; margin: 12vh auto; background: #fff; border-radius: 14px; padding: 2rem; text-align: center; }
    .miss h1 { font: 800 24px "Outfit", sans-serif; margin-bottom: .6rem; }

    @media (max-width: 680px) {
      .sheet { padding: 24px 18px; width: calc(100% - 16px); }
      .sheet::before { width: 120px; height: 120px; }
      .top { flex-direction: column; } .doc { text-align: left; padding-right: 0; }
      .top img { width: 140px; }
      .totals { flex-direction: column-reverse; align-items: stretch; }
      .stamp { align-self: flex-start; margin: .6rem 0 0; transform: rotate(-4deg); }
      .parties, .two { grid-template-columns: 1fr; }
      .facts { grid-template-columns: 1fr 1fr; }
      .facts div { border-right: 1px solid var(--line) !important; border-bottom: 1px solid var(--line) !important; }
      .sum { width: 100%; }
      th:nth-child(2), th:nth-child(3), td:nth-child(2), td:nth-child(3) { display: none; }
      th, td { padding-left: .7rem; padding-right: .7rem; }
      .void span { font-size: 64px; }
      .foot { flex-direction: column; align-items: flex-start; } .verify { text-align: left; max-width: none; }
    }
    @page { size: letter; margin: 0; }
    @media print {
      body { background: #fff; }
      .tools { display: none; }
      .sheet { width: 8.5in; margin: 0; border-radius: 0; box-shadow: none; padding: .45in .55in .35in; zoom: .88; }
      .sheet { width: calc(8.5in / .88); }
      .sheet, .two, .totals, table { break-inside: avoid; }
      * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
  </style>
</head>
<body>
<?php if (!$r): ?>
  <div class="miss">
    <img src="../assets/img/logo-pcb-220.webp" width="120" alt="PCB Soluciones Digitales" style="margin:0 auto 1rem">
    <h1>Recibo no encontrado</h1>
    <p>Revisa que el enlace esté completo o escríbenos por WhatsApp al +1 (401) 616-7029 para enviártelo de nuevo.</p>
  </div>
<?php else: ?>
  <div class="tools">
    <button class="pdf" type="button" onclick="window.print()"><svg viewBox="0 0 24 24"><path d="M12 3v12m0 0l-4.5-4.5M12 15l4.5-4.5M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>Descargar PDF</button>
    <button class="alt" type="button" onclick="window.print()"><svg viewBox="0 0 24 24"><path d="M6 9V3h12v6M6 18H4v-7h16v7h-2M6 14h12v7H6z"/></svg>Imprimir</button>
  </div>

  <article class="sheet">
    <?php if ($anulado): ?><div class="void"><span>ANULADO</span></div><?php endif; ?>

    <header class="top">
      <img src="../assets/img/logo-pcb-440.webp" width="170" height="60" alt="PCB Soluciones Digitales">
      <div class="doc">
        <h1>Recibo <span>de pago</span></h1>
        <p>Nº <b><?= pcb_h($r['numero']) ?></b></p>
        <p>Fecha de emisión: <b><?= pcb_h(pcb_fecha_us($r['fecha_emision'])) ?></b></p>
      </div>
    </header>

    <section class="parties">
      <div>
        <p class="lbl">Emitido por</p>
        <div class="card">
          <strong>PCB Soluciones Digitales</strong>
          <p>Páginas web para pequeños negocios<br>Rhode Island, Estados Unidos<br>+1 (401) 616-7029<br>pcbsolucionesdigitales.com</p>
        </div>
      </div>
      <div>
        <p class="lbl">Cliente</p>
        <div class="card">
          <strong><?= pcb_h($c['nombre']) ?></strong>
          <p>
            <?php if ($c['negocio'] !== ''): ?><?= pcb_h($c['negocio']) ?><br><?php endif; ?>
            <?php if ($c['direccion'] !== ''): ?><?= pcb_h($c['direccion']) ?><br><?php endif; ?>
            <?php if ($c['telefono'] !== ''): ?><?= pcb_h($c['telefono']) ?><br><?php endif; ?>
            <?php if ($c['email'] !== ''): ?><?= pcb_h($c['email']) ?><?php endif; ?>
          </p>
        </div>
      </div>
    </section>

    <dl class="facts">
      <div><dt>Número de pedido</dt><dd><?= pcb_h($r['pedido_id']) ?></dd></div>
      <div><dt>Código de la página</dt><dd><?= pcb_h($pg['codigo']) ?></dd></div>
      <div><dt>Canal de venta</dt><dd><?= pcb_h(PCB_CANALES[$r['canal']] ?? $r['canal']) ?></dd></div>
      <div><dt>Fecha de pago</dt><dd><?= pcb_h(pcb_fecha_us($r['pago']['fecha'])) ?></dd></div>
      <div><dt>Método de pago</dt><dd><?= pcb_h(PCB_METODOS[$r['pago']['metodo']] ?? $r['pago']['metodo']) ?></dd></div>
      <div><dt>Referencia</dt><dd><?= $r['pago']['referencia'] !== '' ? pcb_h($r['pago']['referencia']) : '—' ?></dd></div>
    </dl>

    <table>
      <thead><tr><th>Descripción</th><th>Cant.</th><th>Precio</th><th>Importe</th></tr></thead>
      <tbody>
        <tr>
          <td><b><?= pcb_h($r['concepto']) ?></b><?php if ($pg['dominio'] !== ''): ?><small>Sitio web: <?= pcb_h($pg['dominio']) ?></small><?php endif; ?><small>Pago único, sin mensualidades por el diseño</small></td>
          <td>1</td>
          <td><?= pcb_h(pcb_dinero($r['precio'])) ?></td>
          <td><?= pcb_h(pcb_dinero($r['precio'])) ?></td>
        </tr>
      </tbody>
    </table>
    <div class="totals">
    <?php if (!$anulado): ?><div class="stamp">PAGADO<small><?= pcb_h(pcb_fecha_us($r['pago']['fecha'])) ?></small></div><?php else: ?><span></span><?php endif; ?>
    <div class="sum">
      <div><span>Subtotal</span><span><?= pcb_h(pcb_dinero($r['precio'])) ?></span></div>
      <div><span>Impuesto de venta<?= $r['impuesto_pct'] > 0 ? ' (' . pcb_h(rtrim(rtrim(number_format($r['impuesto_pct'], 3, '.', ''), '0'), '.')) . ' %)' : '' ?></span><span><?= pcb_h(pcb_dinero($r['impuesto'])) ?></span></div>
      <div class="total"><span>Total pagado</span><b><?= pcb_h(pcb_dinero($r['total'])) ?> <small style="font-size:12px;font-weight:700">USD</small></b></div>
    </div>
    </div>

    <section class="two">
      <div class="box">
        <p class="lbl">Tu página web</p>
        <dl class="kv">
          <dt>Dominio</dt><dd><?= $pg['dominio'] !== '' ? pcb_h($pg['dominio']) : '—' ?></dd>
          <dt>Registro del dominio</dt><dd><?= pcb_h(pcb_fecha_us($pg['dominio_registro'])) ?></dd>
          <dt>Próxima renovación</dt><dd><?= pcb_h(pcb_fecha_us($pg['dominio_renovacion'])) ?></dd>
          <dt>Fecha de entrega</dt><dd><?= pcb_h(pcb_fecha_us($pg['fecha_entrega'])) ?></dd>
        </dl>
        <p class="note">El dominio está incluido durante el primer año. Desde el segundo año, su renovación cuesta entre $5 y $20 al año, según el tipo de dominio.</p>
      </div>
      <div class="box incl">
        <p class="lbl">Incluido en tu compra</p>
        <ul><?php foreach ($r['incluye'] as $i): ?><li><?= pcb_h($i) ?></li><?php endforeach; ?></ul>
      </div>
    </section>

    <section class="terms">
      <?php if ($r['notas'] !== ''): ?><p><b>Nota:</b> <?= nl2br(pcb_h($r['notas'])) ?></p><?php endif; ?>
      <?php if ($anulado): ?><p><b>Recibo anulado</b> el <?= pcb_h(pcb_fecha_us($r['anulado']['fecha'] ?? '')) ?>. Motivo: <?= pcb_h($r['anulado']['motivo'] ?? '') ?>. Este documento no es válido como comprobante.</p><?php endif; ?>
      <p>Este recibo confirma el pago recibido por PCB Soluciones Digitales por el servicio descrito. Los cambios solicitados después de la entrega se cotizan aparte.</p>
      <p>Para cualquier duda sobre tu página o este recibo, escríbenos por WhatsApp al +1 (401) 616-7029 indicando tu número de recibo.</p>
    </section>

    <footer class="foot">
      <p class="thanks">Gracias por confiar en <span>PCB Soluciones Digitales</span></p>
      <p class="verify">Recibo verificable en línea:<br><?= pcb_h(pcb_url_base() . '/recibo/?r=' . $r['token']) ?></p>
    </footer>
  </article>
<?php endif; ?>
</body>
</html>
