<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('reorder-points');
require_once __DIR__ . '/../components/layout.php';
 render_layout_open('reorder-points'); ?>
<?php page_header('Reorder Points','ML demand forecast + EOQ, prioritised by profit at risk'); ?>
<div class="metrics-grid">
  <div class="metric-card loading" id="rp-m1"><div class="metric-icon default"><?=icon('package')?></div><div class="metric-body"><div class="metric-label">Total SKUs</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="rp-m2"><div class="metric-icon warning"><?=icon('alert-triangle')?></div><div class="metric-body"><div class="metric-label">Below Reorder Point</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="rp-m3"><div class="metric-icon info"><?=icon('shopping-cart')?></div><div class="metric-body"><div class="metric-label">Suggested Order Value</div><div class="metric-value">—</div></div></div>
</div>
<div class="card"><div class="table-toolbar"><input class="search-input" id="rp_search" placeholder="Search…" oninput="filterTable('rp')"><span class="table-count" id="rp_count">Loading…</span></div>
<div class="data-table-wrap"><table class="data-table" id="rp_table">
<thead><tr><th>SKU</th><th>Product</th><th>Supplier</th><th>Forecast/day</th><th>Lead Time</th><th>Reorder Pt.</th><th>Current Stock</th><th>Available</th><th>EOQ</th><th>Suggested Qty</th><th>Margin %</th><th>Profit at Risk</th><th>Status</th></tr></thead>
<tbody id="rp-tbody"></tbody></table></div></div>
<script>
async function loadRP(){const data=await fetch('/inveniq/api/index.php?action=reorder_points').then(r=>r.json());const below=data.filter(r=>r.below_reorder=='1'||r.below_reorder===true);const tv=below.reduce((s,r)=>s+Number(r.suggested_value),0);[['m1',data.length],['m2',below.length],['m3','₹'+tv.toLocaleString('en-IN',{maximumFractionDigits:0})]].forEach(([id,v])=>{const el=document.getElementById('rp-'+id);el.classList.remove('loading');el.querySelector('.metric-value').textContent=v;});document.getElementById('rp-tbody').innerHTML=data.map(r=>{const s=r.below_reorder=='1'||r.below_reorder===true;const bdg=s?'<span class="badge badge-danger">Below ROP</span>':'<span class="badge badge-success">OK</span>';const src=(r.forecast_source==='ml')?'<span class="badge badge-purple" title="From ML model">ML</span>':'<span class="badge badge-muted" title="Rule-based fallback">rule</span>';return`<tr class="data-row" data-search="${[r.sku,r.description,r.supplier_name].join(' ').toLowerCase()}"><td class="mono">${r.sku}</td><td class="bold">${r.description}</td><td>${r.supplier_name}</td><td class="right font-mono">${Number(r.daily_sales).toFixed(2)} ${src}</td><td class="right font-mono">${r.lead_time_days}d</td><td class="right font-mono">${Number(r.reorder_point).toLocaleString()}</td><td class="right font-mono">${Number(r.current_stock).toLocaleString()}</td><td class="right font-mono">${Number(r.available).toLocaleString()}</td><td class="right font-mono">${Number(r.eoq).toLocaleString()}</td><td class="right font-mono fw-bold">${Number(r.suggested_qty).toLocaleString()}</td><td class="right font-mono">${Number(r.margin_pct||0).toFixed(1)}%</td><td class="right font-mono">₹${Number(r.expected_monthly_margin||0).toLocaleString('en-IN',{maximumFractionDigits:0})}</td><td>${bdg}</td></tr>`;}).join('');document.getElementById('rp_count').textContent=data.length+' rows';}
document.addEventListener('DOMContentLoaded',loadRP);
</script>
<?php render_layout_close(); ?>
