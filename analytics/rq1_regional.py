# =============================================================
# InvenIQ Sales Intelligence — RQ1 regional performance module
#
# Ranks territories (locations) and regions (HQs) on several factors
# instead of revenue alone, and separates territories that are
# under-performing ("untapped potential") from ones that are simply small:
#
#   1. factors per fiscal year: revenue, margin %, growth, customer
#      breadth, product breadth (+ diagnostics: retention, returns, peak)
#   2. composite score = weighted mean of 0-100 percentile scores
#   3. under-performer flags
#        - peer gap:    revenue well below what territories with the same
#                       reach (customers, products) earn — leave-one-out
#                       log-log regression, so no territory predicts itself
#        - lost ground: fell from its own best year much further than the
#                       rest of the business did over the same years
#   4. plain-language reasons for every territory
#   5. evaluation against the single-metric (revenue-only) ranking,
#      ranking stability over time and sensitivity to the weights
# =============================================================
import numpy as np
import pandas as pd

import config as cfg

# factor key -> value column it scores
FACTORS = {
    "revenue":   "revenue",
    "margin":    "margin_pct",
    "growth":    "growth_cagr_pct",
    "customers": "customers",
    "products":  "products",
}
SEGMENTS = ["Strong", "Steady", "Untapped potential", "Weak", "New", "Lapsed", "Insufficient data"]


# ---- Formatting helpers ------------------------------------
def fmt_inr(v: float) -> str:
    """Indian-style money: Rs 3.98 crore / Rs 16.0 lakh / Rs 9,874."""
    if pd.isna(v):
        return "—"
    a = abs(v)
    if a >= 1e7:
        return f"₹{v / 1e7:.2f} crore"
    if a >= 1e5:
        return f"₹{v / 1e5:.1f} lakh"
    return f"₹{v:,.0f}"


def join_words(items):
    return items[0] if len(items) == 1 else ", ".join(items[:-1]) + " and " + items[-1]


def pct_score(s: pd.Series) -> pd.Series:
    """0-100 percentile among the values given (ties share the average rank)."""
    n = s.notna().sum()
    if n <= 1:
        return pd.Series(50.0, index=s.index).where(s.notna())
    return (s.rank(method="average") - 1) / (n - 1) * 100


