# =============================================================
# InvenIQ Sales Intelligence — data preparation (Objective 1)
#
#   1. read the workbook (Data Sheet1 + the Sheet1 summary pivot)
#   2. clean and standardise labels, tag every line's type
#   3. reconcile the cleaned data against the workbook's own totals
#   4. engineer per-territory (and per-HQ) fiscal-year features
#
# SKU and location codes come from ml/ml_common.py, so every result
# joins to the products / locations tables the operational side loads.
# =============================================================
import os
import re
import sys
import glob
import json
import math
import hashlib

import numpy as np
import pandas as pd

import config as cfg

sys.path.insert(0, cfg.ML_DIR)
import ml_common as mc   # noqa: E402  (shared SKU / location-code logic)

CACHE_DIR = os.path.join(cfg.AN_DIR, ".cache")

# Raw workbook column -> clean column name used by every module
RENAME = {
    "Sale Bill Number": "bill_no", "Year": "fy_label", "Month": "month", "Qtr": "qtr",
    "Bill Date": "bill_date", "Category": "category", "Customer Name": "customer",
    "Product Name": "product", "Segmant": "segment", "Product Batch No": "batch",
    "Purchase Date": "purchase_date", "Expiry Date": "expiry_date",
    "Sale Qty": "qty", "Free Qty": "free_qty", "Purchase Price": "purchase_price",
    "Rate": "rate", "MRP": "mrp", "Sales Amount": "amount", "Tax %": "tax",
    "HQ": "hq", "Location": "location", "Total QTY": "total_qty",
    "PP VALUE": "pp_value", "Margin Value": "margin_value", "Margin %": "margin_ratio",
    "source": "source",     # which file the line came from (added on reading)
}
TEXT_COLS = ["category", "customer", "product", "segment", "hq", "location"]
NUM_COLS = ["qty", "free_qty", "purchase_price", "rate", "mrp", "amount",
            "total_qty", "pp_value", "margin_value"]

# Workbook summary column -> clean column it totals
SUMMARY_COLS = {
    "Sum of Quantity": "qty", "Sum of Free Quantity": "free_qty",
    "Sum of Sales Amount": "amount", "Sum of PP VALUE": "pp_value",
    "Sum of Margin Value": "margin_value",
}


# ---- Reading -----------------------------------------------
def _cached(path: str, read):
    """Excel is slow: keep a pickle per file, rebuilt whenever the file is newer than it."""
    os.makedirs(CACHE_DIR, exist_ok=True)
    key = hashlib.md5(os.path.abspath(path).encode("utf-8")).hexdigest()[:12]   # --data files don't share a cache
    cache = os.path.join(CACHE_DIR, f"workbook_{key}.pkl")
    if os.path.exists(cache) and os.path.getmtime(cache) >= os.path.getmtime(path):
        return pd.read_pickle(cache)
    out = read()
    pd.to_pickle(out, cache)
    return out


def read_workbook(path: str = None):
    """Raw data sheet + summary sheet of the main workbook."""
    path = path or mc.DATASET_PATH
    if not os.path.exists(path):
        sys.exit(f"Sales workbook not found: {path}\n"
                 f"Put '{mc.DATASET_NAME}' in the app root or pass --data.")

    def read():
        sheets = pd.read_excel(path, sheet_name=[mc.DATA_SHEET, cfg.SUMMARY_SHEET], header=None)
        raw = sheets[mc.DATA_SHEET]
        raw.columns = [str(c).strip() for c in raw.iloc[0]]
        return raw.iloc[1:].reset_index(drop=True), sheets[cfg.SUMMARY_SHEET]
    raw, summary = _cached(path, read)
    return raw.assign(source=os.path.basename(path)), summary


