<?php
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../../components/layout.php';
render_layout_open('admin/users');
?>

<?php page_header('User Management', 'Create and manage user accounts and role permissions',
  '<button class="btn btn-primary btn-sm" onclick="openAddUser()">'.icon('plus').' Add User</button>'
); ?>

<!-- Role summary cards -->
<div class="metrics-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:20px" id="role-cards">
  <?php
  $roleInfo = [
    ['super_admin',        'Super Admin',         'shield',  'purple'],
    ['inventory_manager',  'Inventory Manager',   'package-search','info'],
    ['procurement_officer','Procurement Officer', 'truck',   'warning'],
    ['finance_officer',    'Finance Officer',     'credit-card','success'],
    ['viewer',             'Viewer',              'grid',    'muted'],
  ];
  foreach ($roleInfo as [$role, $label, $ico, $variant]): ?>
  <div class="metric-card" id="rc-<?= $role ?>">
    <div class="metric-icon <?= $variant ?>"><?= icon($ico) ?></div>
    <div class="metric-body">
      <div class="metric-label"><?= $label ?></div>
      <div class="metric-value" id="rc-<?= $role ?>-count">—</div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Users table -->
<div class="card">
  <div class="table-toolbar">
    <input class="search-input" id="usr_search" placeholder="Search name, email, role…" oninput="filterTable('usr')">
    <span class="table-count" id="usr_count">Loading…</span>
  </div>
  <div class="data-table-wrap">
    <table class="data-table" id="usr_table">
      <thead><tr>
        <th onclick="sortTable('usr',0)">Name</th>
        <th onclick="sortTable('usr',1)">Email</th>
        <th onclick="sortTable('usr',2)">Role</th>
        <th onclick="sortTable('usr',3)">Status</th>
        <th onclick="sortTable('usr',4)">Last Login</th>
        <th onclick="sortTable('usr',5)">Created</th>
        <th>Actions</th>
      </tr></thead>
      <tbody id="usr-tbody">
        <?php for($i=0;$i<5;$i++) echo '<tr class="skeleton-row">'.str_repeat('<td><div class="skeleton" style="width:90px;height:12px"></div></td>',7).'</tr>'; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Roles permission reference -->
<div class="card" style="margin-top:20px">
  <div class="card-header"><span class="card-title">Role Permissions Reference</span></div>
  <div class="data-table-wrap">
    <table class="data-table">
      <thead><tr>
        <th>Page</th>
        <th style="text-align:center">Super Admin</th>
        <th style="text-align:center">Inventory Mgr</th>
        <th style="text-align:center">Procurement</th>
        <th style="text-align:center">Finance</th>
        <th style="text-align:center">Viewer</th>
      </tr></thead>
      <tbody>
        <?php
        $permMatrix = [
          ['Dashboard',           1,1,1,1,1],
          ['Stock Health',        1,1,0,0,0],
          ['Reorder Points',      1,1,1,0,0],
          ['Replenishment Plan',  1,1,1,0,0],
          ['Excess Stock',        1,1,0,1,0],
          ['Expiry Alerts',       1,1,0,0,0],
          ['Slow Movers',         1,1,0,0,0],
          ['ABC/XYZ Analysis',    1,1,0,0,0],
          ['PO vs GRN',           1,0,1,0,0],
          ['Mismatch Analysis',   1,1,1,0,0],
          ['Supplier Performance',1,0,1,1,0],
          ['Payment Ageing',      1,0,0,1,0],
          ['AI Assistant',        1,1,1,1,1],
          ['Anomaly Detection',   1,1,1,0,0],
          ['Products',            1,1,0,0,0],
          ['Suppliers',           1,0,1,0,0],
          ['Data Management',     1,0,0,0,0],
          ['User Management',     1,0,0,0,0],
        ];
        foreach ($permMatrix as $permRow):
          $page  = $permRow[0];
          $perms = array_slice($permRow, 1);
        ?>
        <tr>
          <td class="bold"><?= $page ?></td>
          <?php foreach ($perms as $p): ?>
          <td style="text-align:center"><?= $p ? '<span style="color:var(--success);font-size:16px">✓</span>' : '<span style="color:var(--border-2);font-size:14px">—</span>' ?></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ADD / EDIT USER MODAL -->
<div class="modal-overlay" id="modal-user">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <span class="modal-title" id="user-modal-title">Add User</span>
      <button class="modal-close" onclick="closeModal('modal-user')">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="form-group form-full">
          <label class="form-label">Full Name</label>
          <input class="form-input" id="u-name" placeholder="e.g. Ahmad Fauzi">
        </div>
        <div class="form-group form-full">
          <label class="form-label">Email Address</label>
          <input class="form-input" id="u-email" type="email" placeholder="user@company.com">
        </div>
        <div class="form-group">
          <label class="form-label">Role</label>
          <select class="form-select" id="u-role">
            <option value="viewer">Viewer</option>
            <option value="inventory_manager">Inventory Manager</option>
            <option value="procurement_officer">Procurement Officer</option>
            <option value="finance_officer">Finance Officer</option>
            <option value="super_admin">Super Admin</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select class="form-select" id="u-active">
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </select>
        </div>
        <div class="form-group form-full">
          <label class="form-label">Password <span id="u-pass-hint" style="font-weight:400;color:var(--text-3)">(required for new users)</span></label>
          <input class="form-input" id="u-password" type="password" placeholder="Minimum 6 characters">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modal-user')">Cancel</button>
      <button class="btn btn-primary" id="u-save-btn" onclick="saveUser()">Save User</button>
    </div>
  </div>
