# InvenIQ — Machine-Learning Demand Forecasting

This adds a **real ML model** to InvenIQ. Instead of forecasting demand with a flat
90-day average, the app now loads a model that **learned demand patterns from your
real sales history** (`2.Primary Sales Data - 2020-21 to 2025-26.xlsx`) and uses it
to drive reorder points, stock health, KPIs and a new **Demand Forecast** page.

It works in the two phases your brief describes.

```
PHASE 1 — TRAIN ONCE (Python)
   real sales data  ──►  train_model.py  ──►  model.pkl   (the "trained brain")

PHASE 2 — USE IT LIVE (every forecast)
   model.pkl ──► connector ──► engine.php ──► dashboard
                 (Flask  OR  python predict.py)
```

---

## What the model does

* **Model:** gradient-boosting regressor (`HistGradientBoostingRegressor`) that learns
  per-product monthly demand from trend, month-of-year seasonality, fiscal-year effects,
  recent lags and moving averages, and therapeutic segment.
* **Served forecast:** a blend of the model and the 12-month moving average — the
  combination that backtested best on your data.
* **Accuracy (rolling 6-month backtest):** about **7% lower MAE and ~18% lower MAPE**
  than the flat-average method the app used before. Exact numbers are written to
  `model_report.json` after training.
* **Profit-aware:** every product carries its real cost/price/margin, so reorder
  suggestions are prioritised by **profit at risk**, order quantities lean up on
  high-margin movers and down on thin-margin lines.

---

## One-time setup

### 0. Install Python packages
```
cd C:\xampp\htdocs\inveniq\ml
pip install -r requirements.txt
```
> Need Python 3.9+. If `python` isn't on your PATH, use the full path and update
> `ML_PYTHON` in `includes/config.php` (e.g. `py` on Windows).

### 1. Train the model  → creates `model.pkl`
```
python train_model.py
```
Reads the real workbook, trains, prints accuracy vs the old method, and saves
`model.pkl` + `model_report.json`. Re-run this only when you want to retrain on
newer data. (First run takes ~40s reading the 35 MB Excel; later runs are cached.)

### 2. Load the real dataset into MySQL
Start XAMPP (Apache + MySQL) and make sure the `inveniq` database is imported
(`database.sql`), then:
```
python load_data.py
```
This **replaces the demo data** with your real data, all keyed to the same SKUs the
model uses:
* `products`, `locations`, `stock_movements` ← straight from your sales file
* `stock_balances` ← a current on-hand snapshot derived from recent real demand
* `suppliers`, `purchase_orders`, `goods_received`, `accounts_payable` ← coherent
  operational records generated around the real SKUs (this data isn't in the sales
  file, so it's synthesised to keep every page meaningful)

`load_data.py` is safe to re-run; it truncates the data tables first (your login
users are left untouched).

---

## Running the connector (Phase 2)

You have **both** options the brief mentions. The app tries Flask first, then falls
back to the script automatically.

### Option A — Flask service (recommended, the "deployed ML" way)
```
python predict_service.py
```
Serves on `http://127.0.0.1:5000`. The PHP app calls it with cURL, exactly like it
calls its own API. Endpoints: `/health`, `/predict`, `/predict?sku=ABC-1234`.

### Option B — script via shell_exec (simplest, no server)
Nothing to start. If Flask isn't running, `includes/ml_client.php` runs
`python predict.py`, which loads the pickle and prints the forecast as JSON.
Slower per call, but zero setup.

Open the app: **http://localhost/inveniq/pages/dashboard.php**
The new page is **AI & Analytics → Demand Forecast (ML)**. The banner there shows
whether the model is live (via Flask or shell) and its accuracy.

---

## How it's wired into the PHP app

| File | Role |
|------|------|
| `ml/train_model.py` | Phase 1 — trains and saves `model.pkl` |
| `ml/predict_service.py` | Phase 2 — Flask service that loads `model.pkl` once |
| `ml/predict.py` | Phase 2 — shell_exec connector (prints JSON) |
| `ml/load_data.py` | Imports your real dataset into MySQL |
| `ml/ml_common.py` | Shared data/feature/forecast logic |
| `includes/ml_client.php` | PHP connector — cURL→Flask, shell_exec fallback, cached |
| `includes/engine.php` | `getForecastVelocity()` feeds reorder/stock-health/KPIs; profit-aware reorder |
| `includes/config.php` | `ML_FLASK_URL`, `ML_PYTHON`, `ML_PREDICT_SCRIPT`, `ML_CACHE_TTL` |
| `api/index.php` | new actions `ml_status`, `demand_forecast` |
| `pages/demand-forecast.php` | the ML forecast dashboard |

Predictions are cached (default 15 min, `ML_CACHE_TTL`) so pages stay fast.

---

## Troubleshooting

* **Banner says "Model offline":** train the model (step 1) and/or start
  `predict_service.py`. The app still works via the rule-based fallback.
* **`python` not found from PHP:** set `ML_PYTHON` in `includes/config.php` to your
  Python path (e.g. `C:\\Python311\\python.exe`).
* **Flask port busy:** set `INVENIQ_ML_PORT` env var, and update `ML_FLASK_URL`.
* **Retrain on new data:** drop a newer workbook in the app root (same column
  layout), delete `ml/.sales_cache.pkl`, then re-run `train_model.py`.
* **`.sales_cache.pkl`** is just a fast-load cache of the workbook; safe to delete.
