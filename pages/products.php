<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('products');
require_once __DIR__ . '/../components/layout.php';
 render_layout_open('products'); ?>
<?php page_header('Products','', '<button class="btn btn-primary btn-sm" onclick="openModal(\'modal-product\')">'.icon('plus').' Add Product</button>'); ?>
<div class="card"><div class="table-toolbar"><input class="search-input" id="pr_search" placeholder="Search SKU, product, group…" oninput="filterTable('pr')"><span class="table-count" id="pr_count">Loading…</span></div>
<div class="data-table-wrap"><table class="data-table" id="pr_table">
<thead><tr><th>SKU</th><th>Description</th><th>Group</th><th>Unit Cost</th><th>Unit Price</th><th>Min Cover</th><th>Max Cover</th><th>Status</th><th>Actions</th></tr></thead>
<tbody id="pr-tbody"></tbody></table></div></div>

<!-- Product Modal -->
<div class="modal-overlay" id="modal-product">
  <div class="modal" style="max-width:640px">
    <div class="modal-header"><span class="modal-title" id="pr-modal-title">Add Product</span><button class="modal-close" onclick="closeModal('modal-product')">✕</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="form-group"><label class="form-label">SKU</label><input class="form-input" id="pr-sku" placeholder="SKU-0001"></div>
        <div class="form-group"><label class="form-label">Status</label><select class="form-select" id="pr-status"><option value="active">Active</option><option value="discontinued">Discontinued</option><option value="seasonal">Seasonal</option></select></div>
        <div class="form-group form-full"><label class="form-label">Description</label><input class="form-input" id="pr-desc" placeholder="Product name"></div>
        <div class="form-group"><label class="form-label">Product Group</label><input class="form-input" id="pr-group"></div>
        <div class="form-group"><label class="form-label">Unit Cost (₹)</label><input class="form-input" type="number" id="pr-cost" step="0.01"></div>
        <div class="form-group"><label class="form-label">Unit Price (₹)</label><input class="form-input" type="number" id="pr-price" step="0.01"></div>
        <div class="form-group"><label class="form-label">Shelf Life (days)</label><input class="form-input" type="number" id="pr-shelf" value="0"></div>
        <div class="form-group"><label class="form-label">Min Cover Days</label><input class="form-input" type="number" id="pr-mincover" value="14"></div>
        <div class="form-group"><label class="form-label">Max Cover Days</label><input class="form-input" type="number" id="pr-maxcover" value="60"></div>
        <div class="form-group"><label class="form-label">Safety Stock Days</label><input class="form-input" type="number" id="pr-safety" value="7"></div>
        <div class="form-group"><label class="form-label">Reorder Cover Days</label><input class="form-input" type="number" id="pr-reorder" value="30"></div>
        <div class="form-group"><label class="form-label">Ordering Cost (₹)</label><input class="form-input" type="number" id="pr-ordcost" value="50" step="0.01"></div>
        <div class="form-group"><label class="form-label">Holding Cost %</label><input class="form-input" type="number" id="pr-holdpct" value="20" step="0.1"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modal-product')">Cancel</button>
      <button class="btn btn-primary" onclick="saveProduct()" id="pr-save-btn">Save Product</button>
    </div>
  </div>
</div>
<script>
let editingProductId=null;
async function loadProducts(){const data=await fetch('/inveniq/api/index.php?action=products').then(r=>r.json());const statBadge={active:'<span class="badge badge-success">Active</span>',discontinued:'<span class="badge badge-muted">Discontinued</span>',seasonal:'<span class="badge badge-info">Seasonal</span>'};document.getElementById('pr-tbody').innerHTML=data.map(r=>`<tr class="data-row" data-search="${[r.sku,r.description,r.product_group].join(' ').toLowerCase()}"><td class="mono">${r.sku}</td><td class="bold">${r.description}</td><td class="text-muted text-xs">${r.product_group||''}</td><td class="right font-mono">₹${Number(r.unit_cost).toFixed(2)}</td><td class="right font-mono">₹${Number(r.unit_price).toFixed(2)}</td><td class="right font-mono">${r.min_cover_days}d</td><td class="right font-mono">${r.max_cover_days}d</td><td>${statBadge[r.status]||''}</td><td><div class="gap-row"><button class="btn btn-ghost btn-icon" title="Edit" onclick="editProduct(${JSON.stringify(r).replace(/"/g,'&quot;')})">${'<?=icon("pencil")?>'}</button><button class="btn btn-ghost btn-icon text-danger" title="Delete" onclick="deleteProduct(${r.id},'${r.sku}')">${'<?=icon("trash")?>'}</button></div></td></tr>`).join('');document.getElementById('pr_count').textContent=data.length+' SKUs';}
function editProduct(r){editingProductId=r.id;document.getElementById('pr-modal-title').textContent='Edit Product';['sku','desc','group','cost','price','shelf','mincover','maxcover','safety','reorder','ordcost','holdpct','status'].forEach((id,i)=>{const keys=['sku','description','product_group','unit_cost','unit_price','shelf_life_days','min_cover_days','max_cover_days','safety_stock_days','reorder_cover_days','ordering_cost','holding_cost_pct','status'];setFormValue('pr-'+id,r[keys[i]]??'');});openModal('modal-product');}
function openAddProduct(){editingProductId=null;document.getElementById('pr-modal-title').textContent='Add Product';['sku','desc','group','cost','price'].forEach(id=>setFormValue('pr-'+id,''));[['shelf','0'],['mincover','14'],['maxcover','60'],['safety','7'],['reorder','30'],['ordcost','50'],['holdpct','20'],['status','active']].forEach(([id,v])=>setFormValue('pr-'+id,v));openModal('modal-product');}
document.querySelector('[onclick="openModal(\'modal-product\')"]')?.addEventListener('click',openAddProduct,true);
async function saveProduct(){const btn=document.getElementById('pr-save-btn');btn.textContent='Saving…';btn.disabled=true;const fields={sku:formValue('pr-sku'),description:formValue('pr-desc'),product_group:formValue('pr-group'),unit_cost:formValue('pr-cost'),unit_price:formValue('pr-price'),shelf_life_days:formValue('pr-shelf'),min_cover_days:formValue('pr-mincover'),max_cover_days:formValue('pr-maxcover'),safety_stock_days:formValue('pr-safety'),reorder_cover_days:formValue('pr-reorder'),ordering_cost:formValue('pr-ordcost'),holding_cost_pct:formValue('pr-holdpct'),status:formValue('pr-status')};const action=editingProductId?'update_product':'create_product';if(editingProductId)fields.id=editingProductId;const res=await apiPost(action,fields);btn.textContent='Save Product';btn.disabled=false;if(res.success){showToast(editingProductId?'Product updated':'Product created');closeModal('modal-product');loadProducts();}else showToast(res.error||'Error saving','error');}
async function deleteProduct(id,sku){if(!confirm('Delete '+sku+'?'))return;const res=await apiPost('delete_product',{id});if(res.success){showToast('Product deleted');loadProducts();}else showToast(res.error||'Error','error');}
document.addEventListener('DOMContentLoaded',loadProducts);
</script>
<?php render_layout_close(); ?>