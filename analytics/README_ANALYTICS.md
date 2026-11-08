# InvenIQ — Sales Intelligence (strategic analytics)

This folder is the **strategic** side of InvenIQ: regional performance, product-category
identification and expansion decision support. It is separate from the operational side
(`ml/`, stock and procurement pages), and reuses only its SKU / location-code functions so
every result joins to the same `products` and `locations` tables.

```
sales workbook ──► data_prep.py ──► rq1_regional.py ──► output/  (CSV, JSON, Markdown for the thesis)
                   (clean, reconcile,                 └► MySQL sa_* tables ──► pages/regional-performance.php
                    features)
```

## Run it

1. Put `2.Primary Sales Data -  2020-21 to 2025-26.xlsx` in the app root (same place `ml/` expects it).
2. Install packages once: `pip install -r analytics/requirements.txt`
3. Start XAMPP MySQL with the `inveniq` database imported (`database.sql`).
4. Run:

```
cd analytics
python run_pipeline.py            # files + MySQL
python run_pipeline.py --no-db    # files only
```

Then open **Sales Intelligence → Regional Performance** and **→ Product Categories**
(`http://localhost/inveniq/pages/regional-performance.php`, `.../product-categories.php`;
Super Admin sees them by default).
The first run reads the 35 MB workbook (~20 s); later runs use `analytics/.cache/`.
RQ2 trains and tests 8 model variants over 3 years, so a full run takes about 4 minutes.

## Adding new sales data

Save each new export (same columns as the main workbook) in `sales_updates/` in the app root, then run the pipeline.
- One file per period: files that cover some of the same dates are refused, so no sale is counted twice.
- A missing Category column is taken from the bill number (SR = return, SAM = sample, D / P / NG = sale).
- Numbers written as text (1,01,200.00, or - for zero) are read; anything unreadable is counted in the quality report.
- These files have no summary sheet, so their totals can't be reconciled. The quality report lists each file's totals.
- **Part-years:** the regional, category and expansion analyses compare whole fiscal years, so they use complete years only.
  A part-year (FY2026-27 so far) goes to the Sales Action Plan, which compares the same months a year apart.
- The operational side (`ml/`) still reads only the main workbook.

## What each stage does

| Stage | File | Objective / RQ | Output |
|---|---|---|---|
| Data preparation | `data_prep.py` | Objective 1 | `data_quality_report.md/.json`, `reconciliation.csv`, `sales_clean.pkl`, `territory_year_features.csv` |
| Regional performance | `rq1_regional.py` | Objective 2, RQ1 | `rq1_summary.md`, `rq1_territory_scores.csv`, `rq1_territory_yearly.csv`, `rq1_hq_scores.csv`, `rq1_evaluation.json` |
| Product categories | `rq2_category.py` | Objective 3, RQ2 | `rq2_summary.md`, `rq2_product_classes.csv`, `rq2_class_history.csv`, `rq2_segment_profile.csv`, `rq2_test_predictions.csv`, `rq2_watch_list.csv`, `rq2_evaluation.json` |
| Expansion opportunities | `rq3_expansion.py` | Objective 4, RQ3 | `rq3_summary.md`, `rq3_expansion_cells.csv`, `rq3_win_back.csv`, `rq3_evaluation.json` |
| Back-test | `rq4_backtest.py`, `reference/decision_baseline.md` | Objective 5, RQ4 | `rq4_summary.md`, `rq4_picks.csv`, `rq4_evaluation.json` |
| Sales Action Plan | `sales_actions.py` | Extension for the client | `sales_actions_summary.md`, `sales_actions_evaluation.json` |
| Database load | `db_writer.py`, `sql/schema_analytics.sql` | Objective 6 | `sa_runs`, `sa_territory_scores`, `sa_territory_yearly`, `sa_hq_scores`, `sa_product_classes`, `sa_product_class_history`, `sa_segment_profile`, `sa_product_watch`, `sa_expansion_cells`, `sa_win_back` |
| Settings | `config.py` | — | every weight and threshold in one place |

