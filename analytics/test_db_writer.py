# Self-check, needs MySQL running:  python test_db_writer.py
# A load that fails part-way must leave the existing results untouched.
import pandas as pd
import pymysql

import config as cfg
import db_writer


def state():
    conn = pymysql.connect(**cfg.DB)
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) FROM sa_territory_scores")
        rows = cur.fetchone()[0]
        cur.execute("SELECT COUNT(*) FROM sa_runs")
        runs = cur.fetchone()[0]
        cur.execute("SELECT COUNT(*) FROM information_schema.tables "
                    "WHERE table_schema = DATABASE() AND table_name LIKE 'sa\\_%\\_\\_new'")
        leftovers = cur.fetchone()[0]
    conn.close()
    return rows, runs, leftovers


before = state()
bad = {"territories": pd.DataFrame({"x": [1]}), "territory_yearly": pd.DataFrame(), "hq": pd.DataFrame()}
try:
    db_writer.write_all({"rq1": bad}, "test", {"data_from": None, "data_to": None})
except KeyError:
    pass
else:
    raise AssertionError("the bad load should have failed")
assert state() == before and before[2] == 0, (before, state())
print("db writer ok")
