/* Younis Clinic — before/after carousel (RTL-safe, 1–2 per view, looping) */
(function () {
  "use strict";
  function init(sl) {
    var vp = sl.querySelector(".ba-viewport");
    var track = sl.querySelector(".ba-track");
    var dotsWrap = sl.querySelector(".ba-dots");
    var back = sl.querySelector(".ba-back");     // › on the right = previous
    var fwd = sl.querySelector(".ba-forward");   // ‹ on the left  = next
    if (!vp || !track) return;
    var slides = Array.prototype.slice.call(track.children);
    if (slides.length < 2) { if (back) back.style.display = "none"; if (fwd) fwd.style.display = "none"; return; }

    slides.forEach(function (s, idx) {
      var b = document.createElement("button");
      b.type = "button";
      b.setAttribute("aria-label", "מקרה " + (idx + 1));
      b.addEventListener("click", function () { page = Math.floor(idx / perView()); showCase(idx); });
      dotsWrap.appendChild(b);
    });
    var dots = Array.prototype.slice.call(dotsWrap.children);
    var page = 0;

    function perView() { var w = slides[0].getBoundingClientRect().width || 1; return Math.max(1, Math.round(vp.clientWidth / w)); }
    function pages() { return Math.ceil(slides.length / perView()); }
    function currentCase() {
      var mid = vp.getBoundingClientRect().left + vp.clientWidth / 2, best = 0, bd = Infinity;
      slides.forEach(function (s, i) { var r = s.getBoundingClientRect(); var d = Math.abs((r.left + r.width / 2) - mid); if (d < bd) { bd = d; best = i; } });
      return best;
    }
    function showCase(i) { i = Math.max(0, Math.min(slides.length - 1, i)); slides[i].scrollIntoView({ behavior: "smooth", inline: "start", block: "nearest" }); }
    function showPage() { showCase(Math.min(page * perView(), slides.length - 1)); }
    function update() { var c = currentCase(); dots.forEach(function (d, i) { d.classList.toggle("active", i === c); }); }

    if (fwd) fwd.addEventListener("click", function () { page = (page + 1) % pages(); showPage(); });
    if (back) back.addEventListener("click", function () { page = (page - 1 + pages()) % pages(); showPage(); });

    var raf;
    vp.addEventListener("scroll", function () { cancelAnimationFrame(raf); raf = requestAnimationFrame(function () { update(); page = Math.floor(currentCase() / perView()); }); }, { passive: true });
    window.addEventListener("resize", update);
    update();
  }
  function boot() { document.querySelectorAll("[data-ba-slider]").forEach(init); }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();
