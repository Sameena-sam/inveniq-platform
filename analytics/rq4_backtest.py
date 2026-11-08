# =============================================================
# InvenIQ Sales Intelligence — RQ4 back-test against a decision baseline
#
# Would the system's recommendations have beaten intuition? At each decision
# point the whole method (RQ1 + RQ2 segments + RQ3) is re-run on a copy of the
# data cut off at that year, so later sales cannot leak in. Its top picks are
# then compared with what actually happened, against:
#   - the baseline: "grow where we already sell most" (biggest current sales)
#   - a room-to-grow-only ranking, to see what the rest of the score adds
#   - random picks, to separate the score's value from low sales simply rebounding
#
# Baseline, pass criteria and limits are in reference/decision_baseline.md,
# written before this module first ran. Nobody acted on these recommendations,
# so this tests whether picks did better on their own, not the effect of acting.
# =============================================================
import numpy as np
import pandas as pd
from scipy.stats import fisher_exact

import config as cfg
import data_prep
import rq1_regional as rq1
import rq2_category as rq2
import rq3_expansion as rq3


def score_as_of(df: pd.DataFrame, origin: str) -> tuple:
    """Run the method on the data up to `origin` only. Returns (cells, RQ1 table)."""
    past = df[df["fy"] <= origin]
    fys = sorted(past["fy"].unique())
    master = data_prep.territory_master(past)
    extra = master.set_index("loc_code")[["location", "territory", "hq", "lat", "lon", "distance_km"]]
    terr, _ = rq1.score_year(data_prep.unit_year_features(past, "loc_code"), "loc_code", fys, extra=extra)
    feats = rq2.product_year_features(past, fys)
    segs = rq2.segment_profile(feats, feats[feats["fy"] == origin], fys)
    return rq3.score_cells(past, terr, segs, origin), terr


def add_outcomes(cells: pd.DataFrame, df: pd.DataFrame, target: str, base: pd.Series = None) -> pd.DataFrame:
    """Sales in the target period, and whether each pair beat the pool's overall movement from `base`
    (each pair's sales in the period it is compared with; default: the decision year)."""
    after = df[df["fy"] == target].groupby(["loc_code", "segment"])["amount"].sum().clip(lower=0)
    c = cells.copy()
    pairs = list(zip(c["location_code"], c["segment"]))
    c["sales_after"] = [after.get(k, 0.0) for k in pairs]
    c["sales_base"] = c["sales"] if base is None else [base.get(k, 0.0) for k in pairs]
    pool_change = c["sales_after"].sum() / c["sales_base"].sum()
    c["expected_after"] = c["sales_base"] * pool_change      # had the pair just moved with the pool
    c["excess"] = c["sales_after"] - c["expected_after"]
    c["hit"] = c["sales_after"] > c["expected_after"]   # a pair with no sales hits by selling at all
    return c


def picks_for(c: pd.DataFrame) -> dict:
    n = cfg.RQ3_SHORTLIST
    return {"system": c[c["eligible"]].nsmallest(n, "rank"),
            "room_to_grow_only": c.nlargest(n, "gap_value"),
            "baseline_biggest_sales": c.nlargest(n, "sales")}


def pick_scores(p: pd.DataFrame) -> dict:
    gap = float(p["gap_value"].sum())
    return {"picks": int(len(p)), "hits": int(p["hit"].sum()), "hit_rate": round(float(p["hit"].mean()), 4),
            "sales_before": round(float(p["sales_base"].sum()), 2), "sales_after": round(float(p["sales_after"].sum()), 2),
            "excess": round(float(p["excess"].sum()), 2),
            "share_of_gap_realised": round(float(p["excess"].sum()) / gap, 4) if gap > 0 else None}


def random_hit_rates(pool_hits: np.ndarray, k: int, rng) -> np.ndarray:
    """Hit rate of `k` random picks from the pool, for many random draws."""
    idx = rng.random((cfg.PERMUTATION_DRAWS, len(pool_hits))).argsort(axis=1)[:, :k]
    return pool_hits[idx].mean(axis=1)


def verdict(system_rate: float, baseline_rate: float, p_vs_random: float) -> str:
    """The criteria in reference/decision_baseline.md."""
    if system_rate <= baseline_rate:
        return "Fail"
    return "Pass" if p_vs_random < 0.05 else "Partial"