# ---- Factors for one fiscal year ---------------------------
def year_factors(uy: pd.DataFrame, unit: str, t: int, fys: list) -> pd.DataFrame:
    """Factor values and status of every unit in fiscal year index t."""
    wide = uy.pivot(index=unit, columns="fy_idx", values="net_revenue")
    hist = wide.loc[:, :t]
    cur = uy[uy["fy_idx"] == t].set_index(unit)

    f = pd.DataFrame(index=cur.index)
    f["fy"] = fys[t]
    f["revenue"] = cur["net_revenue"]
    f["margin_value"] = cur["net_margin"]
    f["margin_pct"] = (cur["net_margin"] / cur["net_revenue"] * 100).where(cur["net_revenue"] > 0)
    f["customers"] = cur["customers"]
    f["products"] = cur["products"]
    f["revenue_per_customer"] = (cur["net_revenue"] / cur["customers"]).where(cur["customers"] > 0)
    f["prev_customers"] = cur["prev_customers"]
    f["retention_pct"] = (cur["retained_customers"] / cur["prev_customers"] * 100).where(cur["prev_customers"] > 0)
    f["return_rate_pct"] = (-cur["returns_amount"] / cur["gross_sales"] * 100).where(cur["gross_sales"] > 0)
    to_date = uy[uy["fy_idx"] <= t].groupby(unit)
    f["first_sale"] = to_date["first_sale"].min()
    f["last_sale"] = to_date["last_sale"].max()

    # Growth: annualised from the earliest positive year in the window
    growth, years = {}, {}
    for u, row in wide.iterrows():
        growth[u], years[u] = np.nan, 0
        if row[t] <= 0:
            continue
        for s in range(max(0, t - cfg.GROWTH_YEARS), t):
            if row[s] > 0:
                years[u] = t - s
                growth[u] = ((row[t] / row[s]) ** (1 / years[u]) - 1) * 100
                break
    f["growth_cagr_pct"] = pd.Series(growth)
    f["growth_years"] = pd.Series(years)
    f["yoy_growth_pct"] = ((wide[t] / wide[t - 1] - 1) * 100).where(wide[t - 1] > 0) if t > 0 else np.nan

    # History to date: best year, share of it reached now, lifetime total
    f["peak_revenue"] = hist.max(axis=1)
    peak_idx = hist.idxmax(axis=1)
    f["peak_fy"] = peak_idx.map(lambda i: fys[i])
    f["pct_of_peak"] = (f["revenue"] / f["peak_revenue"] * 100).where(f["peak_revenue"] > 0)
    # ...and how much the rest of the business kept over the same years
    rest = hist.sum(axis=0).to_numpy()[None, :] - hist.to_numpy()
    rest_now = rest[:, -1]
    rest_at_peak = rest[np.arange(len(hist)), peak_idx.to_numpy()]
    f["business_pct_of_peak"] = pd.Series(np.where(rest_at_peak > 0, rest_now / rest_at_peak * 100, np.nan),
                                          index=hist.index)
    f["relative_retention_pct"] = f["pct_of_peak"] / f["business_pct_of_peak"] * 100
    f["lifetime_revenue"] = hist.sum(axis=1)
    has_sales = hist.gt(0)
    f["first_fy"] = has_sales.idxmax(axis=1).map(lambda i: fys[i]).where(has_sales.any(axis=1))

    f = f[f["first_sale"].notna()].copy()      # not trading yet by year t
    f["status"] = np.select(
        [f["lifetime_revenue"] < cfg.MIN_LIFETIME_REVENUE,
         f["revenue"] <= 0,
         f["growth_cagr_pct"].isna()],
        ["Insufficient data", "Lapsed", "New"], default="Active")
    return f


def add_composite(f: pd.DataFrame, weights: dict = None) -> pd.DataFrame:
    """Percentile score per factor + weighted composite, among active units."""
    weights = weights or cfg.RQ1_WEIGHTS
    total = sum(weights.values())
    act = f["status"] == "Active"
    f["composite_score"] = np.nan
    comp = pd.Series(0.0, index=f.index[act])
    for key, col in FACTORS.items():
        f[f"score_{key}"] = np.nan
        f.loc[act, f"score_{key}"] = pct_score(f.loc[act, col])
        comp += f.loc[act, f"score_{key}"].fillna(50) * weights[key] / total
    f.loc[act, "composite_score"] = comp
    f["composite_rank"] = f.loc[act, "composite_score"].rank(ascending=False, method="min")
    f["revenue_rank"] = f.loc[act, "revenue"].rank(ascending=False, method="min")
    return f


# ---- Peer (reach) model ------------------------------------
def _design(df: pd.DataFrame, features: list) -> np.ndarray:
    cols = [np.ones(len(df))]
    for c in features:
        cols.append(df[c].to_numpy(float) / 100 if c == "distance_km" else np.log(df[c].to_numpy(float)))
    return np.column_stack(cols)


def peer_model(f: pd.DataFrame, features: list) -> tuple:
    """Leave-one-out expected revenue for active units from their reach.
    log(revenue) = b0 + b1 log(customers) + b2 log(products) [+ b3 distance]"""
    act = f[f["status"] == "Active"].dropna(subset=features)
    if cfg.PEER_MODEL_EXCLUDE_HUB and "location" in act.columns:
        act = act[act["location"] != cfg.HUB_LOCATION]
    n, k = len(act), len(features) + 1
    if n < k + 3:
        return pd.Series(np.nan, index=f.index), {"n": n, "note": "too few units for a peer model"}
    X, y = _design(act, features), np.log(act["revenue"].to_numpy(float))
    beta, *_ = np.linalg.lstsq(X, y, rcond=None)
    fitted = X @ beta
    r2 = 1 - ((y - fitted) ** 2).sum() / ((y - y.mean()) ** 2).sum()
    loo = np.empty(n)
    for i in range(n):
        keep = np.arange(n) != i
        b, *_ = np.linalg.lstsq(X[keep], y[keep], rcond=None)
        loo[i] = X[i] @ b
    expected = pd.Series(np.exp(loo), index=act.index).reindex(f.index)
    diag = {"n": n, "features": features, "r2_in_sample": round(float(r2), 4),
            "loo_rmse_log": round(float(np.sqrt(((y - loo) ** 2).mean())), 4),
            "loo_median_abs_pct_error": round(float(np.median(np.abs(np.exp(y - loo) - 1)) * 100), 1),
            "coefficients": dict(zip(["intercept"] + features, [round(float(b), 4) for b in beta]))}
    return expected, diag


