<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('sales-action-plan');
require_once __DIR__ . '/../components/layout.php';
render_layout_open('sales-action-plan'); ?>
<?php page_header('Sales Action Plan',
    'Who to contact, why, and whether it helped'); ?>

<link rel="stylesheet" href="/inveniq/assets/css/sales-intel.css">
<style>
  .sa-list{max-height:430px;overflow-y:auto}
  .sa-list thead th{position:sticky;top:0;z-index:1;background:var(--surface,#fff)}
  .sa-note{font-size:12px;color:var(--text-2);line-height:1.5;min-width:170px}
  .sa-done{white-space:nowrap;font-size:12px;color:var(--success)}
  .sa-undo{background:none;border:0;padding:0 0 0 6px;color:var(--text-3);font-size:12px;text-decoration:underline;cursor:pointer}
</style>

<!-- Data status banner -->
<div id="sa-banner" class="card" style="padding:14px 16px;margin-bottom:14px;border-left:4px solid var(--text-3)">
  <span class="metric-label">Loading results…</span>
</div>

<div class="metrics-grid">
  <div class="metric-card loading" id="sa-m1"><div class="metric-icon danger"><?=icon('alert-triangle')?></div><div class="metric-body"><div class="metric-label">Big Accounts Buying Less</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
  <div class="metric-card loading" id="sa-m2"><div class="metric-icon warning"><?=icon('clock')?></div><div class="metric-body"><div class="metric-label">Customers Going Quiet</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
  <div class="metric-card loading" id="sa-m3"><div class="metric-icon info"><?=icon('trending-up')?></div><div class="metric-body"><div class="metric-label">Expansion Customers</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
  <div class="metric-card loading" id="sa-m4"><div class="metric-icon success"><?=icon('check-circle')?></div><div class="metric-body"><div class="metric-label">Actions Marked Done</div><div class="metric-value">—</div><div class="metric-sub">—</div></div></div>
</div>

<div class="card" style="margin-bottom:20px">
  <div class="card-header"><span class="card-title">1. Big accounts buying less</span><span class="si-mini">latest 6 months against the same months last year</span></div>
  <div class="data-table-wrap sa-list"><table class="data-table si-compact">
    <thead><tr><th>Customer</th><th>Same months last year</th><th>Latest 6 months</th><th>Change</th><th>Biggest falls</th><th>Action</th></tr></thead>
    <tbody id="sa-accounts"></tbody></table></div>
</div>

<div class="card" style="margin-bottom:20px">
  <div class="card-header"><span class="card-title">2. Customers going quiet</span><span class="si-mini">well past their usual time between orders</span></div>
  <div class="data-table-wrap sa-list"><table class="data-table si-compact">
    <thead><tr><th>Customer</th><th>Usually orders every</th><th>Last order</th><th>Bought in last 12 months</th><th>Usually buys</th><th>Action</th></tr></thead>
    <tbody id="sa-quiet"></tbody></table></div>
</div>

<div class="card" style="margin-bottom:20px">
  <div class="card-header"><span class="card-title">3. Expansion customers</span><span class="si-mini">from the top 15 on Expansion Opportunities</span></div>
  <div class="data-table-wrap sa-list"><table class="data-table si-compact">
    <thead><tr><th>Customer</th><th>Offer this category</th><th>Start with</th><th>Buys from you now / yr</th><th></th><th>Action</th></tr></thead>
    <tbody id="sa-expansion"></tbody></table></div>
</div>

<div class="two-col-grid">
  <div class="card">
    <div class="card-header"><span class="card-title">Did it work?</span></div>
    <div class="card-body si-method" id="sa-results"></div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">How to read this page</span></div>
    <div class="card-body si-method">
      <p><b>Big accounts buying less.</b> Customers who bought at least ₹2 lakh in the earlier months and are down 20% or more on the same months last year.</p>
      <p><b>Customers going quiet.</b> Regular customers who have gone more than twice their usual time since their last order.</p>
      <p><b>These lists describe what has happened.</b> They do not say why, or whether a customer can be won back. Your team will know things the numbers cannot, such as a customer who has closed.</p>
      <p><b>Mark done</b> when someone has contacted the customer. Later sales are then compared with customers nobody marked.</p>
    </div>
  </div>
</div>

<script src="/inveniq/assets/js/sales-intel.js"></script>
<script>
const STALE_DAYS=45;
let SA=null;

function actionKey(kind,x){return [kind,x.customer,x.location_code||'',x.segment||''].join('|');}

function actionCell(kind,x){
  const k=actionKey(kind,x), done=(SA.actions||[]).find(a=>actionKey(a.kind,a)===k);
  const data=`data-kind="${esc(kind)}" data-customer="${esc(x.customer)}" data-location="${esc(x.location_code||'')}" data-segment="${esc(x.segment||'')}"`;
  return done
    ?`<span class="sa-done">✓ Done ${esc(fmtDay(done.done_on))}</span><button class="sa-undo" ${data} data-done="0">Undo</button>`
    :`<button class="btn btn-sm btn-secondary" ${data} data-done="1">Mark done</button>`;
}

function fmtDay(d){return d?new Date(d+'T00:00:00').toLocaleDateString('en-GB',{day:'numeric',month:'short'}):'';}
function who(x){return `<span class="si-name">${esc(x.customer)}</span><div class="text-muted text-xs">${esc(x.territory||'')}</div>`;}

async function loadSA(){
  const banner=document.getElementById('sa-banner');
  let data;
  try{ data=await fetch('/inveniq/api/index.php?action=sales_action_plan').then(r=>r.json()); }
  catch(e){ banner.style.borderLeftColor='var(--danger)'; banner.innerHTML='<span class="badge badge-danger">Could not reach API</span>'; return; }
  if(!data||!data.available){
    noResultsBanner(banner);
    ['m1','m2','m3','m4'].forEach(id=>document.getElementById('sa-'+id).classList.remove('loading'));
    return;
  }
  SA=data;
  renderBanner(banner); renderAll();
}

function renderBanner(banner){
  const run=SA.run, days=Math.floor((Date.now()-new Date(run.data_to+'T00:00:00'))/864e5), stale=days>STALE_DAYS;
  banner.style.borderLeftColor=stale?'var(--warning)':'var(--success)';
  banner.innerHTML=`<div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center">
    <span class="badge ${stale?'badge-warning':'badge-success'}">${stale?'Sales data is '+days+' days old':'Sales data is up to date'}</span>
    <span>Based on sales up to <b>${fmtMonth(run.data_to)}</b>${stale?'. Some of this may have changed, so load newer sales before relying on these lists':''}</span>
    <span style="color:var(--text-3)">updated ${esc(run.run_at)}</span></div>`;
}

function renderAll(){
  const a=SA.accounts,q=SA.quiet,e=SA.expansion,act=SA.actions;
  const fall=a.reduce((s,x)=>s+num(x.change_value),0), measured=act.filter(x=>x.status==='measured');
  setMetric('sa-m1',a.length,`${fmtINR(Math.abs(fall))} below the same months last year`);
  setMetric('sa-m2',q.length,`bought ${fmtINR(q.reduce((s,x)=>s+num(x.value_12m),0))} in the last 12 months`);
  setMetric('sa-m3',e.length,'customers to offer a new category to');
  setMetric('sa-m4',act.length,`${measured.length} with enough new sales to compare`);
  document.getElementById('sa-accounts').innerHTML=a.map(x=>`<tr>
      <td>${who(x)}</td>
      <td class="right font-mono si-heat">${fmtINR(x.sales_before)}</td>
      <td class="right font-mono si-heat">${fmtINR(x.sales_now)}</td>
      <td class="right font-mono si-heat"><b>${fmtINR(x.change_value)}</b><div class="text-muted text-xs">${fmtPct(x.change_pct,0)}</div></td>
      <td class="sa-note">${x.falls.map(f=>`${esc(f.segment)} (${fmtINR(f.before)} to ${fmtINR(f.now)})`).join('<br>')||'—'}</td>
      <td>${actionCell('account',x)}</td></tr>`).join('')||emptyRow('No big accounts are buying less right now.');
  document.getElementById('sa-quiet').innerHTML=q.map(x=>`<tr>
      <td>${who(x)}</td>
      <td class="font-mono si-heat">${num(x.usual_gap_days)} days</td>
      <td class="font-mono si-heat">${fmtMonth(x.last_order)}<div class="text-muted text-xs">${num(x.days_since)} days before the latest data</div></td>
      <td class="right font-mono si-heat">${fmtINR(x.value_12m)}</td>
      <td class="sa-note">${esc(x.buys.join(', '))||'—'}</td>
      <td>${actionCell('quiet',x)}</td></tr>`).join('')||emptyRow('No regular customers have gone quiet right now.');
  document.getElementById('sa-expansion').innerHTML=e.map(x=>`<tr>
      <td>${who(x)}</td>
      <td class="si-name si-heat">${esc(x.segment)}</td>
      <td class="sa-note">${esc(x.products.join(', '))||'—'}</td>
      <td class="right font-mono si-heat">${fmtINR(x.spend)}</td>
      <td class="text-xs">${x.used_to_buy?'<span class="badge badge-warning">Used to buy this</span>':''}</td>
      <td>${actionCell('expansion',x)}</td></tr>`).join('')||emptyRow('No expansion customers right now.');
  renderResults(act,measured);
}

function emptyRow(text){return `<tr><td colspan="6" class="text-muted" style="text-align:center;padding:18px">${esc(text)}</td></tr>`;}

function renderResults(act,measured){
  const el=document.getElementById('sa-results');
  if(!act.length){ el.innerHTML='<p>Nothing marked yet. Mark customers done as your team contacts them, and this will compare their later sales with the customers nobody marked.</p>'; return; }
  if(!measured.length){ el.innerHTML=`<p><b>${act.length}</b> marked so far. The comparison appears once at least 30 days of sales after a mark have been loaded.</p>`; return; }
  const sum=(k)=>measured.reduce((s,a)=>s+num(a[k]),0);
  const before=sum('sales_before'), after=sum('sales_after');
  // what the same customers would have bought had they moved like the customers nobody marked
  const expected=measured.reduce((s,a)=>num(a.others_before)>0?s+num(a.sales_before)*num(a.others_after)/num(a.others_before):s,0);
  el.innerHTML=`<p>Compared with the same dates a year earlier, for <b>${measured.length}</b> action${measured.length===1?'':'s'} with enough new sales:</p>
    <div class="si-kv"><div><span>Contacted customers bought</span><br><b>${fmtINR(before)}</b> then, <b>${fmtINR(after)}</b> now</div>
    <div><span>If they had moved like customers nobody marked</span><br><b>${fmtINR(expected)}</b> now</div></div>
    <p>This is a guide, not proof. Your team chooses who to contact, so these customers may differ from the rest in other ways. ${act.length-measured.length} more waiting for newer sales.</p>`;
}

document.addEventListener('click',async e=>{
  const b=e.target.closest('button[data-kind]'); if(!b)return;
  b.disabled=true;
  const body=new URLSearchParams({action:'sales_action_mark',kind:b.dataset.kind,customer:b.dataset.customer,
    location_code:b.dataset.location,segment:b.dataset.segment,done:b.dataset.done});
  let r=null;
  try{ r=await fetch('/inveniq/api/index.php',{method:'POST',headers:{'X-Requested-With':'fetch'},body}).then(x=>x.json()); }catch(err){}
  if(!r||!r.ok){ b.disabled=false; alert('That could not be saved. Please try again.'); return; }
  const fresh=await fetch('/inveniq/api/index.php?action=sales_action_plan').then(x=>x.json());
  SA=fresh; renderAll();
});

document.addEventListener('DOMContentLoaded',loadSA);
</script>
<?php render_layout_close(); ?>
