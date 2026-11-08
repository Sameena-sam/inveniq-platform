# =============================================================
# InvenIQ ML — Shared core
# Data loading, cleaning, feature engineering and recursive
# monthly demand forecasting.
#
# Used by train_model.py, predict.py, predict_service.py and
# load_data.py so the SAME logic produces SKUs, features and
# forecasts everywhere.
# =============================================================
import os
import re
import hashlib

import numpy as np
import pandas as pd

# ---- Paths -------------------------------------------------
ML_DIR     = os.path.dirname(os.path.abspath(__file__))
APP_DIR    = os.path.dirname(ML_DIR)                       # ...\inveniq
MODEL_PATH = os.path.join(ML_DIR, "model.pkl")

# Default dataset location (sits in the app root)
DATASET_NAME = "2.Primary Sales Data -  2020-21 to 2025-26.xlsx"
DATASET_PATH = os.path.join(APP_DIR, DATASET_NAME)
DATA_SHEET   = "Data Sheet1"

# Columns we rely on from the workbook
COL = {
    "date": "Bill Date", "year": "Year", "month": "Month",
    "category": "Category", "customer": "Customer Name",
    "product": "Product Name", "segment": "Segmant",
    "batch": "Product Batch No", "purchase_date": "Purchase Date",
    "expiry": "Expiry Date", "qty": "Sale Qty", "free": "Free Qty",
    "pp": "Purchase Price", "rate": "Rate", "mrp": "MRP",
    "amount": "Sales Amount", "tax": "Tax %", "hq": "HQ",
    "location": "Location",
}

# Categories that represent genuine outbound demand
DEMAND_CATEGORIES = {"DIRECT", "PARTY", "P PARTY"}


# ---- SKU generation (deterministic & stable) ----------------
def make_sku(product_name: str) -> str:
    """Deterministic SKU from a product name.
    Same name -> same SKU in BOTH the model and the database loader."""
    name = str(product_name).strip().upper()
    alpha = re.sub(r"[^A-Z0-9]", "", name)[:8] or "PROD"
    h = hashlib.md5(name.encode("utf-8")).hexdigest()[:4].upper()
    return f"{alpha}-{h}"


def make_location_code(location: str) -> str:
    loc = str(location).strip().upper()
    code = re.sub(r"[^A-Z0-9]", "", loc.split("-")[0])[:8] or "LOC"
    h = hashlib.md5(loc.encode("utf-8")).hexdigest()[:3].upper()
    return f"{code}-{h}"


# ---- Cleaning / loading ------------------------------------
def load_raw(path: str = None) -> pd.DataFrame:
    path = path or DATASET_PATH
    # Optional explicit cache override (handy for CI / fast retrains)
    env_cache = os.environ.get("INVENIQ_SALES_PKL")
    if env_cache and os.path.exists(env_cache):
        return pd.read_pickle(env_cache)
    # Reading the 35 MB workbook takes ~30s; cache a fast copy next to it
    # so retraining and serving are quick. Cache is rebuilt if the xlsx changes.
    cache = os.path.join(ML_DIR, ".sales_cache.pkl")
    try:
        if os.path.exists(cache) and os.path.getmtime(cache) >= os.path.getmtime(path):
            return pd.read_pickle(cache)
    except Exception:
        pass
    df = pd.read_excel(path, sheet_name=DATA_SHEET, usecols="A:Z")
    df.columns = [str(c).strip() for c in df.columns]
    df = df.dropna(how="all")
    df[COL["date"]] = pd.to_datetime(df[COL["date"]], errors="coerce")
    for c in [COL["qty"], COL["pp"], COL["rate"], COL["mrp"], COL["amount"]]:
        df[c] = pd.to_numeric(df[c], errors="coerce")
    df[COL["category"]] = df[COL["category"]].astype(str).str.strip().str.upper()
    df[COL["expiry"]] = pd.to_datetime(df[COL["expiry"]], errors="coerce")
    df = df[df[COL["date"]].notna()]
    try:
        df.to_pickle(cache)
    except Exception:
        pass
    return df


