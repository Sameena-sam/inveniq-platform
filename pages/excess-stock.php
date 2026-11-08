<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('excess-stock');
require_once __DIR__ . '/../components/layout.php';
 render_layout_open('excess-stock'); ?>
<?php page_header('Excess Stock','Products with stock above maximum cover days threshold'); ?>
<div class="metrics-grid">
  <div class="metric-card loading" id="es-m1"><div class="metric-icon info"><?=icon('package')?></div><div class="metric-body"><div class="metric-label">Excess SKUs</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="es-m2"><div class="metric-icon warning"><?=icon('bar-chart-3')?></div><div class="metric-body"><div class="metric-label">Total Excess Value</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="es-m3"><div class="metric-icon default"><?=icon('alert-triangle')?></div><div class="metric-body"><div class="metric-label">Avg Cover Days</div><div class="metric-value">—</div></div></div>
</div>
<div class="card"><div class="table-toolbar"><input class="search-input" id="es_search" placeholder="Search…" oninput="filterTable('es')"><span class="table-count" id="es_count">Loading…</span></div>
<div class="data-table-wrap"><table class="data-table" id="es_table">
<thead><tr><th>SKU</th><th>Product</th><th>Group</th><th>Location</th><th>Total Stock</th><th>Excess Qty</th><th>Cover Days</th><th>Max Cover</th><th>Daily Sales</th><th>Total Value</th><th>Excess Value</th></tr></thead>
<tbody id="es-tbody"></tbody></table></div></div>
<script>
async function loadES(){const data=await fetch('/inveniq/api/index.php?action=excess_stock').then(r=>r.json());const tv=data.reduce((s,r)=>s+Math.max(0,Number(r.excess_value)),0);const avgCover=data.length?Math.round(data.reduce((s,r)=>s+Number(r.cover_days),0)/data.length):0;[['m1',data.length],['m2','₹'+tv.toLocaleString('en-IN',{maximumFractionDigits:0})],['m3',avgCover+'d']].forEach(([id,v])=>{const el=document.getElementById('es-'+id);el.classList.remove('loading');el.querySelector('.metric-value').textContent=v;});document.getElementById('es-tbody').innerHTML=data.map(r=>`<tr class="data-row" data-search="${[r.sku,r.description,r.product_group,r.location_name].join(' ').toLowerCase()}"><td class="mono">${r.sku}</td><td class="bold">${r.description}</td><td class="text-muted text-xs">${r.product_group||''}</td><td>${r.location_name}</td><td class="right font-mono">${Number(r.qty_on_hand).toLocaleString()}</td><td class="right font-mono text-info fw-semi">${Number(r.excess_qty).toLocaleString()}</td><td class="right font-mono">${r.cover_days}d</td><td class="right font-mono">${r.max_cover_days}d</td><td class="right font-mono">${Number(r.avg_daily_sales).toFixed(2)}/d</td><td class="right font-mono">₹${Number(r.stock_value).toLocaleString('en-IN',{minimumFractionDigits:2})}</td><td class="right font-mono text-info fw-bold">₹${Math.max(0,Number(r.excess_value)).toLocaleString('en-IN',{minimumFractionDigits:2})}</td></tr>`).join('');document.getElementById('es_count').textContent=data.length+' rows';}
document.addEventListener('DOMContentLoaded',loadES);
</script>
<?php render_layout_close(); ?>