### Data preparation
- Trims labels (e.g. 134 `RETURN ` lines), merges `Suppliments`/`Suppliment` into **Supplements**
  (13 segment labels → 12), tags each line as **sale**, **return** or **sample**.
- Net revenue and margin include all three line types, which is how the workbook's own
  summary (`Sheet1`) is built; the recomputed yearly totals reconcile to it exactly.
- Integrity checks: duplicates, `amount − cost = margin`, `qty + free = total qty`.
- Features per territory and per HQ for every fiscal year: net revenue, margin, gross sales,
  returns, active customers, products sold, customer retention.

### RQ1 — regional performance
- **Status** first: *Insufficient data* (under ₹1 lakh in total), *Lapsed* (no sales this year),
  *New* (no earlier year to measure growth from), otherwise *Active*.
- **Score**: five factors (revenue, margin %, growth, customer breadth, product breadth) turned into
  0–100 percentiles among active territories, averaged with equal weights.
- **Untapped potential** = either flag, so small-but-healthy territories are not called under-performers:
  - *Peer gap*: revenue below what territories with a similar reach earn, by more than the reach
    model's own leave-one-out error. Three model specifications are compared and the one with the
    lowest out-of-sample error is used; the hub (Coimbatore) is not used as a peer.
  - *Lost ground*: kept under half as much of its best-year revenue as the rest of the business kept
    over the same years (so company-wide decline alone does not flag anyone).
- **Segments**: Strong (score ≥ 65), Untapped potential, Steady, Weak (score < 35).
- **Evaluation** (in `rq1_evaluation.json`): the multi-factor flags vs the same number of
  lowest-revenue territories (all with sales, and active ones only as a stricter check), for every
  year that can be scored the same way; ranking stability
  between years; and sensitivity to the weights (2,000 random weightings).

### RQ2 — product categories
- **ABC-XYZ per product per fiscal year**, with the same cut-offs as InvenIQ's ABC/XYZ page:
  A/B/C = first 70% / next 20% / last 10% of net revenue; X/Y/Z = CV of the 12 monthly sale
  quantities below 0.5 / below 1.0 / above. Segments get a class too (revenue Pareto across
  segments; revenue-weighted CV of their products).
- **The classifier predicts next year's class** from this year's features. Training it to reproduce
  this year's class would be circular: the class is defined by the very numbers it would learn from.
- **Compared with two rule-of-thumb baselines:** "same class as last year", and the existing
  ABC/XYZ page's logic (last 90 days of the year), re-implemented in Python.
- **Models:** decision tree, logistic regression, random forest and gradient boosting, each in two
  forms, *direct* (predict the class) and *change* (start from the current class and predict a move
  up, none or down). Every form is tuned on the same criterion: macro-F1 of the implied next-year
  class, by stratified 5-fold cross-validation inside the training years, keeping each product's
  years in one fold so models are not rewarded for memorising products.
- **Evaluation:** expanding window. Each of the last 3 years is predicted by models trained only on
  earlier years. Reported: accuracy and macro-F1 (ABC, XYZ, full class), accuracy on products that
  changed class, and how often predicted changes came true. Against "same class as last year",
  significance is at product level, because most products appear in all three test years: a sign-flip
  permutation test and intervals from resampling whole products. Row-level McNemar is kept for comparison.
- **Outcome on this data:** no method beats "same class as last year" by a statistically
  significant margin. See `rq2_summary.md` for the numbers.
- **What the client sees:** the pages are written for a non-technical reader, so they show no model
  output or statistics. Since the classifier did not beat the simple rule, its next-year outlook goes
  only to the thesis files (`rq2_product_classes.csv`, `rq2_summary.md`). The Product Categories page
  shows a **watch list** of real changes instead (`rq2_watch_list.csv`, `sa_product_watch`): products
  that moved between sales groups, sales moves of 50% or more on products selling at least ₹1 lakh,
  and products that started or stopped selling. Demand-pattern (XYZ) moves are left out because many
  are noise near a cut-off.