def compare_case(cells: pd.DataFrame, df: pd.DataFrame, origin: str, target: str, rng, base=None) -> tuple:
    c = add_outcomes(cells, df, target, base)
    picks = picks_for(c)
    scores = {m: pick_scores(p) for m, p in picks.items()}
    sys_rate, base_rate = scores["system"]["hit_rate"], scores["baseline_biggest_sales"]["hit_rate"]
    k = scores["system"]["picks"]
    rand_elig = random_hit_rates(c.loc[c["eligible"], "hit"].to_numpy(float), k, rng)
    rand_all = random_hit_rates(c["hit"].to_numpy(float), k, rng)
    p_val = lambda r: round(float((1 + (r >= sys_rate).sum()) / (len(r) + 1)), 4)
    s, b = scores["system"]["hits"], scores["baseline_biggest_sales"]["hits"]
    result = {
        "origin": origin, "target": target, "pairs_in_pool": int(len(c)), "pairs_eligible": int(c["eligible"].sum()),
        "pool_change_pct": round(float((c["sales_after"].sum() / c["sales_base"].sum() - 1) * 100), 1),
        "pool_hit_rate": round(float(c["hit"].mean()), 4),
        "eligible_hit_rate": round(float(c.loc[c["eligible"], "hit"].mean()), 4),
        "methods": scores,
        "random_eligible_mean_hit_rate": round(float(rand_elig.mean()), 4), "p_system_vs_random_eligible": p_val(rand_elig),
        "random_all_mean_hit_rate": round(float(rand_all.mean()), 4), "p_system_vs_random_all": p_val(rand_all),
        "fisher_p_system_vs_baseline": round(float(fisher_exact([[s, k - s], [b, k - b]], alternative="greater")[1]), 4),
    }
    frames = [p.assign(method=m, origin=origin, target=target) for m, p in picks.items()]
    return result, pd.concat(frames, ignore_index=True)


def territory_case(terr: pd.DataFrame, df: pd.DataFrame, origin: str, target: str) -> dict:
    """What later happened to territories RQ1 flagged, and to the revenue bottom-k."""
    after = df[df["fy"] == target].groupby("loc_code")["amount"].sum().clip(lower=0).reindex(terr.index).fillna(0)
    total_now, total_after = terr["revenue"].clip(lower=0).sum(), after.sum()
    rest_change = (total_after - after) / (total_now - terr["revenue"].clip(lower=0))
    outgrew = after > terr["revenue"].clip(lower=0) * rest_change
    active = terr[terr["status"] == "Active"]
    flagged = active.index[active["segment"] == "Untapped potential"]
    k = len(flagged)
    selling = terr[terr["revenue"] > 0]

    def describe(units):
        return {"territories": [terr.loc[u, "territory"] for u in units], "stopped_selling": int((after[units] <= 0).sum()),
                "outgrew_rest_of_business": int(outgrew[units].sum()), "n": len(units)}

    growth = after[active.index] / active["revenue"]
    return {"origin": origin, "target": target,
            "flagged_untapped": describe(flagged),
            "revenue_bottom_all": describe(selling.nsmallest(k, "revenue").index),
            "revenue_bottom_active": describe(active.nsmallest(k, "revenue").index),
            "active_outgrew_rest_of_business": f"{int(outgrew[active.index].sum())} of {len(active)}",
            "spearman_score_vs_later_growth": round(float(active["composite_score"].corr(growth, method="spearman")), 3),
            "spearman_revenue_vs_later_growth": round(float(active["revenue"].corr(growth, method="spearman")), 3)}


def forward_case(full: pd.DataFrame, latest: pd.DataFrame, rng) -> tuple:
    """The last complete year's picks, judged on the part-year that arrived after them, against the
    same months a year earlier. (None, None) until a part-year exists."""
    new = latest[~latest["fy"].isin(full["fy"].unique())]
    if new.empty:
        return None, None
    origin = sorted(full["fy"].unique())[-1]
    start, end, year = pd.Timestamp(f"{new['fy'].min()[:4]}-04-01"), new["bill_date"].max(), pd.DateOffset(years=1)
    earlier = latest[(latest["bill_date"] >= start - year) & (latest["bill_date"] <= end - year)]
    base = earlier.groupby(["loc_code", "segment"])["amount"].sum().clip(lower=0)
    label = f"{start:%b %Y} to {end:%b %Y}"
    result, frame = compare_case(score_as_of(full, origin)[0], new.assign(fy=label), origin, label, rng, base)
    result["heading"] = f"Forward test: decide FY{origin}, judge {label} against the same months a year earlier"
    result["verdict"] = verdict(result["methods"]["system"]["hit_rate"],
                                result["methods"]["baseline_biggest_sales"]["hit_rate"], result["p_system_vs_random_eligible"])
    return result, frame


