<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('dashboard');
require_once __DIR__ . '/../components/layout.php';
render_layout_open('dashboard');
?>

<div class="page-header">
  <div class="page-header-left">
    <h1>Dashboard</h1>
    <p id="dash-subtitle">Loading inventory summary…</p>
  </div>
</div>

<!-- KPI CARDS -->
<div class="metrics-grid" id="kpi-grid">
  <?php
  $kpis = [
    ['id'=>'kpi-issues',   'label'=>'Stock Issues',      'icon'=>'alert-triangle','variant'=>'danger'],
    ['id'=>'kpi-reorder',  'label'=>'Below Reorder',     'icon'=>'package-search','variant'=>'warning'],
    ['id'=>'kpi-expiry',   'label'=>'Expiry Risk',       'icon'=>'clock',         'variant'=>'warning'],
    ['id'=>'kpi-supplier', 'label'=>'Supplier Score',    'icon'=>'truck',         'variant'=>'default'],
    ['id'=>'kpi-excess',   'label'=>'Excess Stock SKUs', 'icon'=>'alert-triangle','variant'=>'info'],
    ['id'=>'kpi-po',       'label'=>'PO Match Rate',     'icon'=>'check-circle',  'variant'=>'success'],
  ];
  foreach ($kpis as $k):
  ?>
  <div class="metric-card loading" id="<?= $k['id'] ?>">
    <div class="metric-icon <?= $k['variant'] ?>"><?= icon($k['icon']) ?></div>
    <div class="metric-body">
      <div class="metric-label"><?= $k['label'] ?></div>
      <div class="metric-value" id="<?= $k['id'] ?>-val">—</div>
      <div class="metric-sub" id="<?= $k['id'] ?>-sub">Loading…</div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- VALUE DELIVERED / ROI PANEL -->
<div class="card section-gap" style="margin-bottom:16px;padding:16px;border-left:4px solid var(--success)">
  <div class="card-header" style="padding:0 0 12px">
    <span class="card-title">Value at Your Fingertips</span>
    <span class="text-muted text-xs">What acting on InvenIQ can free up or protect</span>
  </div>
  <div class="metrics-grid" style="margin:0">
    <div class="metric-card"><div class="metric-icon info"><?=icon('alert-triangle')?></div><div class="metric-body"><div class="metric-label">Cash in Excess Stock</div><div class="metric-value" id="v-excess">—</div><div class="metric-sub">Freeable working capital</div></div></div>
    <div class="metric-card"><div class="metric-icon warning"><?=icon('clock')?></div><div class="metric-body"><div class="metric-label">Expiry Value at Risk</div><div class="metric-value" id="v-expiry">—</div><div class="metric-sub">Write-off risk ≤90 days</div></div></div>
    <div class="metric-card"><div class="metric-icon danger"><?=icon('trending-down')?></div><div class="metric-body"><div class="metric-label">Profit at Risk / month</div><div class="metric-value" id="v-profit">—</div><div class="metric-sub" id="v-profit-sub">From potential stockouts</div></div></div>
    <div class="metric-card"><div class="metric-icon success"><?=icon('bar-chart-3')?></div><div class="metric-body"><div class="metric-label">Forecast Monthly Margin</div><div class="metric-value" id="v-margin">—</div><div class="metric-sub">Expected gross margin</div></div></div>
  </div>
</div>

<!-- TWO COLUMN -->
<div class="two-col-grid">

  <!-- Critical Alerts -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Critical Stock Alerts</span>
      <a href="/stock-health.php" class="btn btn-ghost btn-sm">View all</a>
    </div>
    <div id="critical-list" style="padding:0">
      <?php for($i=0;$i<5;$i++): ?>
      <div style="padding:10px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
        <div><div class="skeleton" style="width:140px;height:13px;margin-bottom:5px"></div><div class="skeleton" style="width:80px;height:10px"></div></div>
        <div class="skeleton" style="width:60px;height:20px;border-radius:20px"></div>
      </div>
      <?php endfor; ?>
    </div>
  </div>

  <!-- Expiring Soon -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Expiring Soon (≤30 days)</span>
      <a href="/expiry-alerts.php" class="btn btn-ghost btn-sm">View all</a>
    </div>
    <div id="expiry-list" style="padding:0">
      <?php for($i=0;$i<5;$i++): ?>
      <div style="padding:10px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
        <div><div class="skeleton" style="width:130px;height:13px;margin-bottom:5px"></div><div class="skeleton" style="width:70px;height:10px"></div></div>
        <div class="skeleton" style="width:50px;height:13px"></div>
      </div>
      <?php endfor; ?>
    </div>
  </div>
</div>

