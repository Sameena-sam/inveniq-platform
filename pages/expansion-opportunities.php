<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('expansion-opportunities');
require_once __DIR__ . '/../components/layout.php';
render_layout_open('expansion-opportunities'); ?>
<?php page_header('Expansion Opportunities',
    'Where to grow next and with which categories, based on what similar territories already sell'); ?>

<link rel="stylesheet" href="/inveniq/assets/css/sales-intel.css">
<style>
  .eo-matrix th.eo-seg{writing-mode:vertical-rl;transform:rotate(180deg);height:130px;white-space:nowrap;padding:6px 4px;text-align:left}
  .eo-matrix td.eo-cell{text-align:center;padding:6px 4px;font-family:var(--mono);font-size:12px;min-width:36px}
  .eo-matrix td.eo-cell[data-key]{cursor:pointer;color:var(--text)}
  .eo-matrix td.eo-cell[data-key]:hover{outline:2px solid var(--primary);outline-offset:-2px}
  .eo-why{font-size:12px;color:var(--text-2);line-height:1.5;min-width:260px}
  #eo-plan .eo-why{min-width:150px}
</style>

<!-- Data status banner -->
<div id="eo-banner" class="card" style="padding:14px 16px;margin-bottom:14px;border-left:4px solid var(--text-3)">
  <span class="metric-label">Loading results…</span>
</div>

<div class="metrics-grid">
  <div class="metric-card loading" id="eo-m1"><div class="metric-icon success"><?=icon('target')?></div><div class="metric-body"><div class="metric-label">Top Opportunity</div><div class="metric-value" style="font-size:17px">—</div><div class="metric-sub">—</div></div></div>
  <div class="metric-card loading" id="eo-m2"><div class="metric-icon info"><?=icon('trending-up')?></div><div class="metric-body"><div class="metric-label">Top 15 Could Add</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
  <div class="metric-card loading" id="eo-m3"><div class="metric-icon warning"><?=icon('map-pin')?></div><div class="metric-body"><div class="metric-label">Territories To Win Back</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
</div>

<!-- Shortlist -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><span class="card-title">Top 15 opportunities</span><span class="si-mini">click a row for the reasons</span></div>
  <div class="data-table-wrap"><table class="data-table">
    <thead><tr><th>#</th><th>Territory</th><th>Category</th><th>Score</th><th>Possible extra sales / yr</th><th>Why</th></tr></thead>
    <tbody id="eo-short"></tbody></table></div>
</div>

<!-- Expansion plan -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><span class="card-title">Expansion plan for the top 15</span><span class="si-mini">click a row for the full customer list</span></div>
  <div class="data-table-wrap"><table class="data-table">
    <thead><tr><th>#</th><th>Where and what</th><th>Sales to aim for / yr</th><th>Start with these customers</th><th>Offer these products</th><th>Where the sales could come from</th></tr></thead>
    <tbody id="eo-plan"></tbody></table></div>
</div>

<!-- Matrix -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><span class="card-title">All opportunities by territory and category</span><span class="si-mini">darker = stronger opportunity · blank = no meaningful gap · click a number</span></div>
  <div class="data-table-wrap"><table class="data-table eo-matrix si-compact"><thead id="eo-matrix-head"></thead><tbody id="eo-matrix"></tbody></table></div>
</div>

<div class="two-col-grid">
  <div class="card">
    <div class="card-header"><span class="card-title">Territories to win back</span><span class="si-mini">sold before, no sales this year</span></div>
    <div class="data-table-wrap"><table class="data-table si-compact">
      <thead><tr><th>Territory</th><th>Best year</th><th>Last sale</th><th>What they bought</th></tr></thead>
      <tbody id="eo-win"></tbody></table></div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">How to read this page</span></div>
    <div class="card-body si-method">
      <p><b>Score out of 100.</b> Room to grow in this category (up to 35), how valuable and stable the category is (25),
        whether the territory has room to grow overall (15), its sales trend (15) and its margin (10).</p>
      <p><b>Possible extra sales.</b> What the territory would add if this category made up the same share of its sales
        as it usually does in other territories. It is a guide to the size of the gap, not a forecast.</p>
      <p><b>Customers to start with</b> already buy from you in that territory but not this category. Those who bought it in earlier years come first,
        then the biggest customers.</p>
      <p><b>Where the sales could come from.</b> Existing customers: the number of them, times what a typical buyer of this category spends in other territories.
        Anything left would need new customers. It is a rough guide for planning, not a promise.</p>
      <p><b>Coimbatore</b> is not scored: it is much larger than the others, so comparing it with them would mislead.</p>
      <p><b>Before acting,</b> check what these numbers cannot see: local competition, which doctors and pharmacies are nearby,
        and whether the team can cover it. The decision stays with you.</p>
    </div>
  </div>
</div>