### RQ3 — expansion opportunities
- Scores every active territory (except the hub) x category pair out of 100: room to grow 35, category
  value and stability 25, territory under-served 15, sales trend 15, margin 10. All points are in `config.py`.
- **Room to grow** compares a category's share of a territory's sales with its usual (median) share in the
  other territories, in rupees, capped at ₹2 lakh for full points. Gaps under ₹50,000 are not recommended.
- The hub is left out because RQ1 found it is not a peer of the others. Lapsed territories have no current
  sales to compare, so they get a separate win-back list with what they used to buy.
- **Action plan** for every recommendation (`action_plans()`, `sa_expansion_cells.action_plan`): the category's best sellers to offer,
  the territory's existing customers who don't buy it yet (those who bought it before come first), and how much of the gap they
  could cover at what a typical buyer spends elsewhere. Customer names go only to MySQL for the page, never to the thesis files.
- Checks in `rq3_evaluation.json`: how much the shortlist changes under random points and a different cap,
  and its overlap with simply backing the biggest existing sales. Whether the recommendations would have
  been right is the RQ4 back-test; `score_cells()` takes any year's tables for that.

### RQ4 — back-test
- The baseline, pass criteria and limits are in `reference/decision_baseline.md`, written before the first
  run. The baseline is a **stand-in rule** until the distributor's own account is obtained.
- At each decision point the whole method is re-run on data cut off at that year, then its top 15 is compared
  with what happened, against the baseline, a room-to-grow-only ranking and random picks.
- **Forward test:** once a part-year arrives, the last complete year's top 15 is judged on the new months against the same
  months a year earlier, under the same criteria. Its rules were written down before it first ran (addendum in the same file).
- **The client's own plan** (confidential, kept in the untracked `reference/client_private.json`) is reported at territory level.
  Its outcomes were already known, so it is descriptive, not a test.
- Results are thesis evidence only; the client pages do not show them.

### Sales Action Plan (extension, beyond the research questions)
- **Page:** Sales Intelligence -> Sales Action Plan. Who to contact, from three lists, with a button to mark each one done.
- **Big accounts buying less:** at least Rs 2 lakh in the earlier months and down 20% or more on the same six months last year.
- **Customers going quiet:** at least 4 orders, ordered in the last year, and past twice their usual gap between orders (minimum 30 days).
- **Expansion customers:** the top 15 action plans from RQ3.
- **Did it work?** Staff marks go to `sa_action_log`, which is never rebuilt. Each run compares sales after the mark with the same dates a year
  earlier, against customers nobody marked, once 30 days of later sales exist. Staff choose who to contact, so this is a guide, not proof.
- The contact lists use `data_prep.contactable()`: labels such as Cash Bill and Sample are removed and names differing only by case are joined.
  RQ1's customer counts still count those labels as customers (12 label spellings, and one real customer under two spellings, out of 617), a small limitation.
- `warning_check()` tests the two flags on past years; see `sales_actions_summary.md`. The customer lists hold names, so they stay in MySQL.
- The lists only help with current data, so load new sales regularly.

## Self-checks

Run from this folder: `python test_data_prep.py`, `python test_watch_list.py`, `python test_rq2_stats.py`, `python test_rq3.py`, `python test_rq4.py`, `python test_sales_actions.py`,
and, with MySQL running, `python test_db_writer.py` (a failed load must leave the last good results in place).

## Notes
- `reference/territory_coordinates.csv` holds **approximate town-centre coordinates** for the map
  and the distance-from-hub feature; check them before quoting distances.
- `output/sales_clean.pkl` contains customer names. Keep it local and anonymise names in anything
  you publish (proposal §8.8). `analytics/.htaccess` stops Apache serving this folder.
- `sa_*` tables are rebuilt on every run; `sa_runs` keeps every run's parameters and metrics.
