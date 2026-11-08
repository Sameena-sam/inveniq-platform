<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('data-management');
require_once __DIR__ . '/../components/layout.php';
 render_layout_open('data-management'); ?>
<?php page_header('Data Management','Overview and maintenance of all entity data'); ?>
<div class="dm-grid" id="dm-grid">
  <?php
  $entities=[
    ['products','Products','package'],['suppliers','Suppliers','truck'],
    ['movements','Stock Movements','bar-chart-3'],['purchase_orders','Purchase Orders','shopping-cart'],
    ['goods_received','Goods Received','check-circle'],['accounts_payable','Accounts Payable','credit-card'],
    ['stock_balances','Stock Balances','database'],
  ];
  foreach($entities as [$key,$label,$ico]):
  ?>
  <div class="dm-card" id="dm-<?=$key?>">
    <div class="dm-icon"><?=icon($ico)?></div>
    <div>
      <div class="dm-label"><?=$label?></div>
      <div class="dm-count" id="dm-<?=$key?>-count">Loading…</div>
    </div>
    <div class="dm-action">
      <button class="btn btn-danger btn-sm" onclick="clearEntity('<?=$key?>','<?=$label?>')">
        <?=icon('trash')?> Clear
      </button>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<div class="card" style="margin-top:16px">
  <div class="card-header"><span class="card-title">Data Import Guide</span></div>
  <div class="card-body" style="font-size:13px;color:var(--text-2);line-height:1.7">
    <p style="margin-bottom:10px">Use phpMyAdmin (XAMPP) or MySQL Workbench to import data via SQL or CSV. Supported entities:</p>
    <ul style="padding-left:18px">
      <li><strong>Products</strong> — SKU, description, costs, cover day thresholds</li>
      <li><strong>Suppliers</strong> — supplier_id, name, lead times, payment terms</li>
      <li><strong>Stock Movements</strong> — daily sales (movement_type=sale), receipts (purchase_receipt)</li>
      <li><strong>Stock Balances</strong> — current on-hand quantity per SKU/location/batch</li>
      <li><strong>Purchase Orders</strong> — open POs for in-transit and reconciliation calculation</li>
      <li><strong>Goods Received</strong> — GRN records linked to POs</li>
      <li><strong>Accounts Payable</strong> — outstanding invoices for payment ageing</li>
    </ul>
    <p style="margin-top:12px">Import the full seed dataset using <code style="background:var(--bg-muted);padding:1px 5px;border-radius:4px">database.sql</code> via phpMyAdmin → Import.</p>
  </div>
</div>
<script>
async function loadDMCounts(){const data=await fetch('/inveniq/api/index.php?action=dm_counts').then(r=>r.json());Object.entries(data).forEach(([key,count])=>{const el=document.getElementById('dm-'+key+'-count');if(el)el.textContent=Number(count).toLocaleString()+' records';});}
async function clearEntity(key,label){if(!confirm('Delete ALL '+label+' records? This cannot be undone.'))return;const res=await apiPost('clear_entity',{entity:key});if(res.success){showToast(label+' cleared');loadDMCounts();}else showToast(res.error||'Error','error');}
document.addEventListener('DOMContentLoaded',loadDMCounts);
</script>
<?php render_layout_close(); ?>