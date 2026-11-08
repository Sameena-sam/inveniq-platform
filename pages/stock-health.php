<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('stock-health');
require_once __DIR__ . '/../components/layout.php';
 render_layout_open('stock-health'); ?>
<?php page_header('Stock Health Monitor','Cover days, velocity and status per SKU-location combination'); ?>
<div class="metrics-grid">
  <div class="metric-card loading" id="sh-m1"><div class="metric-icon danger"><?=icon('alert-triangle')?></div><div class="metric-body"><div class="metric-label">Stockout</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="sh-m2"><div class="metric-icon warning"><?=icon('alert-triangle')?></div><div class="metric-body"><div class="metric-label">Critical</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="sh-m3"><div class="metric-icon warning"><?=icon('package-search')?></div><div class="metric-body"><div class="metric-label">Low</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="sh-m4"><div class="metric-icon success"><?=icon('check-circle')?></div><div class="metric-body"><div class="metric-label">Healthy</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="sh-m5"><div class="metric-icon info"><?=icon('trending-down')?></div><div class="metric-body"><div class="metric-label">Excess</div><div class="metric-value">—</div></div></div>
</div>

<div class="card">
  <div class="table-toolbar">
    <input class="search-input" id="sh_search" placeholder="Search SKU, product, location…" oninput="filterTable('sh')">
    <select class="form-select" id="sh-status-filter" onchange="filterByStatus()" style="max-width:140px">
      <option value="">All statuses</option>
      <option value="stockout">Stockout</option>
      <option value="critical">Critical</option>
      <option value="low">Low</option>
      <option value="healthy">Healthy</option>
      <option value="excess">Excess</option>
    </select>
    <span class="table-count" id="sh_count">Loading…</span>
  </div>
  <div class="data-table-wrap">
    <table class="data-table" id="sh_table">
      <thead><tr>
        <th onclick="sortTable('sh',0)">SKU</th>
        <th onclick="sortTable('sh',1)">Product</th>
        <th onclick="sortTable('sh',2)">Group</th>
        <th onclick="sortTable('sh',3)">Location</th>
        <th onclick="sortTable('sh',4)">Qty on Hand</th>
        <th onclick="sortTable('sh',5)">Daily Sales</th>
        <th onclick="sortTable('sh',6)">Cover Days</th>
        <th onclick="sortTable('sh',7)">Stock Value</th>
        <th>Status</th>
      </tr></thead>
      <tbody id="sh-tbody">
        <?php for($i=0;$i<8;$i++) echo '<tr class="skeleton-row">'.str_repeat('<td><div class="skeleton" style="width:80px;height:12px"></div></td>',9).'</tr>'; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
const statusBadge = {
  stockout: '<span class="badge badge-danger">Stockout</span>',
  critical: '<span class="badge badge-orange">Critical</span>',
  low:      '<span class="badge badge-warning">Low</span>',
  healthy:  '<span class="badge badge-success">Healthy</span>',
  excess:   '<span class="badge badge-info">Excess</span>',
};

function coverBar(days, min, max) {
  const label = days >= 9999 ? '∞' : days + 'd';
  const cap = max * 1.3;
  const pct = Math.min(100, (days / Math.max(1, cap)) * 100);
  const clr = days === 0 ? 'var(--danger)'
    : days < min / 2 ? 'var(--danger)'
    : days < min     ? 'var(--warning)'
    : days > max     ? 'var(--info)'
    : 'var(--success)';
  return `<div class="cover-wrap">
    <div class="cover-bar"><div class="cover-fill" style="width:${pct}%;background:${clr}"></div></div>
    <span class="cover-label">${label}</span>
  </div>`;
}

let shAll = [];

async function loadStockHealth() {
  try {
    shAll = await fetch('/inveniq/api/index.php?action=stock_health').then(r => r.json());
    const counts = { stockout: 0, critical: 0, low: 0, healthy: 0, excess: 0 };
    shAll.forEach(h => { if (counts[h.status] !== undefined) counts[h.status]++; });

    [['sh-m1', counts.stockout], ['sh-m2', counts.critical], ['sh-m3', counts.low],
     ['sh-m4', counts.healthy],  ['sh-m5', counts.excess]].forEach(([id, v]) => {
      const el = document.getElementById(id);
      if (el) { el.classList.remove('loading'); el.querySelector('.metric-value').textContent = v; }
    });

    renderSHTable(shAll);
  } catch(e) { console.error('Stock health load error', e); }
}

function renderSHTable(data) {
  const tbody = document.getElementById('sh-tbody');
  tbody.innerHTML = data.map(r => {
    const search = [r.sku, r.description, r.product_group, r.location_name, r.status].join(' ').toLowerCase();
    const qty    = Number(r.qty_on_hand).toLocaleString('en-IN', {maximumFractionDigits:0});
    const vel    = Number(r.avg_daily_sales).toFixed(2);
    const val    = '₹' + Number(r.stock_value).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
    return `<tr class="data-row" data-status="${r.status}" data-search="${search}">
      <td class="mono">${r.sku}</td>
      <td class="bold">${r.description}</td>
      <td class="text-muted text-xs">${r.product_group || ''}</td>
      <td>${r.location_name}</td>
      <td class="right font-mono">${qty}</td>
      <td class="right font-mono">${vel}/d</td>
      <td>${coverBar(Number(r.cover_days), Number(r.min_cover_days), Number(r.max_cover_days))}</td>
      <td class="right font-mono">${val}</td>
      <td>${statusBadge[r.status] || ''}</td>
    </tr>`;
  }).join('');
  document.getElementById('sh_count').textContent = data.length + ' rows';
}

function filterByStatus() {
  const val = document.getElementById('sh-status-filter').value;
  renderSHTable(val ? shAll.filter(r => r.status === val) : shAll);
}

document.addEventListener('DOMContentLoaded', loadStockHealth);
</script>

<?php render_layout_close(); ?>