# ---- Segments, flags, reasons ------------------------------
def assign_segment(composite, flagged, status):
    seg = np.where(composite >= cfg.STRONG_SCORE, "Strong",
          np.where(flagged, "Untapped potential",
          np.where(composite < cfg.WEAK_SCORE, "Weak", "Steady")))
    return np.where(status == "Active", seg, status)


def describe_factor(key: str, r) -> str:
    if key == "revenue":
        return f"sales ({fmt_inr(r.revenue)})"
    if key == "margin":
        return f"margin ({r.margin_pct:.1f}%)"
    if key == "growth":
        return f"sales trend ({r.growth_cagr_pct:+.1f}% a year over {int(r.growth_years)} years)"
    if key == "customers":
        return f"number of customers ({int(r.customers)})"
    return f"range of products sold ({int(r.products)})"


def reasons_for(r, n_active: int, revenue_share_pct: float) -> list:
    fy = r.fy
    if r.status == "Insufficient data":
        return [f"Only {fmt_inr(r.lifetime_revenue)} of sales in total since "
                f"{r.first_sale:%b %Y}: too little to judge."]
    if r.status == "Lapsed":
        return [f"No sales in FY{fy}; last sale {r.last_sale:%b %Y}.",
                f"Best year was FY{r.peak_fy} at {fmt_inr(r.peak_revenue)}: customers bought here before."]
    if r.status == "New":
        start = f"Started selling in FY{fy}" if r.first_fy == fy else "Selling again after a gap"
        return [f"{start}: {fmt_inr(r.revenue)} from {int(r.customers)} customer(s) in FY{fy}.",
                "Too early to give it a score."]

    out = [f"{fmt_inr(r.revenue)} in sales in FY{fy}: number {int(r.revenue_rank)} of {n_active} territories by sales, "
           f"number {int(r.composite_rank)} overall (score {r.composite_score:.0f} out of 100)."]
    top = [describe_factor(k, r) for k in FACTORS if r[f"score_{k}"] >= 75]
    bottom = [describe_factor(k, r) for k in FACTORS if r[f"score_{k}"] <= 25]
    if top:
        out.append("Among the best territories for " + join_words(top) + ".")
    if bottom:
        out.append("Among the weakest for " + join_words(bottom) + ".")
    if r.get("location") == cfg.HUB_LOCATION:
        out.append(f"Main territory ({revenue_share_pct:.0f}% of all sales): too large to compare with the others.")
    elif pd.notna(r.get("peer_gap_pct", np.nan)):
        if r.flag_peer_gap:
            out.append(f"Sells {abs(r.peer_gap_pct):.0f}% less than similar territories "
                       f"({int(r.customers)} customers, {int(r.products)} products): "
                       f"about {fmt_inr(r.expected_revenue)} would be normal.")
        elif r.peer_gap_pct >= 50:
            out.append(f"Sells {r.peer_gap_pct:.0f}% more than similar territories: its customers buy a lot.")
    if r.flag_lost_ground:
        out.append(f"Sales fell to {r.pct_of_peak:.0f}% of their FY{r.peak_fy} best of "
                   f"{fmt_inr(r.peak_revenue)}, while the rest of the business kept "
                   f"{r.business_pct_of_peak:.0f}%: it fell much faster than the company.")
    if pd.notna(r.retention_pct) and r.retention_pct < 60 and r.prev_customers >= 3:
        out.append(f"Only {r.retention_pct:.0f}% of last year's {int(r.prev_customers)} customers bought again.")
    out.append({
        "Strong": "A strong territory: protect it and grow it.",
        "Untapped potential": "Room to grow: sales are below what its customers or its own past sales suggest.",
        "Weak": "Sales fit its small customer base: a small market, not a missed opportunity.",
        "Steady": "Performing about as expected.",
    }[r.segment])
    return out


