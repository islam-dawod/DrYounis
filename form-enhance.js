/* Younis Clinic — form consent: clear custom validation message */
(function () {
  "use strict";
  var MSG = "יש לאשר את מדיניות הפרטיות ותנאי השימוש לפני שליחת הטופס.";
  function wire() {
    var boxes = document.querySelectorAll('.consent input[type="checkbox"]');
    boxes.forEach(function (cb) {
      cb.addEventListener("invalid", function () { cb.setCustomValidity(MSG); });
      cb.addEventListener("change", function () { cb.setCustomValidity(""); });
    });
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", wire);
  else wire();
})();
