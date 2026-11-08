<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('expiry-alerts');
require_once __DIR__ . '/../components/layout.php';
 render_layout_open('expiry-alerts'); ?>
<?php page_header('Expiry Alerts','Batches approaching expiry — write-off risk quantified'); ?>
<div class="metrics-grid">
  <div class="metric-card loading" id="ea-m1"><div class="metric-icon danger"><?=icon('alert-triangle')?></div><div class="metric-body"><div class="metric-label">≤30 Days</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
  <div class="metric-card loading" id="ea-m2"><div class="metric-icon warning"><?=icon('clock')?></div><div class="metric-body"><div class="metric-label">≤90 Days</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
  <div class="metric-card loading" id="ea-m3"><div class="metric-icon info"><?=icon('clock')?></div><div class="metric-body"><div class="metric-label">≤180 Days</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="ea-m4"><div class="metric-icon warning"><?=icon('bar-chart-3')?></div><div class="metric-body"><div class="metric-label">Total Write-off Risk</div><div class="metric-value">—</div></div></div>
</div>
<div class="card"><div class="table-toolbar"><input class="search-input" id="ea_search" placeholder="Search…" oninput="filterTable('ea')"><select class="form-select" id="ea-filter" onchange="filterEA()" style="max-width:130px"><option value="180">≤180 days</option><option value="90">≤90 days</option><option value="30">≤30 days</option></select><span class="table-count" id="ea_count">Loading…</span></div>
<div class="data-table-wrap"><table class="data-table" id="ea_table">
<thead><tr><th>SKU</th><th>Product</th><th>Location</th><th>Batch</th><th>Qty at Risk</th><th>Expiry Date</th><th>Days Left</th><th>Write-off Risk</th><th>Risk Level</th></tr></thead>
<tbody id="ea-tbody"></tbody></table></div></div>
<script>
let eaAll=[];
function riskBadge(d){return d<=30?'<span class="badge badge-danger">Critical</span>':d<=60?'<span class="badge badge-orange">High</span>':'<span class="badge badge-warning">Watch</span>';}
function riskColor(d){return d<=30?'text-danger':d<=60?'text-warning':'';}
async function loadEA(){eaAll=await fetch('/inveniq/api/index.php?action=expiry_alerts&days=180').then(r=>r.json());const d30=eaAll.filter(r=>Number(r.days_to_expiry)<=30),d90=eaAll.filter(r=>Number(r.days_to_expiry)<=90),tv=eaAll.reduce((s,r)=>s+Number(r.write_off_value),0);[['m1',d30.length,'₹'+d30.reduce((s,r)=>s+Number(r.write_off_value),0).toLocaleString('en-IN',{maximumFractionDigits:0})],['m2',d90.length,'₹'+d90.reduce((s,r)=>s+Number(r.write_off_value),0).toLocaleString('en-IN',{maximumFractionDigits:0})],['m3',eaAll.length,null],['m4','₹'+tv.toLocaleString('en-IN',{maximumFractionDigits:0}),null]].forEach(([id,v,sub])=>{const el=document.getElementById('ea-'+id);el.classList.remove('loading');el.querySelector('.metric-value').textContent=v;if(sub&&el.querySelector('.metric-sub'))el.querySelector('.metric-sub').textContent=sub;});renderEA(eaAll);}
function renderEA(data){document.getElementById('ea-tbody').innerHTML=data.map(r=>{const d=Number(r.days_to_expiry);return`<tr class="data-row" data-search="${[r.sku,r.description,r.location_name,r.batch_number||''].join(' ').toLowerCase()}"><td class="mono">${r.sku}</td><td class="bold">${r.description}</td><td>${r.location_name}</td><td class="mono">${r.batch_number||'—'}</td><td class="right font-mono">${Number(r.quantity_on_hand).toLocaleString()}</td><td class="font-mono">${r.expiry_date}</td><td class="right font-mono fw-bold ${riskColor(d)}">${d}</td><td class="right font-mono text-danger">₹${Number(r.write_off_value).toLocaleString('en-IN',{minimumFractionDigits:2})}</td><td>${riskBadge(d)}</td></tr>`;}).join('');document.getElementById('ea_count').textContent=data.length+' rows';}
function filterEA(){const days=Number(document.getElementById('ea-filter').value);renderEA(eaAll.filter(r=>Number(r.days_to_expiry)<=days));}
document.addEventListener('DOMContentLoaded',loadEA);
</script>
<?php render_layout_close(); ?>