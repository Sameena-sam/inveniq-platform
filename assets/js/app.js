// =============================================
// InvenIQ — Frontend JavaScript
// =============================================

// ---- TABLE SEARCH ----
function filterTable(sid) {
  const q = document.getElementById(sid + '_search')?.value?.toLowerCase() ?? '';
  const rows = document.querySelectorAll(`#${sid}_table .data-row`);
  let visible = 0;
  rows.forEach(row => {
    const match = !q || row.dataset.search?.includes(q);
    row.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  const counter = document.getElementById(sid + '_count');
  if (counter) counter.textContent = visible + ' rows';
}

// ---- TABLE SORT ----
function sortTable(sid, colIdx) {
  const table = document.getElementById(sid + '_table');
  if (!table) return;
  const tbody = table.querySelector('tbody');
  const rows = Array.from(tbody.querySelectorAll('tr'));
  const th = table.querySelectorAll('th')[colIdx];

  const asc = th.dataset.sort !== 'asc';
  table.querySelectorAll('th').forEach(h => { h.dataset.sort = ''; h.style.color = ''; });
  th.dataset.sort = asc ? 'asc' : 'desc';
  th.style.color = 'var(--text)';

  rows.sort((a, b) => {
    const av = a.querySelectorAll('td')[colIdx]?.textContent?.trim() ?? '';
    const bv = b.querySelectorAll('td')[colIdx]?.textContent?.trim() ?? '';
    const an = parseFloat(av.replace(/[^0-9.-]/g, ''));
    const bn = parseFloat(bv.replace(/[^0-9.-]/g, ''));
    if (!isNaN(an) && !isNaN(bn)) return asc ? an - bn : bn - an;
    return asc ? av.localeCompare(bv) : bv.localeCompare(av);
  });
  rows.forEach(r => tbody.appendChild(r));
}

// ---- MODAL ----
function openModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.add('open');
}
function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove('open');
}
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('open');
  }
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
  }
});

// ---- TOAST ----
function showToast(msg, type = 'success') {
  const container = document.getElementById('toast-container');
  if (!container) return;
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.innerHTML = `<span>${msg}</span>`;
  container.appendChild(toast);
  setTimeout(() => toast.remove(), 3500);
}

// ---- AI CHAT ----
const AI_ANSWERS = {
  stockout: kpis => `Based on your live data, <strong>${kpis?.stockouts ?? '?'} SKUs</strong> are at critical or stockout level.<br>Visit <a href="/stock-health.php" style="color:inherit;text-decoration:underline">Stock Health</a> for the full list sorted by cover days. Raise POs immediately for any SKU below 5-day cover.`,
  supplier: kpis => `Supplier on-time delivery is currently averaging <strong>${kpis?.supplier_ontime ?? '?'}%</strong>.<br>See <a href="/supplier-performance.php" style="color:inherit;text-decoration:underline">Supplier Performance</a> for individual scores. Suppliers below 70% need escalation.`,
  expiry: kpis => `<strong>${kpis?.expiry_risk ?? '?'} batches</strong> are expiring within 90 days.<br>Check <a href="/expiry-alerts.php" style="color:inherit;text-decoration:underline">Expiry Alerts</a> for write-off values per batch. Prioritise shortest shelf-life items for promotion.`,
  excess: kpis => `<strong>${kpis?.excess_stock ?? '?'} SKUs</strong> are above their maximum cover day threshold.<br>This ties up working capital. See <a href="/excess-stock.php" style="color:inherit;text-decoration:underline">Excess Stock</a> and pause ordering on over-stocked lines.`,
  reorder: kpis => `<strong>${kpis?.reorder_alerts ?? '?'} SKUs</strong> are approaching or below their reorder point.<br>Visit <a href="/replenishment.php" style="color:inherit;text-decoration:underline">Replenishment Plan</a> for EOQ-based order quantities ranked by urgency.`,
  po: kpis => `PO vs GRN match rate is currently <strong>${kpis?.po_match_rate ?? '?'}%</strong>.<br>Any rate below 95% indicates fulfilment gaps. See <a href="/po-vs-grn.php" style="color:inherit;text-decoration:underline">PO vs GRN</a> for unmatched and partial orders.`,
  mismatch: () => `Mismatch analysis compares ordering patterns against actual demand velocity.<br>Slow movers being over-ordered, and fast movers chronically under-stocked, are flagged on the <a href="/mismatch-analysis.php" style="color:inherit;text-decoration:underline">Mismatch Analysis</a> page.`,
  abc: () => `ABC/XYZ classification ranks products by revenue contribution (ABC) and demand variability (XYZ).<br>A-items drive ~70% of revenue — prioritise their availability. See <a href="/abc-xyz.php" style="color:inherit;text-decoration:underline">ABC/XYZ Analysis</a> for the full breakdown.`,
  slow: () => `Slow and non-moving stock ties up capital without generating sales.<br>Products with zero sales in the last 90 days are flagged on <a href="/slow-movers.php" style="color:inherit;text-decoration:underline">Slow Movers</a>, ranked by tied-up value.`,
  default: () => `I can answer questions about your live inventory data. Try:<br>
    • "Which products are at risk of stocking out?"<br>
    • "How is supplier on-time delivery?"<br>
    • "What stock is expiring soon?"<br>
    • "Which products have excess stock?"<br>
    • "What needs to be reordered?"<br>
    • "Show me mismatch analysis"`,
};

