/* PartPulse guide: theme, back navigation, contents highlighting, search. */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  $('[data-theme-toggle]').addEventListener('click', () => {
    const t = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = t;
    try { localStorage.setItem('pp-theme', t); } catch {}
  });
  $('#printGuide').addEventListener('click', () => { $$('details').forEach(d => d.open = true); window.print(); });

  // Go back to the same dashboard state (scroll position included) when we came from it.
  const back = $('#backLink');
  back.addEventListener('click', e => {
    try {
      const ref = new URL(document.referrer);
      if (ref.origin === location.origin && /\/(index\.php)?$/.test(ref.pathname) && history.length > 1) { e.preventDefault(); history.back(); }
    } catch {}
  });

  // Highlight the current section in the contents.
  const links = new Map($$('[data-toc]').map(a => [a.dataset.toc, a]));
  const obs = new IntersectionObserver(entries => {
    entries.forEach(en => { if (en.isIntersecting) { links.forEach(a => a.classList.remove('active')); links.get(en.target.id)?.classList.add('active'); } });
  }, { rootMargin: '-15% 0px -75% 0px' });
  $$('.guide-section').forEach(s => obs.observe(s));

  // Highlight the metric opened from a dashboard info link.
  const flash = () => {
    const el = location.hash && document.getElementById(location.hash.slice(1));
    if (el && el.classList.contains('metric')) { el.classList.remove('flash'); void el.offsetWidth; el.classList.add('flash'); }
  };
  window.addEventListener('hashchange', flash); flash();

  // Search: show only matching sections / metric cards / FAQ items.
  const input = $('#guideSearch');
  const norm = s => s.toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '');
  const blocks = $$('.guide-section');
  let t;
  input.addEventListener('input', () => { clearTimeout(t); t = setTimeout(run, 120); });
  input.addEventListener('keydown', e => { if (e.key === 'Escape') { input.value = ''; run(); } });
  function run() {
    const q = norm(input.value.trim());
    let any = false;
    blocks.forEach(sec => {
      const items = $$('.metric, details, .def-table tbody tr', sec);
      if (!q) { sec.classList.remove('hidden'); items.forEach(i => i.classList.remove('hidden')); return; }
      const secHit = norm($('h2', sec).textContent).includes(q);
      let hits = 0;
      items.forEach(i => { const ok = secHit || norm(i.textContent).includes(q); i.classList.toggle('hidden', !ok); if (ok) hits++; if (ok && i.tagName === 'DETAILS') i.open = true; });
      const textHit = secHit || norm(sec.textContent).includes(q);
      sec.classList.toggle('hidden', !textHit);
      $$('.def-table', sec).forEach(tb => tb.classList.toggle('hidden', !secHit && !$$('tbody tr:not(.hidden)', tb).length));
      if (textHit) any = true;
    });
    $('#searchEmpty').classList.toggle('hidden', any || !q);
    $$('[data-toc]').forEach(a => a.classList.toggle('dim', !!q && document.getElementById(a.dataset.toc).classList.contains('hidden')));
  }
  document.addEventListener('keydown', e => { if (e.key === '/' && document.activeElement !== input) { e.preventDefault(); input.focus(); } });
})();
