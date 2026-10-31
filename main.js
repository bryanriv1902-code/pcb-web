/* PCB Soluciones Digitales — comportamiento (IIFE, sin módulos) */
(function () {
  "use strict";

  var data = window.__BRAND__ || {};
  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
  var reduced = window.matchMedia && matchMedia("(prefers-reduced-motion: reduce)").matches;
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]; }); }
  function safe(fn, name) { try { fn(); } catch (e) { if (window.console) console.warn("[" + name + "]", e); } }

  /* ---------- Formato de los videos ---------- */
  function initRatio() {
    var f = String(data.formatoVideo || "9/16").split("/");
    var w = parseFloat(f[0]), h = parseFloat(f[1]);
    if (w > 0 && h > 0) document.documentElement.style.setProperty("--ar", (w / h).toFixed(4));
  }

  /* ---------- Los 4 espacios de video ---------- */
  var playIcon = '<span class="vcard__play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 4.5v15l13-7.5z"/></svg></span>';

  function mountVideos() {
    var vids = data.videos || [];
    $$(".vcard[data-slot]").forEach(function (card) {
      var i = +card.getAttribute("data-slot");
      var v = vids[i];
      if (!v || !(v.vista || v.completo || v.imagen)) return; // sigue "Próximamente"
      var num = ("0" + (i + 1)).slice(-2);
      var titulo = v.titulo || ("Proyecto " + num);
      var media = $(".vcard__media", card);
      var html = "";
      if (!(v.vista || v.completo)) {
        // proyecto con captura: la imagen recorre la página lentamente
        media.innerHTML = '<img class="vcard__shot" src="' + esc(v.portada || v.imagen) + '" alt="' + esc(titulo) + '" loading="lazy" decoding="async">' +
          '<span class="vcard__play vcard__play--zoom" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 4H4v6M14 20h6v-6M4 4l6 6M20 20l-6-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
        $(".vcard__title", card).innerHTML = esc(titulo) + (v.sector ? '<span class="vcard__sector">' + esc(v.sector) + "</span>" : "");
        card.disabled = false;
        card.setAttribute("aria-label", "Ver imagen: " + titulo);
        card.setAttribute("data-image", v.imagen || v.portada);
        card.setAttribute("data-title", titulo);
        return;
      }
      if (v.portada) html += '<img src="' + esc(v.portada) + '" alt="" loading="lazy" decoding="async">';
      // El video corto se carga solo cuando la tarjeta está a la vista
      html += '<video muted loop playsinline preload="none" data-src="' + esc(v.vista || v.completo) + '"' +
        (v.portada ? ' poster="' + esc(v.portada) + '"' : "") + "></video>";
      media.innerHTML = html + playIcon;
      $(".vcard__title", card).innerHTML = esc(titulo) + (v.sector ? '<span class="vcard__sector">' + esc(v.sector) + "</span>" : "");
      card.disabled = false;
      card.setAttribute("aria-label", "Ver video: " + titulo);
      card.setAttribute("data-full", v.completo || v.vista);
      card.setAttribute("data-title", titulo);
    });
  }

  /* ---------- Celular: cinta continua de proyectos, sin pausas ---------- */
  var marqueeOn = false;
  function initMarquee() {
    var reel = $("[data-reel]");
    if (!reel || reduced || !window.matchMedia) return;
    var mq = matchMedia("(max-width: 959px)");
    var SPEED = 90;                      // píxeles por segundo
    var offset = 0, setW = 0, raf = 0, last = 0, visible = true, held = false, resumeT = 0;
    var dragX = null, dragStart = 0, moved = false;

    function measure() {
      var items = $$(".reel__item:not(.is-clone)", reel);
      var gap = parseFloat(getComputedStyle(reel).columnGap || getComputedStyle(reel).gap) || 16;
      setW = items.reduce(function (w, it) { return w + it.getBoundingClientRect().width + gap; }, 0);
    }
    function apply() {
      if (setW > 0) { offset = ((offset % setW) + setW) % setW; }
      reel.style.setProperty("--mq", (-offset).toFixed(1) + "px");
    }
    function frame(t) {
      var dt = last ? Math.min(0.05, (t - last) / 1000) : 0; last = t;
      var dlg = $("[data-player]");
      if (!held && visible && !(dlg && dlg.open)) { offset += SPEED * dt; apply(); }
      raf = requestAnimationFrame(frame);
    }
    function enable() {
      if (marqueeOn) return; marqueeOn = true;
      // copia de las 4 tarjetas para que la cinta no tenga final
      $$(".reel__item", reel).forEach(function (it) {
        var c = it.cloneNode(true); c.classList.add("is-clone"); c.setAttribute("aria-hidden", "true");
        $$("button, a", c).forEach(function (el) { el.setAttribute("tabindex", "-1"); });
        reel.appendChild(c);
      });
      reel.classList.add("reel--marquee");
      measure(); last = 0; raf = requestAnimationFrame(frame);
    }
    function disable() {
      if (!marqueeOn) return; marqueeOn = false;
      cancelAnimationFrame(raf); raf = 0;
      $$(".reel__item.is-clone", reel).forEach(function (c) { c.remove(); });
      reel.classList.remove("reel--marquee"); reel.style.removeProperty("--mq"); offset = 0;
    }
    // la persona puede arrastrar la cinta con el dedo; al soltar, sigue sola
    reel.addEventListener("touchstart", function (e) {
      if (!marqueeOn) return;
      held = true; clearTimeout(resumeT); dragX = e.touches[0].clientX; dragStart = offset; moved = false;
    }, { passive: true });
    reel.addEventListener("touchmove", function (e) {
      if (!marqueeOn || dragX === null) return;
      var dx = e.touches[0].clientX - dragX; if (Math.abs(dx) > 6) moved = true;
      offset = dragStart - dx; apply();
    }, { passive: true });
    reel.addEventListener("touchend", function () {
      if (!marqueeOn) return;
      dragX = null; resumeT = setTimeout(function () { held = false; }, 1200);
    });
    // si fue un arrastre, no abrir el video
    reel.addEventListener("click", function (e) { if (moved) { e.stopPropagation(); e.preventDefault(); moved = false; } }, true);

    if ("IntersectionObserver" in window) new IntersectionObserver(function (en) { visible = en[0].isIntersecting; }, { threshold: 0 }).observe(reel);
    var rt; window.addEventListener("resize", function () { clearTimeout(rt); rt = setTimeout(function () { if (marqueeOn) { measure(); apply(); } }, 150); });
    function sync() { mq.matches ? enable() : disable(); }
    if (mq.addEventListener) mq.addEventListener("change", sync); else if (mq.addListener) mq.addListener(sync);
    sync();
  }

  function initPreviews() {
    var videos = $$(".vcard video[data-src]");
    if (!videos.length) return;
    function start(v) {
      if (!v.getAttribute("src")) {
        v.preload = reduced ? "metadata" : "auto";
        v.src = v.getAttribute("data-src");
      }
      if (reduced) return; // con movimiento reducido no se reproduce sola
      var p = v.play();
      if (p && p.catch) p.catch(function () {});
    }
    videos.forEach(function (v) {
      v.addEventListener("playing", function () { v.classList.add("is-playing"); });
      v.addEventListener("loadeddata", function () { if (reduced) v.classList.add("is-playing"); });
    });
    if (!("IntersectionObserver" in window)) { videos.forEach(start); return; }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        var v = e.target;
        if (e.isIntersecting) start(v);
        else if (!v.paused) v.pause();
      });
    }, { threshold: 0.05, rootMargin: "120px" });
    videos.forEach(function (v) { io.observe(v); });
  }

  /* ---------- Reproductor del video completo ---------- */
  function initPlayer() {
    var dlg = $("[data-player]"), vid = $("[data-player-video]"), title = $("[data-player-title]");
    var pimg = $("[data-player-img]");
    if (!dlg || !vid) return;
    var lastCard = null;
    function close() {
      try { vid.pause(); } catch (_) {}
      vid.removeAttribute("src"); vid.load();
      if (pimg) { pimg.hidden = true; pimg.removeAttribute("src"); }
      vid.hidden = false;
      if (dlg.open && dlg.close) dlg.close(); else dlg.removeAttribute("open");
      if (lastCard) lastCard.focus();
    }
    $$(".vcard").forEach(function (card) {
      card.addEventListener("click", function () {
        var src = card.getAttribute("data-full"), img = card.getAttribute("data-image");
        if (card.disabled || !(src || img)) return;
        lastCard = card;
        if (img && pimg) {
          vid.hidden = true; pimg.src = img; pimg.alt = card.getAttribute("data-title") || ""; pimg.hidden = false;
          title.textContent = card.getAttribute("data-title") || "";
          if (dlg.showModal) dlg.showModal(); else dlg.setAttribute("open", "");
          return;
        }
        vid.src = src;
        title.textContent = card.getAttribute("data-title") || "";
        if (dlg.showModal) dlg.showModal(); else dlg.setAttribute("open", "");
        var p = vid.play(); if (p && p.catch) p.catch(function () {});
      });
    });
    $("[data-close]", dlg).addEventListener("click", close);
    dlg.addEventListener("cancel", function (e) { e.preventDefault(); close(); });
    dlg.addEventListener("click", function (e) { if (e.target === dlg) close(); });
  }

  /* ---------- Carrusel del celular: avanza solo, se detiene si la persona lo toca ---------- */
  function initDots() {
    var reel = $("[data-reel]"), dots = $$("[data-dots] i");
    if (!reel || !dots.length) return;
    if (marqueeOn) { $("[data-dots]").hidden = true; return; }
    var items = $$(".reel__item", reel);
    var DELAY = 4200, current = 0, timer = 0, resumeT = 0, visible = true, held = false, tick = false;
    reel.style.setProperty("--auto-ms", DELAY + "ms");

    function isCarousel() { return reel.scrollWidth > reel.clientWidth + 8; }   // solo en el celular
    function nearest() {
      var mid = reel.scrollLeft + reel.clientWidth / 2, best = 0, bestD = Infinity;
      items.forEach(function (it, i) {
        var d = Math.abs(it.offsetLeft + it.offsetWidth / 2 - mid);
        if (d < bestD) { bestD = d; best = i; }
      });
      return best;
    }
    function paintDots(i) {
      dots.forEach(function (d, k) { d.classList.toggle("is-on", k === i); });
      // reinicia la barrita de avance del punto activo
      reel.classList.remove("is-auto"); void reel.offsetWidth;
      if (timer) reel.classList.add("is-auto");
    }
    function goTo(i) {
      var it = items[i]; if (!it) return;
      reel.scrollTo({ left: it.offsetLeft - (reel.clientWidth - it.offsetWidth) / 2, behavior: reduced ? "auto" : "smooth" });
    }
    function stop() { clearInterval(timer); timer = 0; reel.classList.remove("is-auto"); }
    function start() {
      stop();
      if (reduced || held || !visible || document.hidden || !isCarousel()) return;
      var dlg = $("[data-player]"); if (dlg && dlg.open) return;
      timer = setInterval(function () { current = (nearest() + 1) % items.length; goTo(current); }, DELAY);
      paintDots(nearest());
    }
    function hold() {            // la persona toca o desliza: pausa y reanuda a los 7 s
      held = true; stop(); clearTimeout(resumeT);
      resumeT = setTimeout(function () { held = false; start(); }, 7000);
    }

    reel.addEventListener("scroll", function () {
      if (tick) return; tick = true;
      requestAnimationFrame(function () {
        tick = false;
        var n = nearest();
        if (n !== current) { current = n; paintDots(n); }
      });
    }, { passive: true });
    ["touchstart", "pointerdown", "wheel"].forEach(function (ev) { reel.addEventListener(ev, hold, { passive: true }); });
    reel.addEventListener("click", hold);
    document.addEventListener("visibilitychange", start);
    var dlg = $("[data-player]"); if (dlg) dlg.addEventListener("close", function () { held = false; start(); });
    if ("IntersectionObserver" in window) {
      new IntersectionObserver(function (en) { visible = en[0].isIntersecting; start(); }, { threshold: 0.3 }).observe(reel);
    }
    var rt; window.addEventListener("resize", function () { clearTimeout(rt); rt = setTimeout(start, 200); });
    setTimeout(start, 1600);   // tras la entrada de la portada
  }

  /* ---------- WhatsApp y correo (solo si están configurados) ---------- */
  function initContact() {
    var num = String(data.whatsapp || "").replace(/\D/g, "");
    if (num.length >= 10) {
      var url = "https://wa.me/" + num + "?text=" + encodeURIComponent(data.whatsappMensaje || "");
      $$("[data-wa]").forEach(function (a) { a.href = url; a.hidden = false; });
    }
    // Botones flotantes: mientras falte el número o el enlace, llevan a la sección de contacto
    var fw = $("[data-float-wa]"), ff = $("[data-float-fb]");
    if (fw) {
      if (num.length >= 10) { fw.href = "https://wa.me/" + num + "?text=" + encodeURIComponent(data.whatsappMensaje || ""); fw.target = "_blank"; }
    }
    if (ff) {
      var fb = String(data.facebook || "").trim();
      if (/^https:\/\/(www\.|m\.)?(facebook|fb)\.com\//i.test(fb)) { ff.href = fb; ff.target = "_blank"; }
    }
    var mail = String(data.email || "").trim();
    var box = $("[data-mail]");
    if (box && /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(mail)) {
      box.innerHTML = 'O escríbenos a <a href="mailto:' + esc(mail) + '">' + esc(mail) + "</a>";
      box.hidden = false;
    }
  }

  /* ---------- Navegación ---------- */
  function initNav() {
    var nav = $("[data-nav]");
    if (!nav) return;
    var on = function () { nav.classList.toggle("is-scrolled", window.scrollY > 8); };
    on(); window.addEventListener("scroll", on, { passive: true });
  }

  /* =============================================================
     EFECTOS PREMIUM
     ============================================================= */
  var fineHover = window.matchMedia && matchMedia("(hover: hover) and (pointer: fine)").matches;

  /* ---------- Cielo de partículas en toda la página ---------- */
  function initParticles() {
    var canvas = $("[data-sky]");
    if (!canvas) return;
    var ctx = canvas.getContext("2d");
    if (!ctx) return;
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    var W = 0, H = 0, parts = [], raf = 0, running = false;
    var mouse = { x: -9999, y: -9999 };
    var lastY = window.scrollY, drift = 0;
    var COLORS = ["255,61,82", "238,20,48", "255,255,255", "154,13,29"];
    var ANG = -65 * Math.PI / 180;                    // trazo diagonal de 115°, como el logo

    function make(fresh) {
      var dash = Math.random() < 0.2;
      var depth = 0.35 + Math.random() * 0.65;         // capas: las lejanas son más pequeñas y lentas
      return {
        x: Math.random() * W, y: fresh ? H + 20 : Math.random() * H, z: depth,
        r: dash ? 0 : (0.6 + Math.random() * 1.9) * depth,
        len: dash ? (7 + Math.random() * 13) * depth : 0,
        vx: ((Math.random() - 0.5) * 0.14 + 0.05) * depth,
        vy: -(0.1 + Math.random() * 0.32) * depth,
        a: 0.2 + Math.random() * 0.45,
        c: COLORS[Math.random() < 0.55 ? 0 : (Math.random() < 0.5 ? 2 : (Math.random() < 0.5 ? 1 : 3))],
        tw: Math.random() * 6.28
      };
    }
    function size() {
      W = window.innerWidth; H = window.innerHeight;
      canvas.width = Math.round(W * dpr); canvas.height = Math.round(H * dpr);
      canvas.style.width = W + "px"; canvas.style.height = H + "px";
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      var n = Math.round((W * H) / (W < 720 ? 9000 : 9500));
      n = Math.max(30, Math.min(n, W < 720 ? 70 : 150));
      parts = []; for (var i = 0; i < n; i++) parts.push(make(false));
    }
    function draw(t) {
      ctx.clearRect(0, 0, W, H);
      var LINK = W < 720 ? 90 : 130, i, j, p, q, dx, dy, d;
      ctx.lineWidth = 0.6;
      for (i = 0; i < parts.length; i++) {
        p = parts[i]; if (!p.r || p.z < 0.55) continue;
        for (j = i + 1; j < parts.length; j++) {
          q = parts[j]; if (!q.r || q.z < 0.55) continue;
          dx = p.x - q.x; dy = p.y - q.y; d = dx * dx + dy * dy;
          if (d < LINK * LINK) {
            ctx.strokeStyle = "rgba(255,61,82," + ((1 - Math.sqrt(d) / LINK) * 0.14).toFixed(3) + ")";
            ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(q.x, q.y); ctx.stroke();
          }
        }
      }
      for (i = 0; i < parts.length; i++) {
        p = parts[i];
        ctx.globalAlpha = p.a * (0.7 + 0.3 * Math.sin((t || 0) / 800 + p.tw));
        if (p.r) {
          ctx.fillStyle = "rgb(" + p.c + ")";
          ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, 6.2832); ctx.fill();
        } else {
          ctx.strokeStyle = "rgb(" + p.c + ")"; ctx.lineWidth = 1.6 * p.z; ctx.lineCap = "round";
          ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(p.x + Math.cos(ANG) * p.len, p.y + Math.sin(ANG) * p.len); ctx.stroke();
          ctx.lineWidth = 0.6;
        }
      }
      ctx.globalAlpha = 1;
    }
    function step(t) {
      // al bajar por la página, las partículas cercanas se desplazan más (profundidad)
      var y = window.scrollY, dy = y - lastY; lastY = y;
      drift += (dy - drift) * 0.2;
      for (var i = 0; i < parts.length; i++) {
        var p = parts[i];
        var mx = p.x - mouse.x, my = p.y - mouse.y, m2 = mx * mx + my * my;
        if (m2 < 10000) { var f = (1 - Math.sqrt(m2) / 100) * 1.2; p.x += mx / 100 * f; p.y += my / 100 * f; }
        p.x += p.vx; p.y += p.vy - drift * 0.35 * p.z;
        if (p.y < -30) { parts[i] = make(true); }
        else if (p.y > H + 40) { p.y = -20; }
        if (p.x < -30) p.x = W + 20; else if (p.x > W + 30) p.x = -20;
      }
      draw(t);
      raf = requestAnimationFrame(step);
    }
    function start() { if (running || reduced) return; running = true; raf = requestAnimationFrame(step); }
    function stop() { running = false; cancelAnimationFrame(raf); }

    size(); draw(0); start();
    var rt; window.addEventListener("resize", function () { clearTimeout(rt); rt = setTimeout(function () { size(); draw(0); }, 150); });
    if (fineHover) {
      window.addEventListener("mousemove", function (e) { mouse.x = e.clientX; mouse.y = e.clientY; }, { passive: true });
      document.addEventListener("mouseleave", function () { mouse.x = mouse.y = -9999; });
    }
    document.addEventListener("visibilitychange", function () { document.hidden ? stop() : start(); });
  }

  /* ---------- Luz que sigue al cursor dentro de las tarjetas ---------- */
  function initSpotlight() {
    if (!fineHover) return;
    $$(".spot").forEach(function (el) {
      el.addEventListener("mousemove", function (e) {
        var r = el.getBoundingClientRect();
        el.style.setProperty("--mx", (e.clientX - r.left) + "px");
        el.style.setProperty("--my", (e.clientY - r.top) + "px");
      });
    });
  }

  /* ---------- Entrada de la portada: palabras que suben ---------- */
  function initHeroIntro() {
    var h1 = $(".hero h1");
    if (!h1 || reduced) return;
    var n = 0;
    (function wrap(node) {
      Array.prototype.slice.call(node.childNodes).forEach(function (ch) {
        if (ch.nodeType === 3) {
          var frag = document.createDocumentFragment();
          ch.textContent.split(/(\s+)/).forEach(function (w) {
            if (!w) return;
            if (/^\s+$/.test(w)) { frag.appendChild(document.createTextNode(w)); return; }
            var o = document.createElement("span"); o.className = "w";
            var i = document.createElement("span"); i.className = "w__i"; i.textContent = w;
            i.style.transitionDelay = (0.08 + n++ * 0.07) + "s";
            o.appendChild(i); frag.appendChild(o);
          });
          node.replaceChild(frag, ch);
        } else if (ch.nodeType === 1) wrap(ch);
      });
    })(h1);
    $$(".reel__item").forEach(function (it, i) { it.style.transitionDelay = (0.35 + i * 0.1) + "s"; });
    document.documentElement.classList.add("intro");
    requestAnimationFrame(function () { requestAnimationFrame(function () { document.documentElement.classList.add("intro-in"); }); });
  }

  /* ---------- Apariciones al bajar ---------- */
  function initReveals() {
    var groups = [
      [".eyebrow", "fx-up"],
      [".order h2, .how h2, .faq h2, .contact h2", "fx-title"],
      [".offer__card, .pay__card", "fx-scale"],
      [".lead, .timeline li, .order .form, .contact .form, .contact__actions", "fx-up"],
      [".how__step", "fx-up", true],
      [".marquee", "fx-up"],
      [".benefits__grid li", "fx-up", true],
      [".faq details", "fx-up", true],
      [".checks li, .steps6 li", "fx-side", true]
    ];
    var els = [];
    groups.forEach(function (g) {
      $$(g[0]).forEach(function (el, i) {
        if (el.closest(".hero")) return;
        el.classList.add("fx", g[1]);
        if (g[2]) el.style.transitionDelay = (Math.min(i, 8) * 0.07) + "s";
        if (g[1] === "fx-title") {
          var inner = document.createElement("span"); inner.className = "fx-title__i";
          while (el.firstChild) inner.appendChild(el.firstChild);
          el.appendChild(inner);
        }
        els.push(el);
      });
    });
    if (reduced || !("IntersectionObserver" in window)) { els.forEach(function (el) { el.classList.add("is-in"); }); return; }
    document.documentElement.classList.add("fx-on");
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add("is-in"); io.unobserve(e.target); } });
    }, { threshold: 0.05, rootMargin: "0px 0px -6% 0px" });
    els.forEach(function (el) { io.observe(el); });
    // Red de seguridad: lo que ya está en pantalla aparece aunque falle el observador
    setTimeout(function () {
      els.forEach(function (el) { if (el.getBoundingClientRect().top < innerHeight) el.classList.add("is-in"); });
    }, 2200);
  }

  /* ---------- Cifras que cuentan al aparecer ---------- */
  function countTo(el, target, decimals, dur, done) {
    var t0 = performance.now();
    (function tick(now) {
      var k = Math.min(1, (now - t0) / dur), e = 1 - Math.pow(1 - k, 4);
      el.textContent = (target * e).toFixed(decimals);
      if (k < 1) requestAnimationFrame(tick); else { el.textContent = target.toFixed(decimals); if (done) done(); }
    })(t0);
  }
  function initFigures() {
    var els = $$("[data-count-to]");
    if (!els.length || reduced || !("IntersectionObserver" in window)) return;
    var io = new IntersectionObserver(function (en) {
      en.forEach(function (e) {
        if (!e.isIntersecting) return; io.unobserve(e.target);
        var raw = e.target.getAttribute("data-count-to");
        countTo(e.target, parseFloat(raw), (raw.split(".")[1] || "").length, 1400);
      });
    }, { threshold: 0.4 });
    els.forEach(function (el) { io.observe(el); });
  }

  /* ---------- El precio protagonista: cuenta, se ilumina y suelta chispas ---------- */
  function initPriceStage() {
    var stage = $("[data-price-stage]");
    if (!stage) return;
    var whole = $("[data-price-whole]", stage), cents = $("[data-price-cents]", stage);
    var canvas = $("[data-sparks]", stage);
    if (reduced || !("IntersectionObserver" in window)) { stage.classList.add("is-lit"); return; }

    // chispas finas que salen del número (puntos y trazos diagonales como el logo)
    var ctx = canvas && canvas.getContext("2d"), sparks = [], raf = 0, visible = false, dpr = Math.min(window.devicePixelRatio || 1, 2), W = 0, H = 0;
    function size() {
      if (!ctx) return;
      var r = canvas.getBoundingClientRect(); W = r.width; H = r.height;
      canvas.width = Math.round(W * dpr); canvas.height = Math.round(H * dpr); ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
    function origin() {   // centro del número, en coordenadas del lienzo
      var a = $(".offer__amount", stage).getBoundingClientRect(), c = canvas.getBoundingClientRect();
      return { x: a.left - c.left + a.width * 0.45, y: a.top - c.top + a.height * 0.55, w: a.width, h: a.height };
    }
    function spawn(n, burst) {
      var o = origin();
      for (var i = 0; i < n; i++) {
        var ang = burst ? Math.random() * 6.283 : (-Math.PI / 2 + (Math.random() - 0.5) * 1.4);
        var sp = burst ? 1.2 + Math.random() * 3.2 : 0.25 + Math.random() * 0.6;
        sparks.push({
          x: o.x + (Math.random() - 0.5) * o.w * (burst ? 0.6 : 0.9), y: o.y + (Math.random() - 0.5) * o.h * 0.6,
          vx: Math.cos(ang) * sp, vy: Math.sin(ang) * sp, life: 0, max: burst ? 50 + Math.random() * 40 : 90 + Math.random() * 80,
          dash: Math.random() < 0.3, r: 0.8 + Math.random() * 1.6, c: Math.random() < 0.7 ? "255,61,82" : "255,255,255"
        });
      }
    }
    function frame() {
      ctx.clearRect(0, 0, W, H);
      if (visible && Math.random() < 0.18) spawn(1, false);
      for (var i = sparks.length - 1; i >= 0; i--) {
        var p = sparks[i]; p.life++; p.x += p.vx; p.y += p.vy; p.vx *= 0.985; p.vy *= 0.985; p.vy -= 0.006;
        var k = p.life / p.max; if (k >= 1) { sparks.splice(i, 1); continue; }
        ctx.globalAlpha = (k < 0.2 ? k / 0.2 : 1 - (k - 0.2) / 0.8) * 0.9;
        if (p.dash) {
          ctx.strokeStyle = "rgb(" + p.c + ")"; ctx.lineWidth = 1.4; ctx.lineCap = "round";
          ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(p.x + 4.2, p.y - 9); ctx.stroke();
        } else {
          ctx.fillStyle = "rgb(" + p.c + ")"; ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, 6.283); ctx.fill();
        }
      }
      ctx.globalAlpha = 1;
      if (visible || sparks.length) raf = requestAnimationFrame(frame); else raf = 0;
    }
    function run() { if (ctx && !raf) raf = requestAnimationFrame(frame); }

    var played = false;
    if (ctx) { size(); window.addEventListener("resize", size); }
    new IntersectionObserver(function (en) {
      visible = en[0].isIntersecting;
      if (visible) run();
      if (visible && !played) {
        played = true;
        whole.textContent = "0"; cents.textContent = "00";
        stage.classList.add("is-lit");
        countTo(whole, 259, 0, 1300, function () {
          if (ctx) spawn(46, true);
        });
        setTimeout(function () {
          var t0 = performance.now();
          (function tick(now) {
            var k = Math.min(1, (now - t0) / 700), v = Math.round(99 * (1 - Math.pow(1 - k, 3)));
            cents.textContent = (v < 10 ? "0" : "") + v;
            if (k < 1) requestAnimationFrame(tick);
          })(t0);
        }, 600);
      }
    }, { threshold: 0.35 }).observe(stage);
    // red de seguridad: si nunca se ve, el precio queda escrito completo
    setTimeout(function () { if (!played) stage.classList.add("is-lit"); }, 4000);
  }

  /* ---------- Luz roja que sigue al cursor ---------- */
  function initLight() {
    var el = $("[data-light]");
    if (!el || !fineHover || reduced) return;
    var tx = innerWidth * 0.7, ty = innerHeight * 0.2, x = tx, y = ty, raf = 0;
    function loop() {
      x += (tx - x) * 0.08; y += (ty - y) * 0.08;
      el.style.setProperty("--lx", x.toFixed(1) + "px"); el.style.setProperty("--ly", y.toFixed(1) + "px");
      raf = (Math.abs(tx - x) + Math.abs(ty - y) > 0.5) ? requestAnimationFrame(loop) : 0;
    }
    window.addEventListener("mousemove", function (e) { tx = e.clientX; ty = e.clientY; if (!raf) raf = requestAnimationFrame(loop); }, { passive: true });
  }

  /* ---------- Barra de progreso + fondos que se mueven al bajar ---------- */
  function initScrollFx() {
    var bar = $("[data-progress]");
    var layers = $$("[data-parallax]");
    var how = $(".how__grid");
    var ticking = false;
    function update() {
      ticking = false;
      var y = window.scrollY, vh = innerHeight;
      if (bar) {
        var max = document.documentElement.scrollHeight - vh;
        bar.style.transform = "scaleX(" + (max > 0 ? Math.min(1, y / max) : 0) + ")";
      }
      if (reduced) return;
      layers.forEach(function (el) {
        var r = el.parentElement.getBoundingClientRect();
        if (r.bottom < 0 || r.top > vh) return;
        var sp = parseFloat(el.getAttribute("data-parallax")) || 0.15;
        el.style.transform = "translate3d(0," + (-(r.top) * sp).toFixed(1) + "px,0)";
      });
      if (how) {
        var hr = how.getBoundingClientRect();
        var k = Math.max(0, Math.min(1, (vh * 0.85 - hr.top) / (hr.height + vh * 0.25)));
        how.style.setProperty("--line", k.toFixed(3));
      }
    }
    window.addEventListener("scroll", function () { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });
    window.addEventListener("resize", update);
    update();
  }

  /* ---------- Botones magnéticos ---------- */
  function initMagnetic() {
    if (!fineHover || reduced) return;
    $$(".btn--lg, .nav__cta").forEach(function (b) {
      b.addEventListener("mousemove", function (e) {
        var r = b.getBoundingClientRect();
        b.style.transform = "translate(" + ((e.clientX - r.left - r.width / 2) * 0.14).toFixed(1) + "px," + ((e.clientY - r.top - r.height / 2) * 0.22).toFixed(1) + "px)";
      });
      b.addEventListener("mouseleave", function () { b.style.transform = ""; });
    });
  }

  /* ---------- Pagar mi página: número de pedido + correo → pago seguro ---------- */
  function initPayLookup() {
    var form = $("form[data-pay]");
    if (!form) return;
    var msg = $(".form__msg", form), btn = $("button[type=submit]", form);
    var idIn = $("#p-pedido", form), mailIn = $("#p-email", form);
    function setMsg(t, k) { msg.textContent = t; msg.className = "form__msg" + (k ? " " + k : ""); }
    idIn.addEventListener("input", function () { idIn.value = idIn.value.toUpperCase().replace(/\s+/g, ""); });
    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var id = idIn.value.trim(), mail = mailIn.value.trim();
      var okId = /^PCB-\d{8}-[A-Z0-9]{4}$/.test(id), okMail = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(mail);
      idIn.classList.toggle("is-bad", !okId); mailIn.classList.toggle("is-bad", !okMail);
      if (!okId) { setMsg("Escribe tu número de pedido como aparece en tu correo, por ejemplo PCB-20261031-AB12.", "err"); idIn.focus(); return; }
      if (!okMail) { setMsg("Escribe el correo con el que solicitaste tu página.", "err"); mailIn.focus(); return; }
      if (window.__PREVIEW__) { setMsg("Vista previa: en la web publicada, este botón te lleva a tu página de pago seguro.", "ok"); return; }
      btn.disabled = true; setMsg("Buscando tu pedido…");
      fetch(form.getAttribute("action"), {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ pedido: id, email: mail, sitio: (form.querySelector("[name=sitio]") || {}).value || "" })
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (res && res.ok && res.url) {
          setMsg("¡Pedido encontrado! Abriendo tu pago seguro…", "ok");
          window.location.href = res.url;
        } else {
          setMsg(res && res.reason === "rate" ? "Demasiados intentos seguidos. Espera unos minutos."
            : "No encontramos un pedido con ese número y correo. Revisa los datos o escríbenos.", "err");
          btn.disabled = false;
        }
      }).catch(function () { setMsg("No pudimos buscar tu pedido en este momento. Inténtalo de nuevo.", "err"); btn.disabled = false; });
    });
  }

  /* ---------- Logo con vida: luz que lo recorre al hacer scroll ---------- */
  function initBrand() {
    var brand = $("[data-brand]"), img = brand && $("img", brand);
    if (!brand || !img) return;
    function setMask() { var src = img.currentSrc || img.src; if (src) { brand.style.setProperty("--logo", 'url("' + src + '")'); brand.classList.add("has-mask"); } }
    if (img.complete) setMask(); else img.addEventListener("load", setMask);
    if (reduced) return;
    var lastY = window.scrollY, energy = 0, raf = 0, pos = 130;
    function loop() {
      energy *= 0.92;                                   // la luz se apaga suavemente al parar
      brand.style.setProperty("--sheen", pos.toFixed(1) + "%");
      brand.style.setProperty("--sheen-o", Math.min(1, energy).toFixed(3));
      brand.style.setProperty("--glow", Math.min(1, energy).toFixed(3));
      raf = energy > 0.01 ? requestAnimationFrame(loop) : 0;
      if (!raf) { brand.style.setProperty("--sheen-o", "0"); brand.style.setProperty("--glow", "0"); }
    }
    window.addEventListener("scroll", function () {
      var y = window.scrollY, dy = y - lastY; lastY = y;
      // la banda de luz avanza con lo que bajas (o retrocede si subes)
      pos -= dy * 0.12; if (pos < -30) pos = 130; if (pos > 130) pos = -30;
      energy = Math.min(1.2, energy + Math.abs(dy) * 0.012);
      if (!raf) raf = requestAnimationFrame(loop);
    }, { passive: true });
  }

  /* ---------- Formularios (pedido y contacto) ---------- */
  function initForms() {
    var t0 = Date.now();
    $$("form[data-form]").forEach(function (form) {
      var msg = $(".form__msg", form), btn = $("button[type=submit]", form);
      var tIn = form.querySelector("input[name=t]"); if (tIn) tIn.value = String(t0);

      function setMsg(text, kind) { msg.textContent = text; msg.className = "form__msg" + (kind ? " " + kind : ""); }

      form.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var bad = null;
        $$("[required]", form).forEach(function (f) {
          var ok = f.type === "checkbox" ? f.checked : String(f.value).trim() !== "" && (f.type !== "email" || /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(f.value.trim()));
          (f.type === "checkbox" ? f.parentNode : f).classList.toggle("is-bad", !ok);
          if (!ok && !bad) bad = f;
        });
        if (bad) { setMsg("Revisa los campos marcados.", "err"); bad.focus(); return; }

        var payload = {};
        $$("input, select, textarea", form).forEach(function (f) {
          if (!f.name) return;
          payload[f.name] = f.type === "checkbox" ? (f.checked ? "1" : "") : f.value;
        });
        btn.disabled = true; setMsg("Enviando…");

        fetch(form.getAttribute("action"), {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); }).then(function (res) {
          if (res && res.ok) {
            form.reset(); if (tIn) tIn.value = String(t0);
            setMsg(form.getAttribute("data-form") === "pedido"
              ? "¡Recibimos tu solicitud! Tu número de pedido es " + (res.id || "") + ". Guárdalo: con él podrás pagar tu página cuando te convenza. Te lo enviamos también por correo."
              : "¡Mensaje recibido! Te responderemos pronto.", "ok");
          } else {
            var r2 = res && res.reason;
            setMsg(r2 === "rate" ? "Has enviado varias solicitudes seguidas. Inténtalo de nuevo en unos minutos."
              : r2 === "fast" ? "Revisa tus datos y vuelve a enviar en unos segundos."
              : "No pudimos enviar tu solicitud. Revisa los datos e inténtalo de nuevo.", "err");
          }
        }).catch(function () {
          var wa = String(data.whatsapp || "").replace(/\D/g, "");
          setMsg("No pudimos enviar tu solicitud en este momento." + (wa.length >= 10 ? " Escríbenos por WhatsApp." : " Inténtalo de nuevo más tarde."), "err");
        }).then(function () { btn.disabled = false; });
      });
    });
  }

  function boot() {
    document.documentElement.classList.remove("no-js");
    safe(initRatio, "initRatio");
    safe(mountVideos, "mountVideos");
    safe(initMarquee, "initMarquee");
    safe(initPreviews, "initPreviews");
    safe(initPlayer, "initPlayer");
    safe(initDots, "initDots");
    safe(initContact, "initContact");
    safe(initNav, "initNav");
    safe(initHeroIntro, "initHeroIntro");
    safe(initReveals, "initReveals");
    safe(initParticles, "initParticles");
    safe(initFigures, "initFigures");
    safe(initPriceStage, "initPriceStage");
    safe(initLight, "initLight");
    safe(initScrollFx, "initScrollFx");
    safe(initSpotlight, "initSpotlight");
    safe(initMagnetic, "initMagnetic");
    safe(initBrand, "initBrand");
    safe(initForms, "initForms");
    safe(initPayLookup, "initPayLookup");
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot); else boot();
})();
