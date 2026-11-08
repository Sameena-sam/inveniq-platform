# =============================================================
# InvenIQ ML — train_model.py
# PHASE 1: train ONCE on the real sales history and save model.pkl
#
#   python train_model.py
#
# Produces:
#   model.pkl              -> the trained model + everything the
#                            connector needs to serve predictions
#   model_report.json     -> accuracy metrics (model vs baselines)
#
# Re-run this only when you want to retrain on newer data.
# =============================================================
import os
import json
import pickle
import datetime as dt

import numpy as np
import pandas as pd
from sklearn.ensemble import HistGradientBoostingRegressor
from sklearn.metrics import mean_absolute_error

import ml_common as mc


def mape(y_true, y_pred):
    y_true = np.asarray(y_true, dtype=float)
    y_pred = np.asarray(y_pred, dtype=float)
    mask = y_true > 0
    if mask.sum() == 0:
        return float("nan")
    return float(np.mean(np.abs((y_true[mask] - y_pred[mask]) / y_true[mask])) * 100)


def smape(y_true, y_pred):
    y_true = np.asarray(y_true, dtype=float)
    y_pred = np.asarray(y_pred, dtype=float)
    denom = (np.abs(y_true) + np.abs(y_pred))
    mask = denom > 0
    return float(np.mean(2 * np.abs(y_pred[mask] - y_true[mask]) / denom[mask]) * 100)


