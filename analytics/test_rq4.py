# Self-check for the back-test:  python test_rq4.py   (run the pipeline once first)
import os

import numpy as np
import pandas as pd

import config as cfg
import rq4_backtest as rq4

# Hit = beat the pool's overall movement; here the pool halves
cells = pd.DataFrame({"location_code": ["a", "b", "c"], "segment": ["S", "S", "S"], "sales": [100.0, 100.0, 0.0],
                      "eligible": [True, True, True], "rank": [1, 2, 3], "gap_value": [10.0, 10.0, 10.0]})
later = pd.DataFrame({"fy": "y", "loc_code": ["a", "b", "c"], "segment": ["S", "S", "S"], "amount": [80.0, 10.0, 10.0]})
c = rq4.add_outcomes(cells, later, "y").set_index("location_code")
assert list(c["hit"]) == [True, False, True] and abs(c.loc["a", "excess"] - 30) < 1e-9    # 80 vs 50; "c" sold from nothing

# Against a different base period (the same months a year earlier): pool goes 250 -> 100
base = pd.Series({("a", "S"): 50.0, ("b", "S"): 200.0})
c = rq4.add_outcomes(cells, later, "y", base).set_index("location_code")
assert list(c["sales_base"]) == [50, 200, 0] and list(c["hit"]) == [True, False, True]      # a: 80 vs 20 expected
assert rq4.pick_scores(c.reset_index())["sales_before"] == 250

# Verdict follows the written criteria
assert rq4.verdict(0.6, 0.4, 0.01) == "Pass" and rq4.verdict(0.6, 0.4, 0.2) == "Partial"
assert rq4.verdict(0.4, 0.4, 0.01) == "Fail"

# Random picks from an all-hit pool always hit
assert (rq4.random_hit_rates(np.ones(20), 5, np.random.default_rng(0)) == 1).all()

# No leakage: changing every later year must not change the scores at the decision point
path = os.path.join(cfg.OUTPUT_DIR, "sales_clean.pkl")
if os.path.exists(path):
    df = pd.read_pickle(path)
    origin = cfg.RQ4_PRIMARY[0]
    changed = df.copy()
    changed.loc[changed["fy"] > origin, ["amount", "margin_value", "qty"]] *= 3
    a, b = rq4.score_as_of(df, origin)[0], rq4.score_as_of(changed, origin)[0]
    assert a.equals(b), "later sales changed the scores"
print("rq4 ok")
