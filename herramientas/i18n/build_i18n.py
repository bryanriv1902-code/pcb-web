#!/usr/bin/env python3
"""Marca los textos de index.html con data-i18n y genera lib/i18n.js (ES → EN, PT)."""
import re, sys, json
from bs4 import BeautifulSoup, NavigableString, Tag, Comment
import os; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from dict import D

repo = sys.argv[1]
html_path = repo + '/index.html'
src = open(html_path, encoding='utf-8').read()

def canon(h):
    # atributos de cada etiqueta en orden alfabético (el navegador y el analizador pueden ordenarlos distinto)
    def f(m):
        attrs = re.findall(r'\s+([^\s=>]+(?:="[^"]*")?)', m.group(2))
        return '<' + m.group(1) + ''.join(' ' + a for a in sorted(attrs)) + '>'
    return re.sub(r'<([a-zA-Z0-9]+)((?:\s+[^\s=>]+(?:="[^"]*")?)*)\s*>', f, h)
def norm(s): return canon(re.sub(r'\s+', ' ', s).strip())

DICT = {}
for es, en, pt in D:
    DICT[norm(es)] = [en, pt]

# ---------- localizar unidades de texto ----------
soup = BeautifulSoup(src, 'html.parser')
INLINE = {'a','b','strong','em','i','span','br','sup','small','code','u'}
SKIP = {'script','style','svg','head','noscript','template'}
BLOCKISH = INLINE | {'h1','h2','h3','h4','p','li','summary','button','label','option','dt','dd','legend','div','td','th','figcaption'}
def inline_only(t): return all(not (isinstance(d, Tag) and d.name not in INLINE) for d in t.descendants)
def direct_text(t): return any(isinstance(c, NavigableString) and not isinstance(c, Comment) and re.search(r'[A-Za-zÁÉÍÓÚáéíóúñÑ]', c) for c in t.children)
units = []
def walk(t):
    for ch in t.children:
        if not isinstance(ch, Tag) or ch.name in SKIP: continue
        if ch.find_parent(class_='hp') or 'hp' in (ch.get('class') or []): continue   # campo trampa anti-spam
        if ch.name in BLOCKISH and inline_only(ch) and direct_text(ch):
            units.append(ch); continue
        walk(ch)
walk(soup.body)

missing = []
lines = src.split('\n')
offsets = [0]
for ln in lines: offsets.append(offsets[-1] + len(ln) + 1)
inserts = []
for u in units:
    key = norm(u.decode_contents())
    if key not in DICT: missing.append(key); continue
    if u.has_attr('data-i18n'): continue
    off = offsets[u.sourceline - 1] + u.sourcepos
    assert src[off:off + 1 + len(u.name)] == '<' + u.name, (u.name, src[off:off + 20])
    inserts.append(off + 1 + len(u.name))
for o in sorted(inserts, reverse=True):
    src = src[:o] + ' data-i18n' + src[o:]

attrs_missing = []
for t in soup.find_all(True):
    for a in ('placeholder', 'aria-label', 'alt'):
        if t.has_attr(a) and re.search(r'[A-Za-zÁÉÍÓÚáéíóúñ]', t[a]):
            v = norm(t[a])
            if v not in DICT and v not in ('PCB Soluciones Digitales', 'PCB-20261031-AB12', 'WhatsApp', 'Facebook'):
                attrs_missing.append(v)
for k in ('title', 'description'):
    pass

open(html_path, 'w', encoding='utf-8').write(src)
print('unidades marcadas:', len(inserts), '| sin traducción:', len(missing), '| atributos sin traducción:', len(set(attrs_missing)))
for m in missing: print('  FALTA:', m[:110])
for m in sorted(set(attrs_missing)): print('  FALTA ATRIBUTO:', m)

