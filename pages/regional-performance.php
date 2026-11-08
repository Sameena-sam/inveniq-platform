<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('regional-performance');
require_once __DIR__ . '/../components/layout.php';
render_layout_open('regional-performance'); ?>
<?php page_header('Regional Performance',
    'How each territory is doing on sales, margin, customers and product range, and where there is room to grow'); ?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<link rel="stylesheet" href="/inveniq/assets/css/sales-intel.css">
<style>
  #rp-map{height:400px;border-radius:0 0 var(--radius-lg) var(--radius-lg);position:relative;z-index:0}  /* keep Leaflet's layers below the modal */
</style>

<!-- Data status banner -->
<div id="rp-banner" class="card" style="padding:14px 16px;margin-bottom:14px;border-left:4px solid var(--text-3)">
  <span class="metric-label">Loading results…</span>
</div>

<div class="metrics-grid">
  <div class="metric-card loading" id="rp-m1"><div class="metric-icon success"><?=icon('trending-up')?></div><div class="metric-body"><div class="metric-label">Strong Territories</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
  <div class="metric-card loading" id="rp-m2"><div class="metric-icon warning"><?=icon('target')?></div><div class="metric-body"><div class="metric-label">Untapped Potential</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
  <div class="metric-card loading" id="rp-m3"><div class="metric-icon danger"><?=icon('alert-triangle')?></div><div class="metric-body"><div class="metric-label">Lapsed Territories</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
  <div class="metric-card loading" id="rp-m4"><div class="metric-icon info"><?=icon('map-pin')?></div><div class="metric-body"><div class="metric-label">Biggest Territory</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
</div>

<div class="two-col-grid">
  <div class="card">
    <div class="card-header"><span class="card-title">Territory map</span><span class="si-mini">bigger bubble = more sales</span></div>
    <div id="rp-map"></div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Regions (HQ)</span></div>
    <div class="data-table-wrap"><table class="data-table si-compact">
      <thead><tr><th>Region</th><th>Status</th><th>Sales</th><th>Share</th><th>Trend / yr</th><th>Score</th></tr></thead>
      <tbody id="rp-hq"></tbody></table></div>
  </div>
</div>

<!-- Ranking -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><span class="card-title">Territory ranking</span>
    <div class="si-legend"><span><span class="si-dot" style="background:rgba(220,38,38,.35)"></span>weaker than other territories</span><span><span class="si-dot" style="background:rgba(22,163,74,.45)"></span>stronger</span><span>· click a row for the reasons</span></div></div>
  <div class="table-toolbar"><input class="search-input" id="rp_search" placeholder="Search territory or status…" oninput="filterTable('rp')"><span class="table-count" id="rp_count">Loading…</span></div>
  <div class="data-table-wrap"><table class="data-table" id="rp_table">
    <thead><tr>
      <th onclick="sortTable('rp',0)">Rank</th><th onclick="sortTable('rp',1)">Territory</th><th onclick="sortTable('rp',2)">Status</th>
      <th onclick="sortTable('rp',3)">Sales</th><th onclick="sortTable('rp',4)">Margin</th><th onclick="sortTable('rp',5)">Trend / yr</th>
      <th onclick="sortTable('rp',6)">Customers</th><th onclick="sortTable('rp',7)">Products</th>
      <th onclick="sortTable('rp',8)">Score</th><th onclick="sortTable('rp',9)">Sales rank</th>
    </tr></thead>
    <tbody id="rp-tbody"></tbody></table></div>
</div>

<div class="two-col-grid">
  <div class="card">
    <div class="card-header"><span class="card-title">Territories not scored</span></div>
    <div class="data-table-wrap"><table class="data-table">
      <thead><tr><th>Territory</th><th>Status</th><th>Best year</th><th>Last sale</th></tr></thead>
      <tbody id="rp-other"></tbody></table></div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">How to read this page</span></div>
    <div class="card-body si-method">
      <p><b>Score.</b> Each territory gets a score out of 100 by comparing it with the others on five things:
        sales, margin, sales trend, number of customers and range of products sold. All five count equally.</p>
      <p><b>Strong:</b> doing well on most of the five.
        <b>Steady:</b> performing about as expected.
        <b>Untapped potential:</b> sales are below what its customers or its own past sales suggest.
        <b>Weak:</b> a small market, performing as expected for its size.</p>
      <p><b>New:</b> started selling recently, too early to score.
        <b>Lapsed:</b> sold before, but no sales this year.
        <b>Insufficient data:</b> too few sales to judge.</p>
    </div>
  </div>
