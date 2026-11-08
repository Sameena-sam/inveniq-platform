# Self-check for label cleaning:  python test_data_prep.py
import pandas as pd

import data_prep as d

row = {c: 1 for c in d.RENAME}
row.update({"Year": "2020-21", "Bill Date": "2020-04-01", "Category": "PARTY", "Customer Name": "A  B",
            "Product Name": "P", "Segmant": "Suppliment", "HQ": "H", "Location": None})
df, rep = d.clean_sales(pd.DataFrame([row]))
assert df.loc[0, "location"] == "Unknown", df.loc[0, "location"]    # not the text "nan"
assert df.loc[0, "customer"] == "A B" and df.loc[0, "segment"] == "Supplements"
assert any(s["step"] == "Label blank location as Unknown" for s in rep["steps"])

# Later exports: category from the bill number, numbers written as text, HQ capitals
assert list(d.category_from_bill(pd.Series(["26-27D01000001", "26-27SR0001", "P01", " SAM01", "NG1", "X9"]))) == \
    ["DIRECT", "RETURN", "PARTY", "SAMPLE", "PARTY", "OTHER"]
row.update({"Category": "DIRECT", "HQ": "SALEM", "Sales Amount": "  1,01,200.00", "PP VALUE": "                -  ",
            "Margin Value": "n/a", "Location": "SALEM - HQ"})
df, rep = d.clean_sales(pd.DataFrame([row]))
assert df.loc[0, "amount"] == 101200 and df.loc[0, "pp_value"] == 0 and df.loc[0, "hq"] == "Salem"
assert rep["checks"]["unreadable_numbers_set_to_zero"] == 1          # "n/a" is reported, not hidden
assert any(s["step"].startswith("Read numbers written as text") and s["rows_affected"] == 2 for s in rep["steps"])

# Part-years and files that overlap
lines = pd.DataFrame({"fy": ["2025-26", "2026-27", "2026-27"], "bill_date": pd.to_datetime(["2026-03-30", "2026-04-01", "2026-10-31"]),
                      "source": ["main.xlsx", "apr-oct.xlsx", "apr-oct.xlsx"]})
assert d.complete_fys(lines) == ["2025-26"]
assert d.overlapping_sources(lines) == []
again = pd.concat([lines, pd.DataFrame({"fy": ["2026-27"], "bill_date": pd.to_datetime(["2026-05-01"]), "source": ["apr-dec.xlsx"]})])
assert d.overlapping_sources(again) == [("apr-oct.xlsx", "apr-dec.xlsx")]
print("data prep ok")
