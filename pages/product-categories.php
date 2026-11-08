<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('product-categories');
require_once __DIR__ . '/../components/layout.php';
render_layout_open('product-categories'); ?>
<?php page_header('Product Categories',
    'Which products and segments bring in the most sales, how steadily they sell, and what changed this year'); ?>

<link rel="stylesheet" href="/inveniq/assets/css/sales-intel.css">
<style>
  .pc-matrix{width:100%;border-collapse:separate;border-spacing:6px}
  .pc-matrix th{font-size:11px;color:var(--text-2);font-weight:600;padding:4px}
  .pc-cell{border:1px solid var(--border);border-radius:var(--radius);padding:10px;text-align:center;min-width:90px}
  .pc-cell .n{font-size:20px;font-weight:700;color:var(--text);line-height:1.2}
  .pc-cell .s{font-size:11px;color:var(--text-2)}
  .pc-cls{white-space:nowrap}
  .pc-cls span{margin-right:2px}
</style>

<!-- Data status banner -->
<div id="pc-banner" class="card" style="padding:14px 16px;margin-bottom:14px;border-left:4px solid var(--text-3)">
  <span class="metric-label">Loading results…</span>
</div>

<div class="metrics-grid">
  <div class="metric-card loading" id="pc-m1"><div class="metric-icon success"><?=icon('bar-chart-3')?></div><div class="metric-body"><div class="metric-label">Top Sellers</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
  <div class="metric-card loading" id="pc-m2"><div class="metric-icon info"><?=icon('target')?></div><div class="metric-body"><div class="metric-label">Reliable Best-Sellers</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
  <div class="metric-card loading" id="pc-m3"><div class="metric-icon warning"><?=icon('alert-triangle')?></div><div class="metric-body"><div class="metric-label">On The Watch List</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
</div>

<!-- Watch list -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><span class="card-title">Watch list: what changed this year</span><span class="si-mini">biggest change in sales first</span></div>
  <div class="data-table-wrap"><table class="data-table">
    <thead><tr><th>Product</th><th>What changed</th><th>Details</th></tr></thead>
    <tbody id="pc-watch"></tbody></table></div>
</div>

<div class="two-col-grid">
  <div class="card">
    <div class="card-header"><span class="card-title" id="pc-matrix-title">Products by group</span><span class="si-mini">number of products · share of sales</span></div>
    <div class="card-body">
      <table class="pc-matrix" id="pc-matrix"></table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Therapeutic segments</span><span class="si-mini">click for details</span></div>
    <div class="data-table-wrap"><table class="data-table si-compact">
      <thead><tr><th>Segment</th><th>Group</th><th>Share</th><th title="Share of the segment's sales from products with steady demand">Steady</th><th>Trend / yr</th></tr></thead>
      <tbody id="pc-seg"></tbody></table></div>
  </div>
</div>

<!-- Products -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><span class="card-title" id="pc-prod-title">Products</span><span class="si-mini">click a row for details</span></div>
  <div class="table-toolbar"><input class="search-input" id="pc_search" placeholder="Search product, segment or group…" oninput="filterTable('pc')"><span class="table-count" id="pc_count">Loading…</span></div>
  <div class="data-table-wrap"><table class="data-table" id="pc_table">
    <thead><tr>
      <th onclick="sortTable('pc',0)">Product</th><th onclick="sortTable('pc',1)">Segment</th><th onclick="sortTable('pc',2)">Group</th>
      <th onclick="sortTable('pc',3)">Sales</th><th onclick="sortTable('pc',4)">Share</th><th onclick="sortTable('pc',5)">Demand</th>
      <th onclick="sortTable('pc',6)">Months sold</th><th onclick="sortTable('pc',7)">Margin</th><th onclick="sortTable('pc',8)">vs last year</th>
    </tr></thead>
    <tbody id="pc-tbody"></tbody></table></div>
</div>

<div class="card" style="margin-bottom:20px">
  <div class="card-header"><span class="card-title">How to read this page</span></div>
  <div class="card-body si-method">
    <p><b>Sales group.</b> Products are ranked by sales for the year. <b>A, top sellers:</b> the products that together make the first 70% of sales.
      <b>B, mid sellers:</b> the next 20%. <b>C, low sellers:</b> the last 10%.</p>
    <p><b>Demand.</b> How evenly a product sells across the 12 months. <b>X, steady:</b> little change from month to month.
      <b>Y, variable:</b> some ups and downs. <b>Z, irregular:</b> big swings, or sold in only a few months.</p>
    <p><b>Watch list.</b> Products that moved between sales groups, whose sales rose or fell by half or more, or that started or stopped selling this year.
      Products selling under ₹1 lakh are listed only if they changed sales group.</p>
  </div>
</div>