</div>

<!-- Sales by year -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><span class="card-title">Sales by year (darker = closer to that territory's best year)</span></div>
  <div class="data-table-wrap"><table class="data-table" id="rp-trend"><thead id="rp-trend-head"></thead><tbody id="rp-trend-body"></tbody></table></div>
</div>

<!-- Territory detail -->
<div class="modal-overlay" id="modal-territory">
  <div class="modal" style="max-width:640px">
    <div class="modal-header"><span class="modal-title" id="rp-modal-title">Territory</span><button class="modal-close" onclick="closeModal('modal-territory')">✕</button></div>
    <div class="modal-body" id="rp-modal-body"></div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="/inveniq/assets/js/sales-intel.js"></script>
<script>
const SEG = {
  'Strong':             {badge:'badge-success', color:'#16a34a'},
  'Steady':             {badge:'badge-info',    color:'#2563eb'},
  'Untapped potential': {badge:'badge-orange',  color:'#ea580c'},
  'Weak':               {badge:'badge-warning', color:'#d97706'},
  'New':                {badge:'badge-purple',  color:'#7c3aed'},
  'Lapsed':             {badge:'badge-danger',  color:'#dc2626'},
  'Insufficient data':  {badge:'badge-muted',   color:'#94a3b8'},
};
const FACTORS = [['revenue','Sales'],['margin','Margin'],['growth','Sales trend'],['customers','Customers'],['products','Product range']];
let RP = null;

function segBadge(s){return `<span class="badge ${(SEG[s]||SEG['Insufficient data']).badge}">${esc(s||'—')}</span>`;}
function heatCell(text,score){const s=num(score);
  return `<td class="right font-mono si-heat" style="${heat(s)}">${text}</td>`;}

async function loadRP(){
  const banner=document.getElementById('rp-banner');
  let data;
  try{ data=await fetch('/inveniq/api/index.php?action=regional_performance').then(r=>r.json()); }
  catch(e){ banner.style.borderLeftColor='var(--danger)'; banner.innerHTML='<span class="badge badge-danger">Could not reach API</span>'; return; }
  if(!data||!data.available){
    noResultsBanner(banner);
    ['m1','m2','m3','m4'].forEach(id=>document.getElementById('rp-'+id).classList.remove('loading'));
    document.getElementById('rp_count').textContent='No results';
    return;
  }
  RP=data;
  const run=data.run, terr=data.territories;
  const active=terr.filter(t=>t.status==='Active');
  banner.style.borderLeftColor='var(--success)';
  banner.innerHTML=`<div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center">
    <span class="badge badge-success">Latest year: FY${esc(run.fy)}</span>
    <span>Based on sales from <b>${fmtMonth(run.data_from)}</b> to <b>${fmtMonth(run.data_to)}</b></span>
    <span><b>${active.length}</b> of <b>${terr.length}</b> territories active</span>
    <span style="color:var(--text-3)">updated ${esc(run.run_at)}</span></div>`;

  const totalRev=terr.reduce((s,t)=>s+Math.max(0,num(t.revenue)),0);
  const strong=terr.filter(t=>t.segment==='Strong');
  const untapped=terr.filter(t=>t.segment==='Untapped potential');
  const lapsed=terr.filter(t=>t.status==='Lapsed');
  const strongShare=strong.reduce((s,t)=>s+num(t.revenue),0)/totalRev*100;
  setMetric('rp-m1',strong.length,`${strongShare.toFixed(0)}% of all sales`);
  setMetric('rp-m2',untapped.length,untapped.map(t=>t.territory).join(', ')||'none');
  setMetric('rp-m3',lapsed.length,`no sales this year; ${fmtINR(lapsed.reduce((s,t)=>s+num(t.peak_revenue),0))} in their best years`);
  const top=terr.reduce((b,t)=>num(t.revenue)>num(b.revenue)?t:b);
  setMetric('rp-m4',(num(top.revenue)/totalRev*100).toFixed(0)+'%',`of all sales come from ${top.territory}`);

  renderMap(terr); renderRanking(active); renderHQ(data.hq);
  renderOther(terr.filter(t=>t.status!=='Active')); renderTrend(terr,data.yearly);
  onRowClick('rp-tbody',openTerritory); onRowClick('rp-other',openTerritory);
}

function renderMap(terr){
  if(typeof L==='undefined'){document.getElementById('rp-map').innerHTML='<div class="card-body si-mini">Map library could not load (offline?).</div>';return;}
  const map=L.map('rp-map',{scrollWheelZoom:false,zoomSnap:0.25});
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:12,
    attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'}).addTo(map);
  const pts=[];
  const maxRev=Math.max(...terr.map(t=>Math.max(num(t.revenue)||0,num(t.peak_revenue)||0)));
  terr.forEach(t=>{
    if(t.lat===null||t.lon===null)return;
    const lapsed=t.status==='Lapsed';
    const size=lapsed?num(t.peak_revenue):Math.max(num(t.revenue),0);
    const r=4+26*Math.sqrt((size||0)/maxRev);
    const c=(SEG[t.segment]||SEG['Insufficient data']).color;
    const mk=L.circleMarker([num(t.lat),num(t.lon)],{radius:r,color:c,weight:2,fillColor:c,
      fillOpacity:lapsed?0.08:0.45,dashArray:lapsed?'4 3':null}).addTo(map);
    mk.bindTooltip(`<b>${esc(t.territory)}</b> · ${esc(t.segment)}<br>${lapsed?'best year '+fmtINR(t.peak_revenue):fmtINR(t.revenue)}`);
    mk.on('click',()=>openTerritory(t.location_code));
    pts.push([num(t.lat),num(t.lon)]);
  });
  if(pts.length)map.fitBounds(pts,{padding:[24,24]});
}

function renderRanking(active){
  document.getElementById('rp-tbody').innerHTML=active.map(t=>`
    <tr class="data-row si-row" data-search="${esc([t.territory,t.hq,t.segment].join(' ').toLowerCase())}" data-key="${esc(t.location_code)}">
      <td class="font-mono fw-bold">${num(t.composite_rank)}</td>
      <td><span class="si-name">${esc(t.territory)}</span><div class="text-muted text-xs">${esc(t.hq)} HQ</div></td>
      <td>${segBadge(t.segment)}</td>
      ${heatCell(fmtL(t.revenue),t.score_revenue)}
      ${heatCell(fmtPct(t.margin_pct),t.score_margin)}
      ${heatCell(fmtPct(t.growth_cagr_pct,1,true),t.score_growth)}
      ${heatCell(num(t.customers),t.score_customers)}
      ${heatCell(num(t.products),t.score_products)}
      <td class="right font-mono fw-bold">${num(t.composite_score).toFixed(1)}</td>
      <td class="right font-mono">${num(t.revenue_rank)}</td>
    </tr>`).join('');
  document.getElementById('rp_count').textContent=active.length+' rows';
}

function renderHQ(hq){
  document.getElementById('rp-hq').innerHTML=hq.map(h=>`
    <tr><td class="si-heat"><span class="text-muted font-mono">${h.composite_rank??'–'}.</span> <span class="si-name">${esc(h.hq)}</span>
        <div class="text-muted text-xs">${h.territories_active??0} of ${h.territories_total??0} territories active</div></td>
      <td>${segBadge(h.segment)}</td>
      <td class="right font-mono si-heat">${fmtL(h.revenue)}</td>
      <td class="right font-mono si-heat">${fmtPct(h.revenue_share_pct)}</td>
      <td class="right font-mono si-heat">${fmtPct(h.growth_cagr_pct,1,true)}</td>
      <td class="right font-mono fw-bold">${h.composite_score===null?'—':num(h.composite_score).toFixed(1)}</td></tr>`).join('');
}

function renderOther(rows){
  const order={'Lapsed':0,'New':1,'Insufficient data':2};
  rows.sort((a,b)=>(order[a.status]-order[b.status])||(num(b.peak_revenue)-num(a.peak_revenue)));
  document.getElementById('rp-other').innerHTML=rows.map(t=>`
    <tr class="si-row" data-key="${esc(t.location_code)}">
      <td><span class="si-name">${esc(t.territory)}</span><div class="text-muted text-xs">${esc(t.hq)} HQ</div></td>
      <td>${segBadge(t.status)}</td>
      <td class="font-mono">${fmtINR(t.peak_revenue)} <span class="text-muted text-xs">FY${esc(t.peak_fy)}</span></td>
      <td class="font-mono">${fmtMonth(t.last_sale)}</td></tr>`).join('');
}

function renderTrend(terr,yearly){
  const fys=[...new Set(yearly.map(y=>y.fy))].sort();
  const scored=fys.filter(fy=>yearly.some(y=>y.fy===fy&&y.segment));
  const by={}; yearly.forEach(y=>{(by[y.location_code]=by[y.location_code]||{})[y.fy]=y;});
  document.getElementById('rp-trend-head').innerHTML=`<tr><th>Territory</th>${fys.map(f=>`<th class="right">${esc(f)}</th>`).join('')}
    ${scored.map(f=>`<th>Status ${esc(f)}</th>`).join('')}</tr>`;
  const rows=terr.filter(t=>num(t.lifetime_revenue)>=1e5);
  document.getElementById('rp-trend-body').innerHTML=rows.map(t=>{
    const yr=by[t.location_code]||{};
    const peak=Math.max(...fys.map(f=>num((yr[f]||{}).revenue)||0));
    const cells=fys.map(f=>{const v=num((yr[f]||{}).revenue);
      if(!v||v<=0)return '<td class="right text-muted">—</td>';
      const a=peak>0?Math.max(0,v/peak):0;
      return `<td class="right font-mono" style="background:rgba(37,99,235,${(a*0.32).toFixed(3)})" title="${(a*100).toFixed(0)}% of best year">${fmtL(v)}</td>`;}).join('');
    const segs=scored.map(f=>`<td>${(yr[f]||{}).segment?segBadge(yr[f].segment):'<span class="text-muted">—</span>'}</td>`).join('');
    return `<tr><td><span class="si-name">${esc(t.territory)}</span><div class="text-muted text-xs">${esc(t.hq)} HQ</div></td>${cells}${segs}</tr>`;
  }).join('');
}

function factorBars(t){
  return FACTORS.map(([k,l])=>{const v=num(t['score_'+k]);if(v===null)return '';
    const clr=v>=75?'var(--success)':(v<=25?'var(--danger)':'var(--warning)');
    return `<div style="display:flex;align-items:center;gap:10px;margin:4px 0;font-size:12px">
      <span style="width:120px;color:var(--text-2)">${l}</span>
      <div class="score-wrap" style="flex:1"><div class="score-bar"><div class="score-fill" style="width:${v}%;background:${clr}"></div></div>
      <span class="score-val">${v.toFixed(0)}</span></div></div>`;}).join('');
}

function openTerritory(code){
  const t=(RP?.territories||[]).find(x=>x.location_code===code); if(!t)return;
  document.getElementById('rp-modal-title').innerHTML=`${esc(t.territory)} <span class="text-muted" style="font-weight:400">· ${esc(t.hq)} HQ</span>`;
  const isActive=t.status==='Active';
  document.getElementById('rp-modal-body').innerHTML=`
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">${segBadge(t.segment)}
      ${isActive?`<span class="si-mini">score ${num(t.composite_score).toFixed(0)} out of 100 · number ${num(t.composite_rank)} overall</span>`:''}</div>
    <ul class="si-reasons">${(t.reasons||[]).map(r=>`<li>${esc(r)}</li>`).join('')}</ul>
    ${isActive?`<div class="si-mini" style="margin-top:14px">Compared with other territories (out of 100)</div><div style="margin-top:4px">${factorBars(t)}</div>`:''}
    <div class="si-kv">
      <div><span>Sales FY${esc(t.fy)}:</span> ${fmtINR(t.revenue)}</div>
      <div><span>Best year:</span> ${fmtINR(t.peak_revenue)} (FY${esc(t.peak_fy)})</div>
      <div><span>Customers / products:</span> ${num(t.customers)} / ${num(t.products)}</div>
      <div><span>Sales per customer:</span> ${fmtINR(t.revenue_per_customer)}</div>
      <div><span>Customers who came back:</span> ${fmtPct(t.retention_pct,0)}</div>
      <div><span>Returns:</span> ${fmtPct(t.return_rate_pct)}</div>
      <div><span>Normal for its size:</span> ${fmtINR(t.expected_revenue)}</div>
      <div><span>Distance from Coimbatore:</span> ${t.distance_km===null?'—':num(t.distance_km).toFixed(0)+' km'}</div>
      <div><span>Sold between:</span> ${fmtMonth(t.first_sale)} – ${fmtMonth(t.last_sale)}</div>
      <div><span>Total sales so far:</span> ${fmtINR(t.lifetime_revenue)}</div>
    </div>`;
  openModal('modal-territory');
}

document.addEventListener('DOMContentLoaded',loadRP);
</script>
<?php render_layout_close(); ?>
