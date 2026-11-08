<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('demand-forecast');
require_once __DIR__ . '/../components/layout.php';
render_layout_open('demand-forecast'); ?>
<?php page_header('Demand Forecast (ML)',
    'Per-product demand predicted by a machine-learning model trained on your real sales history (model.pkl)'); ?>

<!-- Model status banner -->
<div id="ml-banner" class="card" style="padding:14px 16px;margin-bottom:14px;border-left:4px solid var(--text-3)">
  <span class="metric-label">Checking model…</span>
</div>

<div class="metrics-grid">
  <div class="metric-card loading" id="df-m1"><div class="metric-icon default"><?=icon('package')?></div><div class="metric-body"><div class="metric-label">Products Forecast</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="df-m2"><div class="metric-icon info"><?=icon('shopping-cart')?></div><div class="metric-body"><div class="metric-label">Forecast Monthly Revenue</div><div class="metric-value">—</div></div></div>
  <div class="metric-card loading" id="df-m3"><div class="metric-icon success"><?=icon('bar-chart-3')?></div><div class="metric-body"><div class="metric-label">Forecast Monthly Margin</div><div class="metric-value">—</div></div></div>
</div>

<!-- Demand trend + forecast chart -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<div class="card" style="padding:16px;margin-bottom:14px">
  <div class="card-header" style="padding:0 0 10px">
    <span class="card-title">Company demand — actual history &amp; ML forecast</span>
  </div>
  <div style="height:300px"><canvas id="trendChart"></canvas></div>
</div>

<!-- Seasonal demand patterns (by therapeutic category) -->
<div class="card" id="seasonal-card" style="padding:16px;margin-bottom:14px;display:none">
  <div class="card-header" style="padding:0 0 4px">
    <span class="card-title">Seasonal demand patterns (by category)</span>
  </div>
  <p style="margin:0 0 12px;color:var(--text-3);font-size:13px">
    Learned from your real sales history: how far each category's monthly demand
    typically rises or falls versus its own yearly average. Feeds directly into the
    forecast above — it is a demand-pattern signal, not a medical diagnosis.
  </p>
  <div style="height:260px"><canvas id="seasonalChart"></canvas></div>
  <div id="seasonal-callouts" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:14px"></div>
</div>

<div class="card"><div class="table-toolbar">
  <input class="search-input" id="df_search" placeholder="Search product / segment…" oninput="filterTable('df')">
  <span class="table-count" id="df_count">Loading…</span></div>
<div class="data-table-wrap"><table class="data-table" id="df_table">
<thead><tr>
  <th>SKU</th><th>Product</th><th>Segment</th>
  <th>Forecast / day</th><th>Next month</th>
  <th>Next 3 months</th>
  <th>Margin %</th><th>Exp. monthly margin</th>
</tr></thead>
<tbody id="df-tbody"></tbody></table></div></div>

<script>
function fmtRM(v){return '₹'+Number(v||0).toLocaleString('en-IN',{maximumFractionDigits:0});}

async function loadModelBanner(){
  const b=document.getElementById('ml-banner');
  try{
    const s=await fetch('/inveniq/api/index.php?action=ml_status').then(r=>r.json());
    if(s && s.available){
      const m=s.model||{}; const met=(m.metrics&&m.metrics.ml_blend)?m.metrics.ml_blend:{};
      const base=(m.metrics&&m.metrics.baseline_flat_avg)?m.metrics.baseline_flat_avg:{};
      b.style.borderLeftColor='var(--success)';
      b.innerHTML=`<div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center">
        <span class="badge badge-success">Model active · via ${s.source}</span>
        <span><b>${s.sku_count}</b> products served</span>
        <span>Trained on data to <b>${m.data_to||'—'}</b></span>
        <span>Accuracy: <b>${met.MAE??'—'}</b> MAE vs <b>${base.MAE??'—'}</b> flat-average
          → <b style="color:var(--success)">${m.mae_improvement_vs_flat_avg_pct??0}% better</b></span>
        <span style="color:var(--text-3)">trained ${ (m.trained_at||'').replace('T',' ') }</span>
      </div>`;
    }else{
      b.style.borderLeftColor='var(--warning)';
      b.innerHTML=`<span class="badge badge-warning">Model offline</span>
        <span style="margin-left:10px">The app is using rule-based fallback. To enable ML predictions:
        train once with <code>python ml/train_model.py</code>, then start the connector
        <code>python ml/predict_service.py</code>.</span>`;
    }
  }catch(e){
    b.style.borderLeftColor='var(--danger)';
    b.innerHTML='<span class="badge badge-danger">Could not reach API</span>';
  }
}

function spark(months){
  if(!months||!months.length) return '';
  const vals=months.map(m=>Number(m.forecast_qty||0));
  const max=Math.max(1,...vals);
  return '<span style="display:inline-flex;gap:3px;align-items:flex-end;height:26px">'+
    vals.map((v,i)=>{const h=Math.max(3,Math.round(v/max*24));
      return `<span title="${months[i].ym}: ${Math.round(v).toLocaleString()}" style="width:10px;height:${h}px;background:var(--primary);border-radius:2px;display:inline-block"></span>`;}).join('')+
    '</span>';
}

