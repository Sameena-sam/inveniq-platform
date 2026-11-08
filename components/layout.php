<?php
// =============================================================
// InvenIQ — Layout Component v2 (with role-based navigation)
// =============================================================
require_once __DIR__ . '/../includes/auth.php';

// Full nav definition — auth.php ROLES controls what each role sees
$NAV_ALL = [
  'Overview' => [
    ['id'=>'dashboard',            'label'=>'Dashboard',           'icon'=>'grid'],
  ],
  'Stock Intelligence' => [
    ['id'=>'stock-health',         'label'=>'Stock Health',        'icon'=>'package-search'],
    ['id'=>'reorder-points',       'label'=>'Reorder Points',      'icon'=>'refresh-cw'],
    ['id'=>'replenishment',        'label'=>'Replenishment Plan',  'icon'=>'shopping-cart'],
    ['id'=>'excess-stock',         'label'=>'Excess Stock',        'icon'=>'alert-triangle'],
    ['id'=>'expiry-alerts',        'label'=>'Expiry Alerts',       'icon'=>'clock'],
    ['id'=>'slow-movers',          'label'=>'Slow Movers',         'icon'=>'trending-down'],
    ['id'=>'abc-xyz',              'label'=>'ABC/XYZ Analysis',    'icon'=>'bar-chart-3'],
    ['id'=>'location-performance', 'label'=>'Location Performance','icon'=>'map-pin'],
  ],
  'Sales Intelligence' => [
    ['id'=>'regional-performance', 'label'=>'Regional Performance','icon'=>'map'],
    ['id'=>'product-categories',   'label'=>'Product Categories',  'icon'=>'layers'],
    ['id'=>'expansion-opportunities','label'=>'Expansion Opportunities','icon'=>'trending-up'],
    ['id'=>'sales-action-plan',    'label'=>'Sales Action Plan',   'icon'=>'check-circle'],
  ],
  'Procurement' => [
    ['id'=>'po-vs-grn',            'label'=>'PO vs GRN',           'icon'=>'git-compare'],
    ['id'=>'mismatch-analysis',    'label'=>'Mismatch Analysis',   'icon'=>'alert-triangle'],
    ['id'=>'supplier-performance', 'label'=>'Supplier Performance','icon'=>'truck'],
    ['id'=>'payment-ageing',       'label'=>'Payment Ageing',      'icon'=>'credit-card'],
  ],
  'AI & Analytics' => [
    ['id'=>'demand-forecast',      'label'=>'Demand Forecast (ML)','icon'=>'line-chart'],
    ['id'=>'ai-assistant',         'label'=>'AI Assistant',        'icon'=>'bot'],
    ['id'=>'anomaly-detection',    'label'=>'Anomaly Detection',   'icon'=>'zap'],
  ],
  'Master Data' => [
    ['id'=>'products',             'label'=>'Products',            'icon'=>'package'],
    ['id'=>'suppliers',            'label'=>'Suppliers',           'icon'=>'users'],
    ['id'=>'data-management',      'label'=>'Data Management',     'icon'=>'database'],
  ],
  'Administration' => [
    ['id'=>'admin/users',          'label'=>'User Management',     'icon'=>'shield'],
  ],
];

// Filter nav based on current user role
function getFilteredNav(array $navAll): array {
    $out = [];
    foreach ($navAll as $section => $items) {
        $allowed = array_filter($items, fn($item) => canAccess($item['id']));
        if (count($allowed)) $out[$section] = array_values($allowed);
    }
    return $out;
}

// SVG icon map
function icon(string $name, string $cls = ''): string {
  $icons = [
    'grid'           => '<rect x="3" y="3" w="7" h="7"/><rect x="14" y="3" w="7" h="7"/><rect x="3" y="14" w="7" h="7"/><rect x="14" y="14" w="7" h="7"/>',
    'package-search' => '<path d="m21 10-9-7-9 7v11a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2z"/><circle cx="12" cy="14" r="3"/><path d="m18 18-2-2"/>',
    'refresh-cw'     => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
    'shopping-cart'  => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
    'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/>',
    'clock'          => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'trending-down'  => '<polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/>',
    'bar-chart-3'    => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
    'map-pin'        => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
    'map'            => '<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" x2="8" y1="2" y2="18"/><line x1="16" x2="16" y1="6" y2="22"/>',
    'trending-up'    => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
    'target'         => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
    'layers'         => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
    'line-chart'     => '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>',
    'cpu'            => '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 2v2M15 2v2M9 20v2M15 20v2M2 9h2M2 15h2M20 9h2M20 15h2"/>',
    'git-compare'    => '<circle cx="18" cy="18" r="3"/><circle cx="6" cy="6" r="3"/><path d="M13 6h3a2 2 0 0 1 2 2v7"/><path d="M11 18H8a2 2 0 0 1-2-2V9"/>',
    'truck'          => '<rect width="16" height="13" x="1" y="3" rx="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
    'credit-card'    => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
    'bot'            => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
    'zap'            => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    'package'        => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
    'users'          => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'database'       => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
    'log-out'        => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
    'plus'           => '<line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/>',
    'pencil'         => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>',
    'trash'          => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
    'x'              => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    'search'         => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'check-circle'   => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    'send'           => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
    'shield'         => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'user'           => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'key'            => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
  ];
  $body = $icons[$name] ?? '';
  $stroke = 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
  return "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" $stroke class=\"$cls\">$body</svg>";
}

