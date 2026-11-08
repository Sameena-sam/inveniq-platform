# =============================================================
# InvenIQ Sales Intelligence — MySQL writer
# Creates the sa_* tables (sql/schema_analytics.sql) and loads the
# latest results so the PHP pages can read them. New results load into
# staging tables and swap in together, so a failed run leaves the last good ones.
# =============================================================
import re
import json
import datetime as dt

import numpy as np
import pandas as pd

import config as cfg
import sales_actions

try:
    import pymysql
except ImportError:
    pymysql = None

STAGING, OLD = "__new", "__old"     # suffixes used while results are swapped in

TERRITORY_COLS = [
    "location_code", "location_name", "territory", "hq", "fy", "status", "segment",
    "revenue", "margin_value", "margin_pct", "growth_cagr_pct", "growth_years", "yoy_growth_pct",
    "customers", "products", "revenue_per_customer", "retention_pct", "return_rate_pct",
    "peak_fy", "peak_revenue", "pct_of_peak", "business_pct_of_peak", "relative_retention_pct",
    "expected_revenue", "peer_gap_pct", "score_revenue", "score_margin", "score_growth",
    "score_customers", "score_products", "composite_score", "composite_rank", "revenue_rank",
    "rank_low", "rank_high", "segment_stability_pct", "flag_peer_gap", "flag_lost_ground",
    "lat", "lon", "distance_km", "first_sale", "last_sale", "lifetime_revenue", "reasons", "run_id",
]
YEARLY_COLS = ["location_code", "fy", "status", "segment", "revenue", "margin_pct",
               "growth_cagr_pct", "customers", "products", "composite_score", "composite_rank"]
HQ_COLS = ["hq", "fy", "status", "segment", "territories_total", "territories_active", "revenue",
           "revenue_share_pct", "margin_pct", "growth_cagr_pct", "customers", "products",
           "pct_of_peak", "relative_retention_pct", "composite_score", "composite_rank", "reasons", "run_id"]
PRODUCT_COLS = ["sku", "product", "segment", "fy", "abc", "xyz", "class", "revenue", "rev_share_pct",
                "cum_share_pct", "cv", "months_active", "margin_pct", "rev_growth_pct", "customers",
                "territories", "is_new", "reasons", "run_id"]
HISTORY_COLS = ["sku", "fy", "product", "segment", "abc", "xyz", "class", "revenue", "rev_share_pct", "cv"]
SEGMENT_COLS = ["segment", "fy", "revenue", "revenue_share_pct", "products", "a_products", "margin_pct",
                "weighted_cv", "stable_rev_share_pct", "growth_cagr_pct", "seg_abc", "seg_xyz", "seg_class",
                "label", "reasons", "run_id"]
WATCH_COLS = ["sku", "product", "segment", "fy", "kind", "group_before", "group_now", "revenue",
              "prev_revenue", "change_pct", "detail", "run_id"]

CELL_COLS = ["location_code", "segment", "territory", "hq", "fy", "sales", "share_pct", "peer_share_pct", "gap_value",
             "seg_class", "seg_label", "points_gap", "points_category", "points_underserved", "points_momentum",
             "points_margin", "score", "eligible", "`rank`", "reasons", "action_plan", "run_id"]
WIN_BACK_COLS = ["location_code", "territory", "hq", "best_fy", "best_sales", "last_sale", "bought", "why_stopped",
                 "by_choice", "run_id"]


def to_json(obj) -> str:
    return json.dumps(obj, ensure_ascii=False,
                      default=lambda o: o.item() if hasattr(o, "item") else str(o))


def _sql_value(v):
    """pandas/numpy value -> something PyMySQL can insert (NaN -> NULL)."""
    if isinstance(v, (list, dict)):
        return to_json(v)
    if v is None or (not isinstance(v, str) and pd.isna(v)):
        return None
    if isinstance(v, (bool, np.bool_)):
        return int(v)
    if isinstance(v, pd.Timestamp):
        return v.date()
    if isinstance(v, np.generic):
        return v.item()
    return v


def _rows(df: pd.DataFrame, cols: list) -> list:
    names = [c.strip("`") for c in cols]      # `rank` is a reserved word in MySQL
    return [tuple(_sql_value(v) for v in row) for row in df[names].itertuples(index=False)]


def _insert(cur, table: str, df: pd.DataFrame, cols: list) -> int:
    sql = f"INSERT INTO {table}{STAGING} ({','.join(cols)}) VALUES ({','.join(['%s'] * len(cols))})"
    rows = _rows(df, cols)
    cur.executemany(sql, rows)
    return len(rows)


def run_schema(cur) -> list:
    """Create the result tables under staging names; returns their real names."""
    with open(cfg.SCHEMA_PATH, encoding="utf-8") as f:
        text = re.sub(r"--[^\n]*", "", f.read())
    tables = re.findall(r"DROP TABLE IF EXISTS (\w+)", text)
    for t in tables:
        text = re.sub(rf"\b{t}\b", t + STAGING, text)
    for stmt in (s.strip() for s in text.split(";")):
        if stmt:
            cur.execute(stmt)
    return tables


