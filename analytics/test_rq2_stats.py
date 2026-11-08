# Self-check for the RQ2 class choice and significance test:  python test_rq2_stats.py
import numpy as np
import pandas as pd

import rq2_category as rq2


class FakeMoveModel:
    classes_ = np.array([-1, 0, 1])

    def __init__(self, proba):
        self.proba = np.array(proba)

    def predict_proba(self, X):
        return self.proba


# Z with P(steadier)=.40, P(stay)=.35, P(more erratic)=.25: class Z is .60, so it stays Z
X = pd.DataFrame({"xyz_now": [2, 1, 0]})
model = FakeMoveModel([[.40, .35, .25], [.40, .35, .25], [.40, .35, .25]])
labels, proba = rq2.predict_class(model, X, "xyz", "change")
assert list(labels) == ["Z", "X", "X"], labels     # Y moves down; X keeps the "steadier" mass (.75)
assert abs(proba[0, 2] - .60) < 1e-9 and abs(proba.sum(axis=1) - 1).max() < 1e-9
# An exact tie stays put
tie = rq2.predict_class(FakeMoveModel([[.5, .5, 0]]), pd.DataFrame({"abc_now": [1]}), "abc", "change")[0]
assert tie[0] == "B", tie

# Significance: a method identical to the baseline shows no difference at all
p = pd.DataFrame({"product": ["a", "a", "b", "b"], "abc_next": list("ABAB"), "xyz_next": list("XYXY"),
                  "abc": list("AAAB"), "xyz": list("XXXY"), "m_abc": list("AAAB"), "m_xyz": list("XXXY")})
sig = rq2.significance_vs_persistence(p, "m", np.random.default_rng(0))["class"]
assert sig["product_level_p"] == 1.0 and sig["accuracy_diff_ci95"] == [0.0, 0.0], sig
# 90-day baseline: sales in the newest and oldest 30-day windows, none in the middle. The empty
# window must count as zero at any quantity (CV 0.71 = Y), not vanish as float noise (CV 0 = X).
anchor = pd.Timestamp("2021-03-31")
sales = pd.DataFrame([{"fy": "2020-21", "line_type": "sale", "product": f"p{q}", "qty": q, "rate": 10.0,
                       "bill_date": anchor - pd.Timedelta(days=d)}
                      for q in range(1, 200) for d in (0, 70)])
page = rq2.page_method_classes(sales, "2020-21", pd.Index(sales["product"].unique()))
assert (page["page_xyz"] == "Y").all(), page["page_xyz"].value_counts()
print("rq2 stats ok")
