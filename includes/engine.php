<?php
// =============================================================
// InvenIQ — Inventory Engine
// All business-logic calculations live here as a class.
// Every public method returns a plain array ready for json_encode.
// =============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ml_client.php';

class InventoryEngine
{
    private PDO $db;
    private MLClient $ml;
    private ?array $forecastCache = null;
    private ?string $latestSaleDateCache = null;

    public function __construct() {
        $this->db = getDB();
        $this->ml = new MLClient();
    }

    // =========================================================
    // FORECAST VELOCITY  — daily demand from the ML model
    // Falls back to the historical DB calculation if the model
    // (model.pkl / connector) is unavailable.
    // =========================================================
    public function getForecastVelocity(): array {
        if ($this->forecastCache !== null) return $this->forecastCache;
        $ml = $this->ml->dailyDemand();          // [sku => predicted daily demand]
        if (!empty($ml)) {
            return $this->forecastCache = $ml;
        }
        return $this->forecastCache = $this->getSalesVelocity(90);   // rule-based fallback
    }

    // Model status + accuracy, for the API / dashboard badge
    public function getModelStatus(): array {
        $info = $this->ml->modelInfo();
        return [
            'available'   => $info['available'],
            'source'      => $info['source'],
            'model'       => $info['model'],
            'sku_count'   => count($this->ml->predictions()),
        ];
    }

    // =========================================================
    // AI ASSISTANT CONTEXT -- compact live snapshot fed to the LLM
    // =========================================================
    public function getAIContext(): string {
        $kpis = $this->getKPIs();
        $lines = [];
        $lines[] = "KPIs: {$kpis['stockouts']} SKUs at stockout/critical level, {$kpis['reorder_alerts']} approaching reorder point, "
                 . "{$kpis['excess_stock']} SKUs in excess, {$kpis['expiry_risk']} batches expiring within 90 days, "
                 . "PO-vs-GRN match rate {$kpis['po_match_rate']}%, supplier on-time rate {$kpis['supplier_ontime']}%.";

        $health   = $this->getStockHealth();
        $critical = array_slice(array_values(array_filter($health, fn($r) => in_array($r['status'], ['stockout','critical']))), 0, 8);
        if ($critical) {
            $lines[] = "Most urgent stock issues (sku | product | location | cover days | status):";
            foreach ($critical as $r) {
                $lines[] = "- {$r['sku']} | {$r['description']} | {$r['location_name']} | {$r['cover_days']}d | {$r['status']}";
            }
        }

        $reorder = array_slice($this->getReorderPoints(), 0, 6); // already sorted by urgency
        if ($reorder) {
            $lines[] = "Top reorder priorities (sku | product | supplier | suggested order qty | urgency):";
            foreach ($reorder as $r) {
                $lines[] = "- {$r['sku']} | {$r['description']} | {$r['supplier_name']} | {$r['suggested_qty']} | {$r['urgency']}";
            }
        }

        $expiry = array_slice($this->getExpiryAlerts(90), 0, 6);
        if ($expiry) {
            $lines[] = "Batches expiring within 90 days (sku | product | expiry date | write-off value):";
            foreach ($expiry as $r) {
                $lines[] = "- {$r['sku']} | {$r['description']} | {$r['expiry_date']} | ₹" . number_format((float)$r['write_off_value'], 2);
            }
        }

        $suppliers = array_slice($this->getSupplierPerformance(), 0, 5); // already sorted worst-first
        if ($suppliers) {
            $lines[] = "Lowest-performing suppliers (name | on-time rate | avg delivery delay days):";
            foreach ($suppliers as $s) {
                $lines[] = "- {$s['name']} | " . ($s['on_time_rate'] ?? '?') . "% | " . ($s['avg_delivery_delay'] ?? '?') . "d";
            }
        }

        $model = $this->getModelStatus();
        $lines[] = "Demand forecast model: " . ($model['available'] ? "live via {$model['source']}, serving {$model['sku_count']} SKUs" : "offline -- using rule-based fallback");

        return implode("\n", $lines);
    }

    // Per-product forward forecast (drives the Demand Forecast page)
    public function getDemandForecast(): array {
        $preds = $this->ml->predictions();
        if (empty($preds)) return [];
        $out = [];
        foreach ($preds as $sku => $p) {
            $marginUnit = (float)($p['unit_price'] ?? 0) - (float)($p['unit_cost'] ?? 0);
            $out[] = [
                'sku'              => $sku,
                'description'      => $p['description']   ?? $sku,
                'product_group'    => $p['segment']       ?? '',
                'daily_demand'     => (float)($p['daily_demand'] ?? 0),
                'month_demand'     => (float)($p['month_demand'] ?? 0),
                'annual_demand'    => (float)($p['annual_demand'] ?? 0),
                'monthly_forecast' => $p['monthly_forecast'] ?? [],
                'unit_cost'        => (float)($p['unit_cost'] ?? 0),
                'unit_price'       => (float)($p['unit_price'] ?? 0),
                'margin_pct'       => (float)($p['margin_pct'] ?? 0),
                'expected_monthly_revenue' => round((float)($p['month_demand'] ?? 0) * (float)($p['unit_price'] ?? 0), 2),
                'expected_monthly_margin'  => round((float)($p['month_demand'] ?? 0) * $marginUnit, 2),
            ];
        }
        usort($out, fn($a,$b) => $b['expected_monthly_margin'] <=> $a['expected_monthly_margin']);
        return $out;
    }

