<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('mismatch-analysis');
require_once __DIR__ . '/../components/layout.php';
 render_layout_open('mismatch-analysis'); ?>
<?php page_header('Mismatch Analysis','POs with quantity variances between ordered and received'); ?>
<div class="metrics-grid">
  <div class="metric-card loading" id="ma-m1"><div class="metric-icon warning"><?=icon('alert-triangle')?></div><div class="metric-body"><div class="metric-label">Total Mismatches</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="ma-m2"><div class="metric-icon danger"><?=icon('trending-down')?></div><div class="metric-body"><div class="metric-label">Short Deliveries</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="ma-m3"><div class="metric-icon info"><?=icon('bar-chart-3')?></div><div class="metric-body"><div class="metric-label">Over Deliveries</div><div class="metric-value">—</div></div></div>
</div>
<div class="card"><div class="table-toolbar"><input class="search-input" id="ma_search" placeholder="Search…" oninput="filterTable('ma')"><span class="table-count" id="ma_count">Loading…</span></div>
<div class="data-table-wrap"><table class="data-table" id="ma_table">
<thead><tr><th>PO Number</th><th>SKU</th><th>Supplier</th><th>Ordered</th><th>Received</th><th>Variance</th><th>Variance %</th><th>Type</th></tr></thead>
<tbody id="ma-tbody"></tbody></table></div></div>
<script>
async function loadMA(){const all=await fetch('/inveniq/api/index.php?action=po_reconciliation').then(r=>r.json());const data=all.filter(r=>Math.abs(Number(r.variance_pct))>0&&r.reconcile_status!=='complete');const short=data.filter(r=>Number(r.variance)<0),over=data.filter(r=>Number(r.variance)>0);[['m1',data.length],['m2',short.length],['m3',over.length]].forEach(([id,v])=>{const el=document.getElementById('ma-'+id);el.classList.remove('loading');el.querySelector('.metric-value').textContent=v;});document.getElementById('ma-tbody').innerHTML=data.map(r=>{const v=Number(r.variance);const vs=(v>0?'+':'')+v.toFixed(0);const bdg=v<0?'<span class="badge badge-danger">Short Delivery</span>':'<span class="badge badge-info">Over Delivery</span>';const vpc=Number(r.variance_pct);return`<tr class="data-row" data-search="${[r.po_number,r.sku,r.supplier_name].join(' ').toLowerCase()}"><td class="mono">${r.po_number}</td><td class="mono">${r.sku}</td><td>${r.supplier_name}</td><td class="right font-mono">${Number(r.ordered_quantity).toLocaleString()}</td><td class="right font-mono">${Number(r.received_quantity).toLocaleString()}</td><td class="right font-mono fw-bold ${v<0?'text-danger':'text-info'}">${vs}</td><td class="right font-mono ${Math.abs(vpc)>10?'text-danger fw-semi':''}">${vpc.toFixed(1)}%</td><td>${bdg}</td></tr>`;}).join('');document.getElementById('ma_count').textContent=data.length+' rows';}
document.addEventListener('DOMContentLoaded',loadMA);
</script>
<?php render_layout_close(); ?>