function render_layout_open(string $activePage): void {
  global $NAV_ALL;
  $today = date('l, j F Y');
  $user  = currentUser();
  $nav   = getFilteredNav($NAV_ALL);

  // Build page label map
  $pageLabels = [];
  foreach ($NAV_ALL as $section => $items)
    foreach ($items as $item)
      $pageLabels[$item['id']] = $item['label'];
  $currentLabel = $pageLabels[$activePage] ?? 'Dashboard';

  // Role badge colour map
  $roleColors = [
    'badge-purple'=>'background:rgba(124,58,237,0.12);color:#7c3aed;border:1px solid #ddd6fe',
    'badge-info'  =>'background:var(--info-bg);color:var(--info);border:1px solid #bfdbfe',
    'badge-warning'=>'background:var(--warning-bg);color:var(--warning);border:1px solid #fde68a',
    'badge-success'=>'background:var(--success-bg);color:var(--success);border:1px solid #bbf7d0',
    'badge-muted' =>'background:var(--bg-muted);color:var(--text-3);border:1px solid var(--border)',
  ];
  $roleStyle = $roleColors['badge-'.$user['role_color']] ?? $roleColors['badge-muted'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($currentLabel) ?> · InvenIQ</title>
<link rel="stylesheet" href="/inveniq/assets/css/app.css">
</head>
<body>
<div class="app-shell">

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-name">⬡ InvenIQ</div>
    <div class="logo-sub">Inventory Intelligence</div>
  </div>

  <!-- User info panel -->
  <div style="padding:10px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;">
    <div style="width:32px;height:32px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;">
      <?= strtoupper(substr($user['name'], 0, 1)) ?>
    </div>
    <div style="min-width:0;flex:1">
      <div style="font-size:12px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($user['name']) ?></div>
      <div style="font-size:10px;padding:1px 7px;border-radius:20px;display:inline-block;margin-top:2px;<?= $roleStyle ?>"><?= htmlspecialchars($user['role_label']) ?></div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <?php foreach ($nav as $sectionLabel => $items): ?>
    <div class="nav-section"><?= htmlspecialchars($sectionLabel) ?></div>
    <?php foreach ($items as $item): ?>
      <a class="nav-link<?= $item['id'] === $activePage ? ' active' : '' ?>"
         href="/inveniq/pages/<?= $item['id'] ?>.php">
        <?= icon($item['icon'], 'nav-icon') ?>
        <span><?= htmlspecialchars($item['label']) ?></span>
      </a>
    <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <a href="/inveniq/pages/logout.php" class="btn-signout">
      <?= icon('log-out') ?> Sign out
    </a>
  </div>
</aside>

<!-- MAIN -->
<div class="main-area">
  <header class="topbar">
    <span class="topbar-title"><?= htmlspecialchars($currentLabel) ?></span>
    <div style="display:flex;align-items:center;gap:14px;">
      <span class="topbar-right"><?= $today ?></span>
      <span style="font-size:11px;color:var(--text-3);">|</span>
      <span style="font-size:12px;color:var(--text-2);"><?= htmlspecialchars($user['name']) ?></span>
    </div>
  </header>
  <main class="page-content">
<?php
}

function render_layout_close(): void { ?>
  </main>
</div>
</div>
<div class="toast-container" id="toast-container"></div>
<script src="/inveniq/assets/js/app.js"></script>
</body>
</html>
<?php }

// ---- Shared render helpers (unchanged) ----
function page_header(string $title, string $subtitle = '', string $actions = ''): void { ?>
  <div class="page-header">
    <div class="page-header-left">
      <h1><?= htmlspecialchars($title) ?></h1>
      <?php if ($subtitle): ?><p><?= htmlspecialchars($subtitle) ?></p><?php endif; ?>
    </div>
    <?php if ($actions): ?><div class="page-header-right"><?= $actions ?></div><?php endif; ?>
  </div>
<?php }

function badge(string $text, string $variant = 'muted'): string {
  $map = ['success'=>'badge-success','warning'=>'badge-warning','danger'=>'badge-danger',
          'info'=>'badge-info','orange'=>'badge-orange','purple'=>'badge-purple','muted'=>'badge-muted'];
  return '<span class="badge '.($map[$variant]??'badge-muted').'">'.htmlspecialchars($text).'</span>';
}

function status_badge(string $status): string {
  $map = [
    'stockout'=>['danger','Stockout'],'critical'=>['orange','Critical'],
    'low'=>['warning','Low'],'healthy'=>['success','Healthy'],'excess'=>['info','Excess'],
    'complete'=>['success','Complete'],'partial'=>['warning','Partial'],
    'not_received'=>['danger','Not Received'],
    'active'=>['success','Active'],'inactive'=>['muted','Inactive'],'on_hold'=>['warning','On Hold'],
    'open'=>['info','Open'],'partially_received'=>['warning','Part. Received'],
    'fully_received'=>['success','Received'],'cancelled'=>['muted','Cancelled'],
    'unpaid'=>['warning','Unpaid'],'paid'=>['success','Paid'],
    'partially_paid'=>['info','Part. Paid'],'overdue'=>['danger','Overdue'],
    'critical-urgency'=>['danger','Critical'],'high-urgency'=>['warning','High'],
    'medium-urgency'=>['info','Medium'],'ok-urgency'=>['success','OK'],
  ];
  [$v,$l] = $map[$status] ?? ['muted', ucfirst(str_replace('_',' ',$status))];
  return badge($l, $v);
}

function cover_bar(float $days, float $min, float $max): string {
  $label = $days >= 9999 ? '∞' : round($days).'d';
  $pct   = min(100, ($days / max(1,$max*1.3)) * 100);
  $clr   = $days==0?'var(--danger)':($days<$min/2?'var(--danger)':($days<$min?'var(--warning)':($days>$max?'var(--info)':'var(--success)')));
  return "<div class=\"cover-wrap\"><div class=\"cover-bar\"><div class=\"cover-fill\" style=\"width:{$pct}%;background:{$clr}\"></div></div><span class=\"cover-label\">{$label}</span></div>";
}

function score_bar(float $val): string {
  $clr = $val>=80?'var(--success)':($val>=60?'var(--warning)':'var(--danger)');
  $tc  = $val>=80?'text-success':($val>=60?'text-warning':'text-danger');
  return "<div class=\"score-wrap\"><div class=\"score-bar\"><div class=\"score-fill\" style=\"width:{$val}%;background:{$clr}\"></div></div><span class=\"score-val {$tc}\">{$val}%</span></div>";
}

function abc_badge(string $abc): string { return "<span class=\"abc-{$abc}\">{$abc}</span>"; }
function xyz_badge(string $xyz): string { return "<span class=\"xyz-{$xyz}\">{$xyz}</span>"; }
function rm(float $v): string  { return '₹'.number_format($v,2); }
function fnum(float $v, int $dp=0): string { return number_format($v,$dp); }

function data_table(array $cols, array $data, string $search_id=''): void {
  $sid = $search_id ?: 'tbl_'.substr(md5(rand()),0,6);
?>
<div class="card">
  <div class="table-toolbar">
    <input type="text" class="search-input" id="<?=$sid?>_search" placeholder="Search…" oninput="filterTable('<?=$sid?>')">
    <span class="table-count" id="<?=$sid?>_count"><?=count($data)?> rows</span>
  </div>
  <div class="data-table-wrap">
    <table class="data-table" id="<?=$sid?>_table">
      <thead><tr><?php foreach($cols as $i=>$col): ?><th onclick="sortTable('<?=$sid?>',<?=$i?>)"><?=htmlspecialchars($col['label'])?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php foreach($data as $row): ?>
        <tr class="data-row" data-search="<?=htmlspecialchars(strtolower(implode(' ',array_map('strval',$row))))?>">
          <?php foreach($cols as $col): ?><td><?php if(isset($col['render'])) echo ($col['render'])($row[$col['key']]??null,$row); else echo htmlspecialchars((string)($row[$col['key']]??'')); ?></td><?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php }

function skeleton_table(int $cols=6, int $rows=5): void { ?>
<div class="card">
  <div class="table-toolbar"><div class="skeleton" style="width:180px;height:32px"></div></div>
  <div class="data-table-wrap"><table class="data-table"><thead><tr><?php for($i=0;$i<$cols;$i++): ?><th><div class="skeleton" style="width:80px;height:10px"></div></th><?php endfor; ?></tr></thead>
  <tbody><?php for($r=0;$r<$rows;$r++): ?><tr class="skeleton-row"><?php for($i=0;$i<$cols;$i++): ?><td><div class="skeleton" style="width:<?=rand(50,120)?>px"></div></td><?php endfor; ?></tr><?php endfor; ?></tbody></table></div>
</div>
<?php }