async function loadDF(){
  const data=await fetch('/inveniq/api/index.php?action=demand_forecast').then(r=>r.json());
  if(!Array.isArray(data)||!data.length){
    document.getElementById('df_count').textContent='No forecast (model offline)';
    ['m1','m2','m3'].forEach(id=>{const el=document.getElementById('df-'+id);el.classList.remove('loading');});
    return;
  }
  const rev=data.reduce((s,r)=>s+Number(r.expected_monthly_revenue||0),0);
  const mar=data.reduce((s,r)=>s+Number(r.expected_monthly_margin||0),0);
  [['m1',data.length],['m2',fmtRM(rev)],['m3',fmtRM(mar)]].forEach(([id,v])=>{
    const el=document.getElementById('df-'+id);el.classList.remove('loading');
    el.querySelector('.metric-value').textContent=v;});
  document.getElementById('df-tbody').innerHTML=data.map(r=>`
    <tr class="data-row" data-search="${[r.sku,r.description,r.product_group].join(' ').toLowerCase()}">
      <td class="mono">${r.sku}</td>
      <td class="bold">${r.description}</td>
      <td>${r.product_group||''}</td>
      <td class="right font-mono">${Number(r.daily_demand).toFixed(1)}</td>
      <td class="right font-mono">${Number(r.month_demand).toLocaleString('en-IN',{maximumFractionDigits:0})}</td>
      <td>${spark(r.monthly_forecast)}</td>
      <td class="right font-mono">${Number(r.margin_pct).toFixed(1)}%</td>
      <td class="right font-mono fw-bold">${fmtRM(r.expected_monthly_margin)}</td>
    </tr>`).join('');
  document.getElementById('df_count').textContent=data.length+' products';
}
async function loadTrend(){
  try{
    const d=await fetch('/inveniq/api/index.php?action=demand_trend').then(r=>r.json());
    const hist=d.history||[], fc=d.forecast||[];
    if(!hist.length && !fc.length) return;
    const labels=hist.map(h=>h.ym).concat(fc.map(f=>f.ym));
    const histData=hist.map(h=>h.qty).concat(fc.map(()=>null));
    const fcData=hist.map(()=>null);
    if(hist.length) fcData[hist.length-1]=hist[hist.length-1].qty; // bridge lines
    fc.forEach(f=>fcData.push(f.qty));
    if(typeof Chart==='undefined') return;
    new Chart(document.getElementById('trendChart'),{type:'line',
      data:{labels,datasets:[
        {label:'Actual sales',data:histData,borderColor:'#2563eb',backgroundColor:'rgba(37,99,235,.10)',fill:true,tension:.25,pointRadius:2},
        {label:'ML forecast',data:fcData,borderColor:'#7c3aed',borderDash:[6,4],tension:.25,pointRadius:3}
      ]},
      options:{responsive:true,maintainAspectRatio:false,interaction:{intersect:false,mode:'index'},
        plugins:{legend:{position:'top'}},
        scales:{y:{beginAtZero:true,title:{display:true,text:'Units / month'}}}}});
  }catch(e){console.error('trend load',e);}
}
const SEASON_COLORS=['#dc2626','#7c3aed','#0891b2','#d97706','#16a34a','#2563eb'];
async function loadSeasonalPatterns(){
  try{
    const rows=await fetch('/inveniq/api/index.php?action=seasonal_patterns').then(r=>r.json());
    if(!Array.isArray(rows)||!rows.length) return; // hidden by default until the model has this feature
    const card=document.getElementById('seasonal-card');
    card.style.display='';
    const months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const top=rows.slice(0,6);
    if(typeof Chart!=='undefined'){
      new Chart(document.getElementById('seasonalChart'),{type:'line',
        data:{labels:months,datasets:top.map((r,i)=>({
          label:r.segment,
          data:r.monthly.map(v=>Math.round((v-1)*1000)/10),
          borderColor:SEASON_COLORS[i%SEASON_COLORS.length],
          backgroundColor:'transparent',
          tension:.3,pointRadius:3,borderWidth:2
        }))},
        options:{responsive:true,maintainAspectRatio:false,
          interaction:{intersect:false,mode:'index'},
          plugins:{legend:{position:'top'},
            tooltip:{callbacks:{label:(ctx)=>`${ctx.dataset.label}: ${ctx.parsed.y>0?'+':''}${ctx.parsed.y}% vs average`}}},
          scales:{y:{title:{display:true,text:'% vs category yearly average'},
                      ticks:{callback:v=>(v>0?'+':'')+v+'%'}}}}});
    }
    document.getElementById('seasonal-callouts').innerHTML=top.map((r,i)=>`
      <div style="flex:1 1 220px;min-width:200px;padding:10px 12px;border:1px solid var(--border);border-radius:8px">
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">
          <span style="width:10px;height:10px;border-radius:50%;background:${SEASON_COLORS[i%SEASON_COLORS.length]};display:inline-block"></span>
          <b>${r.segment}</b>
        </div>
        <div style="font-size:13px;color:var(--text-2)">
          Peaks <b style="color:var(--success)">+${r.peak_pct}%</b> in <b>${r.peak_month}</b>,
          dips <b style="color:var(--danger)">${r.trough_pct}%</b> in <b>${r.trough_month}</b>
        </div>
      </div>`).join('');
  }catch(e){console.error('seasonal patterns load',e);}
}
document.addEventListener('DOMContentLoaded',()=>{loadModelBanner();loadDF();loadTrend();loadSeasonalPatterns();});
</script>
<?php render_layout_close(); ?>
