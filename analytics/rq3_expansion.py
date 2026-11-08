# =============================================================
# InvenIQ Sales Intelligence — RQ3 expansion decision support
#
# Combines the regional results (RQ1) and the category results (RQ2) into a
# territory x category opportunity score out of 100, with a plain reason for
# every recommendation:
#
#   1. room to grow: a category's share of this territory's sales vs its usual
#      share in the other territories, turned into rupees (capped)
#   2. category value and stability (RQ2 segment class)
#   3. the territory is under-served overall (RQ1 "Untapped potential")
#   4. the territory's sales trend and 5. its margin (RQ1 percentiles)
#
# The hub is left out: RQ1 found it is not a peer of the others, so its share
# gaps mean little and its size would swamp the ranking. Lapsed territories
# have no current sales to compare, so they get a separate win-back list.
# score_cells() takes the year's RQ1 and RQ2 tables, so past years can be
# scored the same way for the RQ4 back-test.
# =============================================================
import numpy as np
import pandas as pd

import config as cfg
import data_prep
from rq1_regional import fmt_inr, join_words

VALUE_WEIGHT = {"A": 1.0, "B": 0.6, "C": 0.2}       # category value class
STABLE_WEIGHT = {"X": 1.0, "Y": 0.5, "Z": 0.0}      # category stability class
PARTS = ["gap", "category", "underserved", "momentum", "margin"]


def score_cells(df: pd.DataFrame, terr: pd.DataFrame, segs: pd.DataFrame, fy: str,
                gap_cap: float = None) -> pd.DataFrame:
    """One row per (active territory except the hub) x segment, with each score part
    as a 0-1 fraction and in points. `terr` is RQ1's table, `segs` RQ2's, both for `fy`."""
    gap_cap = gap_cap or cfg.RQ3_GAP_CAP
    active = terr[(terr["status"] == "Active") & (terr["location"] != cfg.HUB_LOCATION)]
    sales = (df[df["fy"] == fy].groupby(["loc_code", "segment"])["amount"].sum().unstack(fill_value=0)
             .clip(lower=0).reindex(index=active.index, columns=segs.index, fill_value=0))
    totals = sales.sum(axis=1)
    share = sales.div(totals, axis=0)

    rows = []
    for u in active.index:
        peers = share.drop(index=u).median()      # the usual share elsewhere; a median resists one odd territory
        t = active.loc[u]
        for s in segs.index:
            gap = max(0.0, (peers[s] - share.loc[u, s]) * totals[u])
            cls = segs.loc[s, "seg_class"]
            rows.append({
                "location_code": u, "territory": t["territory"], "hq": t["hq"], "segment": s, "fy": fy,
                "sales": sales.loc[u, s], "share_pct": share.loc[u, s] * 100, "peer_share_pct": peers[s] * 100,
                "gap_value": gap, "seg_class": cls, "seg_label": segs.loc[s, "label"],
                "territory_status": t["segment"], "growth_cagr_pct": t["growth_cagr_pct"], "margin_pct": t["margin_pct"],
                "score_growth": t["score_growth"], "score_margin": t["score_margin"],
                "f_gap": min(gap / gap_cap, 1.0),
                "f_category": 0.6 * VALUE_WEIGHT[cls[0]] + 0.4 * STABLE_WEIGHT[cls[1]],
                "f_underserved": float(t["segment"] == "Untapped potential"),
                "f_momentum": t["score_growth"] / 100,
                "f_margin": t["score_margin"] / 100,
            })
    c = pd.DataFrame(rows)
    for p in PARTS:
        c[f"points_{p}"] = c[f"f_{p}"] * cfg.RQ3_POINTS[p]
    c["score"] = c[[f"points_{p}" for p in PARTS]].sum(axis=1)
    c["eligible"] = c["gap_value"] >= cfg.RQ3_MIN_GAP
    c["rank"] = (c[c["eligible"]].sort_values(["score", "gap_value"], ascending=False)
                 .assign(r=lambda x: range(1, len(x) + 1))["r"])
    return c


def _top_products(cur: pd.DataFrame) -> pd.DataFrame:
    ranked = cur.groupby(["segment", "product"])["amount"].sum()
    ranked = ranked[ranked > 0].sort_values(ascending=False)
    return ranked.groupby(level="segment").head(cfg.RQ3_TOP_PRODUCTS).reset_index()


