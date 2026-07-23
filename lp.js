/* ד״ר תחסין יונס — דף נחיתה
   1) תפריט נייד (☰)
   2) סליידר השוואה לפני/אחרי
   3) טיפול בשליחת טופס (דמו)
*/
(function () {
  "use strict";

  /* ---------- 1) תפריט נייד ---------- */
  var toggle = document.querySelector(".menu-toggle");
  var nav = document.querySelector(".main-nav");
  if (toggle && nav) {
    toggle.addEventListener("click", function () {
      var open = nav.classList.toggle("open");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    });
    // סגירת התפריט בלחיצה על קישור (נייד)
    nav.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", function () {
        nav.classList.remove("open");
        toggle.setAttribute("aria-expanded", "false");
      });
    });
  }

  /* ---------- 2) סליידר לפני/אחרי ---------- */
  document.querySelectorAll("[data-compare]").forEach(function (box) {
    var before = box.querySelector(".compare-before");
    var handle = box.querySelector(".compare-handle");
    if (!before || !handle) return;

    var dragging = false;

    function setPos(p) {
      p = Math.max(0, Math.min(100, p));
      before.style.clipPath = "inset(0 " + (100 - p) + "% 0 0)";
      handle.style.left = p + "%";
      handle.setAttribute("aria-valuenow", Math.round(p));
    }

    function posFromEvent(e) {
      var rect = box.getBoundingClientRect();
      var x = e.clientX - rect.left;
      return (x / rect.width) * 100;
    }

    box.addEventListener("pointerdown", function (e) {
      dragging = true;
      box.setPointerCapture && box.setPointerCapture(e.pointerId);
      setPos(posFromEvent(e));
    });
    box.addEventListener("pointermove", function (e) {
      if (!dragging) return;
      setPos(posFromEvent(e));
      if (e.cancelable) e.preventDefault();
    });
    box.addEventListener("pointerup", function () { dragging = false; });
    box.addEventListener("pointercancel", function () { dragging = false; });

    // נגישות מקלדת
    handle.setAttribute("role", "slider");
    handle.setAttribute("aria-valuemin", "0");
    handle.setAttribute("aria-valuemax", "100");
    handle.addEventListener("keydown", function (e) {
      var cur = parseFloat(handle.style.left) || 50;
      if (e.key === "ArrowLeft") { setPos(cur - 4); e.preventDefault(); }
      if (e.key === "ArrowRight") { setPos(cur + 4); e.preventDefault(); }
    });

    setPos(50);
  });
})();

/* ---------- 3) טופס (דמו) ---------- */
/* מופעל דרך onsubmit="return handleDemoSubmit(event)" ב-index.html.
   כרגע מציג הודעת תודה בלבד — יש לחבר לשרת/CRM/דוא״ל לפני פרסום. */
function handleDemoSubmit(e) {
  e.preventDefault();
  var form = e.target;
  var msg = form.querySelector(".form-message");
  if (msg) {
    msg.textContent = "תודה! פנייתך התקבלה, ניצור איתך קשר בהקדם.";
  }
  form.reset();
  return false;
}
