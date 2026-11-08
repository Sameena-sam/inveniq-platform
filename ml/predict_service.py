# =============================================================
# InvenIQ ML — predict_service.py
# PHASE 2 (connector, recommended mode): a tiny Flask service that
# loads model.pkl ONCE and answers prediction requests over HTTP.
# This is how ML models are normally "deployed" in production.
#
#   pip install -r requirements.txt
#   python predict_service.py            (serves on http://127.0.0.1:5000)
#
# Endpoints
#   GET  /health                 -> {"ok":true, ...model info}
#   GET  /predict                -> forecast for ALL products
#   GET  /predict?sku=ABC-1234   -> forecast for one/several (comma sep)
#   GET  /predict?horizon=6      -> change forecast horizon
#   POST /predict  {"skus":[...], "horizon":3}
#
# PHP (engine.php) calls this with cURL, exactly like the app already
# calls its own /api endpoints.
# =============================================================
import os
from flask import Flask, request, jsonify

import predict as P

app = Flask(__name__)
P.load_bundle()                      # load the model once at startup


@app.get("/health")
def health():
    b = P.load_bundle()
    return jsonify({
        "ok": True,
        "service": "inveniq-ml",
        "trained_at": b["meta"].get("trained_at"),
        "data_to": b["meta"].get("last_month"),
        "products": len(b["product_state"]),
        "mae_improvement_vs_flat_avg_pct": b["meta"].get("mae_improvement_vs_flat_avg_pct"),
    })


def _handle(skus, horizon):
    try:
        return jsonify(P.payload(skus or None, horizon))
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500


@app.get("/predict")
def predict_get():
    sku = request.args.get("sku", "").strip()
    skus = [s for s in sku.split(",") if s] if sku else None
    horizon = int(request.args.get("horizon", 3))
    return _handle(skus, horizon)


@app.post("/predict")
def predict_post():
    body = request.get_json(silent=True) or {}
    skus = body.get("skus") or None
    horizon = int(body.get("horizon", 3))
    return _handle(skus, horizon)


if __name__ == "__main__":
    port = int(os.environ.get("INVENIQ_ML_PORT", 5000))
    # threaded so PHP's repeated cURL calls don't block each other
    app.run(host="127.0.0.1", port=port, threaded=True)
