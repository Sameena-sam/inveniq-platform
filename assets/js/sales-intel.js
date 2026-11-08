// =============================================
// InvenIQ — Sales Intelligence shared helpers
// (Regional Performance, Product Categories)
// =============================================

function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function num(v){return v===null||v===undefined||v===''?null:Number(v);}

// Lakh throughout tables so columns sort correctly (1 crore = 100 lakh)
function fmtL(v){v=num(v);return v===null?'—':'₹'+(v/1e5).toLocaleString('en-IN',{minimumFractionDigits:1,maximumFractionDigits:1})+' L';}
function fmtINR(v){v=num(v);if(v===null)return '—';const a=Math.abs(v);
  if(a>=1e7)return '₹'+(v/1e7).toFixed(2)+' crore'; if(a>=1e5)return '₹'+(v/1e5).toFixed(1)+' lakh';
  return '₹'+Math.round(v).toLocaleString('en-IN');}
function fmtPct(v,dp=1,sign=false){v=num(v);if(v===null)return '—';return (sign&&v>0?'+':'')+v.toFixed(dp)+'%';}
function fmtMonth(d){if(!d)return '—';const x=new Date(d+'T00:00:00');return x.toLocaleDateString('en-GB',{month:'short',year:'numeric'});}

// 0-100 percentile -> red (bottom of peers) .. green (top of peers) cell tint
function heat(score){score=num(score);if(score===null)return '';
  return score>=50?`background:rgba(22,163,74,${((score-50)/50*0.32).toFixed(3)})`
                  :`background:rgba(220,38,38,${((50-score)/50*0.24).toFixed(3)})`;}

function setMetric(elId,value,sub){const el=document.getElementById(elId);if(!el)return;el.classList.remove('loading');
  el.querySelector('.metric-value').textContent=value; if(sub!==undefined)el.querySelector('.metric-sub').textContent=sub;}

// Clicks read data-key, so a name with a quote in it cannot break the handler
function onRowClick(tbodyId,fn){document.getElementById(tbodyId).addEventListener('click',e=>{
  const row=e.target.closest('[data-key]'); if(row)fn(row.dataset.key);});}

// Shown when the pipeline has not written results yet
function noResultsBanner(el){
  el.style.borderLeftColor='var(--warning)';
  el.innerHTML=`<span class="badge badge-warning">No results yet</span>
    <span style="margin-left:10px">Run the sales-intelligence pipeline once:
    <code>python analytics/run_pipeline.py</code> (XAMPP MySQL must be running).</span>`;
}