def category_from_bill(bills: pd.Series) -> pd.Series:
    """Line category from the bill number's letters (26-27SR01... -> RETURN), for exports without
    a Category column. The letters match the Category column in the main workbook."""
    letters = bills.astype(str).str.strip().str.extract(r"^(?:\d\d-\d\d)?([A-Za-z]+)", expand=False).str.upper()
    return letters.map(cfg.BILL_PREFIX_CATEGORY).fillna("OTHER")      # "other" lines are counted in the checks


def read_updates(folder: str = cfg.UPDATES_DIR) -> list:
    """Later exports saved in sales_updates/: the first sheet, in the main workbook's column layout."""
    out = []
    for path in sorted(glob.glob(os.path.join(folder, "*.xlsx"))):
        if os.path.basename(path).startswith("~$"):        # Excel's lock file while the file is open
            continue
        raw = _cached(path, lambda: pd.read_excel(path, sheet_name=0))
        raw.columns = [str(c).strip() for c in raw.columns]
        if "Category" not in raw.columns:
            raw = raw.assign(Category=category_from_bill(raw["Sale Bill Number"]))
        out.append(raw.assign(source=os.path.basename(path)))
    return out


def overlapping_sources(df: pd.DataFrame) -> list:
    """Pairs of files that cover some of the same dates, whose sales would be counted twice."""
    spans = df.groupby("source")["bill_date"].agg(["min", "max"]).sort_values("min")
    rows = list(spans.itertuples())
    return [(a.Index, b.Index) for i, a in enumerate(rows) for b in rows[i + 1:] if b.min <= a.max]


def complete_fys(df: pd.DataFrame) -> list:
    """Fiscal years the data reaches the March of. A part-year would look like a collapse next to full years."""
    last = df["bill_date"].max()
    return sorted(fy for fy in df["fy"].unique() if last >= pd.Timestamp(f"{int(fy[:4]) + 1}-03-01"))


def parse_summary(sheet: pd.DataFrame) -> pd.DataFrame:
    """The workbook's pivot: one row per fiscal year with its totals."""
    s = sheet.dropna(how="all").dropna(axis=1, how="all")
    header_row = s.index[s.iloc[:, 0].astype(str).str.strip() == "Row Labels"][0]
    s.columns = [str(c).strip() for c in s.loc[header_row]]
    s = s.loc[header_row + 1:]
    s = s[s["Row Labels"].astype(str).str.match(r"^\d{4}-\d{2}$")]
    s = s.rename(columns={"Row Labels": "fy", **SUMMARY_COLS})
    s = s[["fy"] + list(SUMMARY_COLS.values())]
    for c in SUMMARY_COLS.values():
        s[c] = pd.to_numeric(s[c])
    return s.set_index("fy")


# ---- Cleaning ----------------------------------------------
def fiscal_year(d: pd.Series) -> pd.Series:
    """Indian fiscal year (April-March) label, e.g. 2020-04-01 -> '2020-21'."""
    start = np.where(d.dt.month >= 4, d.dt.year, d.dt.year - 1)
    return pd.Series([f"{y}-{(y + 1) % 100:02d}" for y in start], index=d.index)


def territory_name(location: str) -> str:
    """'COIMBATORE - HQ' -> 'Coimbatore', 'NAGERCOIL-HQ' -> 'Nagercoil'."""
    return re.sub(r"\s*-\s*HQ$", "", str(location).strip(), flags=re.I).title()