    // =========================================================
    // SEASONAL PATTERNS — therapeutic-category (segment) demand
    // seasonality learned from real sales history (the seg_month_idx
    // feature). Drives the "Seasonal Demand Patterns" panel on the
    // Demand Forecast page: an index of 1.20 for Respiratory in
    // November means Respiratory sells ~20% above its own yearly
    // average in that month. This is a demand-pattern signal derived
    // purely from historical category sales, not a disease diagnosis.
    // =========================================================
    public function getSeasonalPatterns(): array {
        $idx = $this->ml->seasonalIndex();
        if (empty($idx)) return [];
        $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        $out = [];
        foreach ($idx as $segment => $vals) {
            if (count($vals) !== 12) continue;
            $peakI = 0; $troughI = 0;
            foreach ($vals as $i => $v) {
                if ($v > $vals[$peakI]) $peakI = $i;
                if ($v < $vals[$troughI]) $troughI = $i;
            }
            $swing = round((max($vals) - min($vals)) * 100, 1); // spread, in %-of-average points
            // Only surface segments with a genuine, non-trivial seasonal swing
            if ($swing < 8.0) continue;
            $out[] = [
                'segment'     => $segment,
                'monthly'     => array_map(fn($v) => round($v, 3), $vals),
                'peak_month'  => $months[$peakI],
                'peak_pct'    => round(($vals[$peakI] - 1) * 100, 1),
                'trough_month'=> $months[$troughI],
                'trough_pct'  => round(($vals[$troughI] - 1) * 100, 1),
                'swing_pct'   => $swing,
            ];
        }
        usort($out, fn($a,$b) => $b['swing_pct'] <=> $a['swing_pct']);
        return $out;
    }

