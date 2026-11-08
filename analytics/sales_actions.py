# =============================================================
# InvenIQ Sales Intelligence — Sales Action Plan
#
# Turns the sales lines into people to contact, and later checks what happened:
#   - big accounts buying less than in the same months last year
#   - customers well past their usual reorder time
#   - did customers who were contacted do better than the others?
#
# Beyond the research questions: an extension for the client. The page states facts
# and does not predict. warning_check() tests whether these flags were followed by
# further decline in past years. Its pass rule was set before it first ran:
# flagged customers do worse than the others AND the 95% interval of that difference
# (resampling whole customers) excludes zero. The thresholds are not changed after it.
# =============================================================
import numpy as np
import pandas as pd

import config as cfg
import data_prep

ACCOUNT_COLS = ["customer", "location_code", "territory", "hq", "sales_before", "sales_now", "change_value",
                "change_pct", "falls"]
QUIET_COLS = ["customer", "location_code", "territory", "hq", "usual_gap_days", "days_since", "last_order",
              "value_12m", "buys"]
RESULT_COLS = ["action_id", "status", "days_after", "sales_before", "sales_after", "others_before", "others_after"]


def _between(df: pd.DataFrame, start, end) -> pd.DataFrame:
    return df[(df["bill_date"] > start) & (df["bill_date"] <= end)]


def _windows(as_of) -> tuple:
    """(start of the latest months, start and end of the same months a year earlier)."""
    start = as_of - pd.DateOffset(months=cfg.ACTION_WINDOW_MONTHS)
    return start, start - pd.DateOffset(years=1), as_of - pd.DateOffset(years=1)


def _place(df: pd.DataFrame) -> pd.DataFrame:
    """Each customer's main territory: where they spent most."""
    spent = df.groupby(["customer", "loc_code", "territory", "hq"])["amount"].sum().reset_index()
    return spent.sort_values("amount", ascending=False).drop_duplicates("customer").set_index("customer")


def account_changes(df: pd.DataFrame, as_of) -> pd.DataFrame:
    """Big accounts only: sales in the latest months vs the same months a year earlier."""
    start, before_start, before_end = _windows(as_of)
    before = _between(df, before_start, before_end).groupby("customer")["amount"].sum().clip(lower=0)
    now = _between(df, start, as_of).groupby("customer")["amount"].sum().clip(lower=0)
    t = pd.DataFrame({"sales_before": before}).join(now.rename("sales_now")).fillna({"sales_now": 0.0})
    t = t[t["sales_before"] >= cfg.ACTION_MIN_SALES]
    t["flagged"] = t["sales_now"] <= t["sales_before"] * (1 - cfg.ACTION_FALL_PCT / 100)
    return t


def falling_accounts(df: pd.DataFrame, as_of) -> pd.DataFrame:
    t = account_changes(df, as_of)
    t = t[t["flagged"]].copy()
    if t.empty:
        return pd.DataFrame(columns=ACCOUNT_COLS)
    start, before_start, before_end = _windows(as_of)
    t["change_value"] = t["sales_now"] - t["sales_before"]
    t["change_pct"] = t["change_value"] / t["sales_before"] * 100
    place = _place(_between(df, before_start, before_end))
    t = t.join(place[["loc_code", "territory", "hq"]]).rename(columns={"loc_code": "location_code"})
    mine = df[df["customer"].isin(t.index)]
    seg_before = _between(mine, before_start, before_end).groupby(["customer", "segment"])["amount"].sum().clip(lower=0)
    seg_now = _between(mine, start, as_of).groupby(["customer", "segment"])["amount"].sum().clip(lower=0)
    segs = pd.DataFrame({"before": seg_before, "now": seg_now}).fillna(0.0)
    segs["fall"] = segs["before"] - segs["now"]
    t["falls"] = [[{"segment": s, "before": round(float(r["before"]), 2), "now": round(float(r["now"]), 2)}
                   for s, r in segs.loc[c].nlargest(3, "fall").iterrows() if r["fall"] > 0] for c in t.index]
    return t.sort_values("change_value").reset_index()[ACCOUNT_COLS]


