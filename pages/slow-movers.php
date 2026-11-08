<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('slow-movers');
require_once __DIR__ . '/../components/layout.php';
 render_layout_open('slow-movers'); ?>
<?php page_header('Slow / Non-Movers','Products with zero or minimal sales in the last 90 days'); ?>
<div class="metrics-grid">
  <div class="metric-card loading" id="sm-m1"><div class="metric-icon warning"><?=icon('package')?></div><div class="metric-body"><div class="metric-label">Non-Moving SKUs</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="sm-m2"><div class="metric-icon danger"><?=icon('bar-chart-3')?></div><div class="metric-body"><div class="metric-label">Capital Tied Up</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="sm-m3"><div class="metric-icon default"><?=icon('trending-down')?></div><div class="metric-body"><div class="metric-label">Avg Stock Value</div><div class="metric-value">—</div></div></div>
</div>
<div class="card"><div class="table-toolbar"><input class="search-input" id="sm_search" placeholder="Search…" oninput="filterTable('sm')"><span class="table-count" id="sm_count">Loading…</span></div>
<div class="data-table-wrap"><table class="data-table" id="sm_table">
<thead><tr><th>SKU</th><th>Product</th><th>Group</th><th>Qty on Hand</th><th>Unit Cost</th><th>Tied-up Value</th><th>Category</th></tr></thead>
<tbody id="sm-tbody"></tbody></table></div></div>
<script>
async function loadSM(){const data=await fetch('/inveniq/api/index.php?action=slow_movers').then(r=>r.json());const tv=data.reduce((s,r)=>s+Number(r.stock_value),0);const avg=data.length?tv/data.length:0;[['m1',data.length],['m2','₹'+tv.toLocaleString('en-IN',{maximumFractionDigits:0})],['m3','₹'+avg.toLocaleString('en-IN',{maximumFractionDigits:0})]].forEach(([id,v])=>{const el=document.getElementById('sm-'+id);el.classList.remove('loading');el.querySelector('.metric-value').textContent=v;});document.getElementById('sm-tbody').innerHTML=data.map(r=>{const bdg=r.category==='non-mover'?'<span class="badge badge-danger">Non-Mover</span>':'<span class="badge badge-warning">Slow Mover</span>';return`<tr class="data-row" data-search="${[r.sku,r.description,r.product_group].join(' ').toLowerCase()}"><td class="mono">${r.sku}</td><td class="bold">${r.description}</td><td class="text-muted text-xs">${r.product_group||''}</td><td class="right font-mono">${Number(r.qty_on_hand).toLocaleString()}</td><td class="right font-mono">₹${Number(r.unit_cost).toFixed(2)}</td><td class="right font-mono fw-semi">₹${Number(r.stock_value).toLocaleString('en-IN',{minimumFractionDigits:2})}</td><td>${bdg}</td></tr>`;}).join('');document.getElementById('sm_count').textContent=data.length+' rows';}
document.addEventListener('DOMContentLoaded',loadSM);
</script>
<?php render_layout_close(); ?>