    // =========================================================
    // DEMAND TREND — historical monthly sales + ML forecast
    // (drives the trend chart on the Demand Forecast page)
    // =========================================================
    public function getDemandTrend(int $monthsBack = 24): array {
        $hist = $this->db->query("
            SELECT DATE_FORMAT(movement_date,'%Y-%m') AS ym, SUM(quantity) AS qty
            FROM   stock_movements
            WHERE  movement_type = 'sale'
            GROUP  BY ym
            ORDER  BY ym
        ")->fetchAll();
        $hist = array_slice($hist, -$monthsBack);
        $history = array_map(fn($r) => ['ym' => $r['ym'], 'qty' => round((float)$r['qty'], 1)], $hist);

        // forecast future months, summed across all products
        $fmap = [];
        foreach ($this->ml->predictions() as $p) {
            foreach (($p['monthly_forecast'] ?? []) as $m) {
                $ym = $m['ym'];
                $fmap[$ym] = ($fmap[$ym] ?? 0) + (float)($m['forecast_qty'] ?? 0);
            }
        }
        ksort($fmap);
        $forecast = [];
        foreach ($fmap as $ym => $q) $forecast[] = ['ym' => $ym, 'qty' => round($q, 1)];

        return [
            'history'  => $history,
            'forecast' => $forecast,
            'source'   => $this->ml->isAvailable() ? 'ml' : 'none',
        ];
    }

    // =========================================================
    // VALUE SUMMARY — "value delivered" / ROI panel
    // Money the platform helps free, protect, or avoid losing.
    // =========================================================
    public function getValueSummary(): array {
        $excess  = $this->getExcessStock();
        $expiry  = $this->getExpiryAlerts(90);
        $reorder = $this->getReorderPoints();

        $cashExcess = 0.0;
        foreach ($excess as $e) $cashExcess += (float)($e['excess_value'] ?? 0);

        $expiryRisk = 0.0;
        foreach ($expiry as $e) $expiryRisk += (float)($e['write_off_value'] ?? 0);

        $atRisk = array_filter($reorder, fn($r) => in_array($r['urgency'] ?? '', ['critical', 'high']));
        $lostMargin = 0.0;
        foreach ($atRisk as $r) $lostMargin += (float)($r['expected_monthly_margin'] ?? 0);

        $forecastMargin = 0.0;
        foreach ($reorder as $r) $forecastMargin += (float)($r['expected_monthly_margin'] ?? 0);

        return [
            'cash_in_excess'          => round($cashExcess, 2),
            'expiry_value_at_risk'    => round($expiryRisk, 2),
            'stockout_risk_items'     => count($atRisk),
            'profit_at_risk_monthly'  => round($lostMargin, 2),
            'forecast_monthly_margin' => round($forecastMargin, 2),
            'currency'                => defined('CURRENCY') ? CURRENCY : '₹',
        ];
    }

    // =========================================================
    // LATEST SALE DATE — the anchor for every trailing window below.
    // Using the sales data's own most recent date (rather than the
    // server's real-world CURDATE()) means "last N days" keeps
    // working correctly however much real time passes between now
    // and the next data refresh, instead of quietly going to zero
    // once the calendar drifts past the dataset's last recorded month.
    // =========================================================
    private function getLatestSaleDate(): string {
        if ($this->latestSaleDateCache !== null) return $this->latestSaleDateCache;
        $row = $this->db->query("
            SELECT MAX(movement_date) AS d
            FROM   stock_movements
            WHERE  movement_type = 'sale'
        ")->fetch();
        return $this->latestSaleDateCache = $row['d'] ?? date('Y-m-d');
    }

    // =========================================================
    // SALES VELOCITY
    // Returns [sku => avg_daily_sales] for the given window,
    // measured back from the sales data's own latest date.
    // =========================================================
    public function getSalesVelocity(int $days = 90): array {
        $anchor = $this->getLatestSaleDate();
        $cutoff = date('Y-m-d', strtotime("{$anchor} -{$days} days"));
        $stmt = $this->db->prepare("
            SELECT sku, SUM(quantity) AS total
            FROM   stock_movements
            WHERE  movement_type = 'sale'
              AND  movement_date >= :cutoff
              AND  movement_date <= :anchor
            GROUP  BY sku
        ");
        $stmt->execute([':cutoff' => $cutoff, ':anchor' => $anchor]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['sku']] = round($r['total'] / $days, 4);
        }
        return $out;
    }

    // =========================================================
    // KPI SUMMARY — Dashboard headline numbers
    // =========================================================
    public function getKPIs(): array {
        $v = $this->getForecastVelocity();

        // Cover days per SKU (all locations combined)
        $balances = $this->db->query("
            SELECT sb.sku, SUM(sb.quantity_on_hand) AS qty,
                   p.min_cover_days, p.max_cover_days
            FROM   stock_balances sb
            JOIN   products p ON p.sku = sb.sku
            GROUP  BY sb.sku, p.min_cover_days, p.max_cover_days
        ")->fetchAll();

        $stockouts = $reorders = $excess = 0;
        foreach ($balances as $b) {
            $vel   = $v[$b['sku']] ?? 0;
            $cover = $vel > 0 ? $b['qty'] / $vel : ($b['qty'] > 0 ? 9999 : 0);
            if ($cover < ($b['min_cover_days'] / 2)) $stockouts++;
            elseif ($cover < $b['min_cover_days'])    $reorders++;
            if ($cover > $b['max_cover_days'])        $excess++;
        }

        // Expiry risk count
        $expiryRisk = (int) $this->db->query("
            SELECT COUNT(*) FROM stock_balances
            WHERE  expiry_date IS NOT NULL
              AND  expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
              AND  quantity_on_hand > 0
        ")->fetchColumn();

        // PO match rate
        $poTotal   = (int) $this->db->query("SELECT COUNT(*) FROM purchase_orders WHERE status != 'cancelled'")->fetchColumn();
        $poMatched = (int) $this->db->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'fully_received'")->fetchColumn();
        $poRate    = $poTotal > 0 ? round($poMatched / $poTotal * 100, 1) : 0;

        // Supplier on-time rate
        $r = $this->db->query("
            SELECT COUNT(g.id)                                                     AS total,
                   SUM(CASE WHEN g.receipt_date <= po.expected_delivery_date
                            THEN 1 ELSE 0 END)                                     AS on_time
            FROM   goods_received g
            JOIN   purchase_orders po ON po.po_number = g.po_number
            WHERE  po.expected_delivery_date IS NOT NULL
        ")->fetch();
        $supplierOnTime = $r['total'] > 0 ? round($r['on_time'] / $r['total'] * 100, 1) : 0;

        return [
            'stockouts'       => $stockouts,
            'reorder_alerts'  => $reorders,
            'expiry_risk'     => $expiryRisk,
            'excess_stock'    => $excess,
            'po_match_rate'   => $poRate,
            'supplier_ontime' => $supplierOnTime,
        ];
    }

    // =========================================================
    // STOCK HEALTH — cover days + status per SKU × location
    // =========================================================
    public function getStockHealth(): array {
        $v = $this->getForecastVelocity();

        $rows = $this->db->query("
            SELECT sb.sku, sb.location_code, l.name AS location_name,
                   SUM(sb.quantity_on_hand)          AS qty_on_hand,
                   MIN(sb.expiry_date)               AS earliest_expiry,
                   p.description, p.product_group,
                   p.unit_cost, p.min_cover_days, p.max_cover_days
            FROM   stock_balances sb
            JOIN   products p  ON p.sku = sb.sku
            JOIN   locations l ON l.location_code = sb.location_code
            GROUP  BY sb.sku, sb.location_code, l.name,
                      p.description, p.product_group,
                      p.unit_cost, p.min_cover_days, p.max_cover_days
            ORDER  BY sb.sku, sb.location_code
        ")->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $vel   = $v[$r['sku']] ?? 0;
            $qty   = (float) $r['qty_on_hand'];
            $cover = $vel > 0 ? round($qty / $vel) : ($qty > 0 ? 9999 : 0);
            $min   = (int)   $r['min_cover_days'];
            $max   = (int)   $r['max_cover_days'];

            $status = 'healthy';
            if ($qty == 0)               $status = 'stockout';
            elseif ($cover < $min / 2)   $status = 'critical';
            elseif ($cover < $min)       $status = 'low';
            elseif ($cover > $max)       $status = 'excess';

            $excessQty   = 0;
            $excessValue = 0;
            if ($status === 'excess' && $vel > 0) {
                $excessQty   = round(($cover - $max) * $vel);
                $excessValue = round($excessQty * (float)$r['unit_cost'], 2);
            }

            $out[] = array_merge($r, [
                'qty_on_hand'     => $qty,
                'avg_daily_sales' => $vel,
                'cover_days'      => $cover,
                'stock_value'     => round($qty * (float)$r['unit_cost'], 2),
                'status'          => $status,
                'excess_qty'      => $excessQty,
                'excess_value'    => $excessValue,
            ]);
        }
        usort($out, fn($a,$b) => $a['cover_days'] <=> $b['cover_days']);
        return $out;
    }

    // =========================================================
    // REORDER POINTS & EOQ — per product (all locations)
    // =========================================================
    public function getReorderPoints(): array {
        $v       = $this->getForecastVelocity();
        $mlPreds = $this->ml->predictions();          // for margin + next-month forecast
        $mlOn    = $this->ml->isAvailable();

        $products = $this->db->query("
            SELECT p.*,
                   s.lead_time_days, s.name AS supplier_name,
                   po_link.supplier_id       AS linked_supplier_id
            FROM   products p
            LEFT JOIN (
                SELECT sku, supplier_id
                FROM   purchase_orders
                WHERE  status IN ('open','partially_received')
                GROUP  BY sku, supplier_id
            ) po_link ON po_link.sku = p.sku
            LEFT JOIN suppliers s ON s.supplier_id = po_link.supplier_id
            WHERE  p.status = 'active'
        ")->fetchAll();

        // Current stock per SKU
        $stockBySku = [];
        foreach ($this->db->query("SELECT sku, SUM(quantity_on_hand) AS qty FROM stock_balances GROUP BY sku")->fetchAll() as $r) {
            $stockBySku[$r['sku']] = (float)$r['qty'];
        }

        // In-transit (open PO quantities)
        $inTransit = [];
        foreach ($this->db->query("
            SELECT sku, SUM(ordered_quantity) AS qty
            FROM   purchase_orders
            WHERE  status IN ('open','partially_received')
            GROUP  BY sku
        ")->fetchAll() as $r) {
            $inTransit[$r['sku']] = (float)$r['qty'];
        }

        $out = [];
        foreach ($products as $p) {
            $dailySales = $v[$p['sku']] ?? 0;
            $leadTime   = (int)($p['lead_time_days'] ?? 7);
            $safetyDays = (int)($p['safety_stock_days'] ?? 7);
            $safetyStock  = $dailySales * $safetyDays;
            $reorderPoint = $dailySales * ($leadTime + $safetyDays);
            $stock        = $stockBySku[$p['sku']] ?? 0;
            $transit      = $inTransit[$p['sku']] ?? 0;
            $available    = $stock + $transit;
            $belowReorder = $available <= $reorderPoint;

            // EOQ formula
            $annualDemand = $dailySales * 365;
            $oc  = (float)($p['ordering_cost'] ?? 50);
            $hcp = ((float)($p['holding_cost_pct'] ?? 20)) / 100;
            $uc  = (float)($p['unit_cost'] ?? 1);
            $eoq = $annualDemand > 0 && $uc > 0
                 ? (int) round(sqrt((2 * $annualDemand * $oc) / ($hcp * $uc)))
                 : 0;
            $suggestedQty = max($eoq, (int)round($dailySales * ((int)($p['reorder_cover_days'] ?? 30))));

            // Urgency classification
            $urgency = 'ok';
            if ($stock == 0)                          $urgency = 'critical';
            elseif ($available < $reorderPoint * 0.5) $urgency = 'high';
            elseif ($belowReorder)                    $urgency = 'medium';

            // ---- PROFIT-AWARE layer ----
            // Use the model's margin + forecast so reorder decisions favour
            // the products that actually make the client money.
            $mp          = $mlPreds[$p['sku']] ?? [];
            $up          = (float)($p['unit_price'] ?? 0);
            $marginUnit  = $up > 0 ? ($up - $uc) : 0.0;
            $marginPct   = isset($mp['margin_pct']) ? (float)$mp['margin_pct']
                            : ($up > 0 ? round($marginUnit / $up * 100, 1) : 0.0);
            $nextMonth   = isset($mp['month_demand']) ? (float)$mp['month_demand'] : round($dailySales * 30.4, 2);
            // expected monthly gross margin if we keep this line in stock
            $expMonthlyMargin = round($nextMonth * $marginUnit, 2);

            // Nudge order quantity by profitability: protect high-margin movers,
            // hold back cash on thin-margin lines.
            $profitFactor = $marginPct >= 50 ? 1.15 : ($marginPct < 20 ? 0.85 : 1.0);
            $suggestedQty = (int) round($suggestedQty * $profitFactor);

            // Combined priority: urgency first, then margin at risk.
            $urgencyWeight = ['critical'=>3,'high'=>2,'medium'=>1,'ok'=>0][$urgency] ?? 0;
            $profitScore   = round($urgencyWeight * max($expMonthlyMargin, 0), 2);

            $out[] = [
                'sku'            => $p['sku'],
                'description'    => $p['description'],
                'product_group'  => $p['product_group'],
                'supplier_name'  => $p['supplier_name'] ?? 'Unknown',
                'lead_time_days' => $leadTime,
                'daily_sales'    => $dailySales,
                'forecast_source'=> $mlOn ? 'ml' : 'rule',
                'forecast_next_month' => $nextMonth,
                'safety_stock'   => round($safetyStock),
                'reorder_point'  => round($reorderPoint),
                'current_stock'  => $stock,
                'in_transit'     => $transit,
                'available'      => $available,
                'below_reorder'  => $belowReorder,
                'eoq'            => $eoq,
                'suggested_qty'  => $suggestedQty,
                'suggested_value'=> round($suggestedQty * $uc, 2),
                'unit_cost'      => $uc,
                'unit_price'     => $up,
                'margin_pct'     => $marginPct,
                'margin_per_unit'=> round($marginUnit, 2),
                'expected_monthly_margin' => $expMonthlyMargin,
                'profit_score'   => $profitScore,
                'urgency'        => $urgency,
            ];
        }

        // Sort by urgency, then by profit at risk (protect the money-makers first)
        usort($out, function($a,$b){
            $o = ['critical'=>0,'high'=>1,'medium'=>2,'ok'=>3];
            $c = ($o[$a['urgency']] ?? 9) <=> ($o[$b['urgency']] ?? 9);
            if ($c !== 0) return $c;
            return $b['expected_monthly_margin'] <=> $a['expected_monthly_margin'];
        });
        return $out;
    }

    // =========================================================
    // EXPIRY ALERTS
    // =========================================================
    public function getExpiryAlerts(int $thresholdDays = 90): array {
        $cutoff = date('Y-m-d', strtotime("+{$thresholdDays} days"));
        $stmt = $this->db->prepare("
            SELECT sb.sku, p.description, p.product_group,
                   sb.location_code, l.name AS location_name,
                   sb.batch_number, sb.expiry_date,
                   sb.quantity_on_hand,
                   DATEDIFF(sb.expiry_date, CURDATE())         AS days_to_expiry,
                   ROUND(sb.quantity_on_hand * p.unit_cost, 2) AS write_off_value
            FROM   stock_balances sb
            JOIN   products p  ON p.sku = sb.sku
            JOIN   locations l ON l.location_code = sb.location_code
            WHERE  sb.expiry_date IS NOT NULL
              AND  sb.expiry_date <= :cutoff
              AND  sb.quantity_on_hand > 0
            ORDER  BY sb.expiry_date ASC
        ");
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->fetchAll();
    }

    // =========================================================
    // EXCESS STOCK
    // =========================================================
    public function getExcessStock(): array {
        $health = $this->getStockHealth();
        $out = [];
        foreach ($health as $h) {
            if ($h['status'] !== 'excess') continue;
            $out[] = $h;
        }
        usort($out, fn($a,$b) => $b['excess_value'] <=> $a['excess_value']);
        return $out;
    }

    // =========================================================
    // SLOW / NON-MOVERS
    // =========================================================
    public function getSlowMovers(int $thresholdDays = 90): array {
        $v = $this->getSalesVelocity($thresholdDays);

        $rows = $this->db->query("
            SELECT sb.sku, SUM(sb.quantity_on_hand) AS qty,
                   p.unit_cost, p.description, p.product_group
            FROM   stock_balances sb
            JOIN   products p ON p.sku = sb.sku
            GROUP  BY sb.sku, p.unit_cost, p.description, p.product_group
        ")->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $vel = $v[$r['sku']] ?? 0;
            $qty = (float)$r['qty'];
            if ($qty <= 0) continue;

            if ($vel == 0) {
                $category = 'non-mover';
            } elseif ($vel < 1) {
                $category = 'slow-mover';
            } else {
                continue; // moving normally
            }

            $out[] = [
                'sku'           => $r['sku'],
                'description'   => $r['description'],
                'product_group' => $r['product_group'],
                'qty_on_hand'   => $qty,
                'unit_cost'     => (float)$r['unit_cost'],
                'stock_value'   => round($qty * (float)$r['unit_cost'], 2),
                'category'      => $category,
            ];
        }
        usort($out, fn($a,$b) => $b['stock_value'] <=> $a['stock_value']);
        return $out;
    }

    // =========================================================
    // ABC / XYZ CLASSIFICATION
    // =========================================================
    public function getABCXYZ(): array {
        $v90 = $this->getSalesVelocity(90);
        $v60 = $this->getSalesVelocity(60);
        $v30 = $this->getSalesVelocity(30);

        $products = $this->db->query("
            SELECT sku, description, product_group, unit_cost, unit_price
            FROM   products WHERE status = 'active'
        ")->fetchAll();

        $rows = [];
        foreach ($products as $p) {
            $daily = $v90[$p['sku']] ?? 0;
            $price = (float)($p['unit_price'] ?: $p['unit_cost']);
            $annualRev = $daily * 365 * $price;
            if ($annualRev <= 0) continue;

            // Coefficient of variation across 3 monthly windows
            $w30 = $v30[$p['sku']] ?? 0;
            $w60 = (($v60[$p['sku']] ?? 0) * 60 - $w30 * 30) / 30;
            $w90 = ($daily * 90 - ($v60[$p['sku']] ?? 0) * 60) / 30;
            $vals = array_filter([$w30, $w60, $w90], fn($x) => $x >= 0);
            $mean = count($vals) ? array_sum($vals) / count($vals) : 0;
            $var  = $mean > 0
                ? array_sum(array_map(fn($x) => ($x - $mean) ** 2, $vals)) / count($vals)
                : 0;
            $cv = $mean > 0 ? sqrt($var) / $mean : 0;

            $rows[] = [
                'sku'            => $p['sku'],
                'description'    => $p['description'],
                'product_group'  => $p['product_group'],
                'daily_sales'    => $daily,
                'annual_revenue' => round($annualRev, 2),
                'cv'             => round($cv, 2),
                'xyz'            => $cv < 0.5 ? 'X' : ($cv < 1 ? 'Y' : 'Z'),
            ];
        }

        usort($rows, fn($a,$b) => $b['annual_revenue'] <=> $a['annual_revenue']);
        $total = array_sum(array_column($rows, 'annual_revenue'));
        $cum   = 0;
        foreach ($rows as &$r) {
            $cum += $r['annual_revenue'];
            $pct = $total > 0 ? ($cum / $total) * 100 : 0;
            $r['abc']         = $pct <= 70 ? 'A' : ($pct <= 90 ? 'B' : 'C');
            $r['class']       = $r['abc'] . $r['xyz'];
            $r['revenue_pct'] = $total > 0 ? round(($r['annual_revenue'] / $total) * 100, 1) : 0;
        }
        return $rows;
    }

    // =========================================================
    // PO vs GRN RECONCILIATION
    // =========================================================
    public function getPOReconciliation(): array {
        return $this->db->query("
            SELECT po.po_number, po.sku, p.description,
                   po.supplier_id, s.name AS supplier_name,
                   po.ordered_quantity, po.order_date,
                   po.expected_delivery_date, po.unit_cost,
                   po.status, po.location_code,
                   COALESCE(grn.received, 0)                    AS received_quantity,
                   COALESCE(grn.received, 0) - po.ordered_quantity AS variance,
                   CASE
                     WHEN po.ordered_quantity > 0
                     THEN ROUND((COALESCE(grn.received,0) - po.ordered_quantity)
                                / po.ordered_quantity * 100, 1)
                     ELSE 0
                   END                                           AS variance_pct,
                   DATEDIFF(CURDATE(), po.order_date)            AS po_age_days,
                   CASE
                     WHEN COALESCE(grn.received,0) = 0              THEN 'not_received'
                     WHEN COALESCE(grn.received,0) >= po.ordered_quantity THEN 'complete'
                     ELSE 'partial'
                   END                                           AS reconcile_status
            FROM   purchase_orders po
            JOIN   products p  ON p.sku = po.sku
            JOIN   suppliers s ON s.supplier_id = po.supplier_id
            LEFT JOIN (
                SELECT po_number, sku, SUM(received_quantity) AS received
                FROM   goods_received
                GROUP  BY po_number, sku
            ) grn ON grn.po_number = po.po_number AND grn.sku = po.sku
            WHERE  po.status != 'cancelled'
            ORDER  BY po.order_date DESC
        ")->fetchAll();
    }

    // =========================================================
    // SUPPLIER PERFORMANCE
    // =========================================================
    public function getSupplierPerformance(): array {
        return $this->db->query("
            SELECT s.supplier_id, s.name, s.lead_time_days,
                   s.payment_terms_days, s.status,
                   COUNT(DISTINCT po.po_number)                              AS total_pos,
                   ROUND(SUM(po.ordered_quantity * po.unit_cost), 2)        AS total_value,
                   COUNT(DISTINCT g.grn_number)                             AS grns_received,
                   ROUND(AVG(CASE WHEN g.receipt_date IS NOT NULL
                                    AND po.expected_delivery_date IS NOT NULL
                               THEN DATEDIFF(g.receipt_date, po.expected_delivery_date)
                               ELSE NULL END), 1)                           AS avg_delivery_delay,
                   SUM(CASE WHEN g.receipt_date <= po.expected_delivery_date
                            THEN 1 ELSE 0 END)                              AS on_time_count,
                   SUM(CASE WHEN g.receipt_date >  po.expected_delivery_date
                            THEN 1 ELSE 0 END)                              AS late_count,
                   ROUND(SUM(CASE WHEN g.receipt_date <= po.expected_delivery_date
                                  THEN 1 ELSE 0 END)
                         / NULLIF(COUNT(g.grn_number),0) * 100, 1)        AS on_time_rate,
                   ROUND(SUM(LEAST(COALESCE(g.received_quantity,0),
                                   po.ordered_quantity))
                         / NULLIF(SUM(po.ordered_quantity),0) * 100, 1)   AS qty_accuracy
            FROM   suppliers s
            LEFT JOIN purchase_orders po ON po.supplier_id = s.supplier_id
            LEFT JOIN goods_received  g  ON g.po_number   = po.po_number
            WHERE  s.status = 'active'
            GROUP  BY s.supplier_id, s.name, s.lead_time_days,
                      s.payment_terms_days, s.status
            ORDER  BY on_time_rate ASC
        ")->fetchAll();
    }

    // =========================================================
    // PAYMENT AGEING
    // =========================================================
    public function getPaymentAgeing(): array {
        return $this->db->query("
            SELECT ap.*, s.name AS supplier_name,
                   s.payment_terms_days,
                   DATEDIFF(CURDATE(), ap.due_date) AS days_overdue,
                   CASE
                     WHEN ap.payment_date IS NOT NULL              THEN 'paid'
                     WHEN DATEDIFF(CURDATE(), ap.due_date) <= 0   THEN 'current'
                     WHEN DATEDIFF(CURDATE(), ap.due_date) <= 30  THEN '1-30'
                     WHEN DATEDIFF(CURDATE(), ap.due_date) <= 60  THEN '31-60'
                     WHEN DATEDIFF(CURDATE(), ap.due_date) <= 90  THEN '61-90'
                     ELSE '90+'
                   END AS bucket
            FROM   accounts_payable ap
            JOIN   suppliers s ON s.supplier_id = ap.supplier_id
            ORDER  BY days_overdue DESC
        ")->fetchAll();
    }

    // =========================================================
    // MISMATCH ANALYSIS — slow movers over-ordered, fast under-stocked
    // =========================================================
    public function getMismatchAnalysis(): array {
        $health = $this->getStockHealth();
        $out = [];
        foreach ($health as $h) {
            $v     = (float)$h['avg_daily_sales'];
            $cover = (int)$h['cover_days'];
            $max   = (int)$h['max_cover_days'];
            $min   = (int)$h['min_cover_days'];

            if ($cover > $max && $v < 5) {
                $out[] = array_merge($h, [
                    'velocity_class' => 'slow',
                    'order_pattern'  => 'over-ordered',
                    'recommendation' => 'Pause orders — ' . ($cover - $max) . ' excess cover days on hand.',
                ]);
            } elseif ($cover < $min && $v >= 20) {
                $out[] = array_merge($h, [
                    'velocity_class' => 'fast',
                    'order_pattern'  => 'under-stocked',
                    'recommendation' => 'Urgent reorder: ~' . round($v * 30) . ' units needed.',
                ]);
            }
        }
        return $out;
    }

    // =========================================================
    // DATA MANAGEMENT — record counts
    // =========================================================
    public function getEntityCounts(): array {
        $tables = [
            'products'         => 'products',
            'suppliers'        => 'suppliers',
            'movements'        => 'stock_movements',
            'purchase_orders'  => 'purchase_orders',
            'goods_received'   => 'goods_received',
            'accounts_payable' => 'accounts_payable',
            'stock_balances'   => 'stock_balances',
        ];
        $out = [];
        foreach ($tables as $key => $table) {
            $out[$key] = (int)$this->db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        }
        return $out;
    }

    // =========================================================
    // CRUD — PRODUCTS
    // =========================================================
    public function getProducts(): array {
        return $this->db->query("SELECT * FROM products ORDER BY sku")->fetchAll();
    }

    public function createProduct(array $d): array {
        $stmt = $this->db->prepare("
            INSERT INTO products
              (sku, description, product_group, unit_cost, unit_price,
               shelf_life_days, min_cover_days, max_cover_days,
               safety_stock_days, reorder_cover_days, ordering_cost,
               holding_cost_pct, status)
            VALUES
              (:sku,:description,:product_group,:unit_cost,:unit_price,
               :shelf_life_days,:min_cover_days,:max_cover_days,
               :safety_stock_days,:reorder_cover_days,:ordering_cost,
               :holding_cost_pct,:status)
        ");
        $stmt->execute($this->productParams($d));
        return ['success'=>true, 'id'=>(int)$this->db->lastInsertId()];
    }

    public function updateProduct(int $id, array $d): array {
        $stmt = $this->db->prepare("
            UPDATE products SET
              sku=:sku, description=:description, product_group=:product_group,
              unit_cost=:unit_cost, unit_price=:unit_price,
              shelf_life_days=:shelf_life_days, min_cover_days=:min_cover_days,
              max_cover_days=:max_cover_days, safety_stock_days=:safety_stock_days,
              reorder_cover_days=:reorder_cover_days, ordering_cost=:ordering_cost,
              holding_cost_pct=:holding_cost_pct, status=:status
            WHERE id=:id
        ");
        $stmt->execute(array_merge($this->productParams($d), [':id'=>$id]));
        return ['success'=>true];
    }

    public function deleteProduct(int $id): array {
        $this->db->prepare("DELETE FROM products WHERE id=:id")->execute([':id'=>$id]);
        return ['success'=>true];
    }

    private function productParams(array $d): array {
        return [
            ':sku'               => trim($d['sku'] ?? ''),
            ':description'       => trim($d['description'] ?? ''),
            ':product_group'     => trim($d['product_group'] ?? ''),
            ':unit_cost'         => (float)($d['unit_cost'] ?? 0),
            ':unit_price'        => (float)($d['unit_price'] ?? 0),
            ':shelf_life_days'   => (int)($d['shelf_life_days'] ?? 0),
            ':min_cover_days'    => (int)($d['min_cover_days'] ?? 14),
            ':max_cover_days'    => (int)($d['max_cover_days'] ?? 60),
            ':safety_stock_days' => (int)($d['safety_stock_days'] ?? 7),
            ':reorder_cover_days'=> (int)($d['reorder_cover_days'] ?? 30),
            ':ordering_cost'     => (float)($d['ordering_cost'] ?? 50),
            ':holding_cost_pct'  => (float)($d['holding_cost_pct'] ?? 20),
            ':status'            => in_array($d['status']??'',['active','discontinued','seasonal'])
                                    ? $d['status'] : 'active',
        ];
    }

    // =========================================================
    // CRUD — SUPPLIERS
    // =========================================================
    public function getSuppliers(): array {
        return $this->db->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();
    }

    public function createSupplier(array $d): array {
        $stmt = $this->db->prepare("
            INSERT INTO suppliers
              (supplier_id, name, lead_time_days, payment_terms_days,
               contact_email, contact_phone, status)
            VALUES
              (:supplier_id,:name,:lead_time_days,:payment_terms_days,
               :contact_email,:contact_phone,:status)
        ");
        $stmt->execute($this->supplierParams($d));
        return ['success'=>true, 'id'=>(int)$this->db->lastInsertId()];
    }

    public function updateSupplier(int $id, array $d): array {
        $stmt = $this->db->prepare("
            UPDATE suppliers SET
              supplier_id=:supplier_id, name=:name,
              lead_time_days=:lead_time_days,
              payment_terms_days=:payment_terms_days,
              contact_email=:contact_email, contact_phone=:contact_phone,
              status=:status
            WHERE id=:id
        ");
        $stmt->execute(array_merge($this->supplierParams($d), [':id'=>$id]));
        return ['success'=>true];
    }

    public function deleteSupplier(int $id): array {
        $this->db->prepare("DELETE FROM suppliers WHERE id=:id")->execute([':id'=>$id]);
        return ['success'=>true];
    }

    private function supplierParams(array $d): array {
        return [
            ':supplier_id'        => trim($d['supplier_id'] ?? ''),
            ':name'               => trim($d['name'] ?? ''),
            ':lead_time_days'     => (int)($d['lead_time_days'] ?? 7),
            ':payment_terms_days' => (int)($d['payment_terms_days'] ?? 30),
            ':contact_email'      => trim($d['contact_email'] ?? ''),
            ':contact_phone'      => trim($d['contact_phone'] ?? ''),
            ':status'             => in_array($d['status']??'',['active','inactive','on_hold'])
                                     ? $d['status'] : 'active',
        ];
    }

    // =========================================================
    // CLEAR ENTITY (Data Management page)
    // =========================================================
    public function clearEntity(string $entity): array {
        $allowed = [
            'products'        => 'products',
            'suppliers'       => 'suppliers',
            'movements'       => 'stock_movements',
            'purchase_orders' => 'purchase_orders',
            'goods_received'  => 'goods_received',
            'accounts_payable'=> 'accounts_payable',
            'stock_balances'  => 'stock_balances',
        ];
        if (!isset($allowed[$entity])) {
            return ['success'=>false, 'error'=>'Unknown entity'];
        }
        $table = $allowed[$entity];
        $this->db->exec("SET FOREIGN_KEY_CHECKS=0");
        $this->db->exec("TRUNCATE TABLE `$table`");
        $this->db->exec("SET FOREIGN_KEY_CHECKS=1");
        return ['success'=>true];
    }

    // =========================================================
    // LOCATION PERFORMANCE — where operational problems concentrate
    // =========================================================
    public function getLocationPerformance(): array {
        $health = $this->getStockHealth();          // per SKU x location: status, stock_value, excess_value
        $expiry = $this->getExpiryAlerts(90);        // per SKU x location: write_off_value within 90 days

        $loc = [];
        foreach ($health as $h) {
            $code = $h['location_code'];
            if (!isset($loc[$code])) {
                $loc[$code] = [
                    'location_code'    => $code,
                    'location_name'    => $h['location_name'],
                    'sku_count'        => 0,
                    'stockout_count'   => 0,
                    'critical_count'   => 0,
                    'low_count'        => 0,
                    'healthy_count'    => 0,
                    'excess_count'     => 0,
                    'excess_value'     => 0.0,
                    'stock_value'      => 0.0,
                    'expiry_value_90d' => 0.0,
                    'expiry_items'     => 0,
                ];
            }
            $loc[$code]['sku_count']++;
            $loc[$code]['stock_value'] += (float)$h['stock_value'];
            switch ($h['status']) {
                case 'stockout': $loc[$code]['stockout_count']++; break;
                case 'critical': $loc[$code]['critical_count']++; break;
                case 'low':      $loc[$code]['low_count']++; break;
                case 'excess':
                    $loc[$code]['excess_count']++;
                    $loc[$code]['excess_value'] += (float)$h['excess_value'];
                    break;
                default:         $loc[$code]['healthy_count']++;
            }
        }
        foreach ($expiry as $e) {
            $code = $e['location_code'];
            if (!isset($loc[$code])) continue;
            $loc[$code]['expiry_value_90d'] += (float)$e['write_off_value'];
            $loc[$code]['expiry_items']++;
        }

        $out = [];
        foreach ($loc as $l) {
            $n = max($l['sku_count'], 1);
            $stockoutRate = round($l['stockout_count'] / $n * 100, 1);
            $criticalRate = round($l['critical_count'] / $n * 100, 1);
            $excessRate   = round($l['excess_count']   / $n * 100, 1);
            $atRiskRate   = round(($l['stockout_count'] + $l['critical_count']) / $n * 100, 1);
            $expiryRatio  = $l['stock_value'] > 0
                ? round($l['expiry_value_90d'] / $l['stock_value'] * 100, 1) : 0.0;

            // Composite risk score - anchored to real business impact, not raw
            // percentages, so a location with one low-value item can't outrank
            // a location holding lakhs of rupees of genuine excess/expiry stock.
            // Each stockout/critical event is a fixed severity penalty (lost-sales
            // risk is categorical, not proportional to stock value); excess cash
            // and write-off exposure are scored by the rupee amount actually at
            // stake (per Rs.1,000).
            $riskScore = round(
                $l['stockout_count'] * 50 +
                $l['critical_count'] * 15 +
                $l['low_count']      * 4  +
                $l['excess_value']     / 1000 +
                $l['expiry_value_90d'] / 1000
            , 1);

            $out[] = array_merge($l, [
                'stock_value'      => round($l['stock_value'], 2),
                'excess_value'     => round($l['excess_value'], 2),
                'expiry_value_90d' => round($l['expiry_value_90d'], 2),
                'stockout_rate'    => $stockoutRate,
                'critical_rate'    => $criticalRate,
                'excess_rate'      => $excessRate,
                'at_risk_rate'     => $atRiskRate,
                'expiry_ratio'     => $expiryRatio,
                'risk_score'       => $riskScore,
            ]);
        }

        usort($out, fn($a, $b) => $b['risk_score'] <=> $a['risk_score']);
        return $out;
    }

    // =========================================================
    // LOCATIONS
    // =========================================================
    public function getLocations(): array {
        return $this->db->query("SELECT * FROM locations ORDER BY name")->fetchAll();
    }
}