def score_year(uy: pd.DataFrame, unit: str, fys: list, extra: pd.DataFrame = None,
               use_peer_model: bool = True, t: int = None) -> tuple:
    """Full RQ1 scoring of one fiscal year (the latest by default)."""
    t = len(fys) - 1 if t is None else t
    f = year_factors(uy, unit, t, fys)
    if extra is not None:
        f = f.join(extra)
    f = add_composite(f)

    diag = {}
    f["expected_revenue"], f["peer_gap_pct"] = np.nan, np.nan
    threshold = np.nan
    if use_peer_model:
        # Pick the reach model with the lowest out-of-sample (leave-one-out) error
        fits = [peer_model(f, s) for s in cfg.PEER_MODEL_SPECS if set(s) <= set(f.columns)]
        diag["specifications"] = [d for _, d in fits]
        usable = [(e, d) for e, d in fits if "loo_rmse_log" in d]
        if usable:
            expected, chosen = min(usable, key=lambda ed: ed[1]["loo_rmse_log"])
            threshold = (np.exp(-cfg.PEER_GAP_SD * chosen["loo_rmse_log"]) - 1) * 100
            chosen["flag_threshold_pct"] = round(float(threshold), 1)
            diag["chosen"] = chosen
            f["expected_revenue"] = expected
            f["peer_gap_pct"] = (f["revenue"] / f["expected_revenue"] - 1) * 100
    act = f["status"] == "Active"
    f.loc[f["status"] == "Insufficient data", "margin_pct"] = np.nan
    f["flag_peer_gap"] = act & (f["peer_gap_pct"] <= threshold)
    f["flag_lost_ground"] = act & (f["relative_retention_pct"] < cfg.LOST_GROUND_FLAG * 100)
    f["segment"] = assign_segment(f["composite_score"].to_numpy(),
                                  (f["flag_peer_gap"] | f["flag_lost_ground"]).to_numpy(),
                                  f["status"].to_numpy())
    n_active = int(act.sum())
    share = f["revenue"].clip(lower=0) / f["revenue"].clip(lower=0).sum() * 100
    f["reasons"] = [reasons_for(row, n_active, share[u]) for u, row in f.iterrows()]
    return f, diag


# ---- Robustness and evaluation -----------------------------
def weight_sensitivity(f: pd.DataFrame) -> tuple:
    """Re-score with random weights (Dirichlet) and measure how much each
    territory's rank and segment move."""
    act = f[f["status"] == "Active"]
    S = act[[f"score_{k}" for k in FACTORS]].fillna(50).to_numpy()
    rng = np.random.default_rng(cfg.RANDOM_SEED)
    W = rng.dirichlet(np.ones(len(FACTORS)), size=cfg.SENSITIVITY_DRAWS)
    C = S @ W.T                                             # units x draws
    ranks = (-C).argsort(axis=0).argsort(axis=0) + 1
    flagged = (act["flag_peer_gap"] | act["flag_lost_ground"]).to_numpy()[:, None]
    segs = np.where(C >= cfg.STRONG_SCORE, "Strong",
           np.where(flagged, "Untapped potential",
           np.where(C < cfg.WEAK_SCORE, "Weak", "Steady")))
    base_rank = act["composite_rank"].to_numpy()
    rho = [np.corrcoef(base_rank, ranks[:, d])[0, 1] for d in range(ranks.shape[1])]
    per_unit = pd.DataFrame({
        "rank_low": np.percentile(ranks, 10, axis=1).round(),
        "rank_high": np.percentile(ranks, 90, axis=1).round(),
        "segment_stability_pct": (segs == act["segment"].to_numpy()[:, None]).mean(axis=1) * 100,
    }, index=act.index)
    summary = {"draws": cfg.SENSITIVITY_DRAWS, "weights": "Dirichlet(1,...,1)",
               "spearman_vs_equal_weights_mean": round(float(np.mean(rho)), 3),
               "spearman_vs_equal_weights_p5": round(float(np.percentile(rho, 5)), 3),
               "segment_stability_mean_pct": round(float(per_unit["segment_stability_pct"].mean()), 1)}
    return per_unit, summary


