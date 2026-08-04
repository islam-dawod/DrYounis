/* Younis Clinic — cookie consent banner (self-injecting, site-wide) */
(function () {
  "use strict";
  var KEY = "yc_cookie_consent";
  try { if (localStorage.getItem(KEY) === "1") return; } catch (e) {}

  function build() {
    var b = document.createElement("div");
    b.id = "cookie-banner";
    b.setAttribute("role", "region");
    b.setAttribute("aria-label", "הודעת קובצי Cookies");
    b.innerHTML =
      '<p>האתר משתמש בקובצי Cookies לשיפור חוויית הגלישה, למדידת ביצועים ולהתאמת שירותים. ' +
      'המשך השימוש באתר מהווה הסכמה למדיניות ה־Cookies שלנו.</p>' +
      '<div class="cb-actions">' +
      '<button type="button" class="cb-accept">אישור</button>' +
      '<a class="cb-more" href="cookies.html">למידע נוסף</a>' +
      '</div>';
    document.body.appendChild(b);
    requestAnimationFrame(function () { b.classList.add("show"); });
    b.querySelector(".cb-accept").addEventListener("click", function () {
      try { localStorage.setItem(KEY, "1"); } catch (e) {}
      b.classList.remove("show");
      setTimeout(function () { b.remove(); }, 200);
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", build);
  } else {
    build();
  }
})();
