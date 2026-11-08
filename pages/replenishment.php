<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('replenishment');
require_once __DIR__ . '/../components/layout.php';
 render_layout_open('replenishment'); ?>
<?php page_header('Replenishment Plan','Items below reorder point requiring immediate action'); ?>
<div class="metrics-grid">
  <div class="metric-card loading" id="re-m1"><div class="metric-icon warning"><?=icon('shopping-cart')?></div><div class="metric-body"><div class="metric-label">Items to Reorder</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="re-m2"><div class="metric-icon danger"><?=icon('alert-triangle')?></div><div class="metric-body"><div class="metric-label">Critical (Stockout)</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="re-m3"><div class="metric-icon info"><?=icon('bar-chart-3')?></div><div class="metric-body"><div class="metric-label">Total Order Value</div><div class="metric-value">—</div></div></div>
</div>
<div class="card"><div class="table-toolbar"><input class="search-input" id="re_search" placeholder="Search…" oninput="filterTable('re')"><span class="table-count" id="re_count">Loading…</span></div>
<div class="data-table-wrap"><table class="data-table" id="re_table">
<thead><tr><th>SKU</th><th>Product</th><th>Supplier</th><th>Current Stock</th><th>Reorder Pt.</th><th>Order Qty</th><th>Order Value</th><th>Lead Time</th><th>Urgency</th></tr></thead>
<tbody id="re-tbody"></tbody></table></div></div>
<script>
const urgBadge={critical:'<span class="badge badge-danger">Critical</span>',high:'<span class="badge badge-warning">High</span>',medium:'<span class="badge badge-info">Medium</span>',ok:'<span class="badge badge-success">OK</span>'};
async function loadRe(){const data=await fetch('/inveniq/api/index.php?action=reorder_points').then(r=>r.json());const plan=data.filter(r=>r.urgency!=='ok').sort((a,b)=>({critical:0,high:1,medium:2}[a.urgency]||3)-({critical:0,high:1,medium:2}[b.urgency]||3));const tv=plan.reduce((s,r)=>s+Number(r.suggested_value),0);[['m1',plan.length],['m2',plan.filter(r=>r.urgency==='critical').length],['m3','₹'+tv.toLocaleString('en-IN',{maximumFractionDigits:0})]].forEach(([id,v])=>{const el=document.getElementById('re-'+id);el.classList.remove('loading');el.querySelector('.metric-value').textContent=v;});document.getElementById('re-tbody').innerHTML=plan.map(r=>`<tr class="data-row" data-search="${[r.sku,r.description,r.supplier_name].join(' ').toLowerCase()}"><td class="mono">${r.sku}</td><td class="bold">${r.description}</td><td>${r.supplier_name}</td><td class="right font-mono">${Number(r.current_stock).toLocaleString()}</td><td class="right font-mono">${Number(r.reorder_point).toLocaleString()}</td><td class="right font-mono fw-bold">${Number(r.suggested_qty).toLocaleString()}</td><td class="right font-mono">₹${Number(r.suggested_value).toLocaleString('en-IN',{minimumFractionDigits:2})}</td><td class="right font-mono">${r.lead_time_days}d</td><td>${urgBadge[r.urgency]||''}</td></tr>`).join('');document.getElementById('re_count').textContent=plan.length+' rows';}
document.addEventListener('DOMContentLoaded',loadRe);
</script>
<?php render_layout_close(); ?>