<!-- Detail -->
<div class="modal-overlay" id="modal-eo">
  <div class="modal" style="max-width:640px">
    <div class="modal-header"><span class="modal-title" id="eo-modal-title">Opportunity</span><button class="modal-close" onclick="closeModal('modal-eo')">✕</button></div>
    <div class="modal-body" id="eo-modal-body"></div>
  </div>
</div>

<script src="/inveniq/assets/js/sales-intel.js"></script>
<script>
const PARTS=[['points_gap','Room to grow',35],['points_category','Category value and stability',25],
             ['points_underserved','Territory has room to grow',15],['points_momentum','Sales trend',15],['points_margin','Margin',10]];
const SHORTLIST=15;
let EO=null;

function cellKey(c){return c.location_code+'|'+c.segment;}

async function loadEO(){
  const banner=document.getElementById('eo-banner');
  let data;
  try{ data=await fetch('/inveniq/api/index.php?action=expansion_opportunities').then(r=>r.json()); }
  catch(e){ banner.style.borderLeftColor='var(--danger)'; banner.innerHTML='<span class="badge badge-danger">Could not reach API</span>'; return; }
  if(!data||!data.available){
    noResultsBanner(banner);
    ['m1','m2','m3'].forEach(id=>document.getElementById('eo-'+id).classList.remove('loading'));
    return;
  }
  EO=data;
  const run=data.run, cells=data.cells, short=cells.slice(0,SHORTLIST);
  banner.style.borderLeftColor='var(--success)';
  banner.innerHTML=`<div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center">
    <span class="badge badge-success">Latest year: FY${esc(run.fy)}</span>
    <span>Based on sales from <b>${fmtMonth(run.data_from)}</b> to <b>${fmtMonth(run.data_to)}</b></span>
    <span><b>${cells.length}</b> opportunities found</span>
    <span style="color:var(--text-3)">updated ${esc(run.run_at)}</span></div>`;
  const t=cells[0], gapTotal=short.reduce((s,c)=>s+num(c.gap_value),0), wins=data.win_back;
  setMetric('eo-m1',t?`${t.territory}, ${t.segment}`:'None',t?`about ${fmtINR(t.gap_value)} a year; score ${num(t.score).toFixed(0)} out of 100`:'');
  setMetric('eo-m2',fmtINR(gapTotal),'a year, if every one of them matched similar territories');
  const open=wins.filter(w=>!Number(w.by_choice)), chosen=wins.length-open.length;
  setMetric('eo-m3',open.length,`${fmtINR(open.reduce((s,w)=>s+num(w.best_sales),0))} in their best years`+(chosen?`; ${chosen} you chose to leave not counted`:''));
  renderShort(short); renderPlan(short); renderMatrix(cells); renderWin(wins);
  onRowClick('eo-short',openCell); onRowClick('eo-plan',openCell); onRowClick('eo-matrix',openCell);
}

function firstFew(names,extra){
  const shown=names.slice(0,3).map(esc).join(', '), more=names.length-3;
  return more>0?`${shown} <span class="text-muted">and ${more} more</span>`:(shown||extra);
}

function renderPlan(short){
  document.getElementById('eo-plan').innerHTML=short.map(c=>{const p=c.action_plan;
    return `<tr class="si-row" data-key="${esc(cellKey(c))}">
      <td class="font-mono fw-bold">${num(c.rank)}</td>
      <td class="si-heat"><span class="si-name">${esc(c.territory)}</span><div class="text-muted text-xs">${esc(c.segment)}</div></td>
      <td class="right font-mono si-heat">${fmtINR(c.gap_value)}</td>
      <td class="eo-why">${firstFew(p.customers.map(x=>x.name),'<span class="text-muted">No existing customers to approach</span>')}</td>
      <td class="eo-why">${firstFew(p.products.map(x=>x.name),'—')}</td>
      <td class="text-xs si-heat">Existing customers: <b>${fmtINR(p.from_existing)}</b><div>New customers: <b>${fmtINR(p.from_new)}</b></div></td></tr>`;}).join('');
}

function planDetail(c){
  const p=c.action_plan;
  const who=p.customers.length?`<div class="data-table-wrap" style="max-height:220px;overflow-y:auto"><table class="data-table si-compact">
      <thead><tr><th>Customer</th><th>Buys from you now / yr</th><th></th></tr></thead>
      <tbody>${p.customers.map(x=>`<tr><td>${esc(x.name)}</td><td class="right font-mono">${fmtINR(x.spend)}</td>
        <td class="text-xs">${x.used_to_buy?'<span class="badge badge-warning">Used to buy this</span>':''}</td></tr>`).join('')}</tbody></table></div>`
    :'<p class="text-xs">Every customer here already buys this category, so growth would need new customers or larger orders.</p>';
  return `<div class="si-mini">The plan</div>
    <p style="font-size:13px;margin:6px 0 12px">Aim for about <b>${fmtINR(c.gap_value)}</b> more a year: about <b>${fmtINR(p.from_existing)}</b> from existing customers
      (a typical buyer of ${esc(c.segment)} spends ${fmtINR(p.typical_spend)} a year elsewhere) and <b>${fmtINR(p.from_new)}</b> from new customers.</p>
    <div class="si-mini">Offer these products</div>
    <ul class="si-reasons" style="margin:6px 0 12px">${p.products.map(x=>`<li>${esc(x.name)}${x.new_here?' <span class="text-muted">(not sold here yet)</span>':''}</li>`).join('')}</ul>
    <div class="si-mini" style="margin-bottom:6px">Customers to approach (${p.customers.length})</div>${who}
    <div class="si-mini" style="margin-top:14px">Why this opportunity</div>`;
}

