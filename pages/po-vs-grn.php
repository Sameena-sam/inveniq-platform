<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('po-vs-grn');
require_once __DIR__ . '/../components/layout.php';
 render_layout_open('po-vs-grn'); ?>
<?php page_header('PO vs GRN Reconciliation','Purchase order to goods received reconciliation'); ?>
<div class="metrics-grid">
  <div class="metric-card loading" id="pg-m1"><div class="metric-icon success"><?=icon('check-circle')?></div><div class="metric-body"><div class="metric-label">Complete</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="pg-m2"><div class="metric-icon warning"><?=icon('alert-triangle')?></div><div class="metric-body"><div class="metric-label">Partial</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="pg-m3"><div class="metric-icon danger"><?=icon('git-compare')?></div><div class="metric-body"><div class="metric-label">Not Received</div><div class="metric-value">—</div></div></div>
</div>
<div class="card"><div class="table-toolbar"><input class="search-input" id="pg_search" placeholder="Search PO, SKU, supplier…" oninput="filterTable('pg')"><span class="table-count" id="pg_count">Loading…</span></div>
<div class="data-table-wrap"><table class="data-table" id="pg_table">
<thead><tr><th>PO Number</th><th>SKU</th><th>Supplier</th><th>Order Date</th><th>Expected Delivery</th><th>Ordered</th><th>Received</th><th>Variance</th><th>Variance %</th><th>PO Age</th><th>Status</th></tr></thead>
<tbody id="pg-tbody"></tbody></table></div></div>
<script>
const pgBadge={complete:'<span class="badge badge-success">Complete</span>',partial:'<span class="badge badge-warning">Partial</span>',not_received:'<span class="badge badge-danger">Not Received</span>'};
async function loadPG(){const data=await fetch('/inveniq/api/index.php?action=po_reconciliation').then(r=>r.json());const c=data.filter(r=>r.reconcile_status==='complete'),p=data.filter(r=>r.reconcile_status==='partial'),n=data.filter(r=>r.reconcile_status==='not_received');[['m1',c.length],['m2',p.length],['m3',n.length]].forEach(([id,v])=>{const el=document.getElementById('pg-'+id);el.classList.remove('loading');el.querySelector('.metric-value').textContent=v;});document.getElementById('pg-tbody').innerHTML=data.map(r=>{const v=Number(r.variance);const vs=(v>0?'+':'')+v.toFixed(0);const vc=v<0?'text-danger fw-semi':v>0?'text-info fw-semi':'text-success';const vpc=Number(r.variance_pct);const vpcs=Math.abs(vpc)>5?'text-danger fw-semi':'';return`<tr class="data-row" data-search="${[r.po_number,r.sku,r.supplier_name].join(' ').toLowerCase()}"><td class="mono">${r.po_number}</td><td class="mono">${r.sku}</td><td>${r.supplier_name}</td><td class="font-mono text-xs">${r.order_date}</td><td class="font-mono text-xs">${r.expected_delivery_date||'—'}</td><td class="right font-mono">${Number(r.ordered_quantity).toLocaleString()}</td><td class="right font-mono">${Number(r.received_quantity).toLocaleString()}</td><td class="right font-mono ${vc}">${vs}</td><td class="right font-mono ${vpcs}">${vpc.toFixed(1)}%</td><td class="right font-mono">${r.po_age_days}d</td><td>${pgBadge[r.reconcile_status]||''}</td></tr>`;}).join('');document.getElementById('pg_count').textContent=data.length+' rows';}
document.addEventListener('DOMContentLoaded',loadPG);
</script>
<?php render_layout_close(); ?>