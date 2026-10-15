(() => {
  'use strict';
  const state = { data: null, filtered: [], charts: {} };
  const $ = s => document.querySelector(s);
  const fmtInt = new Intl.NumberFormat('en-GB');
  const fmtEur = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });
  const palette = ['#0d6b5c','#bb7b2f','#3f729c','#9b5149','#6d6e86','#4f8c74','#d0a555','#657d8a','#a36b55','#7d8f64','#af6c84','#5b817e'];
  const themeButton = $('[data-theme-toggle]');
  let theme = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  document.documentElement.dataset.theme = theme;
  themeButton.addEventListener('click', () => { theme = theme === 'dark' ? 'light' : 'dark'; document.documentElement.dataset.theme = theme; renderCharts(); });

  const delta = (current, previous, formatter = fmtInt.format) => {
    if (previous === null || previous === undefined) return 'First available snapshot';
    const diff = current - previous, sign = diff > 0 ? '+' : '';
    return `${sign}${formatter(diff)} vs previous upload`;
  };
  const setDelta = (selector, current, previous, inverse = false, formatter = fmtInt.format) => {
    const el = $(selector), diff = previous == null ? 0 : current - previous;
    el.textContent = delta(current, previous, formatter);
    el.className = previous == null || diff === 0 ? '' : ((diff > 0) === inverse ? 'delta-down' : 'delta-up');
  };
  const chartText = () => getComputedStyle(document.documentElement).getPropertyValue('--muted').trim();
  const chartGrid = () => getComputedStyle(document.documentElement).getPropertyValue('--line').trim();
  const common = () => ({ responsive:true, maintainAspectRatio:false, interaction:{mode:'index',intersect:false}, plugins:{legend:{display:false},tooltip:{backgroundColor:theme==='dark'?'#e4e8e5':'#17201c',titleColor:theme==='dark'?'#17201c':'#fff',bodyColor:theme==='dark'?'#17201c':'#fff',padding:12,cornerRadius:8}}, scales:{x:{grid:{display:false},ticks:{color:chartText(),font:{family:'Manrope'}}},y:{beginAtZero:true,grid:{color:chartGrid()},ticks:{color:chartText(),font:{family:'Roboto Mono'}}}}});
  const makeChart = (id, config) => { state.charts[id]?.destroy(); state.charts[id] = new Chart($(id), config); };

  function renderCharts(){
    if (!state.data || state.data.empty || typeof Chart === 'undefined') return;
    const d=state.data, c1=getComputedStyle(document.documentElement).getPropertyValue('--primary').trim();
    const trendOpts=common(); trendOpts.scales.y1={beginAtZero:true,position:'right',grid:{drawOnChartArea:false},ticks:{color:chartText(),font:{family:'Roboto Mono'}}};
    makeChart('#trendChart',{type:'line',data:{labels:d.trend.map(x=>x.snapshot_date),datasets:[{label:'Orders',data:d.trend.map(x=>+x.orders),borderColor:c1,backgroundColor:c1,tension:.32,pointRadius:3,pointHoverRadius:5,yAxisID:'y'},{label:'SLA violations',data:d.trend.map(x=>+x.sla),borderColor:'#bb7b2f',backgroundColor:'#bb7b2f',tension:.32,pointRadius:3,pointHoverRadius:5,yAxisID:'y1'}]},options:trendOpts});
    makeChart('#statusChart',{type:'doughnut',data:{labels:d.statuses.map(x=>x.status_name),datasets:[{data:d.statuses.map(x=>+x.order_count),backgroundColor:palette,borderWidth:0,hoverOffset:6}]},options:{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>`${ctx.label}: ${fmtInt.format(ctx.raw)}`}}}}});
    const costOpts=common(); costOpts.indexAxis='y'; costOpts.plugins.tooltip.callbacks={label:ctx=>fmtEur.format(ctx.raw)}; costOpts.scales.x.ticks.callback=v=>fmtEur.format(v);
    makeChart('#costChart',{type:'bar',data:{labels:d.buildings.map(x=>x.building_code),datasets:[{data:d.buildings.map(x=>+x.costs_eur),backgroundColor:c1,borderRadius:5,borderSkipped:false}]},options:costOpts});
    const reqOpts=common(); reqOpts.indexAxis='y';
    makeChart('#requesterChart',{type:'bar',data:{labels:d.requesters.map(x=>x.requester.replace('Admin ','')),datasets:[{data:d.requesters.map(x=>+x.amount),backgroundColor:'#bb7b2f',borderRadius:5,borderSkipped:false}]},options:reqOpts});
  }

  function statusLegend(){
    $('#statusLegend').innerHTML=state.data.statuses.slice(0,8).map((x,i)=>`<div class="micro-item"><span><i class="swatch" style="background:${palette[i]}"></i>${escapeHtml(x.status_name)}</span><strong>${fmtInt.format(x.order_count)}</strong></div>`).join('');
  }
  function escapeHtml(s){const d=document.createElement('div');d.textContent=String(s??'');return d.innerHTML;}
  function renderRows(){
    const q=$('#slaSearch').value.toLocaleLowerCase(), p=$('#priorityFilter').value;
    state.filtered=state.data.sla.filter(r=>(!p||r.priority===p)&&[r.order_no,r.article,r.status_name,r.building_code,r.requester].join(' ').toLocaleLowerCase().includes(q));
    $('#slaRows').innerHTML=state.filtered.map(r=>`<tr><td>#${escapeHtml(r.order_no)}</td><td>${escapeHtml(r.article)}</td><td>${escapeHtml(r.status_name)}</td><td>${escapeHtml(r.building_code)}</td><td><span class="priority ${escapeHtml(r.priority.toLowerCase())}">${escapeHtml(r.priority)}</span></td><td>${fmtInt.format(r.days_in_status)} / ${fmtInt.format(r.threshold_days)}</td><td>${escapeHtml(r.requester)}</td></tr>`).join('')||'<tr><td colspan="7">No matching SLA records.</td></tr>';
    $('#rowCount').textContent=`${fmtInt.format(state.filtered.length)} records`;
  }
  function render(){
    const d=state.data;
    if(d.empty){$('#emptyState').classList.remove('hidden');$('#dashboardContent').classList.add('hidden');return;}
    $('#snapshotSelect').innerHTML=d.snapshots.map(s=>`<option value="${s.id}" ${+s.id===+d.selected.id?'selected':''}>${escapeHtml(s.snapshot_date)}</option>`).join('');
    $('#dataDate').textContent=d.selected.snapshot_date;
    $('#ordersKpi').textContent=fmtInt.format(d.totals.orders);
    $('#costsKpi').textContent=fmtEur.format(d.totals.costs);
    $('#slaKpi').textContent=fmtInt.format(d.totals.sla);
    $('#urgentKpi').textContent=fmtInt.format(d.totals.urgent_sla);
    setDelta('#ordersDelta',d.totals.orders,d.previous?.orders);
    setDelta('#costsDelta',d.totals.costs,d.previous?.costs,false,fmtEur.format);
    setDelta('#slaDelta',d.totals.sla,d.previous?.sla,true);
    statusLegend();renderRows();renderCharts();
  }
  async function load(id=''){
    $('#loadingLayer').classList.remove('done');
    try{const r=await fetch(`api_workbook.php${id?`?snapshot=${encodeURIComponent(id)}`:''}`,{headers:{Accept:'application/json'}});if(!r.ok)throw new Error('Request failed');state.data=await r.json();if(state.data.error)throw new Error(state.data.error);render();}
    catch(e){$('#dashboardContent').innerHTML='<section class="empty-state"><h2>Dashboard unavailable</h2><p>Please try again shortly or contact the administrator.</p></section>';}
    finally{setTimeout(()=>$('#loadingLayer').classList.add('done'),250)}
  }
  $('#snapshotSelect').addEventListener('change',e=>load(e.target.value));
  $('#slaSearch').addEventListener('input',renderRows);$('#priorityFilter').addEventListener('change',renderRows);
  $('#exportCsv').addEventListener('click',()=>{const h=['Order','Article','Status','Building','Priority','Days in status','Threshold days','Requester'];const esc=v=>`"${String(v??'').replaceAll('"','""')}"`;const rows=state.filtered.map(r=>[r.order_no,r.article,r.status_name,r.building_code,r.priority,r.days_in_status,r.threshold_days,r.requester]);const blob=new Blob(['\ufeff'+[h,...rows].map(x=>x.map(esc).join(',')).join('\n')],{type:'text/csv;charset=utf-8'});const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=`partpulse-sla-${state.data.selected.snapshot_date}.csv`;a.click();URL.revokeObjectURL(a.href)});
  document.querySelectorAll('.nav-link').forEach(a=>a.addEventListener('click',()=>{document.querySelectorAll('.nav-link').forEach(x=>x.classList.remove('active'));a.classList.add('active')}));
  load();
})();
