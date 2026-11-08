<?php
// =============================================================
// InvenIQ — Sales Intelligence engine (strategic analytics)
// Reads the results the Python pipeline writes to the sa_* tables.
// Refresh them with:  python analytics/run_pipeline.py
// =============================================================
require_once __DIR__ . '/config.php';

class SalesAnalytics {
    private PDO $db;

    public function __construct() {
        $this->db = getDB();
    }

    private function tableExists(string $table): bool {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :t");
        $stmt->execute([':t' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    // Latest pipeline run of a module: when it ran and what data it covered
    private function latestRun(string $module): ?array {
        $stmt = $this->db->prepare(
            "SELECT run_id, module, DATE_FORMAT(run_at,'%d %b %Y %H:%i') AS run_at,
                    data_from, data_to, fy
             FROM sa_runs WHERE module = :m ORDER BY id DESC LIMIT 1");
        $stmt->execute([':m' => $module]);
        return $stmt->fetch() ?: null;
    }

    // =========================================================
    // RQ1 — REGIONAL PERFORMANCE
    // =========================================================
    public function getRegionalPerformance(): array {
        if (!$this->tableExists('sa_territory_scores') || !$this->tableExists('sa_runs')) {
            return ['available' => false];
        }
        $run = $this->latestRun('rq1');
        if (!$run) return ['available' => false];

        $territories = $this->db->query(
            "SELECT * FROM sa_territory_scores
             ORDER BY composite_rank IS NULL, composite_rank, revenue DESC, peak_revenue DESC"
        )->fetchAll();
        foreach ($territories as &$t) {
            $t['reasons'] = json_decode($t['reasons'] ?? '[]', true) ?: [];
        }
        unset($t);

        $hq = $this->db->query(
            "SELECT * FROM sa_hq_scores ORDER BY composite_rank IS NULL, composite_rank, revenue DESC"
        )->fetchAll();
        foreach ($hq as &$h) {
            $h['reasons'] = json_decode($h['reasons'] ?? '[]', true) ?: [];
        }
        unset($h);

        $yearly = $this->db->query(
            "SELECT location_code, fy, segment, revenue, composite_score, composite_rank
             FROM sa_territory_yearly ORDER BY fy, location_code"
        )->fetchAll();

        return [
            'available'   => true,
            'run'         => $run,
            'territories' => $territories,
            'hq'          => $hq,
            'yearly'      => $yearly,
        ];
    }

    // =========================================================
    // RQ2 — PRODUCT CATEGORIES (ABC-XYZ + watch list)
    // =========================================================
    public function getProductCategories(): array {
        if (!$this->tableExists('sa_product_classes') || !$this->tableExists('sa_runs')) {
            return ['available' => false];
        }
        $run = $this->latestRun('rq2');
        if (!$run) return ['available' => false];

        $products = $this->db->query(
            "SELECT * FROM sa_product_classes ORDER BY revenue DESC"
        )->fetchAll();
        foreach ($products as &$p) {
            $p['reasons'] = json_decode($p['reasons'] ?? '[]', true) ?: [];
        }
        unset($p);

        $segments = $this->db->query(
            "SELECT * FROM sa_segment_profile ORDER BY revenue DESC"
        )->fetchAll();
        foreach ($segments as &$s) {
            $s['reasons'] = json_decode($s['reasons'] ?? '[]', true) ?: [];
        }
        unset($s);

        $history = $this->db->query(
            "SELECT sku, fy, class FROM sa_product_class_history ORDER BY fy, sku"
        )->fetchAll();

        $watch = $this->db->query(
            "SELECT product, segment, kind, detail FROM sa_product_watch
             ORDER BY ABS(revenue - prev_revenue) DESC"
        )->fetchAll();

        return [
            'available' => true,
            'run'       => $run,
            'products'  => $products,
            'segments'  => $segments,
            'history'   => $history,
            'watch'     => $watch,
        ];
    }

    // =========================================================
    // RQ3 — EXPANSION OPPORTUNITIES
    // =========================================================
    public function getExpansionOpportunities(): array {
        if (!$this->tableExists('sa_expansion_cells') || !$this->tableExists('sa_runs')) {
            return ['available' => false];
        }
        $run = $this->latestRun('rq3');
        if (!$run) return ['available' => false];

        $cells = $this->db->query(
            "SELECT * FROM sa_expansion_cells WHERE eligible = 1 ORDER BY `rank`"
        )->fetchAll();
        foreach ($cells as &$c) {
            $c['reasons'] = json_decode($c['reasons'] ?? '[]', true) ?: [];
            $c['action_plan'] = json_decode($c['action_plan'] ?? '{}', true) ?: [];
        }
        unset($c);

        return [
            'available' => true,
            'run'       => $run,
            'cells'     => $cells,
            'win_back'  => $this->db->query("SELECT * FROM sa_win_back ORDER BY best_sales DESC")->fetchAll(),
        ];
    }

    // =========================================================
    // SALES ACTION PLAN
    // =========================================================
    public function getSalesActionPlan(): array {
        if (!$this->tableExists('sa_account_watch') || !$this->tableExists('sa_runs')) {
            return ['available' => false];
        }
        $run = $this->latestRun('actions');
        if (!$run) return ['available' => false];

        $accounts = $this->db->query("SELECT * FROM sa_account_watch ORDER BY change_value")->fetchAll();
        foreach ($accounts as &$a) {
            $a['falls'] = json_decode($a['falls'] ?? '[]', true) ?: [];
        }
        unset($a);
        $quiet = $this->db->query("SELECT * FROM sa_quiet_customers ORDER BY value_12m DESC")->fetchAll();
        foreach ($quiet as &$q) {
            $q['buys'] = json_decode($q['buys'] ?? '[]', true) ?: [];
        }
        unset($q);

        return [
            'available'  => true,
            'run'        => $run,
            'accounts'   => $accounts,
            'quiet'      => $quiet,
            'expansion'  => $this->expansionCustomers(),
            'actions'    => $this->db->query(
                "SELECT l.id, l.kind, l.customer, l.location_code, l.segment, l.done_on, l.done_by, r.status, r.days_after,
                        r.sales_before, r.sales_after, r.others_before, r.others_after
                 FROM sa_action_log l LEFT JOIN sa_action_results r ON r.action_id = l.id
                 ORDER BY l.done_on DESC, l.id DESC")->fetchAll(),
        ];
    }

    // The customers named in the top 15 expansion plans, one row per customer and category
    private function expansionCustomers(): array {
        $cells = $this->db->query(
            "SELECT location_code, territory, segment, `rank`, action_plan FROM sa_expansion_cells
             WHERE eligible = 1 ORDER BY `rank` LIMIT 15")->fetchAll();
        $rows = [];
        foreach ($cells as $c) {
            $plan = json_decode($c['action_plan'] ?? '{}', true) ?: [];
            foreach ($plan['customers'] ?? [] as $x) {
                $rows[] = ['customer' => $x['name'], 'location_code' => $c['location_code'], 'territory' => $c['territory'],
                           'segment' => $c['segment'], 'rank' => $c['rank'], 'spend' => $x['spend'], 'used_to_buy' => $x['used_to_buy'],
                           'products' => array_slice(array_column($plan['products'] ?? [], 'name'), 0, 2)];
            }
        }
        return $rows;
    }

    // Mark an action done, or undo a mark. Results are measured by the pipeline, not here.
    public function setActionDone(string $kind, string $customer, string $location, string $segment, bool $done, string $by): array {
        if (!in_array($kind, ['account', 'quiet', 'expansion'], true) || $customer === ''
            || mb_strlen($customer) > 190 || mb_strlen($location) > 20 || mb_strlen($segment) > 100) {
            return ['ok' => false, 'error' => 'That action could not be saved.'];
        }
        $key = [':k' => $kind, ':c' => $customer, ':l' => $location, ':s' => $segment];
        if ($done) {
            $stmt = $this->db->prepare(
                "INSERT IGNORE INTO sa_action_log (kind, customer, location_code, segment, done_on, done_by)
                 VALUES (:k, :c, :l, :s, CURDATE(), :b)");
            $stmt->execute($key + [':b' => mb_substr($by, 0, 150)]);
        } else {
            $stmt = $this->db->prepare(
                "DELETE FROM sa_action_log WHERE kind = :k AND customer = :c AND location_code = :l AND segment = :s");
            $stmt->execute($key);
        }
        return ['ok' => true];
    }
}
