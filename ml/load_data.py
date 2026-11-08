# =============================================================
# InvenIQ — load_data.py
# Replace the demo data with YOUR REAL pharma sales dataset.
#
#   pip install -r requirements.txt
#   python load_data.py
#
# What it does (all keyed to the SAME SKUs the model uses):
#   1. products      <- real product master (cost / price / margin / shelf life)
#   2. locations     <- real branches from the dataset
#   3. stock_movements <- real sales history (daily, per SKU x branch)
#   4. stock_balances  <- current on-hand snapshot (from recent real demand)
#   5. suppliers / purchase_orders / goods_received / accounts_payable
#                       coherent procurement records keyed to the real SKUs
#                       (this operational data is not in the sales file, so it
#                        is generated to keep every page meaningful)
#
# Safe to re-run: it truncates the data tables first (users are left alone).
# =============================================================
import sys
import math
import random
import datetime as dt

import numpy as np
import pandas as pd

import ml_common as mc

try:
    import pymysql
except ImportError:
    sys.exit("PyMySQL not installed. Run:  pip install -r requirements.txt")

# ---- DB connection (matches includes/config.php) -----------
DB = dict(host="localhost", user="root", password="", database="inveniq",
          charset="utf8mb4", autocommit=False)

random.seed(42)
np.random.seed(42)
TODAY = dt.date.today()

SUPPLIERS = [
    ("SUP001", "MediSource Distributors",   5,  30),
    ("SUP002", "PharmaTrust Wholesale",     7,  45),
    ("SUP003", "Global Health Supplies",   10,  30),
    ("SUP004", "Crescent Pharma Logistics", 4,  60),
    ("SUP005", "Apex Medical Traders",      8,  30),
    ("SUP006", "BlueCross Pharma Supply",   6,  45),
    ("SUP007", "Unity Drug House",          9,  30),
    ("SUP008", "Meridian Life Sciences",    7,  21),
]


def connect():
    try:
        return pymysql.connect(**DB)
    except Exception as e:
        sys.exit(f"Cannot connect to MySQL ({e}). Is XAMPP MySQL running and "
                 f"the 'inveniq' database imported?")


def truncate(cur):
    cur.execute("SET FOREIGN_KEY_CHECKS=0")
    for t in ["accounts_payable", "goods_received", "purchase_orders",
              "stock_movements", "stock_balances", "products",
              "suppliers", "locations"]:
        cur.execute(f"TRUNCATE TABLE `{t}`")
    cur.execute("SET FOREIGN_KEY_CHECKS=1")


def cover_defaults(margin_pct, daily):
    """Profit/velocity-aware planning defaults per product."""
    fast = daily >= 20
    high_margin = margin_pct >= 50
    min_cover = 7 if fast else 14
    max_cover = 45 if fast else 75
    reorder_cover = 21 if fast else 35
    safety = 5 if fast else 10
    # protect availability of high-margin lines a little more
    if high_margin:
        safety += 3
    holding = 18 if high_margin else 22
    return min_cover, max_cover, safety, reorder_cover, holding


