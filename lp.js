const menuButton = document.querySelector('.menu-toggle');
const nav = document.querySelector('.main-nav');
if (menuButton && nav) {
  menuButton.addEventListener('click', () => {
    const open = nav.classList.toggle('open');
    menuButton.setAttribute('aria-expanded', String(open));
  });
  nav.querySelectorAll('a').forEach(link => link.addEventListener('click', () => {
    nav.classList.remove('open');
    menuButton.setAttribute('aria-expanded', 'false');
  }));
}

document.querySelectorAll('[data-compare]').forEach(compare => {
  const before = compare.querySelector('.compare-before');
  const handle = compare.querySelector('.compare-handle');
  let active = false;
  const setPosition = clientX => {
    const rect = compare.getBoundingClientRect();
    const pct = Math.min(100, Math.max(0, ((clientX - rect.left) / rect.width) * 100));
    before.style.clipPath = `inset(0 ${100 - pct}% 0 0)`;
    handle.style.left = `${pct}%`;
  };
  const start = e => { active = true; compare.setPointerCapture?.(e.pointerId); setPosition(e.clientX); };
  const move = e => { if (active) setPosition(e.clientX); };
  const end = () => { active = false; };
  compare.addEventListener('pointerdown', start);
  compare.addEventListener('pointermove', move);
  compare.addEventListener('pointerup', end);
  compare.addEventListener('pointercancel', end);
  handle.addEventListener('keydown', e => {
    const current = parseFloat(handle.style.left || 50);
    if (e.key === 'ArrowLeft') { e.preventDefault(); const next = Math.max(0, current - 5); before.style.clipPath = `inset(0 ${100-next}% 0 0)`; handle.style.left = `${next}%`; }
    if (e.key === 'ArrowRight') { e.preventDefault(); const next = Math.min(100, current + 5); before.style.clipPath = `inset(0 ${100-next}% 0 0)`; handle.style.left = `${next}%`; }
  });
});

function handleDemoSubmit(event) {
  event.preventDefault();
  const message = event.currentTarget.querySelector('.form-message');
  message.textContent = 'הטופס מוכן לעיצוב. יש לחבר אותו ליעד האמיתי לפני העלאה לאוויר.';
  return false;
}
