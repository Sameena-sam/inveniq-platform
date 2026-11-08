<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('location-performance');
require_once __DIR__ . '/../components/layout.php';
render_layout_open('location-performance'); ?>
<?php page_header('Location Performance',
    'Where stockouts, excess cash and write-off risk concentrate across your locations'); ?>

<div class="metrics-grid">
  <div class="metric-card loading" id="lp-m1"><div class="metric-icon danger"><?=icon('alert-triangle')?></div><div class="metric-body"><div class="metric-label">Locations Needing Attention</div><div class="metric-value">—</div><div class="metric-sub">Risk score &gt; 10</div></div></div>
  <div class="metric-card loading" id="lp-m2"><div class="metric-icon info"><?=icon('alert-triangle')?></div><div class="metric-body"><div class="metric-label">Cash Tied Up in Excess</div><div class="metric-value">—</div><div class="metric-sub">Across all locations</div></div></div>
  <div class="metric-card loading" id="lp-m3"><div class="metric-icon warning"><?=icon('clock')?></div><div class="metric-body"><div class="metric-label">Write-off Risk (90d)</div><div class="metric-value">—</div><div class="metric-sub">Across all locations</div></div></div>
</div>

<div class="card" style="padding:16px;margin-bottom:14px">
  <div class="card-header" style="padding:0 0 4px">
    <span class="card-title">Operational risk by location</span>
  </div>
  <p style="margin:0 0 12px;color:var(--text-3);font-size:13px">
    A composite score from your actual stock data: stockouts and near-stockouts weigh
    heaviest (lost sales), then excess stock (tied-up cash), then near-expiry stock
    (write-off risk). Higher bars need attention first.
  </p>
  <div style="height:280px"><canvas id="lpChart"></canvas></div>
  <div id="lp-callouts" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:14px"></div>
</div>

<div class="card"><div class="table-toolbar">
  <input class="search-input" id="lp_search" placeholder="Search location…" oninput="filterTable('lp')">
  <span class="table-count" id="lp_count">Loading…</span></div>
<div class="data-table-wrap"><table class="data-table" id="lp_table">
<thead><tr>
  <th>Location</th><th>SKUs</th><th>Stockouts</th><th>Critical/Low</th>
  <th>Excess SKUs</th><th>Cash in Excess</th><th>Expiry Risk (90d)</th><th>Risk Score</th>
</tr></thead>
<tbody id="lp-tbody"></tbody></table></div></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
function fmtINR(v){return '₹'+Number(v||0).toLocaleString('en-IN',{maximumFractionDigits:0});}
function riskColor(score){ if(score>=100) return '#dc2626'; if(score>=10) return '#d97706'; return '#16a34a'; }

async function loadLocationPerformance(){
  let data;
  try{
    data=await fetch('/inveniq/api/index.php?action=location_performance').then(r=>r.json());
  }catch(e){ console.error('location performance load',e); return; }
  if(!Array.isArray(data)||!data.length){
    document.getElementById('lp_count').textContent='No location data';
    return;
  }

  const needAttention = data.filter(r=>r.risk_score>10);
  const totalExcess = data.reduce((s,r)=>s+Number(r.excess_value||0),0);
  const totalExpiry = data.reduce((s,r)=>s+Number(r.expiry_value_90d||0),0);
  [['m1',needAttention.length],['m2',fmtINR(totalExcess)],['m3',fmtINR(totalExpiry)]].forEach(([id,v])=>{
    const el=document.getElementById('lp-'+id); el.classList.remove('loading');
    el.querySelector('.metric-value').textContent=v;
  });

  if(typeof Chart!=='undefined'){
    new Chart(document.getElementById('lpChart'),{type:'bar',
      data:{labels:data.map(r=>r.location_name||r.location_code),
        datasets:[{label:'Risk score',data:data.map(r=>r.risk_score),
          backgroundColor:data.map(r=>riskColor(r.risk_score))}]},
      options:{responsive:true,maintainAspectRatio:false,
        plugins:{legend:{display:false},
          tooltip:{callbacks:{label:(ctx)=>{
            const r=data[ctx.dataIndex];
            return [`Risk score: ${r.risk_score}`,
                    `Stockouts: ${r.stockout_count} (${r.stockout_rate}%)`,
                    `Excess: ${r.excess_count} SKUs, ${fmtINR(r.excess_value)}`,
                    `Expiry risk (90d): ${fmtINR(r.expiry_value_90d)}`];
          }}}},
        scales:{y:{beginAtZero:true,title:{display:true,text:'Risk score (higher = worse)'}}}}});
  }

  const top=data.slice(0,3).filter(r=>r.risk_score>0);
  document.getElementById('lp-callouts').innerHTML=top.map(r=>`
    <div style="flex:1 1 260px;min-width:230px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;border-left:3px solid ${riskColor(r.risk_score)}">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
        <b>${r.location_name||r.location_code}</b>
        <span style="font-size:12px;color:${riskColor(r.risk_score)};font-weight:700">score ${r.risk_score}</span>
      </div>
      <div style="font-size:13px;color:var(--text-2)">
        ${r.stockout_count>0?`<b style="color:#dc2626">${r.stockout_count} stockout(s)</b> · `:''}
        ${r.excess_count>0?`${r.excess_count} SKUs in excess (${fmtINR(r.excess_value)}) · `:''}
        ${r.expiry_value_90d>0?`${fmtINR(r.expiry_value_90d)} at write-off risk`:''}
      </div>
    </div>`).join('');

  document.getElementById('lp-tbody').innerHTML=data.map(r=>`
    <tr class="data-row" data-search="${[r.location_code,r.location_name].join(' ').toLowerCase()}">
      <td class="bold">${r.location_name||r.location_code}<div class="text-muted text-xs">${r.location_code}</div></td>
      <td class="right font-mono">${r.sku_count}</td>
      <td class="right font-mono">${r.stockout_count} <span class="text-muted text-xs">(${r.stockout_rate}%)</span></td>
      <td class="right font-mono">${r.critical_count+r.low_count}</td>
      <td class="right font-mono">${r.excess_count}</td>
      <td class="right font-mono">${fmtINR(r.excess_value)}</td>
      <td class="right font-mono">${fmtINR(r.expiry_value_90d)}</td>
      <td class="right font-mono fw-bold" style="color:${riskColor(r.risk_score)}">${r.risk_score}</td>
    </tr>`).join('');
  document.getElementById('lp_count').textContent=data.length+' locations';
}
document.addEventListener('DOMContentLoaded',loadLocationPerformance);
</script>
<?php render_layout_close(); ?>