def client_plan(latest: pd.DataFrame, full: pd.DataFrame, terr: pd.DataFrame) -> list:
    """The territories the client planned to push or cut back: how they moved against the rest of the business,
    and what RQ1 said about them when the plan was made. Descriptive: these outcomes were already known."""
    fys = sorted(full["fy"].unique())
    plan = cfg.RQ4_CLIENT_PLAN
    by_terr = lambda lines: lines.groupby("territory")["amount"].sum().clip(lower=0)
    periods = [(f"FY{a} to FY{b}", by_terr(full[full["fy"] == a]), by_terr(full[full["fy"] == b]))
               for a, b in zip(fys, fys[1:]) if a >= plan["decided_at"]]
    new = latest[~latest["fy"].isin(fys)]
    if len(new):
        start, end, year = pd.Timestamp(f"{new['fy'].min()[:4]}-04-01"), new["bill_date"].max(), pd.DateOffset(years=1)
        earlier = latest[(latest["bill_date"] >= start - year) & (latest["bill_date"] <= end - year)]
        periods.append((f"{start - year:%b %Y}-{end - year:%b %Y} to {start:%b %Y}-{end:%b %Y}", by_terr(earlier), by_terr(new)))
    said = terr.set_index("territory")
    rows = []
    for intent in ("push", "cut back"):
        for t in plan[intent]:
            for name, before, after in periods:
                rest_before, rest_after = before.drop(t, errors="ignore").sum(), after.drop(t, errors="ignore").sum()
                rows.append({"territory": t, "plan": intent, "period": name,
                             "change_pct": round(float((after.get(t, 0) / before.get(t, 0) - 1) * 100), 1),
                             "rest_change_pct": round(float((rest_after / rest_before - 1) * 100), 1),
                             "rq1_then": said.loc[t, "segment"] if t in said.index else "not scored"})
    return rows


# ---- Entry point -------------------------------------------
def run(df: pd.DataFrame, latest: pd.DataFrame = None) -> dict:
    """`df`: complete fiscal years. `latest`: every line, including a part-year, for the forward test."""
    latest = df if latest is None else latest
    rng = np.random.default_rng(cfg.RANDOM_SEED)
    scored = {o: score_as_of(df, o) for o, _ in cfg.RQ4_CASES}
    cases, pick_frames, territories = [], [], []
    for origin, target in cfg.RQ4_CASES:
        cells, terr = scored[origin]
        result, frame = compare_case(cells, df, origin, target, rng)
        cases.append(result)
        pick_frames.append(frame)
        territories.append(territory_case(terr, df, origin, target))
    forward, frame = forward_case(df, latest, rng)
    if frame is not None:
        pick_frames.append(frame)
    main = next(c for c in cases if (c["origin"], c["target"]) == cfg.RQ4_PRIMARY)
    metrics = {
        "fy": cfg.RQ4_PRIMARY[1], "primary_case": list(cfg.RQ4_PRIMARY),
        "verdict": verdict(main["methods"]["system"]["hit_rate"], main["methods"]["baseline_biggest_sales"]["hit_rate"],
                           main["p_system_vs_random_eligible"]),
        "baseline_status": "Stand-in rule, not the distributor's documented process",
        "cases": cases, "territories": territories, "forward": forward,
        "client_plan": client_plan(latest, df, scored[cfg.RQ4_CLIENT_PLAN["decided_at"]][1]),
    }
    params = {k: getattr(cfg, k) for k in ["RQ4_CASES", "RQ4_PRIMARY", "RQ4_CLIENT_PLAN", "RQ3_SHORTLIST", "RQ3_POINTS",
                                           "PERMUTATION_DRAWS", "RANDOM_SEED"]}
    return {"picks": pd.concat(pick_frames, ignore_index=True), "metrics": metrics, "params": params,
            "fys": sorted(df["fy"].unique())}