# ---------- generar lib/i18n.js ----------
runtime = r'''/* PCB Soluciones Digitales — idiomas (español, inglés, portugués).
   El español es el texto original de la página. Los demás idiomas salen del diccionario de abajo.
   Para elegir idioma: el selector de banderas del menú o la dirección ?lang=en / ?lang=pt / ?lang=es */
(function () {
  "use strict";
  var LANGS = ["es", "en", "pt"];
  var NAMES = { es: "Español", en: "English", pt: "Português" };
  var FLAGS = {
    es: '<svg viewBox="0 0 30 20" aria-hidden="true"><rect width="10" height="20" fill="#006847"/><rect x="10" width="10" height="20" fill="#fff"/><rect x="20" width="10" height="20" fill="#CE1126"/><circle cx="15" cy="10" r="2.6" fill="#8C6239"/><path d="M12.6 11.3q2.4 2 4.8 0" stroke="#006847" stroke-width=".9" fill="none"/></svg>',
    en: '<svg viewBox="0 0 30 20" aria-hidden="true"><rect width="30" height="20" fill="#B22234"/><path d="M0 2.31h30M0 5.38h30M0 8.46h30M0 11.54h30M0 14.62h30M0 17.69h30" stroke="#fff" stroke-width="1.54"/><rect width="13" height="10.77" fill="#3C3B6E"/><g fill="#fff"><circle cx="2.2" cy="2" r=".6"/><circle cx="5.4" cy="2" r=".6"/><circle cx="8.6" cy="2" r=".6"/><circle cx="11" cy="2" r=".6"/><circle cx="3.8" cy="4.1" r=".6"/><circle cx="7" cy="4.1" r=".6"/><circle cx="10.2" cy="4.1" r=".6"/><circle cx="2.2" cy="6.2" r=".6"/><circle cx="5.4" cy="6.2" r=".6"/><circle cx="8.6" cy="6.2" r=".6"/><circle cx="11" cy="6.2" r=".6"/><circle cx="3.8" cy="8.4" r=".6"/><circle cx="7" cy="8.4" r=".6"/><circle cx="10.2" cy="8.4" r=".6"/></g></svg>',
    pt: '<svg viewBox="0 0 30 20" aria-hidden="true"><rect width="30" height="20" fill="#DA291C"/><rect width="12" height="20" fill="#046A38"/><circle cx="12" cy="10" r="4.4" fill="none" stroke="#FFE900" stroke-width="1.3"/><path d="M9.9 7.6h4.2v3.6q0 2.1-2.1 2.6-2.1-.5-2.1-2.6z" fill="#fff" stroke="#DA291C" stroke-width=".9"/><path d="M11.1 9h1.8v2.5q0 .8-.9 1.1-.9-.3-.9-1.1z" fill="#003399"/></svg>'
  };
  var DICT = __DICT__;

  function canon(h) {
    return h.replace(/<([a-zA-Z0-9]+)((?:\s+[^\s=>]+(?:="[^"]*")?)*)\s*>/g, function (m, tag, at) {
      var list = at.match(/[^\s=>]+(?:="[^"]*")?/g) || [];
      return "<" + tag + (list.length ? " " + list.sort().join(" ") : "") + ">";
    });
  }
  function norm(s) { return canon(String(s).replace(/\s+/g, " ").trim()); }
  function qsLang() { var m = location.search.match(/[?&]lang=(es|en|pt)(?:&|$)/); return m ? m[1] : ""; }
  var lang = qsLang(), saved = "";
  try { saved = localStorage.getItem("pcb_lang") || ""; if (lang) localStorage.setItem("pcb_lang", lang); } catch (e) {}
  if (!lang) lang = saved;
  if (LANGS.indexOf(lang) < 0) lang = "es";
  var col = lang === "en" ? 0 : 1;

  function t(s) {
    if (lang === "es" || s == null) return s;
    var e = DICT[norm(s)];
    if (!e) return s;
    var out = e[col], str = String(s);
    if (/^\s/.test(str) && !/^\s/.test(out)) out = " " + out;
    if (/\s$/.test(str) && !/\s$/.test(out)) out = out + " ";
    return out;
  }
  window.PCB_I18N = { lang: lang, t: t };
  document.documentElement.lang = lang;

  function apply() {
    if (lang === "es") return;
    var els = document.querySelectorAll("[data-i18n]");
    for (var i = 0; i < els.length; i++) {
      var e = DICT[norm(els[i].innerHTML)];
      if (e) els[i].innerHTML = e[col];
    }
    var at = document.querySelectorAll("[placeholder],[aria-label],[alt]");
    ["placeholder", "aria-label", "alt"].forEach(function (a) {
      for (var j = 0; j < at.length; j++) {
        var v = at[j].getAttribute(a);
        if (v && DICT[norm(v)]) at[j].setAttribute(a, DICT[norm(v)][col]);
      }
    });
    var tt = DICT[norm(document.title)];
    if (tt) document.title = tt[col];
    var md = document.querySelector('meta[name="description"]');
    if (md && DICT[norm(md.content)]) md.content = DICT[norm(md.content)][col];
  }

  function go(l) {
    if (l === lang) return;
    try { localStorage.setItem("pcb_lang", l); sessionStorage.setItem("pcb_scroll", String(window.scrollY || 0)); } catch (e) {}
    var u = new URL(location.href);
    if (l === "es") u.searchParams.delete("lang"); else u.searchParams.set("lang", l);
    location.href = u.toString();
  }

  function switcher() {
    var box = document.querySelector("[data-lang]");
    if (!box) return;
    var label = t("Idioma");
    var h = '<button class="lang__btn" type="button" aria-haspopup="true" aria-expanded="false" aria-label="' + label + ': ' + NAMES[lang] + '">' +
      '<span class="flag">' + FLAGS[lang] + '</span><span class="lang__code">' + lang.toUpperCase() + '</span>' +
      '<svg class="lang__chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button><ul class="lang__menu" hidden>';
    LANGS.forEach(function (l) {
      h += '<li><button type="button" data-set-lang="' + l + '" lang="' + l + '"' + (l === lang ? ' aria-current="true"' : "") + '>' +
        '<span class="flag">' + FLAGS[l] + '</span><span>' + NAMES[l] + '</span></button></li>';
    });
    box.innerHTML = h + "</ul>";
    var btn = box.querySelector(".lang__btn"), menu = box.querySelector(".lang__menu");
    function open(v) { menu.hidden = !v; btn.setAttribute("aria-expanded", v ? "true" : "false"); box.classList.toggle("is-open", v); }
    btn.addEventListener("click", function (ev) { ev.stopPropagation(); open(menu.hidden); });
    box.addEventListener("click", function (ev) { var b = ev.target.closest("[data-set-lang]"); if (b) go(b.getAttribute("data-set-lang")); });
    document.addEventListener("click", function () { open(false); });
    document.addEventListener("keydown", function (ev) { if (ev.key === "Escape") open(false); });
  }

  try { apply(); } catch (e) { if (window.console) console.warn("[i18n]", e); }
  try { switcher(); } catch (e) { if (window.console) console.warn("[i18n switcher]", e); }
  document.documentElement.classList.remove("i18n-wait");

  // Al cambiar de idioma, volver al mismo punto de la página
  var y = null;
  try { y = sessionStorage.getItem("pcb_scroll"); sessionStorage.removeItem("pcb_scroll"); } catch (e) {}
  if (y !== null) window.addEventListener("load", function () { window.scrollTo(0, +y || 0); });
})();
'''
open(repo + '/lib/i18n.js', 'w', encoding='utf-8').write(runtime.replace('__DICT__', json.dumps(DICT, ensure_ascii=False, separators=(',', ':'))))
print('lib/i18n.js:', len(open(repo + '/lib/i18n.js', encoding='utf-8').read()) // 1024, 'KB')
