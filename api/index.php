<?php
// =============================================================
// InvenIQ — API Router v2 (with authentication)
// =============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/engine.php';
require_once __DIR__ . '/../includes/sales_analytics.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

// Require login for all API calls
if (empty($_SESSION['user_id'])) {
    jsonResponse(['error' => 'Unauthorised. Please log in.'], 401);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$engine = new InventoryEngine();
$auth   = new AuthEngine();

// Permission check helper for write operations
function requireRole(string ...$roles): void {
    $current = $_SESSION['user_role'] ?? '';
    if (!in_array($current, $roles)) {
        jsonResponse(['error' => 'Permission denied for your role.'], 403);
    }
}

try {
    switch ($action) {

        // ---- DASHBOARD ----
        case 'kpis':
            jsonResponse($engine->getKPIs());

        // ---- MACHINE LEARNING ----
        case 'ml_status':
            jsonResponse($engine->getModelStatus());

        case 'demand_forecast':
            jsonResponse($engine->getDemandForecast());

        case 'demand_trend':
            jsonResponse($engine->getDemandTrend());

        case 'seasonal_patterns':
            jsonResponse($engine->getSeasonalPatterns());

        case 'location_performance':
            jsonResponse($engine->getLocationPerformance());

        case 'value_summary':
            jsonResponse($engine->getValueSummary());

        // ---- SALES INTELLIGENCE (strategic analytics, analytics/ pipeline) ----
        case 'regional_performance':
            if (!canAccess('regional-performance')) jsonResponse(['error' => 'Permission denied for your role.'], 403);
            $sales = new SalesAnalytics();
            jsonResponse($sales->getRegionalPerformance());

        case 'expansion_opportunities':
            if (!canAccess('expansion-opportunities')) jsonResponse(['error' => 'Permission denied for your role.'], 403);
            $sales = new SalesAnalytics();
            jsonResponse($sales->getExpansionOpportunities());

        case 'sales_action_plan':
            if (!canAccess('sales-action-plan')) jsonResponse(['error' => 'Permission denied for your role.'], 403);
            $sales = new SalesAnalytics();
            jsonResponse($sales->getSalesActionPlan());

        case 'sales_action_mark':
            if (!canAccess('sales-action-plan')) jsonResponse(['error' => 'Permission denied for your role.'], 403);
            // a custom header can't be sent by a form on another site
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
                jsonResponse(['error' => 'Invalid request.'], 400);
            }
            $sales = new SalesAnalytics();
            jsonResponse($sales->setActionDone(
                (string)($_POST['kind'] ?? ''), trim((string)($_POST['customer'] ?? '')),
                (string)($_POST['location_code'] ?? ''), (string)($_POST['segment'] ?? ''),
                ($_POST['done'] ?? '') === '1', (string)($_SESSION['user_name'] ?? '')));

        case 'product_categories':
            if (!canAccess('product-categories')) jsonResponse(['error' => 'Permission denied for your role.'], 403);
            $sales = new SalesAnalytics();
            jsonResponse($sales->getProductCategories());

        // ---- AI ASSISTANT ----
        case 'ai_chat':
            $message = trim($_POST['message'] ?? '');
            if ($message === '') jsonResponse(['ok'=>false,'reply'=>'Please type a question.'], 400);
            $history = [];
            if (!empty($_POST['history'])) {
                $decoded = json_decode($_POST['history'], true);
                if (is_array($decoded)) $history = $decoded;
            }
            require_once __DIR__ . '/../includes/ai_client.php';
            $ai = new AIClient();
            jsonResponse($ai->ask($message, $engine->getAIContext(), $history));

        // ---- STOCK INTELLIGENCE ----
        case 'stock_health':
            jsonResponse($engine->getStockHealth());

        case 'reorder_points':
            jsonResponse($engine->getReorderPoints());

        case 'expiry_alerts':
            $days = (int)($_GET['days'] ?? $_POST['days'] ?? 90);
            jsonResponse($engine->getExpiryAlerts($days));

        case 'excess_stock':
            jsonResponse($engine->getExcessStock());

        case 'slow_movers':
            jsonResponse($engine->getSlowMovers());

        case 'abc_xyz':
            jsonResponse($engine->getABCXYZ());

        // ---- PROCUREMENT ----
        case 'po_reconciliation':
            jsonResponse($engine->getPOReconciliation());

        case 'mismatch':
            jsonResponse($engine->getMismatchAnalysis());

        case 'supplier_performance':
            jsonResponse($engine->getSupplierPerformance());

        case 'payment_ageing':
            jsonResponse($engine->getPaymentAgeing());

        // ---- MASTER DATA ----
        case 'products':
            jsonResponse($engine->getProducts());

        case 'suppliers':
            jsonResponse($engine->getSuppliers());

        case 'locations':
            jsonResponse($engine->getLocations());

        case 'dm_counts':
            jsonResponse($engine->getEntityCounts());

        // ---- PRODUCTS CRUD (Inventory Manager + Super Admin) ----
        case 'create_product':
            requireRole('super_admin','inventory_manager');
            jsonResponse($engine->createProduct($_POST));

        case 'update_product':
            requireRole('super_admin','inventory_manager');
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) jsonResponse(['success'=>false,'error'=>'Missing product id'], 400);
            jsonResponse($engine->updateProduct($id, $_POST));

        case 'delete_product':
            requireRole('super_admin');
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) jsonResponse(['success'=>false,'error'=>'Missing product id'], 400);
            jsonResponse($engine->deleteProduct($id));

        // ---- SUPPLIERS CRUD (Procurement Officer + Super Admin) ----
        case 'create_supplier':
            requireRole('super_admin','procurement_officer');
            jsonResponse($engine->createSupplier($_POST));

        case 'update_supplier':
            requireRole('super_admin','procurement_officer');
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) jsonResponse(['success'=>false,'error'=>'Missing supplier id'], 400);
            jsonResponse($engine->updateSupplier($id, $_POST));

        case 'delete_supplier':
            requireRole('super_admin');
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) jsonResponse(['success'=>false,'error'=>'Missing supplier id'], 400);
            jsonResponse($engine->deleteSupplier($id));

        // ---- DATA MANAGEMENT (Super Admin only) ----
        case 'clear_entity':
            requireRole('super_admin');
            jsonResponse($engine->clearEntity($_POST['entity'] ?? ''));

        // ---- USER MANAGEMENT (Super Admin only) ----
        case 'users':
            requireRole('super_admin');
            jsonResponse($auth->getUsers());

        case 'create_user':
            requireRole('super_admin');
            jsonResponse($auth->createUser($_POST));

        case 'update_user':
            requireRole('super_admin');
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) jsonResponse(['success'=>false,'error'=>'Missing user id'], 400);
            jsonResponse($auth->updateUser($id, $_POST));

        case 'delete_user':
            requireRole('super_admin');
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) jsonResponse(['success'=>false,'error'=>'Missing user id'], 400);
            jsonResponse($auth->deleteUser($id));

        default:
            jsonResponse(['error' => "Unknown action: $action"], 400);
    }
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()], 500);
}
