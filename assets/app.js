/* PartPulse Intelligence — order-level dashboard. */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const state = { meta: null, data: null, charts: {}, changeType: '', diffType: '', diff: null, watch: 'sla', loading: 0 };
  const fmtInt = new Intl.NumberFormat('en-GB');
  const fmtEur = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });
  const fmtEur2 = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'EUR', minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const fmtPct = new Intl.NumberFormat('en-GB', { style: 'percent', maximumFractionDigits: 0 });
  const fmtDate = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  const fmtDateShort = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' });
  const fmtMonth = new Intl.DateTimeFormat('en-GB', { month: 'short', year: '2-digit' });
  const STATUS_COLORS = { 'New': '#3f729c', 'Pending': '#6d6e86', 'Quote Requested': '#bb7b2f', 'Quote Received': '#d0a555', 'Quote Under Approval': '#a36b55', 'Approved': '#7d8f64', 'Ordered': '#0d6b5c', 'In Transit': '#4f8c74', 'Partially Delivered': '#5b817e', 'On Hold': '#9b5149', 'Delivered': '#2f7351', 'Cancelled': '#858b87' };
  const TYPE_LABELS = { created: 'Created', completed: 'Completed', cancelled: 'Cancelled', reopened: 'Reopened', status: 'Status move', price: 'Price', eta: 'Delivery date', supplier: 'Supplier', other: 'Other' };
  const DIFF_LABELS = { new: 'New', completed: 'Completed', cancelled: 'Cancelled', reopened: 'Reopened', updated: 'Updated', removed: 'Removed' };
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();

  /* ---------- theme ---------- */
  let theme = localStorage.getItem('pp-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  document.documentElement.dataset.theme = theme;
  $('[data-theme-toggle]').addEventListener('click', () => { theme = theme === 'dark' ? 'light' : 'dark'; document.documentElement.dataset.theme = theme; try { localStorage.setItem('pp-theme', theme); } catch {} renderCharts(); });

  /* ---------- dates ---------- */
  const iso = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const parse = s => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
  const addDays = (s, n) => { const d = parse(s); d.setDate(d.getDate() + n); return iso(d); };
  const addYears = (s, n) => { const d = parse(s); d.setFullYear(d.getFullYear() + n); return iso(d); };
  const daysBetween = (a, b) => Math.round((parse(b) - parse(a)) / 864e5);
  const today = () => iso(new Date());
  function presetRange(p) {
    const t = today(), d = parse(t), dow = (d.getDay() + 6) % 7;
    switch (p) {
      case 'today': return [t, t];
      case 'yesterday': { const y = addDays(t, -1); return [y, y]; }
      case 'this_week': return [addDays(t, -dow), t];
      case 'last_week': { const s = addDays(t, -dow - 7); return [s, addDays(s, 6)]; }
      case 'this_month': return [iso(new Date(d.getFullYear(), d.getMonth(), 1)), t];
      case 'last_month': return [iso(new Date(d.getFullYear(), d.getMonth() - 1, 1)), iso(new Date(d.getFullYear(), d.getMonth(), 0))];
      case 'last_7': return [addDays(t, -6), t];
      case 'last_30': return [addDays(t, -29), t];
      case 'last_90': return [addDays(t, -89), t];
      case 'ytd': return [`${d.getFullYear()}-01-01`, t];
      case 'all': return [state.meta?.options?.first_order || '2025-01-01', t];
    }
    return null;
  }
  function compareRange(from, to, mode) {
    if (mode === 'none') return [null, null];
    if (mode === 'yoy') return [addYears(from, -1), addYears(to, -1)];
    if (mode === 'custom') return [$('#cmpFrom').value || null, $('#cmpTo').value || null];
    const len = daysBetween(from, to) + 1;
    // Calendar months compare with the previous calendar month.
    const f = parse(from), tt = parse(to);
    if (f.getDate() === 1 && iso(new Date(tt.getFullYear(), tt.getMonth() + 1, 0)) === to && f.getMonth() === tt.getMonth()) {
      return [iso(new Date(f.getFullYear(), f.getMonth() - 1, 1)), iso(new Date(f.getFullYear(), f.getMonth(), 0))];
    }
    return [addDays(from, -len), addDays(from, -1)];
  }
  const rangeText = (a, b) => a === b ? fmtDate.format(parse(a)) : `${fmtDateShort.format(parse(a))} – ${fmtDate.format(parse(b))}`;

  /* ---------- URL state ---------- */
  const F = { building: '#fBuilding', priority: '#fPriority', supplier: '#fSupplier', requester: '#fRequester', category: '#fCategory', actor: '#fActor', q: '#fSearch' };
  let preset = 'last_7';
  function readUrl() {
    const p = new URLSearchParams(location.search);
    preset = p.get('preset') || (p.get('from') ? 'custom' : 'last_7');
    const r = preset !== 'custom' ? presetRange(preset) : null;
    $('#fromDate').value = r ? r[0] : (p.get('from') || addDays(today(), -6));
    $('#toDate').value = r ? r[1] : (p.get('to') || today());
    $('#compareMode').value = p.get('cmp') || 'prev';
    if (p.get('cmp') === 'custom') { $('#cmpFrom').value = p.get('cmp_from') || ''; $('#cmpTo').value = p.get('cmp_to') || ''; }
    for (const [k, sel] of Object.entries(F)) if (p.get(k)) $(sel).value = p.get(k);
    toggleCustomCompare();
  }
  function writeUrl() {
    const p = new URLSearchParams(location.search);
    ['preset', 'from', 'to', 'cmp', 'cmp_from', 'cmp_to', ...Object.keys(F)].forEach(k => p.delete(k));
    if (preset !== 'last_7') p.set('preset', preset);
    if (preset === 'custom') { p.set('from', $('#fromDate').value); p.set('to', $('#toDate').value); }
    if ($('#compareMode').value !== 'prev') p.set('cmp', $('#compareMode').value);
    if ($('#compareMode').value === 'custom') { p.set('cmp_from', $('#cmpFrom').value); p.set('cmp_to', $('#cmpTo').value); }
    for (const [k, sel] of Object.entries(F)) if ($(sel).value) p.set(k, $(sel).value);
    p.delete('key');
    const qs = p.toString();
    history.replaceState(null, '', location.pathname + (qs ? `?${qs}` : '') + location.hash);
  }
  const toggleCustomCompare = () => $$('.cmp-custom').forEach(el => el.classList.toggle('hidden', $('#compareMode').value !== 'custom'));
  const markPreset = () => $$('#presets button').forEach(b => b.classList.toggle('active', b.dataset.preset === preset));

  /* ---------- fetch ---------- */
  async function api(params) {
    const r = await fetch(`api.php?${new URLSearchParams(params)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const j = await r.json().catch(() => ({ error: 'Invalid server response' }));
    if (!r.ok || j.error) throw new Error(j.error || `Request failed (${r.status})`);
    return j;
  }
  const busy = on => { state.loading += on ? 1 : -1; document.body.classList.toggle('is-busy', state.loading > 0); };
  function showError(msg) { const b = $('#errorBox'); b.textContent = msg; b.classList.toggle('hidden', !msg); }

  async function init() {
    readUrl();
    try {
      state.meta = await api({ action: 'meta' });
    } catch (e) { showError(e.message); $('#loadingLayer').classList.add('done'); return; }
    if (state.meta.empty) {
      $('#dashboardContent').classList.add('hidden'); $('.control-bar').classList.add('hidden');
      $('#emptyState').classList.remove('hidden');
      if (state.meta.legacy_workbooks) $('#emptyText').innerHTML = 'Only XLSX summary workbooks have been uploaded so far. <a class="text-link" href="workbook.php">Open workbook snapshots</a> or upload a PartPulse SQL export for order-level history.';
      $('#loadingLayer').classList.add('done');
      return;
    }
    fillOptions();
    if (preset === 'all') { const r = presetRange('all'); $('#fromDate').value = r[0]; }
    markPreset();
    fillSnapshots();
    await load();
    $('#loadingLayer').classList.add('done');
    if (state.meta.snapshots.length > 1) runCompare();
  }

  function fillOptions() {
    const o = state.meta.options, url = new URLSearchParams(location.search);
    const add = (sel, items, val = x => x, label = x => x) => { const el = $(sel); items.forEach(it => el.add(new Option(label(it), val(it)))); };
    add('#fBuilding', o.buildings, b => b.code, b => `${b.code} · ${b.name}`);
    add('#fPriority', o.priorities);
    add('#fSupplier', o.suppliers);
    add('#fRequester', o.requesters);
    add('#fCategory', o.categories);
    add('#fActor', o.actors);
    for (const [k, sel] of Object.entries(F)) if (url.get(k)) $(sel).value = url.get(k);
  }
  function fillSnapshots() {
    const s = state.meta.snapshots, a = $('#snapA'), b = $('#snapB');
    s.forEach((x, i) => { const label = `${x.snapshot_date} · ${fmtInt.format(x.order_count)} orders`; a.add(new Option(label, x.id)); b.add(new Option(label, x.id)); });
    if (s.length > 1) { b.value = s[0].id; a.value = s[1].id; }
    const latest = s[0];
    const when = latest.source_generated_at ? latest.source_generated_at.slice(0, 16) : latest.snapshot_date;
    $('#asOfChip').textContent = `Data as of ${when}`;
    $('#asOfFoot').textContent = `Snapshot ${latest.snapshot_date}`;
  }

  async function load() {
    const from = $('#fromDate').value, to = $('#toDate').value;
    if (!from || !to) return;
    const [cf, ct] = compareRange(from, to, $('#compareMode').value);
    const params = { action: 'dashboard', from, to };
    if (cf && ct) { params.cmp_from = cf; params.cmp_to = ct; }
    for (const [k, sel] of Object.entries(F)) if ($(sel).value) params[k] = $(sel).value;
    writeUrl();
    busy(true); showError('');
    try {
      state.data = await api(params);
      state.data._cmp = cf && ct ? [cf, ct] : null;
      render();
    } catch (e) { showError(e.message); }
    finally { busy(false); }
  }

  /* ---------- render ---------- */
  function render() {
    const d = state.data, k = d.kpis, c = d.compare;
    $('#periodLabel').textContent = `${rangeText(d.period.from, d.period.to)}${d._cmp ? ` · compared with ${rangeText(d._cmp[0], d._cmp[1])}` : ''} · ${fmtInt.format(d.universe)} orders in scope`;
    const fmts = { incoming: fmtInt.format, completed: fmtInt.format, net_backlog: v => (v > 0 ? '+' : '') + fmtInt.format(v), backlog_end: fmtInt.format, lead_median: v => v == null ? '—' : `${v.toLocaleString('en-GB', { maximumFractionDigits: 1 })} d`, spend: fmtEur.format, ordered_count: fmtInt.format, events: fmtInt.format };
    const subs = {
      incoming: () => `${k.days} day${k.days === 1 ? '' : 's'} · ${(k.incoming / k.days).toFixed(1)} per day`,
      completed: () => k.throughput_ratio == null ? `${fmtInt.format(k.cancelled)} cancelled` : `${fmtPct.format(k.throughput_ratio)} of new · ${fmtInt.format(k.cancelled)} cancelled`,
      net_backlog: () => `${fmtInt.format(k.backlog_start)} → ${fmtInt.format(k.backlog_end)} open`,
      backlog_end: () => `${fmtInt.format(d.sla.length)} over SLA · ${fmtInt.format(d.overdue.length)} late`,
      lead_median: () => k.lead_p90 == null ? 'No deliveries in period' : `P90 ${k.lead_p90} d · avg ${k.lead_avg} d`,
      spend: () => k.on_time_rate == null ? 'Value of orders delivered' : `${fmtPct.format(k.on_time_rate)} by needed date (n=${k.on_time_sample})`,
      ordered_count: () => `${fmtEur.format(k.ordered_value)} committed`,
      events: () => `${fmtInt.format(k.status_changes)} status moves · ${fmtInt.format(k.orders_touched)} orders`,
    };
    const sparks = { incoming: 'incoming', completed: 'completed', net_backlog: 'backlog', spend: 'spend', events: 'activity' };
    $$('[data-kpi]').forEach(card => {
      const key = card.dataset.kpi, inverse = card.dataset.inverse === '1';
      $('strong', card).textContent = fmts[key](k[key]);
      const small = $('small', card);
      let text = subs[key]();
      small.className = '';
      if (c && c[key] != null && k[key] != null) {
        const diff = k[key] - c[key];
        const pct = c[key] ? diff / Math.abs(c[key]) : null;
        const sign = diff > 0 ? '▲' : diff < 0 ? '▼' : '＝';
        const shown = key === 'spend' ? fmtEur.format(Math.abs(diff)) : key === 'lead_median' ? `${Math.abs(diff).toFixed(1)} d` : fmtInt.format(Math.abs(diff));
        const deltaEl = `<b class="${diff === 0 ? '' : ((diff > 0) !== inverse ? 'delta-up' : 'delta-down')}">${sign} ${shown}${pct != null && key !== 'net_backlog' && key !== 'lead_median' ? ` (${pct > 0 ? '+' : ''}${fmtPct.format(pct)})` : ''}</b>`;
        small.innerHTML = `${deltaEl} vs before<br><span>${esc(text)}</span>`;
      } else small.textContent = text;
      const svg = $('svg.spark', card);
      if (svg) spark(svg, d.series.map(s => +s[sparks[key]] || 0));
    });
    $('#kpiFootnote').textContent = 'New = submitted in period. Completed = moved to Delivered in period (from the PartPulse change log). Backlog = open orders (not Delivered/Cancelled) at the end of the period. Lead time = submission to delivery.';
    const gran = granularity(d.series);
    $('#granChip').textContent = gran; $('#activityGran').textContent = gran.replace('ly', '').replace('dai', 'day');
    renderTransitions(); renderActors(); renderHeatmap(); renderBuildings(); renderSuppliers(); renderRequesters();
    renderWatch(); renderChangeTypes(); renderChanges();
    $('#leadNote').textContent = k.lead_median == null ? '' : `Median ${k.lead_median} days across ${fmtInt.format(k.completed)} deliveries.`;
    renderCharts();
  }
  function granularity(series) {
    if (series.length < 2) return 'daily';
    const g = daysBetween(series[0].bucket, series[1].bucket);
    return g <= 1 ? 'daily' : g <= 7 ? 'weekly' : 'monthly';
  }
  function bucketLabel(b, gran) { const d = parse(b); return gran === 'monthly' ? fmtMonth.format(d) : fmtDateShort.format(d); }
  function spark(svg, vals) {
    if (vals.length < 2) { svg.innerHTML = ''; return; }
    const max = Math.max(...vals), min = Math.min(...vals), span = max - min || 1;
    const pts = vals.map((v, i) => `${(i / (vals.length - 1) * 100).toFixed(2)},${(26 - (v - min) / span * 24).toFixed(2)}`);
    svg.innerHTML = `<polyline points="${pts.join(' ')}" fill="none" stroke="currentColor" stroke-width="1.6" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>`;
  }
  function bars(el, items, label, value, fmt = fmtInt.format, max) {
    const m = max ?? Math.max(1, ...items.map(value));
    el.innerHTML = items.length ? items.map(it => `<div class="bar-item"><div class="bar-top"><span>${esc(label(it))}</span><b>${fmt(value(it))}</b></div><div class="bar-track"><i style="width:${(value(it) / m * 100).toFixed(1)}%"></i></div></div>`).join('') : '<p class="muted small">No status moves in this period.</p>';
  }
  const renderTransitions = () => bars($('#transitions'), state.data.transitions.slice(0, 10), t => t.transition, t => t.count);
  function renderActors() {
    const rows = state.data.actors;
    $('#actorRows').innerHTML = rows.length ? rows.map(a => `<tr><td><button class="link-button" data-actor="${esc(a.name)}">${esc(a.name)}</button></td><td class="num">${fmtInt.format(a.events)}</td><td class="num">${fmtInt.format(a.orders)}</td><td class="num">${fmtInt.format(a.created)}</td><td class="num">${fmtInt.format(a.status)}</td><td class="num">${fmtInt.format(a.ordered)}</td><td class="num">${fmtInt.format(a.delivered)}</td><td class="num">${fmtInt.format(a.prices)}</td></tr>`).join('') : '<tr><td colspan="8" class="muted">No logged activity in this period.</td></tr>';
    $('#workNote').textContent = $('#fActor').value ? `Filtered: ${$('#fActor').value}` : '';
    const f = state.data.fields.slice(0, 6), tot = f.reduce((s, x) => s + x.count, 0) || 1;
    $('#fieldList').innerHTML = f.map(x => `<div class="micro-item"><span>${esc(x.label)}</span><b>${fmtInt.format(x.count)} <small class="muted">${fmtPct.format(x.count / tot)}</small></b></div>`).join('');
  }
  function renderHeatmap() {
    const h = state.data.heatmap, max = Math.max(1, ...h.flat()), days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const hours = [...Array(24).keys()].filter(x => x >= 5 && x <= 21);
    let html = '<span></span>' + hours.map(x => `<span class="hh">${x % 3 === 0 ? x : ''}</span>`).join('');
    h.forEach((row, i) => { html += `<span class="hd">${days[i]}</span>` + hours.map(x => { const v = row[x], lvl = v ? Math.min(5, Math.ceil(v / max * 5)) : 0; return `<i class="l${lvl}" title="${days[i]} ${x}:00 · ${v} actions"></i>`; }).join(''); });
    const el = $('#heatmap'); el.style.setProperty('--cols', hours.length); el.innerHTML = html;
  }
  function renderBuildings() {
    $('#buildingRows').innerHTML = state.data.buildings.map(b => `<tr><td title="${esc(b.name)}"><b>${esc(b.code)}</b> <span class="muted bname">${esc(b.name)}</span></td><td class="num">${fmtInt.format(b.incoming)}</td><td class="num">${fmtInt.format(b.completed)}</td><td class="num">${fmtInt.format(b.open)}</td><td class="num">${fmtEur.format(b.spend)}</td><td class="num">${b.lead_median == null ? '—' : b.lead_median + ' d'}</td></tr>`).join('');
  }
  function renderSuppliers() {
    const s = state.data.suppliers;
    $('#supplierRows').innerHTML = s.length ? s.map(x => `<tr><td class="clip" title="${esc(x.name)}">${esc(x.name)}</td><td class="num">${fmtInt.format(x.completed)}</td><td class="num">${fmtEur.format(x.spend)}</td><td class="num">${x.lead_median == null ? '—' : x.lead_median + ' d'}</td><td class="num">${x.on_time_rate == null ? '—' : fmtPct.format(x.on_time_rate)}</td></tr>`).join('') : '<tr><td colspan="5" class="muted">No deliveries in this period.</td></tr>';
  }
  function renderRequesters() {
    const r = state.data.requesters;
    $('#requesterRows').innerHTML = r.length ? r.map(x => `<tr><td>${esc(x.name)}</td><td class="num">${fmtInt.format(x.incoming)}</td><td class="num">${fmtInt.format(x.open)}</td></tr>`).join('') : '<tr><td colspan="3" class="muted">No new orders in this period.</td></tr>';
  }

  /* ---------- watch ---------- */
  const WATCH = {
    sla: { head: ['Order', 'Article', 'Status', 'Days in status', 'Limit', 'Priority', 'Bldg', 'Requester', 'Supplier'], row: r => [orderLink(r.order_id), esc(r.item), statusPill(r.status), `<b class="${r.days > r.threshold * 3 ? 'text-danger' : ''}">${r.days}</b>`, r.threshold, prio(r.priority), esc(r.building), esc(r.requester), esc(r.supplier)], csv: ['order_id', 'item', 'status', 'days', 'threshold', 'priority', 'building', 'requester', 'supplier', 'value'] },
    overdue: { head: ['Order', 'Article', 'Status', 'Expected', 'Days late', 'Priority', 'Bldg', 'Supplier', 'Value'], row: r => [orderLink(r.order_id), esc(r.item), statusPill(r.status), r.expected, `<b class="text-danger">${r.days_late}</b>`, prio(r.priority), esc(r.building), esc(r.supplier), r.value == null ? '—' : fmtEur2.format(r.value)], csv: ['order_id', 'item', 'status', 'expected', 'days_late', 'priority', 'building', 'supplier', 'value'] },
  };
  const orderLink = id => `<button class="link-button mono" data-order="${id}">#${id}</button>`;
  const prio = p => `<span class="priority ${esc(String(p).toLowerCase())}">${esc(p)}</span>`;
  const statusPill = s => s ? `<span class="status-pill" style="--c:${STATUS_COLORS[s] || '#777'}">${esc(s)}</span>` : '<span class="muted">—</span>';
  function watchRows() {
    const q = $('#watchSearch').value.trim().toLowerCase(), pr = $('#watchPriority').value;
    return (state.data[state.watch === 'sla' ? 'sla' : 'overdue'] || []).filter(r => (!pr || r.priority === pr) && (!q || Object.values(r).join(' ').toLowerCase().includes(q)));
  }
  function renderWatch() {
    const def = WATCH[state.watch], rows = watchRows();
    $('#slaCount').textContent = state.data.sla.length; $('#overdueCount').textContent = state.data.overdue.length;
    $('#watchHead').innerHTML = `<tr>${def.head.map(h => `<th>${h}</th>`).join('')}</tr>`;
    $('#watchRows').innerHTML = rows.length ? rows.slice(0, 800).map(r => `<tr>${def.row(r).map(c => `<td>${c}</td>`).join('')}</tr>`).join('') : `<tr><td colspan="${def.head.length}" class="muted">Nothing here — good.</td></tr>`;
    $('#watchCountLabel').textContent = `${fmtInt.format(rows.length)} records`;
  }

  /* ---------- changes ---------- */
  function changeRows() {
    const q = $('#changeSearch').value.trim().toLowerCase();
    return state.data.changes.filter(r => (!state.changeType || r.type === state.changeType) && (!q || `${r.order_id} ${r.item} ${r.actor} ${r.from} ${r.to} ${r.field}`.toLowerCase().includes(q)));
  }
  function renderChangeTypes() {
    const counts = {}; state.data.changes.forEach(r => counts[r.type] = (counts[r.type] || 0) + 1);
    const types = Object.keys(TYPE_LABELS).filter(t => counts[t]);
    if (state.changeType && !counts[state.changeType]) state.changeType = '';
    $('#changeTypes').innerHTML = `<button type="button" class="chip ${state.changeType ? '' : 'active'}" data-type="">All <b>${fmtInt.format(state.data.changes.length)}</b></button>` + types.map(t => `<button type="button" class="chip chip-${t} ${state.changeType === t ? 'active' : ''}" data-type="${t}">${TYPE_LABELS[t]} <b>${fmtInt.format(counts[t])}</b></button>`).join('');
  }
  const when = r => r.date_only ? `${r.at.slice(0, 10)} <span class="muted">(date only)</span>` : r.at.slice(0, 16);
  const val = (r, v) => v == null || v === '' ? '<span class="muted">—</span>' : (r.field === 'Status' || r.type === 'created' ? statusPill(v) : esc(v));
  function renderChanges() {
    const rows = changeRows();
    $('#changeRows').innerHTML = rows.length ? rows.slice(0, 1500).map(r => `<tr><td class="mono small">${when(r)}</td><td>${orderLink(r.order_id)}</td><td class="wrap">${esc(r.item)}</td><td><span class="type-tag t-${r.type}">${esc(r.type === 'created' ? 'Created' : r.field)}</span></td><td>${r.type === 'created' ? '' : val(r, r.from)}</td><td>${val(r, r.to)}</td><td>${esc(r.actor)}</td><td>${esc(r.building)}</td></tr>`).join('') : '<tr><td colspan="8" class="muted">No changes recorded in this period.</td></tr>';
    $('#changeCount').textContent = `${fmtInt.format(rows.length)} changes${rows.length > 1500 ? ' · first 1,500 shown, CSV has all' : ''}${state.data.changes_truncated ? ' · period capped, narrow the range for the full log' : ''}`;
  }

  /* ---------- snapshot diff ---------- */
  async function runCompare() {
    const a = $('#snapA').value, b = $('#snapB').value;
    if (!a || !b) return;
    if (a === b) { $('#diffCount').textContent = 'Choose two different snapshots.'; return; }
    busy(true);
    try { state.diff = await api({ action: 'compare', a, b }); state.diffType = ''; renderDiff(); }
    catch (e) { $('#diffCount').textContent = e.message; }
    finally { busy(false); }
  }
  function diffRows() { return state.diff ? state.diff.rows.filter(r => !state.diffType || r.type === state.diffType) : []; }
  function renderDiff() {
    const d = state.diff, c = d.counts;
    $('#diffCards').innerHTML = ['new', 'completed', 'cancelled', 'reopened', 'updated', 'removed', 'unchanged'].map(t => `<div class="diff-card d-${t}"><span>${t === 'unchanged' ? 'Unchanged' : DIFF_LABELS[t]}</span><strong>${fmtInt.format(c[t] || 0)}</strong></div>`).join('');
    $('#diffTypes').innerHTML = `<button type="button" class="chip ${state.diffType ? '' : 'active'}" data-dtype="">All changes <b>${fmtInt.format(d.rows.length)}</b></button>` + Object.keys(DIFF_LABELS).filter(t => c[t]).map(t => `<button type="button" class="chip ${state.diffType === t ? 'active' : ''}" data-dtype="${t}">${DIFF_LABELS[t]} <b>${fmtInt.format(c[t])}</b></button>`).join('');
    const rows = diffRows();
    const changeText = r => Object.entries(r.changes).filter(([f]) => f !== 'status').map(([f, [o, n]]) => `<span class="chg"><b>${esc(f.replace(/_/g, ' '))}</b> ${esc(o || '—')} → ${esc(n || '—')}</span>`).join('');
    $('#diffRows').innerHTML = rows.length ? rows.slice(0, 1500).map(r => `<tr><td>${orderLink(r.order_id)}</td><td><span class="type-tag d-${r.type}">${DIFF_LABELS[r.type]}</span></td><td class="wrap">${esc(r.item)}</td><td>${r.old_status && r.new_status && r.old_status !== r.new_status ? `${statusPill(r.old_status)} → ${statusPill(r.new_status)}` : statusPill(r.new_status || r.old_status)}</td><td class="wrap">${changeText(r) || '<span class="muted">—</span>'}</td><td>${esc(r.building)}</td><td class="num">${r.value == null ? '—' : fmtEur2.format(r.value)}</td></tr>`).join('') : '<tr><td colspan="7" class="muted">No differences between these snapshots.</td></tr>';
    $('#diffCount').textContent = `${d.a.snapshot_date} → ${d.b.snapshot_date} · ${fmtInt.format(rows.length)} orders`;
  }

  /* ---------- drawer ---------- */
  async function openOrder(id) {
    const dr = $('#orderDrawer');
    $('#drawerTitle').textContent = `#${id}`; $('#drawerBody').innerHTML = '<div class="loading-mark"></div>';
    dr.classList.add('open'); dr.setAttribute('aria-hidden', 'false'); $('#drawerBackdrop').classList.remove('hidden');
    $('#drawerClose').focus();
    try {
      const o = await api({ action: 'order', id });
      const x = o.order;
      $('#drawerEyebrow').textContent = `Order #${id} · ${x.building_code || ''}`;
      $('#drawerTitle').textContent = x.item_description || `#${id}`;
      const dl = [['Status', statusPill(x.status)], ['Priority', prio(x.priority)], ['Submitted', esc(x.submitted_at)], ['Requester', esc(x.requester_name)], ['Supplier', esc(x.supplier_name || '—')], ['Category', esc(x.category || '—')], ['Quantity', esc(x.quantity ?? '—')], ['Unit price', x.unit_price == null ? '—' : fmtEur2.format(x.unit_price)], ['Total', x.total_price == null ? '—' : fmtEur2.format(x.total_price)], ['Needed by', esc(x.date_needed || '—')], ['Expected', esc(x.expected_delivery_date || '—')], ['Part no.', esc(x.part_number || '—')], ['PO', esc(x.po_number || '—')]];
      const hist = o.history.slice().reverse();
      $('#drawerBody').innerHTML = `<dl class="facts">${dl.map(([k, v]) => `<div><dt>${k}</dt><dd>${v}</dd></div>`).join('')}</dl>
        <h3>Timeline <span class="muted small">${hist.length} entries</span></h3>
        <ol class="timeline">${hist.map(h => `<li class="${h.raw_field === 'status' ? 'is-status' : ''}"><time>${h.at ? (h.date_only ? h.at.slice(0, 10) : h.at.slice(0, 16)) : 'undated'}</time><div><b>${esc(h.field)}</b> ${h.raw_field === 'status' ? `${h.from ? statusPill(h.from) + ' → ' : ''}${statusPill(h.to)}` : `${esc(h.from || '—')} → ${esc(h.to || '—')}`}<small>${esc(h.actor)}</small></div></li>`).join('')}<li><time>${esc((x.submitted_at || '').slice(0, 16))}</time><div><b>Submitted</b><small>${esc(x.requester_name)}</small></div></li></ol>
        ${o.snapshots.length > 1 ? `<h3>In daily snapshots</h3><table class="mini"><thead><tr><th>Snapshot</th><th>Status</th><th class="num">Total</th><th>Expected</th></tr></thead><tbody>${o.snapshots.map(s => `<tr><td>${s.date}</td><td>${statusPill(s.status)}</td><td class="num">${s.total_price == null ? '—' : fmtEur2.format(s.total_price)}</td><td>${esc(s.expected_delivery_date || '—')}</td></tr>`).join('')}</tbody></table>` : ''}`;
    } catch (e) { $('#drawerBody').innerHTML = `<p class="alert error">${esc(e.message)}</p>`; }
  }
  function closeDrawer() { const dr = $('#orderDrawer'); dr.classList.remove('open'); dr.setAttribute('aria-hidden', 'true'); $('#drawerBackdrop').classList.add('hidden'); }

  /* ---------- charts ---------- */
  function base() {
    const text = css('--muted'), grid = css('--line');
    return { responsive: true, maintainAspectRatio: false, animation: { duration: 350 }, interaction: { mode: 'index', intersect: false },
      plugins: { legend: { display: false }, tooltip: { backgroundColor: theme === 'dark' ? '#e4e8e5' : '#17201c', titleColor: theme === 'dark' ? '#17201c' : '#fff', bodyColor: theme === 'dark' ? '#17201c' : '#fff', padding: 10, cornerRadius: 8, boxPadding: 4 } },
      scales: { x: { grid: { display: false }, ticks: { color: text, font: { family: 'Manrope', size: 11 }, maxRotation: 0, autoSkipPadding: 12 } }, y: { beginAtZero: true, grid: { color: grid }, border: { display: false }, ticks: { color: text, font: { family: 'Roboto Mono', size: 11 }, precision: 0 } } } };
  }
  const make = (id, cfg) => { state.charts[id]?.destroy(); if (typeof Chart === 'undefined') return; state.charts[id] = new Chart($(id), cfg); };
  function renderCharts() {
    const d = state.data; if (!d || typeof Chart === 'undefined') return;
    const gran = granularity(d.series), labels = d.series.map(s => bucketLabel(s.bucket, gran));
    const P = css('--primary'), A = '#bb7b2f', G = '#9aa19d', B = '#3f729c';
    const flow = base(); flow.scales.y1 = { position: 'right', beginAtZero: false, grid: { drawOnChartArea: false }, border: { display: false }, ticks: { color: css('--muted'), font: { family: 'Roboto Mono', size: 11 }, precision: 0 } };
    flow.scales.x.stacked = true;
    make('#flowChart', { type: 'bar', data: { labels, datasets: [
      { label: 'New', data: d.series.map(s => s.incoming), backgroundColor: B, borderRadius: 3, stack: 'in', maxBarThickness: 22 },
      { label: 'Completed', data: d.series.map(s => s.completed), backgroundColor: P, borderRadius: 3, stack: 'out', maxBarThickness: 22 },
      { label: 'Cancelled', data: d.series.map(s => s.cancelled), backgroundColor: G, borderRadius: 3, stack: 'out', maxBarThickness: 22 },
      { label: 'Open backlog', type: 'line', data: d.series.map(s => s.backlog), borderColor: A, backgroundColor: A, yAxisID: 'y1', tension: .3, pointRadius: d.series.length > 40 ? 0 : 2.5, borderWidth: 2 },
    ] }, options: flow });

    const statuses = [...new Set([...d.status_start, ...d.status_end].map(s => s.status))].filter(s => !['Delivered', 'Cancelled'].includes(s));
    const get = (arr, s) => arr.find(x => x.status === s)?.count || 0;
    const st = base(); st.indexAxis = 'y'; st.scales.x.grid = { color: css('--line') }; st.scales.y.grid = { display: false }; st.scales.y.ticks.font = { family: 'Manrope', size: 11 }; st.plugins.legend = { display: true, position: 'bottom', labels: { color: css('--muted'), boxWidth: 10, boxHeight: 10, font: { family: 'Manrope' } } };
    make('#statusChart', { type: 'bar', data: { labels: statuses, datasets: [
      { label: `Start (${fmtDateShort.format(parse(addDays(d.period.from, -1)))})`, data: statuses.map(s => get(d.status_start, s)), backgroundColor: theme === 'dark' ? '#3a4440' : '#cfd5d1', borderRadius: 3, barPercentage: .9 },
      { label: `End (${fmtDateShort.format(parse(d.period.to))})`, data: statuses.map(s => get(d.status_end, s)), backgroundColor: statuses.map(s => STATUS_COLORS[s] || P), borderRadius: 3, barPercentage: .9 },
    ] }, options: st });

    const ag = base(); ag.scales.x.stacked = true; ag.scales.y.stacked = true; ag.plugins.legend = { display: true, position: 'bottom', labels: { color: css('--muted'), boxWidth: 9, boxHeight: 9, font: { family: 'Manrope', size: 10 } } };
    make('#agingChart', { type: 'bar', data: { labels: d.aging.buckets, datasets: d.aging.rows.map(r => ({ label: r.status, data: r.counts, backgroundColor: STATUS_COLORS[r.status] || P, maxBarThickness: 34 })) }, options: ag });

    make('#leadChart', { type: 'bar', data: { labels: d.lead_histogram.map(x => x.bucket), datasets: [{ label: 'Deliveries', data: d.lead_histogram.map(x => x.count), backgroundColor: d.lead_histogram.map((_, i) => i < 3 ? P : i < 5 ? A : '#9b5149'), borderRadius: 4, maxBarThickness: 34 }] }, options: base() });

    const act = base(); if (d.compare_series && d.compare_series.length === d.series.length) act.plugins.legend = { display: true, position: 'bottom', labels: { color: css('--muted'), boxWidth: 10, boxHeight: 10, font: { family: 'Manrope' } } };
    make('#activityChart', { type: 'bar', data: { labels, datasets: [{ label: 'Actions', data: d.series.map(s => s.activity), backgroundColor: P, borderRadius: 3, maxBarThickness: 18 }, ...(d.compare_series && d.compare_series.length === d.series.length ? [{ label: 'Previous period', type: 'line', data: d.compare_series.map(s => s.activity), borderColor: G, borderDash: [4, 4], pointRadius: 0, borderWidth: 1.5, tension: .3 }] : [])] }, options: act });

    const bo = base(); bo.scales.y.ticks.callback = v => fmtEur.format(v);
    make('#buildingChart', { type: 'bar', data: { labels: d.buildings.map(b => b.code), datasets: [{ label: 'Delivered value', data: d.buildings.map(b => b.spend), backgroundColor: [P, A, B, '#9b5149', '#6d6e86', '#4f8c74'], borderRadius: 4, maxBarThickness: 46 }] }, options: { ...bo, plugins: { ...bo.plugins, tooltip: { ...bo.plugins.tooltip, callbacks: { label: c => ` ${fmtEur2.format(c.raw)}` } } } } });

    const cat = base(); cat.indexAxis = 'y'; cat.scales.x.grid = { color: css('--line') }; cat.scales.y.grid = { display: false }; cat.scales.y.ticks.font = { family: 'Manrope', size: 11 }; cat.scales.y.ticks.callback = function (v) { const l = this.getLabelForValue(v); return l.length > 28 ? l.slice(0, 27) + '…' : l; };
    make('#categoryChart', { type: 'bar', data: { labels: d.categories.map(c => c.name), datasets: [{ label: 'New orders', data: d.categories.map(c => c.incoming), backgroundColor: B, borderRadius: 3, maxBarThickness: 16 }] }, options: cat });
  }

  /* ---------- CSV ---------- */
  function csv(name, header, rows) {
    const q = v => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const blob = new Blob(['\ufeff' + [header.map(q).join(','), ...rows.map(r => r.map(q).join(','))].join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: name });
    document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  }
  const stamp = () => `${$('#fromDate').value}_${$('#toDate').value}`;
  function toast(msg) { const t = $('#toast'); t.textContent = msg; t.classList.remove('hidden'); clearTimeout(toast.t); toast.t = setTimeout(() => t.classList.add('hidden'), 2200); }

  /* ---------- events ---------- */
  $('#presets').addEventListener('click', e => { const b = e.target.closest('button[data-preset]'); if (!b) return; preset = b.dataset.preset; const r = presetRange(preset); $('#fromDate').value = r[0]; $('#toDate').value = r[1]; markPreset(); load(); });
  ['#fromDate', '#toDate'].forEach(s => $(s).addEventListener('change', () => { preset = 'custom'; markPreset(); load(); }));
  $('#compareMode').addEventListener('change', () => { toggleCustomCompare(); if ($('#compareMode').value !== 'custom' || ($('#cmpFrom').value && $('#cmpTo').value)) load(); });
  ['#cmpFrom', '#cmpTo'].forEach(s => $(s).addEventListener('change', () => { if ($('#cmpFrom').value && $('#cmpTo').value) load(); }));
  Object.values(F).filter(s => s !== '#fSearch').forEach(s => $(s).addEventListener('change', load));
  let st; $('#fSearch').addEventListener('input', () => { clearTimeout(st); st = setTimeout(load, 400); });
  $('#resetFilters').addEventListener('click', () => { Object.values(F).forEach(s => $(s).value = ''); load(); });
  $('#shareView').addEventListener('click', async () => { writeUrl(); try { await navigator.clipboard.writeText(location.href); toast('Link to this view copied'); } catch { toast(location.href); } });
  $('#changeTypes').addEventListener('click', e => { const b = e.target.closest('[data-type]'); if (!b) return; state.changeType = b.dataset.type; renderChangeTypes(); renderChanges(); });
  $('#changeSearch').addEventListener('input', renderChanges);
  $('#changeCsv').addEventListener('click', () => csv(`partpulse-changes_${stamp()}.csv`, ['When', 'Date only', 'Order', 'Article', 'Type', 'Field', 'From', 'To', 'By', 'Building', 'Priority', 'Current status'], changeRows().map(r => [r.at, r.date_only ? 'yes' : '', r.order_id, r.item, TYPE_LABELS[r.type], r.field, r.from, r.to, r.actor, r.building, r.priority, r.status])));
  $$('[data-watch]').forEach(b => b.addEventListener('click', () => { state.watch = b.dataset.watch; $$('[data-watch]').forEach(x => x.classList.toggle('active', x === b)); renderWatch(); }));
  $('#watchSearch').addEventListener('input', renderWatch); $('#watchPriority').addEventListener('change', renderWatch);
  $('#watchCsv').addEventListener('click', () => { const def = WATCH[state.watch]; csv(`partpulse-${state.watch}_${today()}.csv`, def.csv, watchRows().map(r => def.csv.map(k => r[k]))); });
  $('#runCompare').addEventListener('click', runCompare);
  $('#diffTypes').addEventListener('click', e => { const b = e.target.closest('[data-dtype]'); if (!b) return; state.diffType = b.dataset.dtype; renderDiff(); });
  $('#diffCsv').addEventListener('click', () => { if (!state.diff) return; csv(`partpulse-diff_${state.diff.a.snapshot_date}_${state.diff.b.snapshot_date}.csv`, ['Order', 'Result', 'Old status', 'New status', 'Changed fields', 'Article', 'Building', 'Priority', 'Supplier', 'Value'], diffRows().map(r => [r.order_id, DIFF_LABELS[r.type], r.old_status, r.new_status, Object.entries(r.changes).map(([f, [o, n]]) => `${f}: ${o} → ${n}`).join('; '), r.item, r.building, r.priority, r.supplier, r.value])); });
  document.addEventListener('click', e => {
    const o = e.target.closest('[data-order]'); if (o) { openOrder(o.dataset.order); return; }
    const a = e.target.closest('[data-actor]'); if (a) { $('#fActor').value = a.dataset.actor; load(); }
  });
  $('#drawerClose').addEventListener('click', closeDrawer); $('#drawerBackdrop').addEventListener('click', closeDrawer);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDrawer(); });
  const links = $$('.sidebar .nav-link');
  const io = new IntersectionObserver(es => es.forEach(en => { if (en.isIntersecting) links.forEach(l => l.classList.toggle('active', l.getAttribute('href') === `#${en.target.id}`)); }), { rootMargin: '-35% 0px -60% 0px' });
  links.forEach(l => { const t = document.querySelector(l.getAttribute('href')); if (t) io.observe(t); });

  window.addEventListener('load', () => { if (state.data) renderCharts(); });
  init();
})();
