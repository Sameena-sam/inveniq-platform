<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('anomaly-detection');
require_once __DIR__ . '/../components/layout.php';
 render_layout_open('anomaly-detection'); ?>
<?php page_header('AI-Powered Anomaly Detection','Statistical anomaly detection on inventory and demand patterns'); ?>
<div class="metrics-grid">
  <div class="metric-card loading" id="an-m1"><div class="metric-icon warning"><?=icon('alert-triangle')?></div><div class="metric-body"><div class="metric-label">Total Anomalies</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="an-m2"><div class="metric-icon danger"><?=icon('zap')?></div><div class="metric-body"><div class="metric-label">High Severity</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="an-m3"><div class="metric-icon warning"><?=icon('bar-chart-3')?></div><div class="metric-body"><div class="metric-label">Medium Severity</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="an-m4"><div class="metric-icon info"><?=icon('alert-triangle')?></div><div class="metric-body"><div class="metric-label">Low Severity</div><div class="metric-value">—</div></div></div>
</div>
<div class="section-gap" id="anomaly-list-wrap">
  <div style="color:var(--text-3);font-size:13px;padding:24px;text-align:center">Running anomaly detection…</div>
</div>
<script>
async function loadAnomalies(){
  const [health,reorder]=await Promise.all([fetch('/inveniq/api/index.php?action=stock_health').then(r=>r.json()),fetch('/inveniq/api/index.php?action=reorder_points').then(r=>r.json())]);
  const anomalies=[];
  // Velocity anomalies (demand spikes vs excess)
  reorder.forEach(r=>{
    const v=Number(r.daily_sales);
    const stock=Number(r.current_stock);
    const rop=Number(r.reorder_point);
    if(v>0&&stock<rop*0.3&&stock>0) anomalies.push({severity:'high',type:'Critical Understock',sku:r.sku,name:r.description,detail:`Stock (${stock.toLocaleString()}) is only ${Math.round(stock/rop*100)}% of reorder point. Daily sales: ${v.toFixed(1)}/day.`});
    else if(v>0&&stock<rop) anomalies.push({severity:'medium',type:'Below Reorder Point',sku:r.sku,name:r.description,detail:`Available stock (${(stock+Number(r.in_transit)).toLocaleString()}) is below reorder point (${rop.toLocaleString()}).`});
  });
  health.filter(h=>h.status==='excess').forEach(r=>{anomalies.push({severity:'low',type:'Excess Stock Outlier',sku:r.sku,name:r.description,detail:`${r.cover_days} days cover vs max ${r.max_cover_days}d. Excess value: ₹${Number(r.excess_value||0).toLocaleString('en-IN',{minimumFractionDigits:0})}.`});});
  const high=anomalies.filter(a=>a.severity==='high'),med=anomalies.filter(a=>a.severity==='medium'),low=anomalies.filter(a=>a.severity==='low');
  [['m1',anomalies.length],['m2',high.length],['m3',med.length],['m4',low.length]].forEach(([id,v])=>{const el=document.getElementById('an-'+id);el.classList.remove('loading');el.querySelector('.metric-value').textContent=v;});
  const wrap=document.getElementById('anomaly-list-wrap');
  if(!anomalies.length){wrap.innerHTML='<div style="color:var(--success);padding:24px;text-align:center">✓ No anomalies detected</div>';return;}
  wrap.innerHTML=`<div class="anomaly-list">${anomalies.map(a=>{const borderClr=a.severity==='high'?'var(--danger)':a.severity==='medium'?'var(--warning)':'var(--info)';const badge=a.severity==='high'?'<span class="badge badge-danger">High</span>':a.severity==='medium'?'<span class="badge badge-warning">Medium</span>':'<span class="badge badge-info">Low</span>';return`<div class="anomaly-card ${a.severity}"><div class="anomaly-header"><span class="anomaly-sku">${a.sku}</span><span class="anomaly-name">${a.name}</span><span style="font-size:11px;color:var(--text-3)">${a.type}</span>${badge}</div><div class="anomaly-detail">${a.detail}</div></div>`;}).join('')}</div>`;
}
document.addEventListener('DOMContentLoaded',loadAnomalies);
</script>
<?php render_layout_close(); ?>