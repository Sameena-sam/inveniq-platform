# Self-check for the Sales Action Plan:  python test_sales_actions.py
import numpy as np
import pandas as pd

import config as cfg
import data_prep
import sales_actions as sa


def line(customer, date, amount, segment="S1", loc="L1", kind="sale"):
    return {"customer": customer, "bill_date": pd.Timestamp(date), "amount": amount, "segment": segment,
            "loc_code": loc, "territory": loc + " town", "hq": "H", "line_type": kind, "fy": "y"}


as_of = pd.Timestamp("2025-03-31")      # latest months: Oct 2024 to Mar 2025; a year earlier: Oct 2023 to Mar 2024
big = cfg.ACTION_MIN_SALES
df = pd.DataFrame([
    line("Falling", "2024-01-15", big), line("Falling", "2024-01-15", big, "S2"), line("Falling", "2025-01-15", big),
    line("Steady", "2024-01-15", big), line("Steady", "2025-01-15", big * 0.9),
    line("Small", "2024-01-15", big / 2), line("Small", "2025-01-15", 0.0),
    line("Gone", "2024-03-31", big),                       # last day of the window counts
    line("OutsideWindow", "2024-04-01", big),              # first day after the window does not
    line("Falling", "2025-04-01", big * 9),                # after the date being judged
])
a = sa.falling_accounts(df, as_of).set_index("customer")
assert set(a.index) == {"Falling", "Gone"}, list(a.index)
assert a.loc["Falling", "change_value"] == -big and a.loc["Falling", "change_pct"] == -50
assert a.loc["Gone", "change_pct"] == -100
assert a.loc["Falling", "falls"] == [{"segment": "S2", "before": big, "now": 0.0}]     # S1 did not fall


def months(customer, last, n=8):
    return [line(customer, d, 1000.0) for d in pd.date_range(end=last, periods=n, freq="30D")]


df = pd.DataFrame(months("Quiet", "2025-01-01") + months("Regular", "2025-03-20") + months("Few", "2025-01-01", 3)
                  + months("LongGone", "2023-01-01") + months("Flagged elsewhere", "2025-01-01")
                  + [line("Quiet", "2025-03-30", -500.0, kind="return")])       # a return is not an order
q = sa.quiet_customers(df, as_of, skip=["Flagged elsewhere"]).set_index("customer")
assert list(q.index) == ["Quiet"], list(q.index)
assert q.loc["Quiet", "days_since"] == 89 and q.loc["Quiet", "usual_gap_days"] == 30

# A customer who always orders every 200 days is not late at 100 days
df = pd.DataFrame([line("Slow", d, 1000.0) for d in pd.date_range(end="2024-12-21", periods=5, freq="200D")])
assert sa.quiet_customers(df, as_of).empty

# Results: too recent waits; otherwise the customer vs the unmarked customers over the same dates
df = pd.DataFrame([line("A", "2024-07-01", 100.0), line("A", "2025-07-01", 300.0),
                   line("Other", "2024-07-01", 100.0), line("Other", "2025-07-01", 150.0),
                   line("B", "2025-07-01", 50.0, "S2", "L2"), line("B", "2025-07-02", 40.0, "S1", "L2"),
                   line("Other", "2025-08-01", 0.0)])
log = pd.DataFrame([{"id": 1, "kind": "account", "customer": "A", "location_code": "L1", "segment": "", "done_on": "2025-06-01"},
                    {"id": 2, "kind": "quiet", "customer": "A", "location_code": "L1", "segment": "", "done_on": "2025-07-20"},
                    {"id": 3, "kind": "expansion", "customer": "B", "location_code": "L2", "segment": "S2", "done_on": "2025-06-01"}])
r = sa.action_results(df, log).set_index("action_id")
assert r.loc[1, "status"] == "measured" and (r.loc[1, "sales_before"], r.loc[1, "sales_after"]) == (100, 300)
assert (r.loc[1, "others_before"], r.loc[1, "others_after"]) == (100, 150)     # marked customers are not "others"
assert r.loc[2, "status"] == "waiting" and r.loc[2, "days_after"] == 12
assert r.loc[3, "sales_after"] == 50                                           # expansion counts only that category
assert sa.action_results(df, log.iloc[0:0]).empty

# Labels are nobody to contact; spellings of one name join under the most used one
names = ["Cash Bill", "CASH BILL", "SAMPLE SALEM", "Dr X (SAMPLE)", "Unknown", "Sri  Ram medicals", "SRI RAM MEDICALS", "SRI RAM MEDICALS", "Real"]
kept = data_prep.contactable(pd.DataFrame({"customer": names}))
assert list(kept["customer"]) == ["SRI RAM MEDICALS", "SRI RAM MEDICALS", "SRI RAM MEDICALS", "Real"], list(kept["customer"])

# Interval: a clear difference holds up, no difference does not
rows = pd.DataFrame({"customer": range(200), "flagged": [i < 100 for i in range(200)], "hit": [i < 90 or 100 <= i < 110 for i in range(200)]})
assert sa._gap_with_interval(rows, "hit", np.random.default_rng(0))["holds_up"]
rows["hit"] = [i % 2 == 0 for i in range(200)]
assert not sa._gap_with_interval(rows, "hit", np.random.default_rng(0))["holds_up"]
print("sales actions ok")