def compare_with_revenue_ranking(f: pd.DataFrame, name_col: str) -> dict:
    """RQ1 success criterion: does the multi-factor view surface under-performers
    that a revenue-only ranking (the intuitive baseline) overlooks?"""
    act = f[f["status"] == "Active"]
    name = f[name_col].to_dict()
    sys_under = list(act.index[act["segment"] == "Untapped potential"])
    k = len(sys_under)
    selling = f[f["revenue"] > 0]       # what a real revenue ranking lists
    strong = list(act.index[act["segment"] == "Strong"])
    base_strong = list(selling.nlargest(len(strong), "revenue").index) if strong else []
    lapsed = f[f["status"] == "Lapsed"].sort_values("peak_revenue", ascending=False)

    def names(units):
        return [name[u] for u in units]

    def versus(pool):
        base_under = list(pool.nsmallest(k, "revenue").index) if k else []
        return {
            "k": k,
            "multi_factor": names(sys_under),
            "revenue_bottom_k": names(base_under),
            "surfaced_only_by_multi_factor": names([u for u in sys_under if u not in base_under]),
            "revenue_bottom_but_not_under_performing": [
                {"name": name[u], "segment": f.loc[u, "segment"]} for u in base_under if u not in sys_under],
            "jaccard": round(len(set(sys_under) & set(base_under)) / len(set(sys_under) | set(base_under)), 3) if k else None,
        }

    return {
        "active_units": len(act),
        "spearman_composite_vs_revenue_rank": round(float(act["composite_rank"].corr(act["revenue_rank"], method="spearman")), 3),
        "under_performers": versus(selling),
        # Stricter comparison: a manager may ignore new and tiny territories anyway
        "under_performers_active_only": versus(act),
        "strong": {
            "multi_factor": names(strong), "revenue_top_k": names(base_strong),
            "jaccard": round(len(set(strong) & set(base_strong)) / len(set(strong) | set(base_strong)), 3) if strong else None,
        },
        # A latest-year revenue ranking only lists units that sold this year,
        # so lapsed territories disappear from it entirely.
        "lapsed_invisible_to_revenue_ranking": [
            {"name": name[u], "peak_fy": r.peak_fy, "peak_revenue": round(float(r.peak_revenue), 2),
             "last_sale": str(r.last_sale.date())} for u, r in lapsed.iterrows()],
    }


def yearly_scores(uy: pd.DataFrame, unit: str, fys: list) -> pd.DataFrame:
    """Factor values for every fiscal year; composite and ranks for the years
    with a full growth window (so every year is scored the same way)."""
    rows = []
    for t in range(len(fys)):
        f = year_factors(uy, unit, t, fys)
        if t >= cfg.GROWTH_YEARS:
            f = add_composite(f)
        else:
            f["status"], f["composite_score"], f["composite_rank"] = None, np.nan, np.nan
        rows.append(f)
    cols = ["fy", "status", "revenue", "margin_pct", "growth_cagr_pct", "customers", "products",
            "composite_score", "composite_rank"]
    out = pd.concat(rows)[cols]
    out.index.name = unit
    return out.reset_index()