<!-- AI ASSISTANT (Dashboard Quick Chat) -->
<div class="card section-gap">
  <div class="card-header">
    <div class="gap-row">
      <div class="ai-badge-dot"></div>
      <span class="card-title" style="text-transform:none;font-size:13px;font-weight:600">AI Inventory Assistant</span>
    </div>
    <span class="text-muted text-xs font-mono">Live data</span>
  </div>
  <div class="ai-messages" id="dash-messages" style="max-height:260px;padding:14px 16px;display:flex;flex-direction:column;gap:12px;overflow-y:auto"></div>
  <div class="ai-chips" id="dash-chips">
    <span class="ai-chip" onclick="useChip('Which products are at critical stockout risk?','dash-input','dash-messages')">Stockout risks?</span>
    <span class="ai-chip" onclick="useChip('How is supplier on-time delivery?','dash-input','dash-messages')">Supplier performance?</span>
    <span class="ai-chip" onclick="useChip('What stock is expiring soon?','dash-input','dash-messages')">Expiry alerts?</span>
    <span class="ai-chip" onclick="useChip('Which products have excess stock?','dash-input','dash-messages')">Excess stock?</span>
  </div>
  <div class="ai-input-row">
    <input class="ai-input" id="dash-input" data-container="dash-messages" placeholder="Ask about your inventory…">
    <button class="ai-send" onclick="sendAIMessage('dash-input','dash-messages')"><?= icon('send') ?></button>
  </div>
</div>

<script>
async function loadDashboard() {
  try {
    const [kpis, health, expiry] = await Promise.all([
      fetch('/inveniq/api/index.php?action=kpis').then(r=>r.json()),
      fetch('/inveniq/api/index.php?action=stock_health').then(r=>r.json()),
      fetch('/inveniq/api/index.php?action=expiry_alerts&days=30').then(r=>r.json()),
    ]);

    // KPIs
    const critical = health.filter(h => h.status === 'critical' || h.status === 'stockout');
    const excess    = health.filter(h => h.status === 'excess');
    const scoreNum  = parseFloat(kpis.supplier_ontime) || 0;
    const scoreVar  = scoreNum >= 80 ? 'success' : 'warning';

    function setKPI(id, val, sub) {
      const el = document.getElementById(id);
      if (!el) return;
      el.classList.remove('loading');
      el.querySelector('.metric-value').textContent = val;
      el.querySelector('.metric-sub').textContent = sub;
    }
    setKPI('kpi-issues',   critical.length, `${health.filter(h=>h.status==='stockout').length} stockouts`);
    setKPI('kpi-reorder',  kpis.reorder_alerts, 'Needs replenishment');
    setKPI('kpi-expiry',   kpis.expiry_risk, 'Write-off risk items');
    setKPI('kpi-supplier', kpis.supplier_ontime + '%', 'Avg performance');
    setKPI('kpi-excess',   kpis.excess_stock, 'Above max cover days');
    setKPI('kpi-po',       kpis.po_match_rate + '%', 'PO vs GRN match rate');

    // Subtitle
    document.getElementById('dash-subtitle').textContent =
      `${health.length} SKU-location combinations · ₹${health.reduce((s,h)=>s+(parseFloat(h.stock_value)||0),0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2})} total value`;

    // Critical list
    const cl = document.getElementById('critical-list');
    cl.innerHTML = '';
    const crits = health.filter(h=>['stockout','critical'].includes(h.status)).slice(0,8);
    if (!crits.length) {
      cl.innerHTML = '<div style="padding:24px;text-align:center;color:var(--success)">✓ No critical stock alerts</div>';
    } else {
      crits.forEach(h => {
        const badge = h.status === 'stockout'
          ? '<span class="badge badge-danger">Stockout</span>'
          : '<span class="badge badge-orange">Critical</span>';
        const cover = h.cover_days >= 9999 ? '∞' : h.cover_days + 'd cover';
        cl.innerHTML += `<div style="padding:10px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
          <div>
            <div class="fw-semi" style="font-size:13px">${h.description}</div>
            <div class="text-muted text-xs">${h.sku} · ${h.location_name}</div>
          </div>
          <div class="gap-row"><span class="text-xs text-muted">${cover}</span>${badge}</div>
        </div>`;
      });
    }

    // Expiry list
    const el = document.getElementById('expiry-list');
    el.innerHTML = '';
    if (!expiry.length) {
      el.innerHTML = '<div style="padding:24px;text-align:center;color:var(--success)">✓ No items expiring within 30 days</div>';
    } else {
      expiry.slice(0,8).forEach(e => {
        el.innerHTML += `<div style="padding:10px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
          <div>
            <div class="fw-semi" style="font-size:13px">${e.description}</div>
            <div class="text-muted text-xs">${e.sku} · ${e.location_name}</div>
          </div>
          <div class="text-danger fw-bold text-xs">${e.days_to_expiry}d left</div>
        </div>`;
      });
    }
  } catch(err) {
    console.error('Dashboard load error', err);
  }
}

async function loadValue(){
  try{
    const v=await fetch('/inveniq/api/index.php?action=value_summary').then(r=>r.json());
    const rm=n=>(v.currency||'RM')+' '+Number(n||0).toLocaleString('en-IN',{maximumFractionDigits:0});
    const set=(id,val)=>{const e=document.getElementById(id);if(e)e.textContent=val;};
    set('v-excess',rm(v.cash_in_excess));
    set('v-expiry',rm(v.expiry_value_at_risk));
    set('v-profit',rm(v.profit_at_risk_monthly));
    set('v-margin',rm(v.forecast_monthly_margin));
    const ps=document.getElementById('v-profit-sub');
    if(ps) ps.textContent=(v.stockout_risk_items||0)+' high-risk items';
  }catch(e){console.error('value load',e);}
}

document.addEventListener('DOMContentLoaded', () => {
  loadDashboard();
  loadValue();
  initAIPage('dash-messages');
});
</script>

<?php render_layout_close(); ?>