def clean_sales(raw: pd.DataFrame):
    """Return (clean line-level data, data-quality report)."""
    rep = {"raw_rows": int(len(raw)), "raw_columns": int(raw.shape[1]), "steps": []}
    df = raw.rename(columns=RENAME)[list(RENAME.values())].copy()

    empty = df.isna().all(axis=1)
    df = df[~empty]
    rep["steps"].append({"step": "Drop fully empty rows", "rows_affected": int(empty.sum())})

    df["bill_date"] = pd.to_datetime(df["bill_date"], errors="coerce")
    bad_date = df["bill_date"].isna()
    df = df[~bad_date]
    rep["steps"].append({"step": "Drop rows without a valid bill date",
                         "rows_affected": int(bad_date.sum())})

    for c in ["purchase_date", "expiry_date"]:
        df[c] = pd.to_datetime(df[c], errors="coerce")
    text_fixed = unreadable = 0
    for c in NUM_COLS:
        plain = pd.to_numeric(df[c], errors="coerce")
        # Indian digit grouping (1,01,200.00) and Excel's "-" for zero come through as text
        tidy = df[c].astype(str).str.replace(",", "").str.strip().replace({"-": "0", "": "0"})
        val = plain.fillna(pd.to_numeric(tidy.where(plain.isna()), errors="coerce"))
        text_fixed += int((plain.isna() & val.notna() & df[c].notna()).sum())
        unreadable += int((val.isna() & df[c].notna()).sum())
        df[c] = val.fillna(0.0)
    rep["steps"].append({"step": "Read numbers written as text (1,01,200.00 or -)", "rows_affected": text_fixed})

    # Trim whitespace on every label; record which ones actually changed
    for c in TEXT_COLS:
        blank = df[c].isna()
        if blank.any():
            rep["steps"].append({"step": f"Label blank {c} as Unknown", "rows_affected": int(blank.sum())})
        before = df[c].fillna("Unknown").astype(str)
        df[c] = before.str.strip().str.replace(r"\s+", " ", regex=True)
        changed = before != df[c]
        if changed.any():
            rep["steps"].append({"step": f"Trim whitespace in {c}",
                                 "rows_affected": int(changed.sum()),
                                 "examples": sorted({repr(v) for v in before[changed].unique()})[:5]})
    df["category"] = df["category"].str.upper()
    hq_before = df["hq"]
    df["hq"] = df["hq"].str.title()          # later exports write SALEM where the workbook has Salem
    rep["steps"].append({"step": "Standardise HQ capitalisation", "rows_affected": int((hq_before != df["hq"]).sum())})

    seg_before = df["segment"].copy()
    df["segment"] = df["segment"].replace(cfg.SEGMENT_MAP)
    rep["steps"].append({"step": "Merge duplicate segment labels",
                         "mapping": cfg.SEGMENT_MAP,
                         "rows_affected": int((seg_before != df["segment"]).sum()),
                         "segments_before": int(seg_before.nunique()),
                         "segments_after": int(df["segment"].nunique())})

    df["line_type"] = np.select(
        [df["category"].isin(cfg.SALE_CATEGORIES),
         df["category"].isin(cfg.RETURN_CATEGORIES),
         df["category"].isin(cfg.SAMPLE_CATEGORIES)],
        ["sale", "return", "sample"], default="other")
    lt = df.groupby("line_type").agg(lines=("amount", "size"), qty=("qty", "sum"),
                                     amount=("amount", "sum"), margin=("margin_value", "sum"))
    rep["line_types"] = {k: {kk: round(float(vv), 2) for kk, vv in v.items()}
                         for k, v in lt.to_dict(orient="index").items()}

    # Fiscal year from the bill date (checked against the workbook's own label)
    df["fy"] = fiscal_year(df["bill_date"])
    fy_mismatch = df["fy"] != df["fy_label"].astype(str).str.strip()
    rep["steps"].append({"step": "Check fiscal-year label against bill date",
                         "rows_affected": int(fy_mismatch.sum())})

    # Keys shared with the operational side, plus a readable territory name
    df["sku"] = df["product"].map(mc.make_sku)
    df["loc_code"] = df["location"].map(mc.make_location_code)
    df["territory"] = df["location"].map(territory_name)

    # Integrity checks (reported, not "fixed": the summary sheet includes these rows)
    dup = df.duplicated(subset=[c for c in RENAME.values() if c in df.columns], keep="first")
    margin_err = (df["amount"] - df["pp_value"] - df["margin_value"]).abs()
    qty_err = (df["qty"] + df["free_qty"] - df["total_qty"]).abs()
    rep["checks"] = {
        "exact_duplicate_lines": int(dup.sum()),
        "margin_identity_breaks": int((margin_err > 1).sum()),      # amount - cost != margin
        "total_qty_identity_breaks": int((qty_err > 0).sum()),      # qty + free != total
        "negative_amount_outside_returns": int(((df["amount"] < 0) & (df["line_type"] != "return")).sum()),
        "zero_amount_sale_lines": int(((df["amount"] == 0) & (df["line_type"] == "sale")).sum()),
        "other_line_types": int((df["line_type"] == "other").sum()),
        "unreadable_numbers_set_to_zero": unreadable,
    }
    rep["clean_rows"] = int(len(df))
    rep["date_range"] = [str(df["bill_date"].min().date()), str(df["bill_date"].max().date())]
    rep["entities"] = {
        "products": int(df["product"].nunique()), "customers": int(df["customer"].nunique()),
        "locations": int(df["location"].nunique()), "hqs": int(df["hq"].nunique()),
        "segments": int(df["segment"].nunique()), "fiscal_years": int(df["fy"].nunique()),
        "customers_in_more_than_one_location":
            int((df.groupby("customer")["location"].nunique() > 1).sum()),
    }
    return df.reset_index(drop=True), rep