def rank_stability(yearly: pd.DataFrame, unit: str, fys: list) -> list:
    """Spearman correlation of rankings between consecutive fiscal years."""
    out = []
    for a, b in zip(fys[:-1], fys[1:]):
        ya = yearly[yearly["fy"] == a].set_index(unit)
        yb = yearly[yearly["fy"] == b].set_index(unit)
        both_rev = ya.index[(ya["revenue"] > 0)].intersection(yb.index[yb["revenue"] > 0])
        row = {"from": a, "to": b, "units_trading_both": len(both_rev),
               "revenue_rank_spearman": round(float(ya.loc[both_rev, "revenue"].corr(
                   yb.loc[both_rev, "revenue"], method="spearman")), 3)}
        both_act = ya.index[ya["composite_score"].notna()].intersection(yb.index[yb["composite_score"].notna()])
        if len(both_act) >= 3:
            row["units_scored_both"] = len(both_act)
            row["composite_rank_spearman"] = round(float(ya.loc[both_act, "composite_score"].corr(
                yb.loc[both_act, "composite_score"], method="spearman")), 3)
        out.append(row)
    return out


# ---- Entry point -------------------------------------------
def run(master: pd.DataFrame, ty: pd.DataFrame, hy: pd.DataFrame) -> dict:
    fys = sorted(ty["fy"].unique())
    extra = master.set_index("loc_code")[["location", "territory", "hq", "lat", "lon", "distance_km"]]

    terr, peer_diag = score_year(ty, "loc_code", fys, extra=extra)
    sens_unit, sens = weight_sensitivity(terr)
    terr = terr.join(sens_unit)
    terr_yearly = yearly_scores(ty, "loc_code", fys)

    # Same scoring for every year with a full growth window: segment history
    # and the revenue-only comparison repeated year by year
    by_year, seg_rows = [], []
    for t in range(cfg.GROWTH_YEARS, len(fys)):
        ft = terr if t == len(fys) - 1 else score_year(ty, "loc_code", fys, extra=extra, t=t)[0]
        by_year.append({"fy": fys[t], **compare_with_revenue_ranking(ft, "territory")})
        seg_rows.append(pd.DataFrame({"loc_code": ft.index, "fy": fys[t], "segment": ft["segment"].to_numpy()}))
    terr_yearly = terr_yearly.merge(pd.concat(seg_rows), on=["loc_code", "fy"], how="left")

    hq_extra = pd.DataFrame({"hq_name": sorted(hy["hq"].unique())}, index=sorted(hy["hq"].unique()))
    hq, _ = score_year(hy, "hq", fys, extra=hq_extra, use_peer_model=False)
    hq["territories_total"] = master.groupby("hq").size()
    hq["territories_active"] = terr[terr["status"] == "Active"].groupby("hq").size()
    hq["territories_active"] = hq["territories_active"].fillna(0)
    hq["revenue_share_pct"] = hq["revenue"] / hq["revenue"].sum() * 100

    metrics = {
        "fy": fys[-1],
        "segment_counts": {s: int((terr["segment"] == s).sum()) for s in SEGMENTS},
        "baseline_comparison": by_year[-1],
        "baseline_comparison_by_year": by_year,
        "hq_baseline_comparison": compare_with_revenue_ranking(hq, "hq_name"),
        "peer_model": peer_diag,
        "weight_sensitivity": sens,
        "rank_stability": rank_stability(terr_yearly, "loc_code", fys),
        "revenue_concentration": {
            "top_territory": terr.loc[terr["revenue"].idxmax(), "territory"],
            "top_territory_share_pct": round(float(terr["revenue"].max() / terr["revenue"].clip(lower=0).sum() * 100), 1),
        },
    }
    params = {k: getattr(cfg, k) for k in
              ["RQ1_WEIGHTS", "STRONG_SCORE", "WEAK_SCORE", "PEER_GAP_SD", "PEER_MODEL_SPECS",
               "PEER_MODEL_EXCLUDE_HUB", "LOST_GROUND_FLAG", "GROWTH_YEARS", "MIN_LIFETIME_REVENUE",
               "HUB_LOCATION", "SENSITIVITY_DRAWS", "RANDOM_SEED"]}
    return {"territories": terr, "territory_yearly": terr_yearly, "hq": hq,
            "metrics": metrics, "params": params, "fys": fys}