def order_gaps(df: pd.DataFrame, as_of) -> pd.DataFrame:
    """Regular customers who ordered in the last 12 months: their usual gap and days since the last order."""
    sales = df[(df["line_type"] == "sale") & (df["bill_date"] <= as_of)]
    year_ago = as_of - pd.DateOffset(years=1)
    dates = sales[["customer", "bill_date"]].drop_duplicates().sort_values("bill_date")
    g = dates.groupby("customer")["bill_date"]
    t = pd.DataFrame({"orders": g.size(), "last_order": g.max(),
                      "usual_gap_days": dates.assign(d=g.diff().dt.days).groupby("customer")["d"].median()})
    t = t[(t["orders"] >= cfg.QUIET_MIN_ORDERS) & (t["last_order"] > year_ago)].copy()
    t["days_since"] = (as_of - t["last_order"]).dt.days
    t["flagged"] = t["days_since"] > (t["usual_gap_days"] * cfg.QUIET_GAP_MULTIPLE).clip(lower=cfg.QUIET_MIN_DAYS)
    return t


def quiet_customers(df: pd.DataFrame, as_of, skip=()) -> pd.DataFrame:
    t = order_gaps(df, as_of)
    t = t[t["flagged"] & ~t.index.isin(list(skip))].copy()
    if t.empty:
        return pd.DataFrame(columns=QUIET_COLS)
    year = _between(df[df["customer"].isin(t.index)], as_of - pd.DateOffset(years=1), as_of)
    t["value_12m"] = year.groupby("customer")["amount"].sum().clip(lower=0).reindex(t.index).fillna(0.0)
    t = t[t["value_12m"] > 0]
    t = t.join(_place(year)[["loc_code", "territory", "hq"]]).rename(columns={"loc_code": "location_code"})
    seg = year.groupby(["customer", "segment"])["amount"].sum()
    t["buys"] = [list(seg.loc[c].nlargest(3)[lambda x: x > 0].index) for c in t.index]
    return t.sort_values("value_12m", ascending=False).reset_index()[QUIET_COLS]


# ---- Did the flags mean anything in past years? -------------
def _gap_with_interval(rows: pd.DataFrame, outcome: str, rng) -> dict:
    """Outcome share, flagged vs not, with an interval that resamples whole customers."""
    g = rows.assign(hit=rows[outcome].astype(float)).groupby(["customer", "flagged"])["hit"].agg(["sum", "count"]).unstack(fill_value=0)
    hit_f, n_f = g[("sum", True)].to_numpy(), g[("count", True)].to_numpy()
    hit_o, n_o = g[("sum", False)].to_numpy(), g[("count", False)].to_numpy()
    idx = rng.integers(0, len(g), (cfg.BOOTSTRAP_DRAWS, len(g)))
    draws = hit_f[idx].sum(1) / np.maximum(n_f[idx].sum(1), 1) - hit_o[idx].sum(1) / np.maximum(n_o[idx].sum(1), 1)
    share_f, share_o = hit_f.sum() / n_f.sum(), hit_o.sum() / n_o.sum()
    low, high = np.percentile(draws, [2.5, 97.5])
    return {"flagged": int(n_f.sum()), "others": int(n_o.sum()), "flagged_share": round(float(share_f), 4),
            "others_share": round(float(share_o), 4), "difference": round(float(share_f - share_o), 4),
            "interval_95": [round(float(low), 4), round(float(high), 4)],
            "holds_up": bool(low > 0)}