def reconcile(df: pd.DataFrame, summary: pd.DataFrame) -> pd.DataFrame:
    """Recomputed yearly totals vs the workbook's own summary pivot."""
    mine = df.groupby("fy")[list(SUMMARY_COLS.values())].sum()
    rows = []
    for fy in summary.index:
        for col in SUMMARY_COLS.values():
            book = float(summary.loc[fy, col])
            calc = float(mine.loc[fy, col]) if fy in mine.index else 0.0
            rows.append({"fy": fy, "measure": col, "workbook_summary": round(book, 2),
                         "recomputed": round(calc, 2), "difference": round(calc - book, 2),
                         "difference_pct": round((calc - book) / book * 100, 6) if book else None})
    return pd.DataFrame(rows)


# ---- Feature engineering -----------------------------------
def unit_year_features(df: pd.DataFrame, unit: str) -> pd.DataFrame:
    """One row per (unit, fiscal year) for every unit x every year (zeros
    where a unit did not trade). `unit` is 'loc_code' or 'hq'."""
    fys = sorted(df["fy"].unique())
    g = df.groupby([unit, "fy"])
    base = g.agg(net_revenue=("amount", "sum"), net_margin=("margin_value", "sum"),
                 net_qty=("qty", "sum"), lines=("amount", "size"),
                 first_sale=("bill_date", "min"), last_sale=("bill_date", "max"))
    base["gross_sales"] = df[df["line_type"] == "sale"].groupby([unit, "fy"])["amount"].sum()
    base["returns_amount"] = df[df["line_type"] == "return"].groupby([unit, "fy"])["amount"].sum()

    # Breadth: customers / products with positive net sales in that year
    cust = df.groupby([unit, "fy", "customer"])["amount"].sum()
    cust = cust[cust > 0].reset_index()
    prod = df.groupby([unit, "fy", "product"])["amount"].sum()
    prod = prod[prod > 0].reset_index()
    base["customers"] = cust.groupby([unit, "fy"]).size()
    base["products"] = prod.groupby([unit, "fy"]).size()

    # Retention: share of last year's active customers who bought again
    sets = cust.groupby([unit, "fy"])["customer"].agg(set).to_dict()
    retained, prev = {}, {}
    for (u, fy) in base.index:
        i = fys.index(fy)
        if i == 0:
            continue
        last = sets.get((u, fys[i - 1]), set())
        prev[(u, fy)] = len(last)
        retained[(u, fy)] = len(last & sets.get((u, fy), set()))
    base["prev_customers"] = pd.Series(prev)
    base["retained_customers"] = pd.Series(retained)

    units = sorted(df[unit].unique())
    full = pd.MultiIndex.from_product([units, fys], names=[unit, "fy"])
    out = base.reindex(full)
    zero_cols = ["net_revenue", "net_margin", "net_qty", "lines", "gross_sales",
                 "returns_amount", "customers", "products", "prev_customers", "retained_customers"]
    out[zero_cols] = out[zero_cols].fillna(0)
    out = out.reset_index()
    out["fy_idx"] = out["fy"].map({fy: i for i, fy in enumerate(fys)})
    return out