def unsold_top_products(df: pd.DataFrame, fy: str) -> dict:
    """(territory, segment) -> the segment's best-selling products not sold there this year."""
    cur = df[df["fy"] == fy]
    top = _top_products(cur)
    by_cell = cur.groupby(["loc_code", "product"])["amount"].sum()
    sold = set(by_cell[by_cell > 0].index)
    out = {}
    for u in cur["loc_code"].unique():
        for s, sub in top.groupby("segment"):
            out[(u, s)] = [p for p in sub["product"] if (u, p) not in sold]
    return out


def action_plans(df: pd.DataFrame, fy: str, cells: pd.DataFrame) -> dict:
    """(territory, segment) -> what to offer, which existing customers to approach, and how much of
    the gap they could cover if each spent what a typical buyer of the category spends elsewhere."""
    top = _top_products(df[df["fy"] == fy]).groupby("segment")["product"].apply(list)
    unsold = unsold_top_products(df, fy)
    named = data_prep.contactable(df)
    keys = ["loc_code", "customer", "segment"]
    bought = named[named["fy"] == fy].groupby(keys)["amount"].sum()
    bought = bought[bought > 0]
    earlier = named[named["fy"] < fy].groupby(keys)["amount"].sum()
    used = set(earlier[earlier > 0].index)
    scored = bought[bought.index.get_level_values("loc_code").isin(cells["location_code"])]

    plans = {}
    for r in cells[cells["eligible"]].itertuples():
        u, s = r.location_code, r.segment
        here = scored[scored.index.get_level_values("loc_code") == u]
        spend = here.groupby("customer").sum()
        buying = here[here.index.get_level_values("segment") == s].index.get_level_values("customer")
        customers = [{"name": c, "spend": round(float(v), 2), "used_to_buy": (u, c, s) in used}
                     for c, v in spend.drop(buying).items()]
        customers.sort(key=lambda x: (not x["used_to_buy"], -x["spend"]))
        elsewhere = scored[(scored.index.get_level_values("loc_code") != u)
                           & (scored.index.get_level_values("segment") == s)]
        typical = float(elsewhere.median()) if len(elsewhere) else 0.0
        existing = min(float(r.gap_value), len(customers) * typical)
        new_here = set(unsold.get((u, s), []))
        products = sorted(top.get(s, []), key=lambda p: p not in new_here)      # not sold here yet comes first
        plans[(u, s)] = {"products": [{"name": p, "new_here": p in new_here} for p in products],
                         "customers": customers, "typical_spend": round(typical, 2),
                         "from_existing": round(existing, 2), "from_new": round(float(r.gap_value) - existing, 2)}
    return plans


def cell_reasons(r, unsold: list) -> list:
    t, s = r["territory"], r["segment"]
    first = (f"{t} has no {s} sales this year" if r["sales"] <= 0
             else f"{s} is {r['share_pct']:.1f}% of {t}'s sales")
    out = [f"{first}; in other territories it is usually {r['peer_share_pct']:.1f}%.",
           f"Matching them would add about {fmt_inr(r['gap_value'])} a year.",
           f"{s} is a {r['seg_label'].lower()} category for the company."]
    if unsold:
        out.append(f"Best-selling {s} products not sold here yet: {join_words(unsold)}.")
    if r["territory_status"] == "Untapped potential":
        out.append(f"{t} also has room to grow overall (see Regional Performance).")
    g = r["growth_cagr_pct"]
    pace = ("growing" if g >= 0 else "falling") + f" {abs(g):.0f}% a year"
    compare = "better than most territories" if r["score_growth"] >= 50 else "worse than most territories"
    out.append(f"{t}'s sales are {pace}, {compare}.")
    return out


def win_back_list(df: pd.DataFrame, terr: pd.DataFrame) -> pd.DataFrame:
    """Lapsed territories that sold enough to matter, with what they used to buy."""
    rows = []
    lapsed = terr[(terr["status"] == "Lapsed") & (terr["peak_revenue"] >= cfg.WATCH_MIN_REVENUE)]
    for u, t in lapsed.sort_values("peak_revenue", ascending=False).iterrows():
        was = df[(df["loc_code"] == u) & (df["fy"] == t["peak_fy"])].groupby("segment")["amount"].sum()
        top = was[was > 0].sort_values(ascending=False).head(3)
        bought = ", ".join(f"{s} ({fmt_inr(v)})" for s, v in top.items())
        chose = t["territory"] in cfg.STOPPED_BY_CHOICE
        why = "You chose to stop selling here" if chose else "The sales rep left" if t["territory"] in cfg.REP_LEFT else ""
        rows.append({"location_code": u, "territory": t["territory"], "hq": t["hq"], "best_fy": t["peak_fy"],
                     "best_sales": t["peak_revenue"], "last_sale": t["last_sale"], "bought": bought,
                     "why_stopped": why, "by_choice": chose})
    return pd.DataFrame(rows)