function classifyQuery(q) {
  q = q.toLowerCase();
  if (q.match(/stock.?out|critical|at risk|running out/)) return 'stockout';
  if (q.match(/supplier|on.?time|delivery|vendor/)) return 'supplier';
  if (q.match(/expir|write.?off|shelf life/)) return 'expiry';
  if (q.match(/excess|overstock|too much/)) return 'excess';
  if (q.match(/reorder|replenish|order/)) return 'reorder';
  if (q.match(/po|grn|reconcil|purchase order/)) return 'po';
  if (q.match(/mismatch|over.?order|under.?stock/)) return 'mismatch';
  if (q.match(/abc|xyz|classification|pareto/)) return 'abc';
  if (q.match(/slow|non.?mov/)) return 'slow';
  return 'default';
}

let aiKPIs = null;

async function loadAIKPIs() {
  if (aiKPIs) return aiKPIs;
  try {
    const r = await fetch('/inveniq/api/index.php?action=kpis');
    aiKPIs = await r.json();
  } catch(e) { aiKPIs = {}; }
  return aiKPIs;
}

function appendAIMsg(containerId, role, html) {
  const c = document.getElementById(containerId);
  if (!c) return null;
  const wrap = document.createElement('div');
  wrap.className = `ai-message ${role}`;
  wrap.innerHTML = `<div class="msg-avatar ${role}">${role === 'ai' ? 'AI' : 'ME'}</div>
    <div class="msg-bubble ${role}">${html}</div>`;
  c.appendChild(wrap);
  c.scrollTop = c.scrollHeight;
  return wrap;
}
function appendTyping(containerId) {
  const c = document.getElementById(containerId);
  const wrap = document.createElement('div');
  wrap.className = 'ai-message ai';
  wrap.innerHTML = `<div class="msg-avatar ai">AI</div>
    <div class="msg-bubble ai"><div class="typing-dots"><span></span><span></span><span></span></div></div>`;
  c.appendChild(wrap);
  c.scrollTop = c.scrollHeight;
  return wrap;
}

const aiHistory = {}; // containerId -> [{role, content}, ...]

async function sendAIMessage(inputId, containerId) {
  const input = document.getElementById(inputId);
  const query = input?.value?.trim();
  if (!query) return;
  input.value = '';
  appendAIMsg(containerId, 'user', query);
  const typing = appendTyping(containerId);

  const history = (aiHistory[containerId] ?? []).slice(-8);
  let reply;
  try {
    const res = await apiPost('ai_chat', { message: query, history: JSON.stringify(history) });
    reply = res.reply || 'Sorry, the AI Assistant is unavailable right now.';
  } catch (e) {
    // Network/API totally unreachable -- fall back to the offline rule-based answers
    // rather than leaving the user with nothing.
    const kpis = await loadAIKPIs();
    const key = classifyQuery(query);
    const fn = AI_ANSWERS[key] ?? AI_ANSWERS.default;
    reply = fn(kpis) + '<br><small style="opacity:.7">(offline fallback \u2014 AI service unreachable)</small>';
  }

  typing.remove();
  appendAIMsg(containerId, 'ai', reply);

  const h = aiHistory[containerId] ?? (aiHistory[containerId] = []);
  h.push({ role: 'user', content: query });
  h.push({ role: 'assistant', content: reply });
}

function useChip(text, inputId, containerId) {
  const el = document.getElementById(inputId);
  if (el) el.value = text;
  sendAIMessage(inputId, containerId);
}

// ---- FORM HELPERS ----
function formValue(id) {
  return document.getElementById(id)?.value ?? '';
}
function setFormValue(id, val) {
  const el = document.getElementById(id);
  if (el) el.value = val;
}

// ---- API HELPER ----
async function apiPost(action, data) {
  const body = new FormData();
  body.append('action', action);
  Object.entries(data).forEach(([k, v]) => body.append(k, v));
  const r = await fetch('/inveniq/api/index.php', { method: 'POST', body });
  return r.json();
}

// ---- AI PAGE INIT ----
function initAIPage(containerId) {
  const c = document.getElementById(containerId);
  if (c && c.children.length === 0) {
    appendAIMsg(containerId, 'ai', AI_ANSWERS.default());
  }
}

// Init on load
document.addEventListener('DOMContentLoaded', () => {
  // Keyboard shortcut: Enter on AI inputs
  document.querySelectorAll('.ai-input').forEach(el => {
    el.addEventListener('keydown', e => {
      if (e.key === 'Enter') {
        const inputId = el.id;
        const containerId = el.dataset.container;
        if (containerId) sendAIMessage(inputId, containerId);
      }
    });
  });
});