def main():
    print("=" * 60)
    print("InvenIQ — training demand model on REAL sales data")
    print("=" * 60)
    print(f"[1/6] Reading dataset: {mc.DATASET_PATH}")
    raw = mc.load_raw()
    print(f"      {len(raw):,} rows | {raw[mc.COL['date']].min().date()} -> {raw[mc.COL['date']].max().date()}")

    print("[2/6] Building monthly demand panel ...")
    panel = mc.monthly_panel(raw)
    panel, seg_codes, _ = mc.add_features(panel)
    print(f"      {panel['sku'].nunique()} products | {len(panel):,} product-months | {len(seg_codes)} segments")

    # Supervised rows: need at least lag1 present
    data = panel.dropna(subset=["lag1"]).copy()
    data[mc.FEATURES] = data[mc.FEATURES].fillna(0.0)

    LOG_TARGET   = True
    BLEND_WEIGHT = 0.5   # weight on the ML model; (1-w) on the 12-mo moving average

    def fit_model(tr):
        m = HistGradientBoostingRegressor(
            max_iter=400, learning_rate=0.06, max_depth=6,
            l2_regularization=2.0, min_samples_leaf=15, random_state=42)
        y = np.log1p(tr["qty"]) if LOG_TARGET else tr["qty"]
        m.fit(tr[mc.FEATURES], y)
        return m

    def predict_blend(m, te):
        raw = m.predict(te[mc.FEATURES])
        ml = np.clip(np.expm1(raw) if LOG_TARGET else raw, 0, None)
        flat = te["roll12"].fillna(te["prod_mean"]).fillna(0).values
        return np.clip(BLEND_WEIGHT * ml + (1 - BLEND_WEIGHT) * flat, 0, None), ml, flat

    # -------- Rolling-origin backtest over the last 6 months --------
    # seg_month_idx (segment x calendar-month seasonality, added after the
    # ablation in ablation_seasonal.py) is recomputed from the TRAINING
    # rows only at each split, so the reported backtest metrics stay
    # leak-free exactly as they were before this feature was added.
    months = sorted(data["ym"].unique())
    print(f"[3/6] Rolling-origin backtest over last 6 months ...")
    A, P, ML, F = [], [], [], []
    for split in months[-6:]:
        tr = data[data["ym"] < split].copy()
        te = data[data["ym"] == split].copy()
        if len(te) == 0:
            continue
        split_idx_table = mc.build_seasonal_index(tr)
        tr["seg_month_idx"] = tr.apply(
            lambda r: mc.lookup_seasonal_index(split_idx_table, r["segment"], r["month_num"]), axis=1)
        te["seg_month_idx"] = te.apply(
            lambda r: mc.lookup_seasonal_index(split_idx_table, r["segment"], r["month_num"]), axis=1)
        m = fit_model(tr)
        p, ml, flat = predict_blend(m, te)
        A.append(te["qty"].values); P.append(p); ML.append(ml); F.append(flat)
    A = np.concatenate(A); P = np.concatenate(P); ML = np.concatenate(ML); F = np.concatenate(F)
    base_ly = np.concatenate([data[data["ym"] == s]["lag12"].fillna(
        data[data["ym"] == s]["lag1"]).fillna(0).values for s in months[-6:]])

    report = {
        "trained_at": dt.datetime.now().isoformat(timespec="seconds"),
        "rows": int(len(raw)),
        "products": int(panel["sku"].nunique()),
        "data_from": str(panel["ym"].min()),
        "data_to": str(panel["ym"].max()),
        "backtest_window": f"{months[-6]} .. {months[-1]} (6 months, rolling origin)",
        "metrics": {
            "ml_blend":          {"MAE": round(mean_absolute_error(A, P), 1),
                                   "MAPE": round(mape(A, P), 1), "sMAPE": round(smape(A, P), 1)},
            "ml_only":           {"MAE": round(mean_absolute_error(A, ML), 1),
                                   "MAPE": round(mape(A, ML), 1), "sMAPE": round(smape(A, ML), 1)},
            "baseline_flat_avg": {"MAE": round(mean_absolute_error(A, F), 1),
                                   "MAPE": round(mape(A, F), 1), "sMAPE": round(smape(A, F), 1)},
            "baseline_last_year":{"MAE": round(mean_absolute_error(A, base_ly), 1),
                                   "MAPE": round(mape(A, base_ly), 1), "sMAPE": round(smape(A, base_ly), 1)},
        },
    }
    imp = mean_absolute_error(A, F)
    mlm = mean_absolute_error(A, P)
    report["mae_improvement_vs_flat_avg_pct"] = round((imp - mlm) / imp * 100, 1) if imp else 0.0
    print(f"      blended MAE={report['metrics']['ml_blend']['MAE']} MAPE={report['metrics']['ml_blend']['MAPE']} "
          f"vs flat-avg MAE={report['metrics']['baseline_flat_avg']['MAE']} "
          f"({report['mae_improvement_vs_flat_avg_pct']}% better)")

    # -------- Final model on ALL data --------
    print("[4/6] Fitting final model on full history ...")
    model = fit_model(data)
    # feature importance (permutation) for transparency
    try:
        from sklearn.inspection import permutation_importance
        pi = permutation_importance(model, data[mc.FEATURES],
                                    (np.log1p(data["qty"]) if LOG_TARGET else data["qty"]),
                                    n_repeats=3, random_state=42, n_jobs=1)
        report["feature_importance"] = dict(sorted(
            {f: round(float(v), 4) for f, v in zip(mc.FEATURES, pi.importances_mean)}.items(),
            key=lambda x: -x[1]))
    except Exception:
        pass

    # -------- Product economics + serving state --------
    print("[5/6] Building product economics + serving state ...")
    pm = mc.product_master(raw)
    product_state = mc.build_product_state(panel)
    t0 = panel["ym"].min()
    fy_min = panel["ym"].apply(mc._fiscal_year_index).min()
    # Seasonal index for live serving is computed from the FULL history
    # (there is no future to leak from once this is the deployed model).
    full_idx_table = mc.build_seasonal_index(data)

    bundle = {
        "model": model,
        "features": mc.FEATURES,
        "seg_codes": seg_codes,
        "seg_month_idx_table": full_idx_table,
        "product_state": product_state,
        "product_master": pm.set_index("sku").to_dict("index"),
        "meta": {
            "trained_at": report["trained_at"],
            "t0_year": int(t0.year), "t0_month": int(t0.month),
            "fy_min": int(fy_min),
            "last_month": str(panel["ym"].max()),
            "currency": "₹",
            "log_target": LOG_TARGET,
            "blend_weight": BLEND_WEIGHT,
            "metrics": report["metrics"],
            "mae_improvement_vs_flat_avg_pct": report["mae_improvement_vs_flat_avg_pct"],
        },
    }

    print(f"[6/6] Saving {mc.MODEL_PATH} ...")
    with open(mc.MODEL_PATH, "wb") as f:
        pickle.dump(bundle, f)            # <-- the .pkl the PHP app loads
    with open(os.path.join(mc.ML_DIR, "model_report.json"), "w") as f:
        json.dump(report, f, indent=2)

    size_kb = os.path.getsize(mc.MODEL_PATH) / 1024
    print(f"\nDONE. model.pkl = {size_kb:.0f} KB, {len(product_state)} products served.")
    print("Next: start the connector  ->  python predict_service.py")


if __name__ == "__main__":
    main()
