<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('suppliers');
require_once __DIR__ . '/../components/layout.php';
 render_layout_open('suppliers'); ?>
<?php page_header('Suppliers','', '<button class="btn btn-primary btn-sm" onclick="openAddSupplier()">'.icon('plus').' Add Supplier</button>'); ?>
<div class="card"><div class="table-toolbar"><input class="search-input" id="su_search" placeholder="Search supplier, email…" oninput="filterTable('su')"><span class="table-count" id="su_count">Loading…</span></div>
<div class="data-table-wrap"><table class="data-table" id="su_table">
<thead><tr><th>ID</th><th>Name</th><th>Lead Time</th><th>Payment Terms</th><th>Email</th><th>Phone</th><th>Status</th><th>Actions</th></tr></thead>
<tbody id="su-tbody"></tbody></table></div></div>
<div class="modal-overlay" id="modal-supplier">
  <div class="modal" style="max-width:500px">
    <div class="modal-header"><span class="modal-title" id="su-modal-title">Add Supplier</span><button class="modal-close" onclick="closeModal('modal-supplier')">✕</button></div>
    <div class="modal-body"><div class="form-grid">
      <div class="form-group"><label class="form-label">Supplier ID</label><input class="form-input" id="su-id" placeholder="SUP-001"></div>
      <div class="form-group"><label class="form-label">Status</label><select class="form-select" id="su-status"><option value="active">Active</option><option value="inactive">Inactive</option><option value="on_hold">On Hold</option></select></div>
      <div class="form-group form-full"><label class="form-label">Name</label><input class="form-input" id="su-name"></div>
      <div class="form-group"><label class="form-label">Lead Time (days)</label><input class="form-input" type="number" id="su-lead" value="7"></div>
      <div class="form-group"><label class="form-label">Payment Terms (days)</label><input class="form-input" type="number" id="su-terms" value="30"></div>
      <div class="form-group"><label class="form-label">Email</label><input class="form-input" type="email" id="su-email"></div>
      <div class="form-group"><label class="form-label">Phone</label><input class="form-input" id="su-phone"></div>
    </div></div>
    <div class="modal-footer"><button class="btn btn-outline" onclick="closeModal('modal-supplier')">Cancel</button><button class="btn btn-primary" onclick="saveSupplier()" id="su-save-btn">Save</button></div>
  </div>
</div>
<script>
let editingSuppId=null;
const statBadge={active:'<span class="badge badge-success">Active</span>',inactive:'<span class="badge badge-muted">Inactive</span>',on_hold:'<span class="badge badge-warning">On Hold</span>'};
async function loadSuppliers(){const data=await fetch('/inveniq/api/index.php?action=suppliers').then(r=>r.json());document.getElementById('su-tbody').innerHTML=data.map(r=>`<tr class="data-row" data-search="${[r.supplier_id,r.name,r.contact_email||''].join(' ').toLowerCase()}"><td class="mono">${r.supplier_id}</td><td class="bold">${r.name}</td><td class="right font-mono">${r.lead_time_days}d</td><td class="right font-mono">${r.payment_terms_days}d</td><td class="text-muted text-xs">${r.contact_email||'—'}</td><td class="text-muted text-xs">${r.contact_phone||'—'}</td><td>${statBadge[r.status]||''}</td><td><div class="gap-row"><button class="btn btn-ghost btn-icon" onclick="editSupplier(${JSON.stringify(r).replace(/"/g,'&quot;')})">${'<?=icon("pencil")?>'}</button><button class="btn btn-ghost btn-icon text-danger" onclick="deleteSupplier(${r.id},'${r.name}')">${'<?=icon("trash")?>'}</button></div></td></tr>`).join('');document.getElementById('su_count').textContent=data.length+' suppliers';}
function openAddSupplier(){editingSuppId=null;document.getElementById('su-modal-title').textContent='Add Supplier';['id','name','email','phone'].forEach(id=>setFormValue('su-'+id,''));setFormValue('su-lead','7');setFormValue('su-terms','30');setFormValue('su-status','active');openModal('modal-supplier');}
function editSupplier(r){editingSuppId=r.id;document.getElementById('su-modal-title').textContent='Edit Supplier';setFormValue('su-id',r.supplier_id);setFormValue('su-name',r.name);setFormValue('su-lead',r.lead_time_days);setFormValue('su-terms',r.payment_terms_days);setFormValue('su-email',r.contact_email||'');setFormValue('su-phone',r.contact_phone||'');setFormValue('su-status',r.status);openModal('modal-supplier');}
async function saveSupplier(){const btn=document.getElementById('su-save-btn');btn.textContent='Saving…';btn.disabled=true;const fields={supplier_id:formValue('su-id'),name:formValue('su-name'),lead_time_days:formValue('su-lead'),payment_terms_days:formValue('su-terms'),contact_email:formValue('su-email'),contact_phone:formValue('su-phone'),status:formValue('su-status')};const action=editingSuppId?'update_supplier':'create_supplier';if(editingSuppId)fields.id=editingSuppId;const res=await apiPost(action,fields);btn.textContent='Save';btn.disabled=false;if(res.success){showToast(editingSuppId?'Supplier updated':'Supplier created');closeModal('modal-supplier');loadSuppliers();}else showToast(res.error||'Error','error');}
async function deleteSupplier(id,name){if(!confirm('Delete '+name+'?'))return;const res=await apiPost('delete_supplier',{id});if(res.success){showToast('Supplier deleted');loadSuppliers();}else showToast(res.error||'Error','error');}
document.addEventListener('DOMContentLoaded',loadSuppliers);
</script>
<?php render_layout_close(); ?>