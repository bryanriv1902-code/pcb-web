# PCB Soluciones Digitales — web oficial

## Qué hay en la carpeta
- `index.html`, `styles.css`, `main.js` — la página.
- `lib/manifest.js` — **el único archivo que hay que tocar** para poner los 4 videos, el WhatsApp y el correo.
- `assets/videos/` — aquí van los videos de los proyectos.
- `admin/` — panel de pedidos (estados, enlaces de pago, ajustes, descarga para Excel).
- `pago/` — página donde el cliente ve su pedido y paga (solo cuando está «Pendiente de pago»).
- `api/` — recepción de formularios y confirmación de pagos de Stripe.

## Poner los videos
1. Copia los MP4 en `assets/videos/` (ver `assets/videos/LEEME.txt`).
2. En `lib/manifest.js`, rellena `titulo`, `sector`, `vista` y `completo` de cada proyecto.
3. Si grabas en computadora en lugar del celular, cambia `formatoVideo` a `"16/10"`.

## Primer acceso al panel
Abre `/admin/`, escribe el código de configuración y crea tu contraseña. El código sirve una sola vez.

## Activar los pagos (Stripe)
1. En Stripe: Desarrolladores → Claves de API → copia la **clave secreta** (sk_live_… o sk_test_… para probar).
2. Stripe → Destinos de eventos → nuevo destino con la dirección que muestra el panel en Ajustes y el evento `checkout.session.completed`. Copia el **secreto de firma** (whsec_…).
3. Pega ambas en Panel → Ajustes.
Sin claves, la web funciona en **modo demostración** y no cobra nada.

## Flujo de un pedido
Solicitado → En desarrollo → En prueba (pon el enlace de la página de prueba; cuentan 3 días) → Pendiente de pago (se activa el botón de pago) → Pago confirmado por Stripe → Entregado.
El pago solo se marca como confirmado cuando Stripe lo avisa con firma verificada.

## Privado
Los pedidos y ajustes se guardan fuera de `public_html` (carpeta `pcb_privado`) y nunca se pueden abrir desde el navegador.