def _swap_in(cur, tables: list) -> None:
    """Replace every result table at once (RENAME TABLE is atomic in MySQL)."""
    for t in tables:
        cur.execute(f"CREATE TABLE IF NOT EXISTS {t} LIKE {t}{STAGING}")    # first run
        cur.execute(f"DROP TABLE IF EXISTS {t}{OLD}")
    cur.execute("RENAME TABLE " + ", ".join(f"{t} TO {t}{OLD}, {t}{STAGING} TO {t}" for t in tables))
    for t in tables:
        cur.execute(f"DROP TABLE {t}{OLD}")


def _log_run(cur, module: str, res: dict, run_id: str, run_info: dict) -> None:
    cur.execute(
        "INSERT INTO sa_runs (run_id,module,run_at,data_from,data_to,fy,params_json,metrics_json) "
        "VALUES (%s,%s,%s,%s,%s,%s,%s,%s)",
        (run_id, module, dt.datetime.now().replace(microsecond=0), run_info["data_from"],
         run_info["data_to"], res["fys"][-1], to_json(res["params"]), to_json(res["metrics"])))


def write_rq1(cur, res: dict, run_id: str, run_info: dict) -> dict:
    terr = res["territories"].copy()
    terr.index.name = "location_code"
    terr = terr.reset_index().rename(columns={"location": "location_name"})
    terr["run_id"] = run_id
    yearly = res["territory_yearly"].rename(columns={"loc_code": "location_code"})
    hq = res["hq"].copy()
    hq.index.name = "hq"
    hq = hq.reset_index()
    hq["run_id"] = run_id
    counts = {
        "sa_territory_scores": _insert(cur, "sa_territory_scores", terr, TERRITORY_COLS),
        "sa_territory_yearly": _insert(cur, "sa_territory_yearly", yearly, YEARLY_COLS),
        "sa_hq_scores": _insert(cur, "sa_hq_scores", hq, HQ_COLS),
    }
    _log_run(cur, "rq1", res, run_id, run_info)
    return counts


def write_rq2(cur, res: dict, run_id: str, run_info: dict) -> dict:
    prod = res["products"].assign(run_id=run_id)
    seg = res["segments"].reset_index().assign(run_id=run_id)
    counts = {
        "sa_product_classes": _insert(cur, "sa_product_classes", prod, PRODUCT_COLS),
        "sa_product_class_history": _insert(cur, "sa_product_class_history", res["history"], HISTORY_COLS),
        "sa_segment_profile": _insert(cur, "sa_segment_profile", seg, SEGMENT_COLS),
        "sa_product_watch": _insert(cur, "sa_product_watch", res["watch"].assign(run_id=run_id), WATCH_COLS),
    }
    _log_run(cur, "rq2", res, run_id, run_info)
    return counts


def write_rq3(cur, res: dict, run_id: str, run_info: dict) -> dict:
    counts = {
        "sa_expansion_cells": _insert(cur, "sa_expansion_cells", res["cells"].assign(run_id=run_id), CELL_COLS),
        "sa_win_back": _insert(cur, "sa_win_back", res["win_back"].assign(run_id=run_id), WIN_BACK_COLS),
    }
    _log_run(cur, "rq3", res, run_id, run_info)
    return counts


def write_rq4(cur, res: dict, run_id: str, run_info: dict) -> dict:
    _log_run(cur, "rq4", res, run_id, run_info)      # thesis evidence only: the client pages don't show it
    return {}


def write_actions(cur, res: dict, run_id: str, run_info: dict) -> dict:
    cur.execute("SELECT id, kind, customer, location_code, segment, done_on FROM sa_action_log")
    cols = [d[0] for d in cur.description]
    log = pd.DataFrame(list(cur.fetchall()), columns=cols)
    results = sales_actions.action_results(res["sales"], log)
    counts = {
        "sa_account_watch": _insert(cur, "sa_account_watch", res["accounts"].assign(run_id=run_id), sales_actions.ACCOUNT_COLS + ["run_id"]),
        "sa_quiet_customers": _insert(cur, "sa_quiet_customers", res["quiet"].assign(run_id=run_id), sales_actions.QUIET_COLS + ["run_id"]),
        "sa_action_results": _insert(cur, "sa_action_results", results.assign(run_id=run_id), sales_actions.RESULT_COLS + ["run_id"]),
    }
    _log_run(cur, "actions", res, run_id, {**run_info, "data_to": res["metrics"]["as_of"]})     # includes any part-year
    return counts


def write_all(results: dict, run_id: str, run_info: dict) -> dict:
    """Load every module's results into staging tables, commit, then swap them in.
    `results` maps module name ('rq1' to 'rq4', 'actions') to its result."""
    if pymysql is None:
        raise RuntimeError("PyMySQL not installed. Run:  pip install -r requirements.txt")
    writers = {"rq1": write_rq1, "rq2": write_rq2, "rq3": write_rq3, "rq4": write_rq4, "actions": write_actions}
    conn = pymysql.connect(**cfg.DB)
    try:
        with conn.cursor() as cur:
            tables = run_schema(cur)
            try:
                counts = {}
                for module, res in results.items():
                    counts.update(writers[module](cur, res, run_id, run_info))
            except Exception:
                conn.rollback()
                for t in tables:
                    cur.execute(f"DROP TABLE IF EXISTS {t}{STAGING}")
                raise
            conn.commit()
            _swap_in(cur, tables)
    finally:
        conn.close()
    return counts
