# =============================================================
# InvenIQ ML — predict.py
# PHASE 2 (connector, simple mode): load model.pkl and print a
# JSON forecast that PHP reads back via shell_exec.
#
#   python predict.py                  -> forecast for ALL products
#   python predict.py SKU1 SKU2        -> only those SKUs
#   python predict.py --horizon 6      -> change forecast horizon
#
# Output: JSON to stdout (nothing else), so PHP can json_decode it.
#
# This same module is imported by predict_service.py (Flask), so the
# two connectors return byte-for-byte identical predictions.
# =============================================================
import os
import sys
import json
import pickle

import ml_common as mc

_BUNDLE = None


def load_bundle(path: str = None):
    """Load model.pkl once (a 'trained brain' saved to disk)."""
    global _BUNDLE
    if _BUNDLE is None:
        with open(path or mc.MODEL_PATH, "rb") as f:
            _BUNDLE = pickle.load(f)        # <-- pickle.load: the .pkl model
    return _BUNDLE


def predict_skus(skus=None, horizon: int = 3, bundle=None) -> dict:
    """Return a dict keyed by SKU with demand forecast + economics."""
    b = bundle or load_bundle()
    model = b["model"]
    pmaster = b["product_master"]
    if not skus:
        skus = list(b["product_state"].keys())

    near = max(1, min(horizon, 3))           # near-term window used for reorder
    out = {}
    for sku in skus:
        fc = mc.forecast_sku(model, b, sku, horizon=horizon)
        if not fc:
            continue
        near_avg = sum(f["forecast_qty"] for f in fc[:near]) / near
        daily = mc.daily_demand_from_monthly(near_avg)
        pm = pmaster.get(sku, {})
        out[sku] = {
            "sku": sku,
            "description": pm.get("description", sku),
            "segment": pm.get("product_group", "General"),
            "daily_demand": daily,                       # replaces flat velocity
            "month_demand": round(near_avg, 2),
            "annual_demand": round(daily * 365, 1),
            "monthly_forecast": fc,
            "unit_cost": pm.get("unit_cost", 0),
            "unit_price": pm.get("unit_price", 0),
            "mrp": pm.get("mrp", 0),
            "margin_pct": pm.get("margin_pct", 0),
        }
    return out


_CACHE = {}


def seasonal_index_by_segment(b: dict) -> dict:
    """{segment: [idx for month 1..12]} — the seg_month_idx feature
    (Section: seasonal-disease-category ablation) reshaped for the UI,
    since JSON can't carry the trained (segment, month) tuple keys."""
    idx_table = b.get("seg_month_idx_table") or {}
    segments = sorted({seg for (seg, _m) in idx_table.keys()})
    out = {}
    for seg in segments:
        out[seg] = [round(float(idx_table.get((seg, m), 1.0)), 3) for m in range(1, 13)]
    return out


def payload(skus=None, horizon: int = 3) -> dict:
    b = load_bundle()
    # Forecasts depend only on the (static) model bundle, so memoise.
    key = ("ALL" if not skus else tuple(sorted(skus)), horizon)
    if key in _CACHE:
        return _CACHE[key]
    preds = predict_skus(skus, horizon, b)
    out = {
        "ok": True,
        "model": {
            "trained_at": b["meta"].get("trained_at"),
            "data_to": b["meta"].get("last_month"),
            "currency": b["meta"].get("currency", "₹"),
            "blend_weight": b["meta"].get("blend_weight"),
            "mae_improvement_vs_flat_avg_pct": b["meta"].get("mae_improvement_vs_flat_avg_pct"),
            "metrics": b["meta"].get("metrics", {}),
            "seasonal_index": seasonal_index_by_segment(b),
        },
        "count": len(preds),
        "predictions": preds,
    }
    _CACHE[key] = out
    return out


def main(argv):
    horizon = 3
    skus = []
    i = 0
    while i < len(argv):
        a = argv[i]
        if a in ("--horizon", "-h") and i + 1 < len(argv):
            horizon = int(argv[i + 1]); i += 2; continue
        skus.append(a); i += 1
    try:
        print(json.dumps(payload(skus or None, horizon)))
    except Exception as e:
        print(json.dumps({"ok": False, "error": str(e)}))
        sys.exit(1)


if __name__ == "__main__":
    main(sys.argv[1:])
