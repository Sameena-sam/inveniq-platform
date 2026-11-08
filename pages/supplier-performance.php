<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('supplier-performance');
require_once __DIR__ . '/../components/layout.php';
 render_layout_open('supplier-performance'); ?>
<?php page_header('Supplier Performance','On-time delivery, quantity accuracy and performance scores'); ?>
<div class="metrics-grid">
  <div class="metric-card loading" id="sp-m1"><div class="metric-icon default"><?=icon('truck')?></div><div class="metric-body"><div class="metric-label">Total Suppliers</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="sp-m2"><div class="metric-icon default"><?=icon('check-circle')?></div><div class="metric-body"><div class="metric-label">Avg Performance Score</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="sp-m3"><div class="metric-icon success"><?=icon('check-circle')?></div><div class="metric-body"><div class="metric-label">High Performers ≥80%</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="sp-m4"><div class="metric-icon danger"><?=icon('alert-triangle')?></div><div class="metric-body"><div class="metric-label">Low Performers &lt;60%</div><div class="metric-value">—</div></div></div>
</div>
<div class="card"><div class="table-toolbar"><input class="search-input" id="sp_search" placeholder="Search supplier…" oninput="filterTable('sp')"><span class="table-count" id="sp_count">Loading…</span></div>
<div class="data-table-wrap"><table class="data-table" id="sp_table">
<thead><tr><th>Supplier</th><th>Status</th><th>Total POs</th><th>Total Value</th><th>On-Time</th><th>Late</th><th>On-Time Rate</th><th>Qty Accuracy</th><th>Score</th><th>Lead Time</th></tr></thead>
<tbody id="sp-tbody"></tbody></table></div></div>
<script>
function scoreBar(v){const clr=v>=80?'var(--success)':v>=60?'var(--warning)':'var(--danger)';const tc=v>=80?'text-success':v>=60?'text-warning':'text-danger';return`<div class="score-wrap"><div class="score-bar"><div class="score-fill" style="width:${v}%;background:${clr}"></div></div><span class="score-val ${tc}">${v}%</span></div>`;}
async function loadSP(){const data=await fetch('/inveniq/api/index.php?action=supplier_performance').then(r=>r.json());const avg=data.length?Math.round(data.reduce((s,r)=>s+Number(r.performanceScore||((Number(r.on_time_rate||0)+Number(r.qty_accuracy||0))/2)),0)/data.length):0;const high=data.filter(r=>Number(r.on_time_rate||0)>=80);const low=data.filter(r=>Number(r.on_time_rate||0)<60);[['m1',data.length],['m2',avg+'%'],['m3',high.length],['m4',low.length]].forEach(([id,v])=>{const el=document.getElementById('sp-'+id);el.classList.remove('loading');el.querySelector('.metric-value').textContent=v;});document.getElementById('sp-tbody').innerHTML=data.map(r=>{const ot=Number(r.on_time_rate||0);const qa=Number(r.qty_accuracy||0);const score=Math.round((ot+qa)/2);const sc=score>=80?'text-success fw-bold':score>=60?'text-warning fw-bold':'text-danger fw-bold';return`<tr class="data-row" data-search="${[r.name,r.supplier_id].join(' ').toLowerCase()}"><td class="bold">${r.name}<br><span class="mono">${r.supplier_id}</span></td><td><span class="capitalize text-xs">${r.status}</span></td><td class="right font-mono">${Number(r.total_pos||0).toLocaleString()}</td><td class="right font-mono">₹${Number(r.total_value||0).toLocaleString('en-IN',{minimumFractionDigits:0})}</td><td class="right font-mono">${r.on_time_count||0}</td><td class="right font-mono ${(r.late_count||0)>0?'text-danger':''}">${r.late_count||0}</td><td>${scoreBar(ot)}</td><td>${scoreBar(qa)}</td><td class="${sc}" style="font-size:16px">${score}</td><td class="right font-mono">${r.lead_time_days}d</td></tr>`;}).join('');document.getElementById('sp_count').textContent=data.length+' rows';}
document.addEventListener('DOMContentLoaded',loadSP);
</script>
<?php render_layout_close(); ?>