</div>

<script>
const roleBadge = {
  super_admin:         '<span class="badge" style="background:rgba(124,58,237,0.12);color:#7c3aed;border:1px solid #ddd6fe">Super Admin</span>',
  inventory_manager:   '<span class="badge badge-info">Inventory Manager</span>',
  procurement_officer: '<span class="badge badge-warning">Procurement Officer</span>',
  finance_officer:     '<span class="badge badge-success">Finance Officer</span>',
  viewer:              '<span class="badge badge-muted">Viewer</span>',
};

let editingUserId = null;
let allUsers = [];

async function loadUsers() {
  const res  = await fetch('/inveniq/api/index.php?action=users');
  allUsers   = await res.json();

  // Role counts
  const counts = {};
  allUsers.forEach(u => counts[u.role] = (counts[u.role]||0) + 1);
  Object.keys(counts).forEach(role => {
    const el = document.getElementById('rc-' + role + '-count');
    if (el) el.textContent = counts[role];
  });
  // Zero out missing roles
  ['super_admin','inventory_manager','procurement_officer','finance_officer','viewer'].forEach(r => {
    const el = document.getElementById('rc-' + r + '-count');
    if (el && !counts[r]) el.textContent = '0';
  });

  renderUsers(allUsers);
}

function renderUsers(data) {
  document.getElementById('usr-tbody').innerHTML = data.map(u => {
    const active = u.is_active == 1
      ? '<span class="badge badge-success">Active</span>'
      : '<span class="badge badge-muted">Inactive</span>';
    const search = [u.full_name, u.email, u.role].join(' ').toLowerCase();
    const editBtn = `<button class="btn btn-ghost btn-icon" onclick='editUser(${JSON.stringify(u).replace(/'/g,"&#39;")})'>${'<?= icon("pencil") ?>'}</button>`;
    const delBtn  = `<button class="btn btn-ghost btn-icon text-danger" onclick="deleteUser(${u.id},'${u.full_name}')">${'<?= icon("trash") ?>'}</button>`;
    return `<tr class="data-row" data-search="${search}">
      <td class="bold">${u.full_name}</td>
      <td class="text-muted text-xs">${u.email}</td>
      <td>${roleBadge[u.role]||u.role}</td>
      <td>${active}</td>
      <td class="text-xs text-muted">${u.last_login||'Never'}</td>
      <td class="text-xs text-muted">${u.created_at||''}</td>
      <td><div class="gap-row">${editBtn}${delBtn}</div></td>
    </tr>`;
  }).join('');
  document.getElementById('usr_count').textContent = data.length + ' users';
}

function openAddUser() {
  editingUserId = null;
  document.getElementById('user-modal-title').textContent = 'Add User';
  document.getElementById('u-pass-hint').textContent = '(required)';
  ['u-name','u-email','u-password'].forEach(id => setFormValue(id,''));
  setFormValue('u-role','viewer');
  setFormValue('u-active','1');
  openModal('modal-user');
}

function editUser(u) {
  editingUserId = u.id;
  document.getElementById('user-modal-title').textContent = 'Edit User';
  document.getElementById('u-pass-hint').textContent = '(leave blank to keep current)';
  setFormValue('u-name',    u.full_name);
  setFormValue('u-email',   u.email);
  setFormValue('u-role',    u.role);
  setFormValue('u-active',  u.is_active);
  setFormValue('u-password','');
  openModal('modal-user');
}

async function saveUser() {
  const btn = document.getElementById('u-save-btn');
  btn.textContent = 'Saving…'; btn.disabled = true;

  const fields = {
    full_name: formValue('u-name'),
    email:     formValue('u-email'),
    role:      formValue('u-role'),
    is_active: formValue('u-active'),
    password:  formValue('u-password'),
  };

  const action = editingUserId ? 'update_user' : 'create_user';
  if (editingUserId) fields.id = editingUserId;

  const res = await apiPost(action, fields);
  btn.textContent = 'Save User'; btn.disabled = false;

  if (res.success) {
    showToast(editingUserId ? 'User updated successfully' : 'User created successfully');
    closeModal('modal-user');
    loadUsers();
  } else {
    showToast(res.error || 'Error saving user', 'error');
  }
}

async function deleteUser(id, name) {
  if (!confirm('Delete user "' + name + '"? This cannot be undone.')) return;
  const res = await apiPost('delete_user', { id });
  if (res.success) { showToast('User deleted'); loadUsers(); }
  else showToast(res.error || 'Error deleting user', 'error');
}

document.addEventListener('DOMContentLoaded', loadUsers);
</script>

<?php render_layout_close(); ?>