def _load_raw_legacy(path: str = None) -> pd.DataFrame:
    path = path or DATASET_PATH
    df = pd.read_excel(path, sheet_name=DATA_SHEET, usecols="A:Z")
    df.columns = [str(c).strip() for c in df.columns]
    df = df.dropna(how="all")
    df[COL["date"]] = pd.to_datetime(df[COL["date"]], errors="coerce")
    for c in [COL["qty"], COL["pp"], COL["rate"], COL["mrp"], COL["amount"]]:
        df[c] = pd.to_numeric(df[c], errors="coerce")
    df[COL["category"]] = df[COL["category"]].astype(str).str.strip().str.upper()
    df[COL["expiry"]] = pd.to_datetime(df[COL["expiry"]], errors="coerce")
    df = df[df[COL["date"]].notna()]
    return df


def clean_demand(df: pd.DataFrame) -> pd.DataFrame:
    """Keep genuine sales lines (positive qty, real demand categories)."""
    d = df[df[COL["category"]].isin(DEMAND_CATEGORIES)].copy()
    d = d[d[COL["qty"]] > 0]
    d["sku"] = d[COL["product"]].map(make_sku)
    d["loc_code"] = d[COL["location"]].map(make_location_code)
    d["ym"] = d[COL["date"]].dt.to_period("M")
    return d


def product_master(df: pd.DataFrame) -> pd.DataFrame:
    """One row per product with economics learned from the real data."""
    d = clean_demand(df)
    g = d.groupby(COL["product"])
    rows = []
    for name, sub in g:
        amount = sub[COL["amount"]].sum()
        qty = sub[COL["qty"]].sum()
        pp = sub[COL["pp"]].replace(0, np.nan).median()
        rate = sub[COL["rate"]].replace(0, np.nan).median()
        mrp = sub[COL["mrp"]].replace(0, np.nan).median()
        unit_cost = float(np.nan_to_num(pp, nan=0.0))
        unit_price = float(np.nan_to_num(rate if not np.isnan(rate) else mrp, nan=0.0))
        margin_unit = unit_price - unit_cost
        margin_pct = (margin_unit / unit_price * 100) if unit_price > 0 else 0.0
        # shelf life from batch purchase->expiry
        shelf = (sub[COL["expiry"]] - pd.to_datetime(sub[COL["purchase_date"]], errors="coerce")).dt.days
        shelf_days = int(np.nan_to_num(shelf.median(), nan=365))
        seg_mode = sub[COL["segment"]].mode()
        rows.append({
            "sku": make_sku(name),
            "description": str(name).strip(),
            "product_group": str(seg_mode.iloc[0]) if not seg_mode.empty else "General",
            "unit_cost": round(unit_cost, 2),
            "unit_price": round(unit_price, 2),
            "mrp": round(float(np.nan_to_num(mrp, nan=unit_price)), 2),
            "margin_pct": round(float(margin_pct), 2),
            "shelf_life_days": max(shelf_days, 30),
            "total_qty": float(qty),
            "total_revenue": round(float(amount), 2),
            "total_margin": round(float(margin_unit * qty), 2),
        })
    pm = pd.DataFrame(rows).drop_duplicates("sku").reset_index(drop=True)
    return pm


def monthly_panel(df: pd.DataFrame) -> pd.DataFrame:
    """Continuous month grid per product (gaps filled with 0 demand)."""
    d = clean_demand(df)
    g = (d.groupby(["sku", "ym"])[COL["qty"]].sum()
           .rename("qty").reset_index())
    full_idx = pd.period_range(g["ym"].min(), g["ym"].max(), freq="M")
    panels = []
    seg = d.groupby("sku")[COL["segment"]].agg(
        lambda s: s.mode().iloc[0] if not s.mode().empty else "General")
    for sku, sub in g.groupby("sku"):
        s = sub.set_index("ym")["qty"].reindex(full_idx, fill_value=0.0)
        first = sub["ym"].min()       # start at product's first real sale
        s = s[s.index >= first]
        p = pd.DataFrame({"sku": sku, "ym": s.index, "qty": s.values})
        p["segment"] = seg.get(sku, "General")
        panels.append(p)
    panel = pd.concat(panels, ignore_index=True)
    return panel


