/* Younis Clinic — before/after carousel behavior (RTL-safe) */
(function () {
  "use strict";
  function init(sl) {
    var vp = sl.querySelector(".ba-viewport");
    var track = sl.querySelector(".ba-track");
    var dotsWrap = sl.querySelector(".ba-dots");
    var prev = sl.querySelector(".ba-prev");
    var next = sl.querySelector(".ba-next");
    if (!vp || !track) return;
    var slides = Array.prototype.slice.call(track.children);
    if (slides.length < 2) { if (prev) prev.style.display = "none"; if (next) next.style.display = "none"; return; }

    slides.forEach(function (s, idx) {
      var b = document.createElement("button");
      b.type = "button";
      b.setAttribute("aria-label", "מקרה " + (idx + 1));
      b.addEventListener("click", function () { goTo(idx); });
      dotsWrap.appendChild(b);
    });
    var dots = Array.prototype.slice.call(dotsWrap.children);

    function current() {
      var mid = vp.getBoundingClientRect().left + vp.clientWidth / 2;
      var best = 0, bd = Infinity;
      slides.forEach(function (s, i) {
        var r = s.getBoundingClientRect();
        var d = Math.abs((r.left + r.width / 2) - mid);
        if (d < bd) { bd = d; best = i; }
      });
      return best;
    }
    function goTo(i) {
      i = Math.max(0, Math.min(slides.length - 1, i));
      slides[i].scrollIntoView({ behavior: "smooth", inline: "center", block: "nearest" });
    }
    function update() {
      var c = current();
      dots.forEach(function (d, i) { d.classList.toggle("active", i === c); });
    }
    if (prev) prev.addEventListener("click", function () { goTo(current() - 1); });
    if (next) next.addEventListener("click", function () { goTo(current() + 1); });
    var raf;
    vp.addEventListener("scroll", function () { cancelAnimationFrame(raf); raf = requestAnimationFrame(update); }, { passive: true });
    window.addEventListener("resize", update);
    update();
  }
  function boot() { document.querySelectorAll("[data-ba-slider]").forEach(init); }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();
