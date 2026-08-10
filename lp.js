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
  /* Thank-you modal */
  var thanks = document.getElementById('thanksModal');
  var lastFocus = null;
  function openThanks() {
    if (!thanks) return;
    lastFocus = document.activeElement;
    thanks.classList.add('open');
    thanks.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    var ok = thanks.querySelector('.thanks-ok');
    if (ok) ok.focus();
    // Google Ads conversion — fires when the thank-you popup is shown (successful registration)
    if (typeof gtag === 'function') {
      gtag('event', 'ads_conversion___1', {});
    }
  }
  function closeThanks() {
    if (!thanks) return;
    thanks.classList.remove('open');
    thanks.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  if (thanks) {
    thanks.addEventListener('click', function (e) {
      if (e.target === thanks || e.target.closest('.thanks-close') || e.target.closest('.thanks-ok')) closeThanks();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && thanks.classList.contains('open')) closeThanks();
    });
  }

  var form = document.querySelector('.contact-form');
  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var status = form.querySelector('.form-status');
      var btn = form.querySelector('button[type=submit]');
      if (!form.checkValidity()) { form.reportValidity(); return; }
      var orig = btn ? btn.textContent : '';
      if (btn) { btn.disabled = true; btn.textContent = 'שולחים את הפנייה…'; }
      fetch('send.php', { method: 'POST', body: new FormData(form) })
        .then(function (r) { return r.json().catch(function () { return { ok: r.ok }; }); })
        .then(function (d) {
          if (d && d.ok) {
            if (status) status.textContent = '';
            form.reset();
            if (btn) { btn.disabled = false; btn.textContent = orig; }
            openThanks();
          } else { throw new Error(); }
        })
        .catch(function () {
          if (btn) { btn.disabled = false; btn.textContent = orig; }
          if (status) { status.innerHTML = 'לא הצלחנו לשלוח את הפנייה כעת. אפשר לנסות שוב, או לפנות אלינו בטלפון <a href="tel:0543345333" dir="ltr">054-334-5333</a> או ב־<a href="https://wa.me/972543345333" target="_blank" rel="noopener">WhatsApp</a>.'; status.style.color = '#b23'; }
        });
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

  /* Call / WhatsApp click tracking (separate events) */
  document.querySelectorAll('[data-track]').forEach(function (el) {
    el.addEventListener('click', function () {
      if (typeof gtag === 'function') {
        gtag('event', el.getAttribute('data-track') === 'whatsapp' ? 'whatsapp_click' : 'call_click', { transport_type: 'beacon' });
      }
    });
  });
})();

/* Exclusive FAQ accordion fallback (older Safari that ignores <details name>) */
(function () {
  var items = document.querySelectorAll('.faq-list details');
  if (items.length < 2) return;
  items.forEach(function (d) {
    d.addEventListener('toggle', function () {
      if (!d.open) return;
      items.forEach(function (o) { if (o !== d) o.open = false; });
    });
  });
})();