<!-- Detail -->
<div class="modal-overlay" id="modal-pc">
  <div class="modal" style="max-width:660px">
    <div class="modal-header"><span class="modal-title" id="pc-modal-title">Product</span><button class="modal-close" onclick="closeModal('modal-pc')">✕</button></div>
    <div class="modal-body" id="pc-modal-body"></div>
  </div>
</div>

<script src="/inveniq/assets/js/sales-intel.js"></script>
<script>
const ABC={A:'Top sellers',B:'Mid sellers',C:'Low sellers'}, XYZ={X:'Steady',Y:'Variable',Z:'Irregular'};
const ABC_ONE={A:'Top seller',B:'Mid seller',C:'Low seller'};
const WATCH_BADGE={'Moved up':'badge-success','Sales jumped':'badge-success','New this year':'badge-purple',
                   'Moved down':'badge-warning','Sales dropped':'badge-warning','Stopped selling':'badge-danger'};
let PC=null;

function clsBadge(c){if(!c)return '—';return `<span class="pc-cls"><span class="abc-${esc(c[0])}">${esc(c[0])}</span><span class="xyz-${esc(c[1])}">${esc(c[1])}</span></span>`;}

async function loadPC(){
  const banner=document.getElementById('pc-banner');
  let data;
  try{ data=await fetch('/inveniq/api/index.php?action=product_categories').then(r=>r.json()); }
  catch(e){ banner.style.borderLeftColor='var(--danger)'; banner.innerHTML='<span class="badge badge-danger">Could not reach API</span>'; return; }
  if(!data||!data.available){
    noResultsBanner(banner);
    ['m1','m2','m3'].forEach(id=>document.getElementById('pc-'+id).classList.remove('loading'));
    document.getElementById('pc_count').textContent='No results';
    return;
  }
  PC=data;
  const run=data.run, prods=data.products;
  banner.style.borderLeftColor='var(--success)';
  banner.innerHTML=`<div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center">
    <span class="badge badge-success">Latest year: FY${esc(run.fy)}</span>
    <span>Based on sales from <b>${fmtMonth(run.data_from)}</b> to <b>${fmtMonth(run.data_to)}</b></span>
    <span><b>${prods.length}</b> products sold this year</span>
    <span style="color:var(--text-3)">updated ${esc(run.run_at)}</span></div>`;

  const total=prods.reduce((s,p)=>s+Math.max(0,num(p.revenue)),0);
  const share=list=>list.reduce((s,p)=>s+Math.max(0,num(p.revenue)),0)/total*100;
  const a=prods.filter(p=>p.abc==='A'), ax=prods.filter(p=>p.class==='AX');
  setMetric('pc-m1',a.length,`${share(a).toFixed(0)}% of all sales`);
  setMetric('pc-m2',ax.length,`${share(ax).toFixed(0)}% of all sales; top sellers with steady demand`);
  setMetric('pc-m3',data.watch.length,'products that changed a lot this year');

  document.getElementById('pc-matrix-title').textContent=`Products by group · FY${run.fy}`;
  document.getElementById('pc-prod-title').textContent=`Products · FY${run.fy}`;
  renderWatch(data.watch); renderMatrix(prods,total); renderSegments(data.segments); renderProducts(prods);
  onRowClick('pc-seg',openSegment); onRowClick('pc-tbody',openProduct);
}

function renderWatch(watch){
  document.getElementById('pc-watch').innerHTML=watch.map(w=>`
    <tr><td><span class="si-name">${esc(w.product)}</span><div class="text-muted text-xs">${esc(w.segment)}</div></td>
      <td class="si-heat"><span class="badge ${WATCH_BADGE[w.kind]||'badge-muted'}">${esc(w.kind)}</span></td>
      <td>${esc(w.detail)}</td></tr>`).join('');
}

function renderMatrix(prods,total){
  const cell={};
  prods.forEach(p=>{const c=cell[p.class]=cell[p.class]||{n:0,rev:0};c.n++;c.rev+=Math.max(0,num(p.revenue));});
  const maxShare=Math.max(...Object.values(cell).map(c=>c.rev/total));
  let html=`<tr><th></th>${Object.keys(XYZ).map(x=>`<th><span class="xyz-${x}">${x}</span> ${XYZ[x]} demand</th>`).join('')}</tr>`;
  Object.keys(ABC).forEach(a=>{
    html+=`<tr><th style="text-align:left;white-space:nowrap"><span class="abc-${a}">${a}</span> ${ABC[a]}</th>`+Object.keys(XYZ).map(x=>{
      const c=cell[a+x]||{n:0,rev:0}, sh=c.rev/total;
      const bg=c.n?`background:rgba(37,99,235,${(0.05+0.4*sh/maxShare).toFixed(3)})`:'';
      return `<td class="pc-cell" style="${bg}"><div class="n">${c.n}</div><div class="s">${(sh*100).toFixed(1)}%</div></td>`;}).join('')+'</tr>';
  });
  document.getElementById('pc-matrix').innerHTML=html;
}

