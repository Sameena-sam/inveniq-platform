<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('payment-ageing');
require_once __DIR__ . '/../components/layout.php';
 render_layout_open('payment-ageing'); ?>
<?php page_header('Payment Ageing','Outstanding payables by ageing bucket'); ?>
<div class="metrics-grid" id="pa-metrics">
  <?php foreach(['current','1-30','31-60','61-90','90+'] as $b): ?>
  <div class="metric-card loading" id="pa-<?=str_replace(['+','-'],['p','m'],$b)?>"><div class="metric-icon <?=$b==='current'?'success':($b==='1-30'?'warning':'danger')?>"><?=icon($b==='current'?'check-circle':'alert-triangle')?></div><div class="metric-body"><div class="metric-label"><?=$b==='current'?'Current':$b.' days'?></div><div class="metric-value">—</div></div></div>
  <?php endforeach; ?>
</div>
<div class="card"><div class="table-toolbar"><input class="search-input" id="pa_search" placeholder="Search invoice, supplier…" oninput="filterTable('pa')"><span class="table-count" id="pa_count">Loading…</span></div>
<div class="data-table-wrap"><table class="data-table" id="pa_table">
<thead><tr><th>Invoice</th><th>Supplier</th><th>Invoice Date</th><th>Due Date</th><th>Amount</th><th>Days Overdue</th><th>Bucket</th><th>Status</th></tr></thead>
<tbody id="pa-tbody"></tbody></table></div></div>
<script>
const bucketClr={current:'text-success','1-30':'text-warning','31-60':'text-warning','61-90':'text-danger','90+':'text-danger'};
const bucketBadge={current:'<span class="badge badge-success">Current</span>','1-30':'<span class="badge badge-warning">1-30d</span>','31-60':'<span class="badge badge-orange">31-60d</span>','61-90':'<span class="badge badge-danger">61-90d</span>','90+':'<span class="badge badge-danger">90+d</span>'};
async function loadPA(){const data=await fetch('/inveniq/api/index.php?action=payment_ageing').then(r=>r.json());const buckets={'current':0,'1-30':0,'31-60':0,'61-90':0,'90+':0};data.forEach(r=>buckets[r.bucket]=(buckets[r.bucket]||0)+Number(r.amount));Object.entries(buckets).forEach(([b,v])=>{const key='pa-'+b.replace('+','p').replace('-','m');const el=document.getElementById(key);if(el){el.classList.remove('loading');el.querySelector('.metric-value').textContent='₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});}});document.getElementById('pa-tbody').innerHTML=data.map(r=>{const ov=Number(r.days_overdue);const ovStr=ov>0?`<span class="text-danger fw-semi">+${ov}d</span>`:`<span class="text-success">${Math.abs(ov)}d left</span>`;return`<tr class="data-row" data-search="${[r.invoice_number,r.supplier_name].join(' ').toLowerCase()}"><td class="mono">${r.invoice_number}</td><td>${r.supplier_name}</td><td class="font-mono text-xs">${r.invoice_date}</td><td class="font-mono text-xs">${r.due_date}</td><td class="right font-mono fw-semi">₹${Number(r.amount).toLocaleString('en-IN',{minimumFractionDigits:2})}</td><td class="right">${ovStr}</td><td>${bucketBadge[r.bucket]||''}</td><td class="capitalize text-xs">${(r.status||'').replace('_',' ')}</td></tr>`;}).join('');document.getElementById('pa_count').textContent=data.length+' rows';}
document.addEventListener('DOMContentLoaded',loadPA);
</script>
<?php render_layout_close(); ?>