-- ============================================================
-- InvenIQ Sales Intelligence — result tables (sa_*)
-- Written by analytics/run_pipeline.py; read by the Sales
-- Intelligence pages. The pipeline runs this file on every run:
-- result tables are rebuilt each time, sa_runs keeps the history.
-- Kept separate from database.sql (the operational schema).
-- ============================================================
USE inveniq;

CREATE TABLE IF NOT EXISTS sa_runs (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  run_id        VARCHAR(20)  NOT NULL,
  module        VARCHAR(20)  NOT NULL,
  run_at        DATETIME     NOT NULL,
  data_from     DATE,
  data_to       DATE,
  fy            VARCHAR(10),
  params_json   MEDIUMTEXT,
  metrics_json  MEDIUMTEXT,
  INDEX (module, run_at)
);

-- Actions staff mark as done: their own record, so never rebuilt
CREATE TABLE IF NOT EXISTS sa_action_log (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  kind          VARCHAR(20)  NOT NULL,
  customer      VARCHAR(190) NOT NULL,
  location_code VARCHAR(20)  NOT NULL DEFAULT '',
  segment       VARCHAR(100) NOT NULL DEFAULT '',
  done_on       DATE         NOT NULL,
  done_by       VARCHAR(150),
  UNIQUE KEY one_mark (kind, customer, location_code, segment)
);

-- ---- RQ1: territory (location) scores, latest fiscal year ----
DROP TABLE IF EXISTS sa_territory_scores;
CREATE TABLE sa_territory_scores (
  location_code          VARCHAR(20)  PRIMARY KEY,
  location_name          VARCHAR(100),
  territory              VARCHAR(100),
  hq                     VARCHAR(50),
  fy                     VARCHAR(10),
  status                 VARCHAR(20),
  segment                VARCHAR(30),
  revenue                DECIMAL(14,2),
  margin_value           DECIMAL(14,2),
  margin_pct             DECIMAL(8,2),
  growth_cagr_pct        DECIMAL(8,2),
  growth_years           INT,
  yoy_growth_pct         DECIMAL(10,2),
  customers              INT,
  products               INT,
  revenue_per_customer   DECIMAL(14,2),
  retention_pct          DECIMAL(6,2),
  return_rate_pct        DECIMAL(8,2),
  peak_fy                VARCHAR(10),
  peak_revenue           DECIMAL(14,2),
  pct_of_peak            DECIMAL(8,2),
  business_pct_of_peak   DECIMAL(8,2),
  relative_retention_pct DECIMAL(8,2),
  expected_revenue       DECIMAL(14,2),
  peer_gap_pct           DECIMAL(10,2),
  score_revenue          DECIMAL(5,1),
  score_margin           DECIMAL(5,1),
  score_growth           DECIMAL(5,1),
  score_customers        DECIMAL(5,1),
  score_products         DECIMAL(5,1),
  composite_score        DECIMAL(5,1),
  composite_rank         INT,
  revenue_rank           INT,
  rank_low               INT,
  rank_high              INT,
  segment_stability_pct  DECIMAL(5,1),
  flag_peer_gap          TINYINT(1)   DEFAULT 0,
  flag_lost_ground       TINYINT(1)   DEFAULT 0,
  lat                    DECIMAL(9,5),
  lon                    DECIMAL(9,5),
  distance_km            DECIMAL(7,1),
  first_sale             DATE,
  last_sale              DATE,
  lifetime_revenue       DECIMAL(14,2),
  reasons                TEXT,
  run_id                 VARCHAR(20)
);

-- ---- RQ1: every territory x fiscal year (trend and rank history) ----
DROP TABLE IF EXISTS sa_territory_yearly;
CREATE TABLE sa_territory_yearly (
  location_code    VARCHAR(20) NOT NULL,
  fy               VARCHAR(10) NOT NULL,
  status           VARCHAR(20),
  segment          VARCHAR(30),
  revenue          DECIMAL(14,2),
  margin_pct       DECIMAL(8,2),
  growth_cagr_pct  DECIMAL(8,2),
  customers        INT,
  products         INT,
  composite_score  DECIMAL(5,1),
  composite_rank   INT,
  PRIMARY KEY (location_code, fy)
);

-- ---- RQ1: regional (HQ) scores, latest fiscal year ----
DROP TABLE IF EXISTS sa_hq_scores;
CREATE TABLE sa_hq_scores (
  hq                     VARCHAR(50)  PRIMARY KEY,
  fy                     VARCHAR(10),
  status                 VARCHAR(20),
  segment                VARCHAR(30),
  territories_total      INT,
  territories_active     INT,
  revenue                DECIMAL(14,2),
  revenue_share_pct      DECIMAL(6,2),
  margin_pct             DECIMAL(8,2),
  growth_cagr_pct        DECIMAL(8,2),
  customers              INT,
  products               INT,
  pct_of_peak            DECIMAL(8,2),
  relative_retention_pct DECIMAL(8,2),
  composite_score        DECIMAL(5,1),
  composite_rank         INT,
  reasons                TEXT,
  run_id                 VARCHAR(20)
);

-- ---- RQ2: product classes, latest fiscal year ----
DROP TABLE IF EXISTS sa_product_classes;
CREATE TABLE sa_product_classes (
  sku                    VARCHAR(30)  PRIMARY KEY,
  product                VARCHAR(200),
  segment                VARCHAR(100),
  fy                     VARCHAR(10),
  abc                    CHAR(1),
  xyz                    CHAR(1),
  class                  CHAR(2),
  revenue                DECIMAL(14,2),
  rev_share_pct          DECIMAL(7,3),
  cum_share_pct          DECIMAL(7,2),
  cv                     DECIMAL(8,3),
  months_active          INT,
  margin_pct             DECIMAL(8,2),
  rev_growth_pct         DECIMAL(9,2),
  customers              INT,
  territories            INT,
  is_new                 TINYINT(1)   DEFAULT 0,
  reasons                TEXT,
  run_id                 VARCHAR(20)
);