function renderSegments(segs){
  document.getElementById('pc-seg').innerHTML=segs.map(s=>`
    <tr class="si-row" data-key="${esc(s.segment)}">
      <td><span class="si-name">${esc(s.segment)}</span><div class="text-muted text-xs">${esc(s.label)}</div></td>
      <td>${clsBadge(s.seg_class)}</td>
      <td class="right font-mono si-heat">${fmtPct(s.revenue_share_pct)}</td>
      <td class="right font-mono si-heat">${fmtPct(s.stable_rev_share_pct,0)}</td>
      <td class="right font-mono si-heat">${fmtPct(s.growth_cagr_pct,1,true)}</td></tr>`).join('');
}

function vsLastYear(p){
  if(num(p.is_new))return 'New';
  const g=num(p.rev_growth_pct);       // capped at +300 by the pipeline
  return g>=300?'over +300%':fmtPct(g,0,true);
}

function renderProducts(prods){
  document.getElementById('pc-tbody').innerHTML=prods.map(p=>`
    <tr class="data-row si-row" data-search="${esc([p.product,p.sku,p.segment,ABC[p.abc],XYZ[p.xyz]].join(' ').toLowerCase())}" data-key="${esc(p.sku)}">
      <td><span class="si-name">${esc(p.product)}</span></td>
      <td class="text-xs">${esc(p.segment)}</td>
      <td class="si-heat"><span class="abc-${esc(p.abc)}">${esc(p.abc)}</span> ${ABC_ONE[p.abc]||''}</td>
      <td class="right font-mono si-heat">${fmtL(p.revenue)}</td>
      <td class="right font-mono">${fmtPct(p.rev_share_pct,2)}</td>
      <td class="si-heat"><span class="xyz-${esc(p.xyz)}">${esc(p.xyz)}</span> ${XYZ[p.xyz]||''}</td>
      <td class="right font-mono">${num(p.months_active)}</td>
      <td class="right font-mono">${fmtPct(p.margin_pct)}</td>
      <td class="right font-mono si-heat">${vsLastYear(p)}</td></tr>`).join('');
  document.getElementById('pc_count').textContent=prods.length+' rows';
}

function historyRow(sku){
  return (PC.history||[]).filter(x=>x.sku===sku).map(x=>
    `<div style="text-align:center"><div class="si-mini">${esc(x.fy)}</div>${clsBadge(x.class)}</div>`).join('');
}

function openProduct(sku){
  const p=(PC?.products||[]).find(x=>x.sku===sku); if(!p)return;
  document.getElementById('pc-modal-title').innerHTML=`${esc(p.product)} <span class="text-muted" style="font-weight:400">· ${esc(p.segment)}</span>`;
  document.getElementById('pc-modal-body').innerHTML=`
    <ul class="si-reasons">${(p.reasons||[]).map(r=>`<li>${esc(r)}</li>`).join('')}</ul>
    <div class="si-mini" style="margin-top:14px">Group each year</div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin:6px 0 4px">${historyRow(p.sku)}</div>
    <div class="si-kv">
      <div><span>Sales:</span> ${fmtINR(p.revenue)}</div>
      <div><span>Share of all sales:</span> ${fmtPct(p.rev_share_pct,2)}</div>
      <div><span>Months sold:</span> ${num(p.months_active)} of 12</div>
      <div><span>Margin:</span> ${fmtPct(p.margin_pct)}</div>
      <div><span>vs last year:</span> ${vsLastYear(p)}</div>
      <div><span>Customers / territories:</span> ${num(p.customers)} / ${num(p.territories)}</div>
    </div>`;
  openModal('modal-pc');
}

function openSegment(name){
  const s=(PC?.segments||[]).find(x=>x.segment===name); if(!s)return;
  const prods=(PC.products||[]).filter(p=>p.segment===name);
  document.getElementById('pc-modal-title').innerHTML=`${esc(s.segment)} <span class="text-muted" style="font-weight:400">· ${esc(s.label)}</span>`;
  document.getElementById('pc-modal-body').innerHTML=`
    <ul class="si-reasons">${(s.reasons||[]).map(r=>`<li>${esc(r)}</li>`).join('')}</ul>
    <div class="data-table-wrap" style="margin-top:14px"><table class="data-table si-compact">
      <thead><tr><th>Product</th><th>Group</th><th>Sales</th></tr></thead>
      <tbody>${prods.map(p=>`<tr><td>${esc(p.product)}</td><td>${clsBadge(p.class)}</td><td class="right font-mono">${fmtL(p.revenue)}</td></tr>`).join('')}</tbody>
    </table></div>`;
  openModal('modal-pc');
}

document.addEventListener('DOMContentLoaded',loadPC);
</script>
<?php render_layout_close(); ?>
