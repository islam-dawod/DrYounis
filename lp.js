/* ד״ר תחסין יונס — דף נחיתה (v9) */
(function () {
  "use strict";

  /* Mobile menu */
  var menuButton = document.querySelector('.menu-button');
  var menu = document.querySelector('.main-menu');
  if (menuButton && menu) {
    menuButton.addEventListener('click', function () {
      var open = menu.classList.toggle('open');
      menuButton.setAttribute('aria-expanded', String(open));
    });
    menu.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        menu.classList.remove('open');
        menuButton.setAttribute('aria-expanded', 'false');
      });
    });
  }

  /* Contact form (demo handler — connect to server/CRM before go-live) */
  var form = document.querySelector('.contact-form');
  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var status = form.querySelector('.form-status');
      if (status) status.textContent = 'תודה! פנייתכם התקבלה, נחזור אליכם בהקדם לתיאום פגישת ייעוץ.';
      form.reset();
    });
  }

  /* Lightbox for before/after images */
  var box = document.getElementById('lightbox');
  if (box) {
    var limg = box.querySelector('img');
    var closeBtn = box.querySelector('.lightbox-close');
    var open = function (src, alt) {
      limg.src = src; limg.alt = alt || '';
      box.classList.add('open'); box.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    };
    var hide = function () {
      box.classList.remove('open'); box.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = ''; limg.src = '';
    };
    document.querySelectorAll('.case-images img').forEach(function (el) {
      el.addEventListener('click', function () { open(el.currentSrc || el.src, el.alt); });
    });
    closeBtn.addEventListener('click', hide);
    box.addEventListener('click', function (e) { if (e.target === box) hide(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && box.classList.contains('open')) hide(); });
  }

  /* Reviews rail — position dots (RTL-safe) */
  var track = document.querySelector('.reviews-track');
  var dots = document.querySelectorAll('.rail-dots span');
  if (track && dots.length) {
    var updateDots = function () {
      var cards = Array.prototype.slice.call(track.children);
      var mid = track.getBoundingClientRect().left + track.clientWidth / 2;
      var best = 0, bd = Infinity;
      cards.forEach(function (c, i) {
        var r = c.getBoundingClientRect();
        var d = Math.abs((r.left + r.width / 2) - mid);
        if (d < bd) { bd = d; best = i; }
      });
      dots.forEach(function (d, i) { d.classList.toggle('on', i === best); });
    };
    track.addEventListener('scroll', updateDots, { passive: true });
    window.addEventListener('resize', updateDots);
    updateDots();
  }

  /* Sticky bar: hidden while Hero is visible; revealed after scroll; hidden on input focus */
  var bar = document.querySelector('.mobile-bar');
  var hero = document.querySelector('.hero');
  if (bar && hero && 'IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      bar.classList.toggle('hidden', entries[0].isIntersecting);
    }, { threshold: 0.15 });
    io.observe(hero);
  }
  if (bar) {
    document.addEventListener('focusin', function (e) {
      if (e.target.matches('input, textarea, select')) bar.classList.add('hidden');
    });
    document.addEventListener('focusout', function (e) {
      if (e.target.matches('input, textarea, select')) setTimeout(function () { bar.classList.remove('hidden'); }, 150);
    });
  }
})();