-- ---- RQ2: every product x fiscal year class (history) ----
DROP TABLE IF EXISTS sa_product_class_history;
CREATE TABLE sa_product_class_history (
  sku                    VARCHAR(30)  NOT NULL,
  fy                     VARCHAR(10)  NOT NULL,
  product                VARCHAR(200),
  segment                VARCHAR(100),
  abc                    CHAR(1),
  xyz                    CHAR(1),
  class                  CHAR(2),
  revenue                DECIMAL(14,2),
  rev_share_pct          DECIMAL(7,3),
  cv                     DECIMAL(8,3),
  PRIMARY KEY (sku, fy)
);

-- ---- RQ2: therapeutic segment (category) value and stability ----
DROP TABLE IF EXISTS sa_segment_profile;
CREATE TABLE sa_segment_profile (
  segment                VARCHAR(100) PRIMARY KEY,
  fy                     VARCHAR(10),
  revenue                DECIMAL(14,2),
  revenue_share_pct      DECIMAL(6,2),
  products               INT,
  a_products             INT,
  margin_pct             DECIMAL(8,2),
  weighted_cv            DECIMAL(8,3),
  stable_rev_share_pct   DECIMAL(6,2),
  growth_cagr_pct        DECIMAL(8,2),
  seg_abc                CHAR(1),
  seg_xyz                CHAR(1),
  seg_class              CHAR(2),
  label                  VARCHAR(40),
  reasons                TEXT,
  run_id                 VARCHAR(20)
);

-- ---- RQ2: client watch list, real changes between the last two years ----
DROP TABLE IF EXISTS sa_product_watch;
CREATE TABLE sa_product_watch (
  sku                    VARCHAR(30)  PRIMARY KEY,
  product                VARCHAR(200),
  segment                VARCHAR(100),
  fy                     VARCHAR(10),
  kind                   VARCHAR(20),
  group_before           CHAR(1),
  group_now              CHAR(1),
  revenue                DECIMAL(14,2),
  prev_revenue           DECIMAL(14,2),
  change_pct             DECIMAL(10,2),
  detail                 TEXT,
  run_id                 VARCHAR(20)
);

-- ---- RQ3: territory x category opportunity scores ----
DROP TABLE IF EXISTS sa_expansion_cells;
CREATE TABLE sa_expansion_cells (
  location_code          VARCHAR(20)  NOT NULL,
  segment                VARCHAR(100) NOT NULL,
  territory              VARCHAR(100),
  hq                     VARCHAR(50),
  fy                     VARCHAR(10),
  sales                  DECIMAL(14,2),
  share_pct              DECIMAL(6,2),
  peer_share_pct         DECIMAL(6,2),
  gap_value              DECIMAL(14,2),
  seg_class              CHAR(2),
  seg_label              VARCHAR(40),
  points_gap             DECIMAL(5,1),
  points_category        DECIMAL(5,1),
  points_underserved     DECIMAL(5,1),
  points_momentum        DECIMAL(5,1),
  points_margin          DECIMAL(5,1),
  score                  DECIMAL(5,1),
  eligible               TINYINT(1)   DEFAULT 0,
  `rank`                 INT,
  reasons                TEXT,
  action_plan            TEXT,
  run_id                 VARCHAR(20),
  PRIMARY KEY (location_code, segment)
);

-- ---- RQ3: lapsed territories worth winning back ----
DROP TABLE IF EXISTS sa_win_back;
CREATE TABLE sa_win_back (
  location_code          VARCHAR(20)  PRIMARY KEY,
  territory              VARCHAR(100),
  hq                     VARCHAR(50),
  best_fy                VARCHAR(10),
  best_sales             DECIMAL(14,2),
  last_sale              DATE,
  bought                 TEXT,
  why_stopped            VARCHAR(60),
  by_choice              TINYINT(1)   DEFAULT 0,
  run_id                 VARCHAR(20)
);

-- ---- Sales Action Plan: big accounts buying less ----
DROP TABLE IF EXISTS sa_account_watch;
CREATE TABLE sa_account_watch (
  customer      VARCHAR(190) PRIMARY KEY,
  location_code VARCHAR(20),
  territory     VARCHAR(100),
  hq            VARCHAR(50),
  sales_before  DECIMAL(14,2),
  sales_now     DECIMAL(14,2),
  change_value  DECIMAL(14,2),
  change_pct    DECIMAL(7,1),
  falls         TEXT,
  run_id        VARCHAR(20)
);

-- ---- Sales Action Plan: customers past their usual reorder time ----
DROP TABLE IF EXISTS sa_quiet_customers;
CREATE TABLE sa_quiet_customers (
  customer       VARCHAR(190) PRIMARY KEY,
  location_code  VARCHAR(20),
  territory      VARCHAR(100),
  hq             VARCHAR(50),
  usual_gap_days INT,
  days_since     INT,
  last_order     DATE,
  value_12m      DECIMAL(14,2),
  buys           TEXT,
  run_id         VARCHAR(20)
);

-- ---- Sales Action Plan: what happened after each marked action ----
DROP TABLE IF EXISTS sa_action_results;
CREATE TABLE sa_action_results (
  action_id     INT PRIMARY KEY,
  status        VARCHAR(10),
  days_after    INT,
  sales_before  DECIMAL(14,2),
  sales_after   DECIMAL(14,2),
  others_before DECIMAL(16,2),
  others_after  DECIMAL(16,2),
  run_id        VARCHAR(20)
);
