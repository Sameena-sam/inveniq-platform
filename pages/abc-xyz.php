<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('abc-xyz');
require_once __DIR__ . '/../components/layout.php';
 render_layout_open('abc-xyz'); ?>
<?php page_header('ABC/XYZ Classification','Pareto-based inventory classification with demand variability'); ?>
<div class="metrics-grid">
  <div class="metric-card loading" id="ax-m1"><div class="metric-icon success"><?=icon('bar-chart-3')?></div><div class="metric-body"><div class="metric-label">A-Items (Top 70%)</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
  <div class="metric-card loading" id="ax-m2"><div class="metric-icon warning"><?=icon('bar-chart-3')?></div><div class="metric-body"><div class="metric-label">B-Items (70–90%)</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
  <div class="metric-card loading" id="ax-m3"><div class="metric-icon info"><?=icon('bar-chart-3')?></div><div class="metric-body"><div class="metric-label">C-Items (90–100%)</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
</div>
<div class="card"><div class="table-toolbar"><input class="search-input" id="ax_search" placeholder="Search…" oninput="filterTable('ax')"><span class="table-count" id="ax_count">Loading…</span></div>
<div class="data-table-wrap"><table class="data-table" id="ax_table">
<thead><tr><th>SKU</th><th>Product</th><th>Group</th><th>Annual Revenue</th><th>Revenue %</th><th>Daily Sales</th><th>Coeff. Var.</th><th>ABC</th><th>XYZ</th><th>Class</th></tr></thead>
<tbody id="ax-tbody"></tbody></table></div></div>
<script>
const abcBadge={A:'<span class="abc-A">A</span>',B:'<span class="abc-B">B</span>',C:'<span class="abc-C">C</span>'};
const xyzBadge={X:'<span class="xyz-X">X</span>',Y:'<span class="xyz-Y">Y</span>',Z:'<span class="xyz-Z">Z</span>'};
async function loadAX(){const data=await fetch('/inveniq/api/index.php?action=abc_xyz').then(r=>r.json());const a=data.filter(r=>r.abc==='A'),b=data.filter(r=>r.abc==='B'),c=data.filter(r=>r.abc==='C');[['m1',a.length,'₹'+a.reduce((s,r)=>s+Number(r.annual_revenue),0).toLocaleString('en-IN',{maximumFractionDigits:0})+' revenue'],['m2',b.length,'₹'+b.reduce((s,r)=>s+Number(r.annual_revenue),0).toLocaleString('en-IN',{maximumFractionDigits:0})+' revenue'],['m3',c.length,null]].forEach(([id,v,sub])=>{const el=document.getElementById('ax-'+id);el.classList.remove('loading');el.querySelector('.metric-value').textContent=v;if(sub&&el.querySelector('.metric-sub'))el.querySelector('.metric-sub').textContent=sub;});document.getElementById('ax-tbody').innerHTML=data.map(r=>`<tr class="data-row" data-search="${[r.sku,r.description,r.product_group].join(' ').toLowerCase()}"><td class="mono">${r.sku}</td><td class="bold">${r.description}</td><td class="text-muted text-xs">${r.product_group||''}</td><td class="right font-mono">₹${Number(r.annual_revenue).toLocaleString('en-IN',{minimumFractionDigits:2})}</td><td class="right font-mono">${Number(r.revenue_pct).toFixed(1)}%</td><td class="right font-mono">${Number(r.daily_sales).toFixed(2)}</td><td class="right font-mono">${Number(r.cv).toFixed(2)}</td><td>${abcBadge[r.abc]||r.abc}</td><td>${xyzBadge[r.xyz]||r.xyz}</td><td class="fw-bold" style="font-size:15px">${r.class}</td></tr>`).join('');document.getElementById('ax_count').textContent=data.length+' rows';}
document.addEventListener('DOMContentLoaded',loadAX);
</script>
<?php render_layout_close(); ?>