# ---- Feature engineering -----------------------------------
# seg_month_idx: a therapeutic-category (segment) x calendar-month
# seasonal index — e.g. Respiratory and Anti-Infective demand
# genuinely rises in cooler months in the real data. Validated as
# an ablation (ablation_seasonal.py) before being added here: it
# reduced overall backtest MAE by 1.5% (197.2 -> 194.2) and MAPE by
# 2.8% relative, with the largest gains in Respiratory (+4.7%),
# Neuro (+8.0%), Cardiac (+3.5%) and Gastro-Intestinal (+3.2%).
FEATURES = [
    "month_sin", "month_cos", "trend", "fy_index",
    "lag1", "lag2", "lag3", "lag12",
    "roll3", "roll6", "roll12", "prod_mean", "seg_code", "seg_month_idx",
]


def _fiscal_year_index(period) -> int:
    # Indian fiscal year starts in April
    y, m = period.year, period.month
    return y if m >= 4 else y - 1


def build_seasonal_index(panel: pd.DataFrame) -> dict:
    """Segment x month seasonal index (1.0 = that segment's average
    month). Computed from whichever rows are passed in — callers that
    need a leak-free backtest should pass only the training rows."""
    g = panel.groupby(["segment", "month_num"])["qty"].mean()
    seg_mean = panel.groupby("segment")["qty"].mean()
    idx = (g / seg_mean.reindex(g.index.get_level_values(0)).values)
    return idx.to_dict()  # {(segment, month_num): index}


def lookup_seasonal_index(idx_table: dict, segment: str, month_num: int) -> float:
    return float(idx_table.get((segment, month_num), 1.0))


def add_features(panel: pd.DataFrame, seg_codes: dict = None, idx_table: dict = None):
    panel = panel.sort_values(["sku", "ym"]).copy()
    if seg_codes is None:
        seg_codes = {s: i for i, s in enumerate(sorted(panel["segment"].unique()))}
    t0 = panel["ym"].min()
    panel["trend"] = panel["ym"].apply(lambda p: (p.year - t0.year) * 12 + (p.month - t0.month))
    panel["month_num"] = panel["ym"].apply(lambda p: p.month)
    panel["month_sin"] = np.sin(2 * np.pi * panel["month_num"] / 12)
    panel["month_cos"] = np.cos(2 * np.pi * panel["month_num"] / 12)
    fy = panel["ym"].apply(_fiscal_year_index)
    panel["fy_index"] = fy - fy.min()
    panel["seg_code"] = panel["segment"].map(seg_codes).fillna(-1).astype(int)
    if idx_table is None:
        idx_table = build_seasonal_index(panel)
    panel["seg_month_idx"] = panel.apply(
        lambda r: lookup_seasonal_index(idx_table, r["segment"], r["month_num"]), axis=1)

    # Per-product lag / rolling features, computed via grouped Series ops
    # rather than groupby(...).apply(...) — pandas 3.x drops the grouping
    # column ("sku") from the sub-frame an apply() callback receives, which
    # silently broke every downstream panel['sku'] reference.
    by_sku = panel.groupby("sku")["qty"]
    for L in (1, 2, 3, 12):
        panel[f"lag{L}"] = by_sku.shift(L)
    shifted = by_sku.shift(1)
    shifted_by_sku = shifted.groupby(panel["sku"])
    panel["roll3"] = shifted_by_sku.rolling(3, min_periods=1).mean().reset_index(level=0, drop=True)
    panel["roll6"] = shifted_by_sku.rolling(6, min_periods=1).mean().reset_index(level=0, drop=True)
    panel["roll12"] = shifted_by_sku.rolling(12, min_periods=1).mean().reset_index(level=0, drop=True)
    panel["prod_mean"] = shifted_by_sku.expanding(min_periods=1).mean().reset_index(level=0, drop=True)

    return panel, seg_codes, idx_table


