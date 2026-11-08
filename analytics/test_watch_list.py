# Self-check for the client watch list:  python test_watch_list.py
import pandas as pd

import rq2_category as rq2


def row(product, fy, abc, revenue):
    return {"product": product, "sku": product, "segment": "S", "fy": fy, "abc": abc, "revenue": revenue}


feats = pd.DataFrame([
    row("steady", "y1", "A", 500_000), row("steady", "y2", "A", 520_000),     # no real change
    row("mover", "y1", "B", 200_000), row("mover", "y2", "A", 250_000),       # moved up a group
    row("drop", "y1", "B", 400_000), row("drop", "y2", "B", 100_000),         # sales fell 75%
    row("tiny", "y1", "C", 1_000), row("tiny", "y2", "C", 5_000),             # sharp, but too small
    row("gone", "y1", "A", 300_000),                                          # stopped selling
    row("tiny_gone", "y1", "C", 900),                                         # stopped, too small
    row("fresh", "y2", "B", 150_000),                                         # new this year
])
w = rq2.watch_list(feats, ["y1", "y2"]).set_index("product")
assert set(w.index) == {"mover", "drop", "gone", "fresh"}, set(w.index)
assert w.loc["mover", "kind"] == "Moved up"
assert w.loc["drop", "kind"] == "Sales dropped" and "down 75%" in w.loc["drop", "detail"]
assert w.loc["gone", "kind"] == "Stopped selling" and w.loc["gone", "revenue"] == 0
assert w.loc["fresh", "kind"] == "New this year"
assert w.index[0] == "drop"      # biggest rupee change first
print("watch list ok")
