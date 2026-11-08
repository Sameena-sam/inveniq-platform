# Self-check for the expansion scoring:  python test_rq3.py
import pandas as pd

import config as cfg
import rq3_expansion as rq3


def terr_row(code, status="Active", tseg="Steady", location=None):
    return {"loc_code": code, "status": status, "location": location or code, "territory": code, "hq": "H",
            "segment": tseg, "growth_cagr_pct": -5.0, "margin_pct": 60.0, "score_growth": 50.0, "score_margin": 100.0}


terr = pd.DataFrame([terr_row("HUB", location=cfg.HUB_LOCATION), terr_row("T1", tseg="Untapped potential"),
                     terr_row("T2"), terr_row("T3"), terr_row("GONE", status="Lapsed")]).set_index("loc_code")
segs = pd.DataFrame({"seg_class": ["AX", "CZ"], "label": ["High value, stable", "Marginal, erratic"]},
                    index=pd.Index(["Cardiac", "Other"], name="segment"))


def sale(code, seg, amount):
    return {"fy": "y", "loc_code": code, "segment": seg, "amount": amount}


df = pd.DataFrame([sale("HUB", "Cardiac", 9e6),
                   sale("T1", "Cardiac", 100_000), sale("T1", "Other", 900_000),     # Cardiac 10% of T1
                   sale("T2", "Cardiac", 600_000), sale("T2", "Other", 400_000),     # 60%
                   sale("T3", "Cardiac", 600_000), sale("T3", "Other", 400_000)])
c = rq3.score_cells(df, terr, segs, "y").set_index(["territory", "segment"])

assert set(c.index.get_level_values(0)) == {"T1", "T2", "T3"}       # no hub, no lapsed
x = c.loc[("T1", "Cardiac")]
assert abs(x["peer_share_pct"] - 60) < 1e-9 and abs(x["gap_value"] - 500_000) < 1e-6
assert x["f_gap"] == 1.0 and x["points_gap"] == cfg.RQ3_POINTS["gap"]    # capped
assert x["f_underserved"] == 1 and x["f_category"] == 1
assert x["eligible"] and x["rank"] == 1
assert not c.loc[("T2", "Cardiac"), "eligible"] and c.loc[("T2", "Cardiac"), "gap_value"] == 0
assert abs(x["score"] - sum(x[f"points_{p}"] for p in rq3.PARTS)) < 1e-9
assert sum(cfg.RQ3_POINTS.values()) == 100


# Action plan: who to approach, what to offer, how far existing customers could go
def line(fy, code, customer, seg, product, amount):
    return {"fy": fy, "loc_code": code, "customer": customer, "segment": seg, "product": product, "amount": amount}


sold = pd.DataFrame([
    line("y", "T1", "Big", "Other", "O1", 900_000),
    line("y", "T1", "Past", "Other", "O1", 50_000), line("x", "T1", "Past", "Cardiac", "C1", 70_000),   # used to buy it
    line("y", "T1", "Already", "Cardiac", "C1", 100_000),
    line("y", "T1", "Unknown", "Other", "O1", 800_000),                 # no customer name to approach
    line("y", "T1", "Refunded", "Other", "O1", -5_000),                 # no real sales this year
    line("y", "T2", "D", "Cardiac", "C2", 200_000), line("y", "T2", "E", "Cardiac", "C2", 400_000),
    line("y", "HUB", "H", "Cardiac", "C3", 9_000_000),                  # the hub is no guide to a typical buyer
])
cells = pd.DataFrame({"location_code": ["T1", "T2"], "segment": ["Cardiac", "Cardiac"],
                      "gap_value": [500_000.0, 1_000.0], "eligible": [True, False]})
plans = rq3.action_plans(sold, "y", cells)
assert list(plans) == [("T1", "Cardiac")]                               # recommended pairs only
p = plans[("T1", "Cardiac")]
assert [(x["name"], x["used_to_buy"]) for x in p["customers"]] == [("Past", True), ("Big", False)]
assert p["typical_spend"] == 300_000                                    # median of T2's buyers, not the hub's
assert p["from_existing"] == 500_000 and p["from_new"] == 0             # 2 x 300k, limited to the gap
assert p["products"] == [{"name": "C3", "new_here": True}, {"name": "C2", "new_here": True},
                         {"name": "C1", "new_here": False}]             # not sold here yet comes first, then by sales
print("rq3 ok")