function renderShort(short){
  document.getElementById('eo-short').innerHTML=short.map(c=>`
    <tr class="si-row" data-key="${esc(cellKey(c))}">
      <td class="font-mono fw-bold">${num(c.rank)}</td>
      <td><span class="si-name">${esc(c.territory)}</span><div class="text-muted text-xs">${esc(c.hq)} HQ</div></td>
      <td class="si-heat"><span class="si-name">${esc(c.segment)}</span><div class="text-muted text-xs">${esc(c.seg_label)}</div></td>
      <td class="right font-mono fw-bold" style="${heat(num(c.score))}">${num(c.score).toFixed(0)}</td>
      <td class="right font-mono si-heat">${fmtINR(c.gap_value)}</td>
      <td class="eo-why">${esc(c.reasons[0]||'')}</td></tr>`).join('');
}

function renderMatrix(cells){
  const segs=[...new Set(cells.map(c=>c.segment))].sort();
  const best={}; cells.forEach(c=>{best[c.territory]=Math.max(best[c.territory]||0,num(c.score));});
  const terrs=Object.keys(best).sort((a,b)=>best[b]-best[a]);
  const at={}; cells.forEach(c=>{at[c.territory+'|'+c.segment]=c;});
  document.getElementById('eo-matrix-head').innerHTML=`<tr><th>Territory</th>${segs.map(s=>`<th class="eo-seg">${esc(s)}</th>`).join('')}</tr>`;
  document.getElementById('eo-matrix').innerHTML=terrs.map(t=>`<tr><td class="si-name si-heat">${esc(t)}</td>${segs.map(s=>{
    const c=at[t+'|'+s]; if(!c)return '<td class="eo-cell"></td>';
    return `<td class="eo-cell" data-key="${esc(cellKey(c))}" style="background:rgba(22,163,74,${(num(c.score)/100*0.55).toFixed(3)})" title="${esc(t)}, ${esc(s)}">${num(c.score).toFixed(0)}</td>`;}).join('')}</tr>`).join('');
}

function renderWin(wins){
  document.getElementById('eo-win').innerHTML=wins.map(w=>`
    <tr><td><span class="si-name">${esc(w.territory)}</span><div class="text-muted text-xs">${esc(w.hq)} HQ</div>
      ${w.why_stopped?`<span class="badge ${Number(w.by_choice)?'badge-muted':'badge-warning'}">${esc(w.why_stopped)}</span>`:''}</td>
      <td class="font-mono si-heat">${fmtINR(w.best_sales)}<div class="text-muted text-xs">FY${esc(w.best_fy)}</div></td>
      <td class="font-mono si-heat">${fmtMonth(w.last_sale)}</td>
      <td class="text-xs">${esc(w.bought)}</td></tr>`).join('');
}

function openCell(key){
  const c=(EO?.cells||[]).find(x=>cellKey(x)===key); if(!c)return;
  document.getElementById('eo-modal-title').innerHTML=`${esc(c.territory)}, ${esc(c.segment)} <span class="text-muted" style="font-weight:400">· number ${num(c.rank)}</span>`;
  const bars=PARTS.map(([k,l,max])=>{const v=num(c[k]);
    return `<div style="display:flex;align-items:center;gap:10px;margin:4px 0;font-size:12px">
      <span style="width:190px;color:var(--text-2)">${l}</span>
      <div class="score-wrap" style="flex:1"><div class="score-bar"><div class="score-fill" style="width:${v/max*100}%;background:var(--primary)"></div></div>
      <span class="score-val">${v.toFixed(0)} of ${max}</span></div></div>`;}).join('');
  document.getElementById('eo-modal-body').innerHTML=`${planDetail(c)}
    <ul class="si-reasons">${c.reasons.map(r=>`<li>${esc(r)}</li>`).join('')}</ul>
    <div class="si-mini" style="margin-top:14px">Score: ${num(c.score).toFixed(0)} out of 100</div>
    <div style="margin-top:4px">${bars}</div>`;
  openModal('modal-eo');
}

document.addEventListener('DOMContentLoaded',loadEO);
</script>
<?php render_layout_close(); ?>