def main():
    print("=" * 60)
    print("InvenIQ — loading REAL dataset into MySQL")
    print("=" * 60)

    print("[1/7] Reading + cleaning the sales workbook ...")
    raw = mc.load_raw()
    demand = mc.clean_demand(raw)
    pm = mc.product_master(raw)

    # recent daily demand per SKU (last 180 days of available data)
    last_day = demand[mc.COL["date"]].max()
    recent = demand[demand[mc.COL["date"]] >= (last_day - pd.Timedelta(days=180))]
    daily_by_sku = (recent.groupby("sku")[mc.COL["qty"]].sum() / 180.0).to_dict()

    # main branch per SKU (where it sells most) + location master
    loc_names = (demand.groupby("loc_code")[mc.COL["location"]]
                 .agg(lambda s: s.mode().iloc[0]).to_dict())
    main_loc = (demand.groupby(["sku", "loc_code"])[mc.COL["qty"]].sum()
                .reset_index().sort_values(mc.COL["qty"], ascending=False)
                .drop_duplicates("sku").set_index("sku")["loc_code"].to_dict())
    hq_by_loc = (demand.groupby("loc_code")[mc.COL["hq"]]
                 .agg(lambda s: s.mode().iloc[0] if not s.mode().empty else "")).to_dict()

    conn = connect()
    cur = conn.cursor()
    print("[2/7] Clearing old demo data ...")
    truncate(cur)

    # ---- locations ----
    print(f"[3/7] Inserting {len(loc_names)} locations ...")
    cur.executemany(
        "INSERT INTO locations (location_code,name,type,region,status) VALUES (%s,%s,%s,%s,'active')",
        [(code, name[:100], "distribution_center", hq_by_loc.get(code, "")[:50])
         for code, name in loc_names.items()])

    # ---- suppliers ----
    print(f"[4/7] Inserting {len(SUPPLIERS)} suppliers ...")
    cur.executemany(
        "INSERT INTO suppliers (supplier_id,name,lead_time_days,payment_terms_days,"
        "contact_email,contact_phone,status) VALUES (%s,%s,%s,%s,%s,%s,'active')",
        [(sid, name, lt, pt, f"orders@{name.split()[0].lower()}.example",
          f"+91-{random.randint(70000,99999)}-{random.randint(10000,99999)}")
         for sid, name, lt, pt in SUPPLIERS])

    # ---- products ----  (assign a supplier deterministically per product)
    print(f"[5/7] Inserting {len(pm)} products ...")
    prod_rows, sku_supplier, sku_lead = [], {}, {}
    for i, r in pm.iterrows():
        sku = r["sku"]
        daily = daily_by_sku.get(sku, r["total_qty"] / max(1, 180))
        mn, mx, safe, rc, hold = cover_defaults(r["margin_pct"], daily)
        sup = SUPPLIERS[hash(sku) % len(SUPPLIERS)]
        sku_supplier[sku] = sup[0]
        sku_lead[sku] = sup[2]
        prod_rows.append((sku, r["description"][:200], r["product_group"][:100],
                          float(r["unit_cost"]), float(r["unit_price"]),
                          int(r["shelf_life_days"]), mn, mx, safe, rc, 50.0, hold, "active"))
    cur.executemany(
        "INSERT INTO products (sku,description,product_group,unit_cost,unit_price,"
        "shelf_life_days,min_cover_days,max_cover_days,safety_stock_days,reorder_cover_days,"
        "ordering_cost,holding_cost_pct,status) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
        prod_rows)

    # ---- stock_movements (real sales, daily per SKU x branch) ----
    print("[6/7] Inserting real sales as stock movements (daily) ...")
    mv = (demand.groupby(["sku", "loc_code", demand[mc.COL["date"]].dt.date])
          [mc.COL["qty"]].sum().reset_index())
    mv.columns = ["sku", "loc_code", "d", "qty"]
    mv_rows = [(r.sku, r.loc_code, r.d, "sale", float(r.qty), "HIST")
               for r in mv.itertuples(index=False)]
    for i in range(0, len(mv_rows), 5000):
        cur.executemany(
            "INSERT INTO stock_movements (sku,location_code,movement_date,movement_type,"
            "quantity,reference) VALUES (%s,%s,%s,%s,%s,%s)", mv_rows[i:i + 5000])
    print(f"        {len(mv_rows):,} movement rows")

    # ---- current stock balances + procurement (anchored to today) ----
    print("[7/7] Building current stock, POs, GRNs and payables ...")
    bal_rows, po_rows, grn_rows, ap_rows = [], [], [], []
    pidx = 0
    for _, r in pm.iterrows():
        sku = r["sku"]
        loc = main_loc.get(sku)
        if not loc:
            continue
        daily = daily_by_sku.get(sku, 0.0)
        cost = float(r["unit_cost"])
        shelf = int(r["shelf_life_days"])
        sup = sku_supplier[sku]
        lead = sku_lead[sku]

        # on-hand = recent daily demand x a random cover (creates low/healthy/excess mix)
        cover = random.choice([0, 8, 18, 30, 45, 70, 110])     # incl. 0 -> stockout
        on_hand = round(daily * cover)
        # split into 1-2 batches with realistic expiries
        if on_hand > 0:
            nb = 1 if on_hand < 200 else 2
            for b in range(nb):
                q = on_hand if nb == 1 else (on_hand // 2 if b == 0 else on_hand - on_hand // 2)
                # ~12% of batches expire soon -> drives expiry alerts
                if random.random() < 0.12:
                    exp = TODAY + dt.timedelta(days=random.randint(10, 85))
                else:
                    exp = TODAY + dt.timedelta(days=random.randint(120, max(150, shelf)))
                batch = f"B{abs(hash((sku, b))) % 900000 + 100000}"
                bal_rows.append((sku, loc, q, batch, exp, TODAY))

        # procurement: only for products that actually move
        if daily >= 3 and random.random() < 0.55:
            for _ in range(random.randint(1, 2)):
                pidx += 1
                qty = max(10, round(daily * random.choice([21, 30, 45])))
                odate = TODAY - dt.timedelta(days=random.randint(3, 70))
                edate = odate + dt.timedelta(days=lead)
                status = random.choices(
                    ["fully_received", "partially_received", "open", "cancelled"],
                    weights=[50, 20, 22, 8])[0]
                po = f"PO{pidx:05d}"
                po_rows.append((po, sup, sku, qty, odate, edate, cost, status, loc))
                if status in ("fully_received", "partially_received"):
                    rec = qty if status == "fully_received" else round(qty * random.uniform(0.4, 0.8))
                    rdate = edate + dt.timedelta(days=random.choice([-2, 0, 1, 3, 7, 12]))
                    if rdate > TODAY:
                        rdate = TODAY
                    exp = rdate + dt.timedelta(days=max(120, shelf))
                    grn = f"GRN{pidx:05d}"
                    batch = f"B{abs(hash((po, 'g'))) % 900000 + 100000}"
                    grn_rows.append((grn, po, sup, sku, rec, rdate, batch, exp, loc))
                    # payable for the received goods
                    amt = round(rec * cost * 1.12, 2)
                    pterms = next(s[3] for s in SUPPLIERS if s[0] == sup)
                    due = rdate + dt.timedelta(days=pterms)
                    paid = None
                    st = "unpaid"
                    if random.random() < 0.5:
                        paid = rdate + dt.timedelta(days=random.randint(5, pterms + 5))
                        st = "paid"
                    elif due < TODAY:
                        st = "overdue"
                    ap_rows.append((f"INV{pidx:05d}", sup, po, amt, rdate, due, paid, st))

    cur.executemany(
        "INSERT INTO stock_balances (sku,location_code,quantity_on_hand,batch_number,"
        "expiry_date,last_updated) VALUES (%s,%s,%s,%s,%s,%s)", bal_rows)
    cur.executemany(
        "INSERT INTO purchase_orders (po_number,supplier_id,sku,ordered_quantity,order_date,"
        "expected_delivery_date,unit_cost,status,location_code) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)",
        po_rows)
    cur.executemany(
        "INSERT INTO goods_received (grn_number,po_number,supplier_id,sku,received_quantity,"
        "receipt_date,batch_number,expiry_date,location_code) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)",
        grn_rows)
    cur.executemany(
        "INSERT INTO accounts_payable (invoice_number,supplier_id,po_number,amount,invoice_date,"
        "due_date,payment_date,status) VALUES (%s,%s,%s,%s,%s,%s,%s,%s)", ap_rows)

    conn.commit()
    print("\nDONE. Loaded into MySQL:")
    print(f"  products        {len(prod_rows):>7}")
    print(f"  locations       {len(loc_names):>7}")
    print(f"  stock_movements {len(mv_rows):>7}")
    print(f"  stock_balances  {len(bal_rows):>7}")
    print(f"  purchase_orders {len(po_rows):>7}")
    print(f"  goods_received  {len(grn_rows):>7}")
    print(f"  accounts_payable{len(ap_rows):>7}")
    cur.close(); conn.close()
    print("\nOpen the app:  http://localhost/inveniq/pages/dashboard.php")


if __name__ == "__main__":
    main()