def stability_checks(c: pd.DataFrame, df: pd.DataFrame, terr: pd.DataFrame, segs: pd.DataFrame, fy: str) -> tuple:
    """How much the shortlist depends on the chosen points and cap, and how it differs
    from simply backing the biggest existing sales."""
    e = c[c["eligible"]].reset_index(drop=True)
    n = cfg.RQ3_SHORTLIST
    base = set(e.nsmallest(n, "rank").index)
    F = e[[f"f_{p}" for p in PARTS]].to_numpy()
    rng = np.random.default_rng(cfg.RANDOM_SEED)
    W = rng.dirichlet(np.ones(len(PARTS)), size=cfg.SENSITIVITY_DRAWS)
    top = (-(F @ W.T)).argsort(axis=0)[:n]              # shortlist under each random weighting
    held = np.zeros(len(e))
    for d in range(top.shape[1]):
        held[top[:, d]] += 1
    overlap = [len(base & set(top[:, d])) / n for d in range(top.shape[1])]

    def cap_overlap(mult):
        o = score_cells(df, terr, segs, fy, gap_cap=cfg.RQ3_GAP_CAP * mult)
        o = o[o["eligible"]].nsmallest(n, "rank")
        return round(len(set(zip(o["territory"], o["segment"])) & _pairs(e, base)) / n, 3)

    biggest = set(e.nlargest(n, "sales").index)
    metrics = {
        "shortlist_size": n, "weight_draws": cfg.SENSITIVITY_DRAWS,
        "shortlist_overlap_random_weights_mean": round(float(np.mean(overlap)), 3),
        "shortlist_overlap_random_weights_p5": round(float(np.percentile(overlap, 5)), 3),
        "shortlist_overlap_cap_half": cap_overlap(0.5),
        "shortlist_overlap_cap_double": cap_overlap(2.0),
        "overlap_with_biggest_existing_sales": round(len(base & biggest) / n, 3),
    }
    stability = pd.Series(held / top.shape[1] * 100, index=pd.MultiIndex.from_frame(e[["territory", "segment"]]))
    return metrics, stability


def _pairs(e: pd.DataFrame, idx: set) -> set:
    return set(zip(e.loc[list(idx), "territory"], e.loc[list(idx), "segment"]))


# ---- Entry point -------------------------------------------
def run(df: pd.DataFrame, rq1: dict, rq2: dict) -> dict:
    fy = rq1["fys"][-1]
    terr, segs = rq1["territories"], rq2["segments"]
    c = score_cells(df, terr, segs, fy)
    unsold = unsold_top_products(df, fy)
    c["reasons"] = [cell_reasons(r, unsold.get((r["location_code"], r["segment"]), [])) if r["eligible"] else []
                    for _, r in c.iterrows()]
    checks, stability = stability_checks(c, df, terr, segs, fy)
    c["shortlist_stability_pct"] = [stability.get((r.territory, r.segment), np.nan) for r in c.itertuples()]
    wins = win_back_list(df, terr)
    plans = action_plans(df, fy, c)
    c["action_plan"] = [plans.get((r.location_code, r.segment), {}) for r in c.itertuples()]

    short = c[c["eligible"]].nsmallest(cfg.RQ3_SHORTLIST, "rank")
    short_plans = short["action_plan"]
    metrics = {
        "fy": fy, "cells_scored": int(len(c)), "cells_eligible": int(c["eligible"].sum()),
        "shortlist_territories": short["territory"].value_counts().to_dict(),
        "shortlist_segments": short["segment"].value_counts().to_dict(),
        "shortlist_gap_total": round(float(short["gap_value"].sum()), 2),
        "shortlist_customers_to_approach": int(sum(len(p["customers"]) for p in short_plans)),
        "shortlist_from_existing_customers": round(sum(p["from_existing"] for p in short_plans), 2),
        "shortlist_from_new_customers": round(sum(p["from_new"] for p in short_plans), 2),
        "win_back_territories": int(len(wins)),
        "stability": checks,
    }
    params = {k: getattr(cfg, k) for k in ["RQ3_POINTS", "RQ3_GAP_CAP", "RQ3_MIN_GAP", "RQ3_SHORTLIST",
                                           "RQ3_TOP_PRODUCTS", "HUB_LOCATION", "SENSITIVITY_DRAWS", "RANDOM_SEED"]}
    return {"cells": c, "win_back": wins, "metrics": metrics, "params": params, "fys": rq1["fys"]}