def haversine_km(lat1, lon1, lat2, lon2) -> float:
    r = 6371.0
    p1, p2 = math.radians(lat1), math.radians(lat2)
    dp, dl = p2 - p1, math.radians(lon2 - lon1)
    a = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    return 2 * r * math.asin(math.sqrt(a))


def contactable(df: pd.DataFrame) -> pd.DataFrame:
    """Lines from real customers, for lists of who to contact: drops labels like Cash Bill and
    joins names that differ only by case or spacing, under their most used spelling."""
    key = df["customer"].astype(str).str.upper().str.replace(r"\s+", " ", regex=True).str.strip()
    keep = ~key.str.contains(cfg.NOT_A_CUSTOMER, regex=True)
    out, key = df[keep].copy(), key[keep]
    spelling = out.groupby([key, "customer"]).size().sort_values().groupby(level=0).tail(1)
    out["customer"] = key.map(dict(spelling.index))
    return out


def territory_master(df: pd.DataFrame) -> pd.DataFrame:
    """One row per location: names, HQ, trading span, coordinates and
    great-circle distance from the hub territory."""
    m = df.groupby("loc_code").agg(
        location=("location", "first"), territory=("territory", "first"),
        hq=("hq", lambda s: s.mode().iloc[0]),
        first_sale=("bill_date", "min"), last_sale=("bill_date", "max"),
        lifetime_revenue=("amount", "sum")).reset_index()
    coords = pd.read_csv(cfg.COORDS_PATH)
    m = m.merge(coords[["location", "lat", "lon"]], on="location", how="left")
    missing = m[m["lat"].isna()]["location"].tolist()
    if missing:
        print(f"        ! no coordinates for: {', '.join(missing)} (distance left blank)")
    hub = m[m["location"] == cfg.HUB_LOCATION].iloc[0]
    m["distance_km"] = [
        round(haversine_km(hub.lat, hub.lon, r.lat, r.lon), 1) if pd.notna(r.lat) else np.nan
        for r in m.itertuples()]
    return m