def build_product_state(panel: pd.DataFrame) -> dict:
    """Last 13 months of demand per SKU (enough for lag12 + rolling)."""
    state = {}
    for sku, sub in panel.sort_values("ym").groupby("sku"):
        hist = sub.tail(13)
        state[sku] = {
            "segment": sub["segment"].iloc[-1],
            "history": [
                {"ym": str(p), "qty": float(q)}
                for p, q in zip(hist["ym"], hist["qty"])
            ],
        }
    return state


# ---- Recursive forecasting (serving) -----------------------
def _feature_row(history_qty, periods, target_period, seg_code, t0_year, t0_month,
                 fy_min, prod_mean_hint, segment=None, idx_table=None):
    """Build one feature dict for target_period given prior history."""
    q = pd.Series(history_qty, index=periods, dtype="float64")

    def lag(L):
        return float(q.get(target_period - L, np.nan))

    last = q.dropna()
    roll = lambda n: float(last.tail(n).mean()) if len(last) else np.nan
    trend = (target_period.year - t0_year) * 12 + (target_period.month - t0_month)
    fy = _fiscal_year_index(target_period)
    seg_month_idx = (lookup_seasonal_index(idx_table, segment, target_period.month)
                      if idx_table is not None and segment is not None else 1.0)
    return {
        "month_sin": np.sin(2 * np.pi * target_period.month / 12),
        "month_cos": np.cos(2 * np.pi * target_period.month / 12),
        "trend": trend,
        "fy_index": fy - fy_min,
        "lag1": lag(1), "lag2": lag(2), "lag3": lag(3), "lag12": lag(12),
        "roll3": roll(3), "roll6": roll(6), "roll12": roll(12),
        "prod_mean": float(last.mean()) if len(last) else prod_mean_hint,
        "seg_code": seg_code,
        "seg_month_idx": seg_month_idx,
    }


def forecast_sku(model, bundle, sku, horizon=3):
    """Recursive monthly forecast for one SKU. Returns list of dicts.

    The served value is a blend of the gradient-boosting model and the
    12-month moving average (the strong baseline) — this combination is
    what backtested best on the real data."""
    meta = bundle["meta"]
    seg_codes = bundle["seg_codes"]
    idx_table = bundle.get("seg_month_idx_table")
    log_target = meta.get("log_target", True)
    w = meta.get("blend_weight", 0.5)
    st = bundle["product_state"].get(sku)
    if st is None:
        return []
    periods = [pd.Period(h["ym"], freq="M") for h in st["history"]]
    qty = [h["qty"] for h in st["history"]]
    segment = st["segment"]
    seg_code = seg_codes.get(segment, -1)
    prod_mean_hint = float(np.mean(qty)) if qty else 0.0

    last_period = periods[-1] if periods else pd.Period(meta["last_month"], freq="M")
    target = last_period + 1
    out = []
    for _ in range(horizon):
        feats = _feature_row(qty, periods, target, seg_code,
                             meta["t0_year"], meta["t0_month"],
                             meta["fy_min"], prod_mean_hint,
                             segment=segment, idx_table=idx_table)
        X = pd.DataFrame([feats])[bundle["features"]].fillna(0.0)
        raw = float(model.predict(X)[0])
        ml = np.expm1(raw) if log_target else raw
        ml = max(0.0, ml)
        flat = float(np.mean(qty[-12:])) if qty else 0.0   # 12-mo moving avg
        yhat = max(0.0, w * ml + (1 - w) * flat)
        out.append({"ym": str(target), "forecast_qty": round(yhat, 2),
                     "ml_only": round(ml, 2), "moving_avg": round(flat, 2)})
        periods.append(target)
        qty.append(yhat)
        target = target + 1
    return out


def daily_demand_from_monthly(monthly_qty: float) -> float:
    return round(monthly_qty / 30.4375, 4)
