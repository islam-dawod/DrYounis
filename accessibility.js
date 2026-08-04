/* Younis Clinic — accessibility widget (self-contained, site-wide)
   Font size, contrast (light/dark/grayscale), highlight links,
   readable font, stop animations, keyboard + screen-reader friendly. */
(function () {
  "use strict";
  var KEY = "yc_a11y";
  var ZOOMS = [0.9, 1, 1.15, 1.3, 1.5];
  var state = { zoom: 1, contrast: "normal", links: false, readable: false, noanim: false };
  try { var saved = JSON.parse(localStorage.getItem(KEY)); if (saved) state = Object.assign(state, saved); } catch (e) {}

  /* ---------- styles ---------- */
  var css =
    'html.a11y-dark{filter:invert(1) hue-rotate(180deg)}' +
    'html.a11y-dark img,html.a11y-dark video,html.a11y-dark iframe,html.a11y-dark .a11y-fab,html.a11y-dark .a11y-panel{filter:invert(1) hue-rotate(180deg)}' +
    'html.a11y-gray{filter:grayscale(1)}' +
    'html.a11y-links a{text-decoration:underline !important;text-underline-offset:3px;outline:2px dashed currentColor;outline-offset:2px}' +
    'html.a11y-readable *{font-family:Arial,"Segoe UI",sans-serif !important;letter-spacing:.01em;line-height:1.7 !important}' +
    'html.a11y-noanim *,html.a11y-noanim *::before,html.a11y-noanim *::after{animation:none !important;transition:none !important;scroll-behavior:auto !important}' +
    '.a11y-fab{position:fixed;inset-inline-start:16px;bottom:16px;z-index:95;width:52px;height:52px;border-radius:50%;background:#0B6F70;color:#fff;border:0;cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,.28);display:flex;align-items:center;justify-content:center}' +
    '.a11y-fab:focus-visible{outline:3px solid #fff;outline-offset:3px}' +
    '.a11y-fab svg{width:28px;height:28px}' +
    '.a11y-panel{position:fixed;inset-inline-start:16px;bottom:78px;z-index:96;width:min(320px,calc(100vw - 32px));background:#fff;color:#231F20;border:1px solid #dfe8e8;border-radius:16px;box-shadow:0 16px 48px rgba(0,0,0,.22);padding:18px 16px 16px;display:none;font-family:Arial,sans-serif;direction:rtl}' +
    '.a11y-panel.open{display:block}' +
    '.a11y-panel h2{margin:0 0 12px;font-size:1.05rem;color:#231F20}' +
    '.a11y-grp{margin:0 0 12px}' +
    '.a11y-grp>span{display:block;font-size:.82rem;color:#5f5b5c;margin-bottom:6px;font-weight:700}' +
    '.a11y-row{display:flex;gap:8px;flex-wrap:wrap}' +
    '.a11y-panel button.opt{flex:1;min-width:80px;min-height:44px;border:1px solid #dfe8e8;border-radius:10px;background:#f7f7f5;color:#231F20;font-weight:700;font-size:.88rem;cursor:pointer;padding:8px}' +
    '.a11y-panel button.opt[aria-pressed="true"]{background:#0B6F70;color:#fff;border-color:#0B6F70}' +
    '.a11y-panel button.opt:focus-visible{outline:3px solid #0B6F70;outline-offset:2px}' +
    '.a11y-panel .a11y-reset{width:100%;min-height:44px;border:0;border-radius:10px;background:#231F20;color:#fff;font-weight:700;cursor:pointer;margin-top:4px}' +
    '.a11y-panel .a11y-close{position:absolute;top:8px;inset-inline-end:8px;background:none;border:0;font-size:1.5rem;cursor:pointer;color:#231F20;line-height:1;width:38px;height:38px}';
  var st = document.createElement("style"); st.textContent = css; document.head.appendChild(st);

  /* ---------- apply ---------- */
  function apply() {
    var h = document.documentElement;
    try { document.body.style.zoom = state.zoom; } catch (e) {}
    h.classList.toggle("a11y-dark", state.contrast === "dark");
    h.classList.toggle("a11y-gray", state.contrast === "gray");
    h.classList.toggle("a11y-links", state.links);
    h.classList.toggle("a11y-readable", state.readable);
    h.classList.toggle("a11y-noanim", state.noanim);
    try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {}
    sync();
  }

  /* ---------- build UI ---------- */
  var A11Y_ICON = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="4" r="2"/><path d="M21 9c0 .6-.4 1-1 1h-4v11c0 .6-.4 1-1 1s-1-.4-1-1v-5h-2v5c0 .6-.4 1-1 1s-1-.4-1-1V10H4c-.6 0-1-.4-1-1s.4-1 1-1h16c.6 0 1 .4 1 1z"/></svg>';
  var fab = document.createElement("button");
  fab.className = "a11y-fab"; fab.type = "button";
  fab.setAttribute("aria-label", "אפשרויות נגישות");
  fab.setAttribute("aria-expanded", "false");
  fab.innerHTML = A11Y_ICON;

  var panel = document.createElement("div");
  panel.className = "a11y-panel"; panel.setAttribute("role", "dialog");
  panel.setAttribute("aria-label", "אפשרויות נגישות");
  panel.innerHTML =
    '<button class="a11y-close" type="button" aria-label="סגירה">×</button>' +
    '<h2>הגדרות נגישות</h2>' +
    '<div class="a11y-grp"><span>גודל טקסט</span><div class="a11y-row">' +
      '<button class="opt" data-act="zoom-out" aria-label="הקטנת טקסט">א−</button>' +
      '<button class="opt" data-act="zoom-in" aria-label="הגדלת טקסט">א+</button>' +
    '</div></div>' +
    '<div class="a11y-grp"><span>ניגודיות</span><div class="a11y-row">' +
      '<button class="opt" data-contrast="normal">רגיל</button>' +
      '<button class="opt" data-contrast="dark">כהה</button>' +
      '<button class="opt" data-contrast="gray">שחור-לבן</button>' +
    '</div></div>' +
    '<div class="a11y-grp"><span>אפשרויות נוספות</span><div class="a11y-row">' +
      '<button class="opt" data-toggle="links">הדגשת קישורים</button>' +
      '<button class="opt" data-toggle="readable">גופן קריא</button>' +
      '<button class="opt" data-toggle="noanim">עצירת אנימציות</button>' +
    '</div></div>' +
    '<button class="a11y-reset" type="button">איפוס הגדרות</button>';

  function sync() {
    panel.querySelectorAll("[data-contrast]").forEach(function (b) {
      b.setAttribute("aria-pressed", String(b.getAttribute("data-contrast") === state.contrast));
    });
    ["links", "readable", "noanim"].forEach(function (k) {
      var b = panel.querySelector('[data-toggle="' + k + '"]');
      if (b) b.setAttribute("aria-pressed", String(!!state[k]));
    });
  }

  function open(v) {
    panel.classList.toggle("open", v);
    fab.setAttribute("aria-expanded", String(v));
    if (v) panel.querySelector(".a11y-close").focus();
  }

  fab.addEventListener("click", function () { open(!panel.classList.contains("open")); });
  panel.querySelector(".a11y-close").addEventListener("click", function () { open(false); fab.focus(); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape" && panel.classList.contains("open")) { open(false); fab.focus(); } });

  panel.addEventListener("click", function (e) {
    var t = e.target.closest("button"); if (!t) return;
    if (t.dataset.act === "zoom-in") { var i = ZOOMS.indexOf(state.zoom); state.zoom = ZOOMS[Math.min(ZOOMS.length - 1, (i < 0 ? 1 : i) + 1)]; apply(); }
    else if (t.dataset.act === "zoom-out") { var j = ZOOMS.indexOf(state.zoom); state.zoom = ZOOMS[Math.max(0, (j < 0 ? 1 : j) - 1)]; apply(); }
    else if (t.dataset.contrast) { state.contrast = t.dataset.contrast; apply(); }
    else if (t.dataset.toggle) { state[t.dataset.toggle] = !state[t.dataset.toggle]; apply(); }
    else if (t.classList.contains("a11y-reset")) { state = { zoom: 1, contrast: "normal", links: false, readable: false, noanim: false }; apply(); }
  });

  function init() {
    document.body.appendChild(fab);
    document.body.appendChild(panel);
    apply();
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