# ---- Report ------------------------------------------------
def write_quality_report(rep: dict, recon: pd.DataFrame, out_dir: str) -> None:
    rep = dict(rep)
    rep["reconciliation_max_abs_difference"] = float(recon["difference"].abs().max())
    with open(os.path.join(out_dir, "data_quality_report.json"), "w", encoding="utf-8") as f:
        json.dump(rep, f, indent=2, default=str)
    recon.to_csv(os.path.join(out_dir, "reconciliation.csv"), index=False)

    lines = ["# Data preparation and quality report", "",
             f"Source rows: **{rep['raw_rows']:,}** ({rep['raw_columns']} columns). "
             f"Rows after cleaning: **{rep['clean_rows']:,}**. "
             f"Bill dates {rep['date_range'][0]} to {rep['date_range'][1]}.", "",
             "## Cleaning steps", "", "| Step | Rows affected | Detail |", "|---|---:|---|"]
    for s in rep["steps"]:
        detail = ""
        if "mapping" in s:
            detail = f"{s['mapping']}; {s['segments_before']} labels -> {s['segments_after']}"
        elif "examples" in s:
            detail = ", ".join(s["examples"])
        lines.append(f"| {s['step']} | {s['rows_affected']:,} | {detail} |")
    lines += ["", "## Source files", "", "| File | Lines | First bill | Last bill | Amount (Rs) |", "|---|---:|---|---|---:|"]
    lines += [f"| {s['file']} | {s['lines']:,} | {s['first']} | {s['last']} | {s['amount']:,.2f} |" for s in rep["sources"]]
    lines += ["", f"Complete fiscal years, used for the regional, category and expansion analyses: {', '.join(rep['complete_fys'])}. "
              f"Part-year, used for the Sales Action Plan and the forward test only: {', '.join(rep['partial_fys']) or 'none'}."]
    lines += ["", "## Line types", "", "| Type | Lines | Quantity | Amount (Rs) | Margin (Rs) |",
              "|---|---:|---:|---:|---:|"]
    for k, v in rep["line_types"].items():
        lines.append(f"| {k} | {int(v['lines']):,} | {v['qty']:,.0f} | {v['amount']:,.2f} | {v['margin']:,.2f} |")
    lines += ["", "## Integrity checks", "", "| Check | Lines |", "|---|---:|"]
    lines += [f"| {k.replace('_', ' ')} | {v:,} |" for k, v in rep["checks"].items()]
    lines += ["", "## Reconciliation with the workbook summary (Sheet1)", "",
              f"Largest absolute difference across all years and measures: "
              f"**Rs {rep['reconciliation_max_abs_difference']:,.2f}**.", "",
              "| FY | Measure | Workbook | Recomputed | Difference |", "|---|---|---:|---:|---:|"]
    for r in recon.itertuples():
        lines.append(f"| {r.fy} | {r.measure} | {r.workbook_summary:,.2f} | {r.recomputed:,.2f} | {r.difference:,.2f} |")
    unchecked = sorted(set(rep["complete_fys"] + rep["partial_fys"]) - set(recon["fy"]))
    if unchecked:
        lines += ["", f"Not reconciled, because their files have no summary sheet: {', '.join(unchecked)}."]
    lines += ["", "## Entities", ""] + [f"- {k.replace('_', ' ')}: {v}" for k, v in rep["entities"].items()]
    with open(os.path.join(out_dir, "data_quality_report.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(lines) + "\n")


def prepare(data_path: str = None, out_dir: str = cfg.OUTPUT_DIR):
    """Run the whole preparation stage; returns (clean lines, territory master,
    territory-year features, HQ-year features, quality report). The lines include any
    part-year; the master and features cover complete fiscal years only."""
    os.makedirs(out_dir, exist_ok=True)
    raw, summary_sheet = read_workbook(data_path)
    df, rep = clean_sales(pd.concat([raw] + read_updates(), ignore_index=True))
    clash = overlapping_sources(df)
    if clash:
        sys.exit("These files cover some of the same dates, so their sales would be counted twice:\n"
                 + "\n".join(f"  {a}  and  {b}" for a, b in clash)
                 + f"\nKeep one file per period in {cfg.UPDATES_DIR}.")
    rep["sources"] = [{"file": f, "lines": int(len(g)), "first": str(g["bill_date"].min().date()),
                       "last": str(g["bill_date"].max().date()), "amount": round(float(g["amount"].sum()), 2)}
                      for f, g in df.groupby("source", sort=False)]
    rep["complete_fys"] = complete_fys(df)
    rep["partial_fys"] = sorted(set(df["fy"]) - set(rep["complete_fys"]))
    recon = reconcile(df, parse_summary(summary_sheet))
    write_quality_report(rep, recon, out_dir)
    full = df[df["fy"].isin(rep["complete_fys"])]
    master = territory_master(full)
    ty = unit_year_features(full, "loc_code")
    hy = unit_year_features(full, "hq")
    df.to_pickle(os.path.join(out_dir, "sales_clean.pkl"))
    ty.merge(master[["loc_code", "territory", "hq"]], on="loc_code").to_csv(
        os.path.join(out_dir, "territory_year_features.csv"), index=False)
    rep["reconciliation_max_abs_difference"] = float(recon["difference"].abs().max())
    return df, master, ty, hy, rep