def warning_check(df: pd.DataFrame) -> dict:
    """At each past half-year end, flag using only earlier sales, then look at the next six months."""
    rng = np.random.default_rng(cfg.RANDOM_SEED)
    first, last = df["bill_date"].min(), df["bill_date"].max()
    months = cfg.ACTION_WINDOW_MONTHS
    accounts, quiet = [], []
    for as_of in pd.date_range(first, last, freq="ME"):
        after = as_of + pd.DateOffset(months=months)
        if as_of.month not in (3, 9) or after > last or _windows(as_of)[1] < first:
            continue
        past = df[df["bill_date"] <= as_of]
        later = _between(df, as_of, after).groupby("customer")["amount"].sum().clip(lower=0)
        # compared with the same months a year earlier, which neither flag window used
        base = _between(df, as_of - pd.DateOffset(years=1), as_of - pd.DateOffset(months=months)).groupby("customer")["amount"].sum().clip(lower=0)
        a = account_changes(past, as_of).assign(later=later, base=base).fillna({"later": 0.0, "base": 0.0})
        a = a[a["base"] > 0]
        a["fell_again"] = a["later"] <= a["base"] * (1 - cfg.ACTION_FALL_PCT / 100)
        accounts.append(a.reset_index().assign(as_of=as_of))
        q = order_gaps(past, as_of).assign(later=later).fillna({"later": 0.0})
        q["bought_nothing"] = q["later"] <= 0
        quiet.append(q.reset_index().assign(as_of=as_of))
    a, q = pd.concat(accounts), pd.concat(quiet)
    return {"checkpoints": [str(d.date()) for d in sorted(a["as_of"].unique())],
            "accounts_fell_20pct_again": _gap_with_interval(a, "fell_again", rng),
            "quiet_bought_nothing_next_6_months": _gap_with_interval(q, "bought_nothing", rng)}


# ---- Did contacting them help? ------------------------------
def action_results(df: pd.DataFrame, log: pd.DataFrame) -> pd.DataFrame:
    """Each marked action: sales since it vs the same dates a year earlier, for this customer and for
    the customers nobody marked. Marked customers were chosen, not random, so this is a guide, not proof."""
    data_end = df["bill_date"].max()
    marked = set(log["customer"]) if len(log) else set()
    rows = []
    for a in log.itertuples():
        start = pd.Timestamp(a.done_on)
        days = (data_end - start).days
        row = {"action_id": a.id, "status": "waiting", "days_after": max(days, 0)}
        if days >= cfg.ACTION_MIN_RESULT_DAYS:
            scope = df
            own = df["customer"] == a.customer
            if a.kind == "expansion":       # only this category counts as the result of offering it
                scope = df[df["segment"] == a.segment]
                own = (scope["customer"] == a.customer) & (scope["loc_code"] == a.location_code)
            total = lambda part, s, e: float(_between(part, s, e).groupby("customer")["amount"].sum().clip(lower=0).sum())
            windows = ((start - pd.DateOffset(years=1), data_end - pd.DateOffset(years=1)), (start, data_end))
            others = scope[~scope["customer"].isin(marked)]
            row.update(status="measured", sales_before=total(scope[own], *windows[0]), sales_after=total(scope[own], *windows[1]),
                       others_before=total(others, *windows[0]), others_after=total(others, *windows[1]))
        rows.append(row)
    return pd.DataFrame(rows, columns=RESULT_COLS)


# ---- Entry point -------------------------------------------
def run(df: pd.DataFrame) -> dict:
    as_of = df["bill_date"].max()
    df = data_prep.contactable(df)
    accounts = falling_accounts(df, as_of)
    quiet = quiet_customers(df, as_of, skip=accounts["customer"])     # one reason to call each customer
    start, before_start, before_end = _windows(as_of)
    metrics = {
        "fy": sorted(df["fy"].unique())[-1], "as_of": str(as_of.date()),
        "window_now": [str((start + pd.Timedelta(days=1)).date()), str(as_of.date())],
        "window_before": [str((before_start + pd.Timedelta(days=1)).date()), str(before_end.date())],
        "accounts_flagged": int(len(accounts)), "accounts_fall_total": round(float(-accounts["change_value"].sum()), 2),
        "quiet_flagged": int(len(quiet)), "quiet_value_total": round(float(quiet["value_12m"].sum()), 2),
        "warning_check": warning_check(df),
    }
    params = {k: getattr(cfg, k) for k in ["ACTION_WINDOW_MONTHS", "ACTION_MIN_SALES", "ACTION_FALL_PCT", "QUIET_GAP_MULTIPLE",
                                           "QUIET_MIN_DAYS", "QUIET_MIN_ORDERS", "ACTION_MIN_RESULT_DAYS"]}
    return {"accounts": accounts, "quiet": quiet, "sales": df, "metrics": metrics, "params": params,
            "fys": sorted(df["fy"].unique())}
