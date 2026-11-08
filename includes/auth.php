<?php
// =============================================================
// InvenIQ — Authentication & Role Middleware
// Include at the top of every protected page:
//   require_once __DIR__.'/../includes/auth.php';
//   requireAuth('page-id');
// =============================================================
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================================================
// ROLE DEFINITIONS
// Each role has a list of page IDs it can access.
// 'dashboard' is always included for all roles.
// =============================================================
const ROLES = [
    'super_admin' => [
        'label' => 'Super Admin',
        'color' => 'purple',
        'pages' => '*', // all pages
    ],
    'inventory_manager' => [
        'label' => 'Inventory Manager',
        'color' => 'info',
        'pages' => [
            'dashboard','stock-health','reorder-points','replenishment',
            'excess-stock','expiry-alerts','slow-movers','abc-xyz',
            'location-performance',
            'mismatch-analysis','anomaly-detection','ai-assistant','products',
            'demand-forecast',
        ],
    ],
    'procurement_officer' => [
        'label' => 'Procurement Officer',
        'color' => 'warning',
        'pages' => [
            'dashboard','po-vs-grn','reorder-points','replenishment',
            'supplier-performance','suppliers','mismatch-analysis',
            'ai-assistant','anomaly-detection','demand-forecast',
        ],
    ],
    'finance_officer' => [
        'label' => 'Finance Officer',
        'color' => 'success',
        'pages' => [
            'dashboard','payment-ageing','supplier-performance',
            'excess-stock','ai-assistant',
        ],
    ],
    'viewer' => [
        'label' => 'Viewer',
        'color' => 'muted',
        'pages' => [
            'dashboard','ai-assistant',
        ],
    ],
];

// =============================================================
// HELPER: Check if logged-in user can access a page
// =============================================================
function canAccess(string $pageId): bool {
    $role = $_SESSION['user_role'] ?? '';
    if (!$role || !isset(ROLES[$role])) return false;
    $pages = ROLES[$role]['pages'];
    if ($pages === '*') return true;
    return in_array($pageId, $pages);
}

// =============================================================
// HELPER: Require login + role check — call at top of each page
// =============================================================
function requireAuth(string $pageId = 'dashboard'): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: /inveniq/pages/login.php');
        exit;
    }
    if (!canAccess($pageId)) {
        header('Location: /inveniq/pages/denied.php');
        exit;
    }
}

// =============================================================
// HELPER: Require super_admin role
// =============================================================
function requireAdmin(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: /inveniq/pages/login.php');
        exit;
    }
    if ($_SESSION['user_role'] !== 'super_admin') {
        header('Location: /inveniq/pages/denied.php');
        exit;
    }
}

// =============================================================
// HELPER: Get current user info from session
// =============================================================
function currentUser(): array {
    return [
        'id'       => $_SESSION['user_id']   ?? 0,
        'name'     => $_SESSION['user_name'] ?? '',
        'email'    => $_SESSION['user_email'] ?? '',
        'role'     => $_SESSION['user_role'] ?? '',
        'role_label' => ROLES[$_SESSION['user_role'] ?? '']['label'] ?? '',
        'role_color' => ROLES[$_SESSION['user_role'] ?? '']['color'] ?? 'muted',
    ];
}

// =============================================================
// HELPER: Check if current user is super_admin
// =============================================================
function isSuperAdmin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'super_admin';
}

// =============================================================
// AUTH ENGINE — login / logout / user CRUD
// =============================================================
class AuthEngine {
    private PDO $db;

    public function __construct() {
        $this->db = getDB();
    }

    public function login(string $email, string $password): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM users WHERE email = :email AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([':email' => strtolower(trim($email))]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Invalid email or password.'];
        }

        // Set session
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_name']  = $user['full_name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role']  = $user['role'];

        // Update last login
        $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = :id")
                 ->execute([':id' => $user['id']]);

        return ['success' => true, 'role' => $user['role']];
    }

    public function logout(): void {
        session_destroy();
    }

    public function getUsers(): array {
        return $this->db->query(
            "SELECT id, full_name, email, role, is_active,
                    DATE_FORMAT(last_login,'%d %b %Y %H:%i') AS last_login,
                    DATE_FORMAT(created_at,'%d %b %Y') AS created_at
             FROM users ORDER BY full_name"
        )->fetchAll();
    }

    public function createUser(array $d): array {
        if (empty($d['email']) || empty($d['password'])) {
            return ['success' => false, 'error' => 'Email and password are required.'];
        }
        // Check duplicate
        $check = $this->db->prepare("SELECT id FROM users WHERE email = :email");
        $check->execute([':email' => strtolower(trim($d['email']))]);
        if ($check->fetch()) {
            return ['success' => false, 'error' => 'Email already exists.'];
        }

        $stmt = $this->db->prepare("
            INSERT INTO users (full_name, email, password_hash, role, is_active)
            VALUES (:full_name, :email, :password_hash, :role, :is_active)
        ");
        $stmt->execute([
            ':full_name'     => trim($d['full_name'] ?? ''),
            ':email'         => strtolower(trim($d['email'])),
            ':password_hash' => password_hash($d['password'], PASSWORD_DEFAULT),
            ':role'          => $this->validRole($d['role'] ?? ''),
            ':is_active'     => 1,
        ]);
        return ['success' => true, 'id' => (int)$this->db->lastInsertId()];
    }

    public function updateUser(int $id, array $d): array {
        // Build dynamic update
        $fields = [
            'full_name' => trim($d['full_name'] ?? ''),
            'email'     => strtolower(trim($d['email'] ?? '')),
            'role'      => $this->validRole($d['role'] ?? ''),
            'is_active' => (int)($d['is_active'] ?? 1),
        ];
        $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        $params = array_combine(
            array_map(fn($k) => ":$k", array_keys($fields)),
            array_values($fields)
        );
        $params[':id'] = $id;

        // Update password only if provided
        if (!empty($d['password'])) {
            $sets .= ', password_hash = :password_hash';
            $params[':password_hash'] = password_hash($d['password'], PASSWORD_DEFAULT);
        }

        $this->db->prepare("UPDATE users SET $sets WHERE id = :id")->execute($params);
        return ['success' => true];
    }

    public function deleteUser(int $id): array {
        // Prevent deleting yourself
        if ($id === (int)($_SESSION['user_id'] ?? 0)) {
            return ['success' => false, 'error' => 'You cannot delete your own account.'];
        }
        $this->db->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $id]);
        return ['success' => true];
    }

    private function validRole(string $role): string {
        return array_key_exists($role, ROLES) ? $role : 'viewer';
    }
}
