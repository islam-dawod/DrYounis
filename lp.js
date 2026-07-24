
const menuButton = document.querySelector('.menu-button');
const menu = document.querySelector('.main-menu');

if (menuButton && menu) {
  menuButton.addEventListener('click', () => {
    const open = menu.classList.toggle('open');
    menuButton.setAttribute('aria-expanded', String(open));
  });
  menu.querySelectorAll('a').forEach(link => link.addEventListener('click', () => {
    menu.classList.remove('open');
    menuButton.setAttribute('aria-expanded', 'false');
  }));
}

const form = document.querySelector('.contact-form');
if (form) {
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const status = form.querySelector('.form-status');
    status.textContent = 'הטופס מוכן לחיבור למייל, ל־CRM או למערכת המרפאה.';
  });
}

/* Lightbox — tap/click a before/after image to zoom */
(function () {
  const box = document.getElementById('lightbox');
  if (!box) return;
  const img = box.querySelector('img');
  const closeBtn = box.querySelector('.lightbox-close');

  function open(src, alt) {
    img.src = src;
    img.alt = alt || '';
    box.classList.add('open');
    box.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }
  function close() {
    box.classList.remove('open');
    box.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    img.src = '';
  }

  document.querySelectorAll('.case-images img').forEach(el => {
    el.addEventListener('click', () => open(el.currentSrc || el.src, el.alt));
  });
  closeBtn.addEventListener('click', close);
  box.addEventListener('click', e => { if (e.target === box) close(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && box.classList.contains('open')) close(); });
})();
