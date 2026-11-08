# =============================================================
# InvenIQ Sales Intelligence — RQ2 product-category module
#
# Identifies products (and therapeutic segments) as high-value and stable
# versus marginal and erratic, and tests whether interpretable machine
# learning beats rule-of-thumb prioritisation:
#
#   1. ABC-XYZ per product per fiscal year (ABC: cumulative revenue share;
#      XYZ: coefficient of variation of the 12 monthly demand quantities)
#   2. features of year t -> class of year t+1. The classifier predicts next
#      year's class; re-deriving this year's class from the very numbers that
#      define it would be circular.
#   3. baselines: "same class as last year" and InvenIQ's existing ABC/XYZ
#      page logic (last 90 days of the year)
#   4. decision tree, logistic regression, random forest, gradient boosting,
#      each in two formulations:
#        - direct: predict next year's class from scratch
#        - change: start from this year's class and predict whether it moves
#          one step up, stays, or moves one step down (classes are sticky, so
#          this anchors the model on the rule-of-thumb and learns only the moves)
#      expanding-window test on the last target years (training only on
#      earlier years), tuned by stratified k-fold inside each training set
#   5. the change-formulation decision tree writes a next-year outlook to the
#      thesis files only: it did not beat "same class as last year", so the
#      client's page does not show it
#   6. segment (category) profile: value and stability of each segment
#   7. client watch list: real changes between the last two fiscal years
# =============================================================
import numpy as np
import pandas as pd
from scipy.stats import binomtest
from sklearn.ensemble import HistGradientBoostingClassifier, RandomForestClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import accuracy_score, confusion_matrix, f1_score
from sklearn.model_selection import GridSearchCV, StratifiedGroupKFold
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import StandardScaler
from sklearn.tree import DecisionTreeClassifier, export_text

import config as cfg
from rq1_regional import fmt_inr, join_words

CLASSES = {"abc": ["A", "B", "C"], "xyz": ["X", "Y", "Z"]}
MODELS = ["decision_tree", "logistic_regression", "random_forest", "gradient_boosting"]
FORMULATIONS = ["direct", "change"]
BASELINES = ["persistence", "page_method"]
METHODS = BASELINES + [f"{m}_{f}" for f in FORMULATIONS for m in MODELS]
MODEL_LABELS = {"decision_tree": "Decision tree", "logistic_regression": "Logistic regression",
                "random_forest": "Random forest", "gradient_boosting": "Gradient boosting"}
METHOD_LABELS = {
    "persistence": "Same class as last year",
    "page_method": "InvenIQ ABC/XYZ page (90-day window)",
    **{f"{m}_{f}": f"{MODEL_LABELS[m]} ({'predicts the class' if f == 'direct' else 'predicts the move'})"
       for f in FORMULATIONS for m in MODELS},
}
# Served: interpretability is a design requirement (a single tree whose path can
# be read), and the change formulation keeps the rule-of-thumb as its default.
SERVED_MODEL, SERVED_FORM = "decision_tree", "change"
ABC_WORDS = {"A": "Top seller", "B": "Mid seller", "C": "Low seller"}
DEMAND_WORDS = {"X": "Steady demand: little change from month to month",
                "Y": "Demand varies from month to month",
                "Z": "Irregular demand: big swings between months"}
MOVE_WORDS = {"abc": {-1: "rise", 0: "stay", 1: "fall"},
              "xyz": {-1: "become steadier", 0: "stay", 1: "become more erratic"}}

# feature column -> (plain-language name, display format)
FEATURES = {
    "rev_share_pct":          ("revenue share", "pct2"),
    "cum_share_pct":          ("cumulative revenue share", "pct1"),
    "abc_now":                ("current value class", "abc"),
    "margin_pct":             ("margin", "pct1"),
    "rev_growth_pct":         ("revenue growth on last year", "spct"),
    "is_new":                 ("first year on sale", "flag"),
    "monthly_trend_pct":      ("demand trend through the year", "trend"),
    "cv":                     ("demand variability (CV)", "num2"),
    "cv_prev":                ("last year's demand variability (CV)", "num2"),
    "xyz_now":                ("current variability class", "xyz"),
    "months_active":          ("months with sales", "int"),
    "max_month_share_pct":    ("biggest month's share of demand", "pct1"),
    "customers":              ("customers", "int"),
    "territories":            ("territories", "int"),
    "top_customer_share_pct": ("top customer's share of revenue", "pct1"),
    "return_rate_pct":        ("returns", "pct1"),
    "age_years":              ("years on sale", "int"),
}


# ---- Labels ------------------------------------------------
def abc_labels(values: pd.Series) -> tuple:
    """Pareto classes, as on the InvenIQ page: a product is A while the
    cumulative share up to and including it is within the first cut-off,
    B within the second, else C. Returns (labels, cumulative share %)."""
    v = values.sort_values(ascending=False)
    pos = v.clip(lower=0)
    cum = pos.cumsum() / pos.sum() * 100
    lab = pd.Series(np.where(cum <= cfg.ABC_CUTS[0], "A",
                             np.where(cum <= cfg.ABC_CUTS[1], "B", "C")), index=v.index)
    lab[v <= 0] = "C"
    return lab.reindex(values.index), cum.reindex(values.index)


def xyz_labels(cv: pd.Series) -> pd.Series:
    """X below the first cut-off, Y below the second, else Z (no demand -> Z)."""
    return pd.Series(np.where(cv < cfg.XYZ_CUTS[0], "X", np.where(cv < cfg.XYZ_CUTS[1], "Y", "Z")),
                     index=cv.index)


def monthly_demand(df: pd.DataFrame) -> pd.DataFrame:
    """Sale quantity per (product, fiscal year) x fiscal month 0..11 (April = 0)."""
    s = df[(df["line_type"] == "sale") & (df["qty"] > 0)]
    m = (s["bill_date"].dt.month - 4) % 12
    return (s.assign(m=m).groupby(["product", "fy", "m"])["qty"].sum()
             .unstack(fill_value=0).reindex(columns=range(12), fill_value=0))


# ---- Features ----------------------------------------------
def product_year_features(df: pd.DataFrame, fys: list) -> pd.DataFrame:
    """One row per product per fiscal year in which it had positive net
    revenue: its ABC-XYZ class that year plus the predictive features."""
    net = df.groupby(["product", "fy"]).agg(revenue=("amount", "sum"), margin=("margin_value", "sum"))
    gross = df[df["line_type"] == "sale"].groupby(["product", "fy"])["amount"].sum()
    returns = df[df["line_type"] == "return"].groupby(["product", "fy"])["amount"].sum()
    by_cust = df.groupby(["product", "fy", "customer"])["amount"].sum().clip(lower=0)
    by_terr = df.groupby(["product", "fy", "loc_code"])["amount"].sum()
    meta = df.groupby("product").agg(sku=("sku", "first"),
                                     segment=("segment", lambda s: s.mode().iloc[0]))
    mq = monthly_demand(df)
    xc = np.arange(12) - 5.5

    rows = []
    for t, fy in enumerate(fys):
        cur = net.xs(fy, level="fy")
        cur = cur[cur["revenue"] > 0].copy()
        cur["abc"], cur["cum_share_pct"] = abc_labels(cur["revenue"])
        cur["rev_share_pct"] = cur["revenue"] / cur["revenue"].sum() * 100
        q = mq.xs(fy, level="fy").reindex(cur.index, fill_value=0).to_numpy(float)
        mean = q.mean(axis=1)
        with np.errstate(divide="ignore", invalid="ignore"):
            cur["cv"] = np.where(mean > 0, q.std(axis=1) / mean, np.nan)
            cur["max_month_share_pct"] = np.where(mean > 0, q.max(axis=1) / q.sum(axis=1) * 100, 100.0)
            cur["monthly_trend_pct"] = np.where(mean > 0, (q * xc).sum(axis=1) / (xc ** 2).sum() / mean * 100, 0.0)
        cur["months_active"] = (q > 0).sum(axis=1)
        cur["xyz"] = xyz_labels(cur["cv"])
        cur["margin_pct"] = (cur["margin"] / cur["revenue"] * 100).clip(-100, 100)
        g = gross.xs(fy, level="fy").reindex(cur.index).fillna(0) if fy in gross.index.get_level_values(1) else 0
        r = returns.xs(fy, level="fy").reindex(cur.index).fillna(0) if fy in returns.index.get_level_values(1) else 0
        cur["return_rate_pct"] = np.where(g > 0, -r / g * 100, 0.0)
        c = by_cust.xs(fy, level="fy")
        cur["customers"] = c[c > 0].groupby(level="product").size().reindex(cur.index).fillna(0)
        cur["top_customer_share_pct"] = (c.groupby(level="product").max()
                                         / c.groupby(level="product").sum() * 100).reindex(cur.index)
        tr = by_terr.xs(fy, level="fy")
        cur["territories"] = tr[tr > 0].groupby(level="product").size().reindex(cur.index).fillna(0)
        if t > 0 and fys[t - 1] in net.index.get_level_values(1):
            prev = net.xs(fys[t - 1], level="fy")["revenue"].reindex(cur.index)
        else:
            prev = pd.Series(np.nan, index=cur.index)
        cur["is_new"] = (prev.fillna(0) <= 0).astype(int)
        cur["rev_growth_pct"] = ((cur["revenue"] / prev - 1) * 100).where(prev > 0).clip(-100, 300).fillna(0)
        cur["fy"], cur["fy_idx"] = fy, t
        rows.append(cur)

    f = pd.concat(rows).reset_index().rename(columns={"index": "product"})
    f = f.merge(meta, left_on="product", right_index=True)
    f = f.sort_values(["product", "fy_idx"])
    prev_cv = f.groupby("product")["cv"].shift()
    prev_idx = f.groupby("product")["fy_idx"].shift()
    f["cv_prev"] = prev_cv.where(prev_idx == f["fy_idx"] - 1)
    f["cv"] = f["cv"].fillna(f["cv"].max())
    f["cv_prev"] = f["cv_prev"].fillna(f["cv"])
    f["age_years"] = f["fy_idx"] - f.groupby("product")["fy_idx"].transform("min")
    f["abc_now"] = f["abc"].map({c: i for i, c in enumerate(CLASSES["abc"])})
    f["xyz_now"] = f["xyz"].map({c: i for i, c in enumerate(CLASSES["xyz"])})
    f["class"] = f["abc"] + f["xyz"]
    return f.reset_index(drop=True)


def page_method_classes(df: pd.DataFrame, fy: str, products: pd.Index) -> pd.DataFrame:
    """InvenIQ's ABC/XYZ page logic (includes/engine.php getABCXYZ), applied at
    the end of fiscal year `fy`: daily velocity over the last 90 days,
    annualised at the product's median rate, CV over three 30-day windows.
    Products with no sales in the window (the page leaves them out) -> C, Z."""
    s = df[(df["fy"] == fy) & (df["line_type"] == "sale") & (df["qty"] > 0)]
    anchor = s["bill_date"].max()

    def velocity(days):
        w = s[s["bill_date"] >= anchor - pd.Timedelta(days=days)]
        return w.groupby("product")["qty"].sum().reindex(products).fillna(0) / days

    step = cfg.PAGE_WINDOW_DAYS // 3
    v_all, v_two, v_one = velocity(step * 3), velocity(step * 2), velocity(step)
    price = s[s["rate"] > 0].groupby("product")["rate"].median().reindex(products).fillna(0)
    annual = v_all * 365 * price
    # Rate in each of the three windows. They are nested and quantities are positive, so a
    # window is never truly negative: rounding removes float noise from an empty window.
    w = np.round(np.column_stack([v_one, v_two * 2 - v_one, v_all * 3 - v_two * 2]), 9)
    mean = w.mean(axis=1)
    cv = pd.Series(np.where(mean > 0, w.std(axis=1) / np.where(mean > 0, mean, 1), 0), index=products)

    abc, _ = abc_labels(annual[annual > 0])
    out = pd.DataFrame({"page_abc": abc.reindex(products).fillna("C"),
                        "page_xyz": xyz_labels(cv)}, index=products)
    out.loc[annual <= 0, "page_xyz"] = "Z"
    return out


def build_panel(df: pd.DataFrame, feats: pd.DataFrame, fys: list) -> pd.DataFrame:
    """Features of year t joined to the class of year t+1 (products sold in both)."""
    nxt = feats[["product", "fy_idx", "abc", "xyz", "cv"]].rename(
        columns={"abc": "abc_next", "xyz": "xyz_next", "cv": "cv_next"})
    nxt["fy_idx"] -= 1
    panel = feats.merge(nxt, on=["product", "fy_idx"], how="inner")
    panel["fy_next"] = panel["fy_idx"].map(lambda i: fys[i + 1])
    pages = []
    for fy, sub in panel.groupby("fy"):
        pm = page_method_classes(df, fy, pd.Index(sub["product"].unique()))
        pages.append(sub[["product", "fy"]].merge(pm, left_on="product", right_index=True))
    panel = panel.merge(pd.concat(pages), on=["product", "fy"])
    panel["persistence_abc"], panel["persistence_xyz"] = panel["abc"], panel["xyz"]
    panel["page_method_abc"], panel["page_method_xyz"] = panel["page_abc"], panel["page_xyz"]
    return panel


def feature_matrix(f: pd.DataFrame, segments: list) -> pd.DataFrame:
    X = f[list(FEATURES)].astype(float).copy()
    for s in segments:
        X[f"seg_{s}"] = (f["segment"] == s).astype(float)
    return X


# ---- Models ------------------------------------------------
def model_specs() -> dict:
    seed = cfg.RANDOM_SEED
    return {
        "decision_tree": (DecisionTreeClassifier(random_state=seed),
                          {"max_depth": [2, 3, 4, 5], "min_samples_leaf": [5, 10, 20],
                           "class_weight": [None, "balanced"]}),
        "logistic_regression": (make_pipeline(StandardScaler(), LogisticRegression(max_iter=5000)),
                                {"logisticregression__C": [0.1, 1.0, 10.0],
                                 "logisticregression__class_weight": [None, "balanced"]}),
        "random_forest": (RandomForestClassifier(n_estimators=300, random_state=seed),
                          {"max_depth": [3, 6, None], "min_samples_leaf": [1, 5]}),
        "gradient_boosting": (HistGradientBoostingClassifier(random_state=seed),
                              {"max_depth": [2, 3], "learning_rate": [0.05, 0.1], "max_iter": [100, 200]}),
    }


def target(f: pd.DataFrame, dim: str, form: str) -> pd.Series:
    """What a model learns: next year's class (direct) or the one-step move
    from this year's class, -1 / 0 / +1 towards C or Z (change)."""
    if form == "direct":
        return f[f"{dim}_next"]
    idx = {c: i for i, c in enumerate(CLASSES[dim])}
    return (f[f"{dim}_next"].map(idx) - f[dim].map(idx)).clip(-1, 1).astype(int)


def predict_class(est, X: pd.DataFrame, dim: str, form: str) -> tuple:
    """(next-year class, class probabilities or None). A move off the end of the scale
    lands on the same class as staying, so moves are summed per class before choosing."""
    if form == "direct":
        return est.predict(X), None
    now = X[f"{dim}_now"].to_numpy(int)
    rows = np.arange(len(now))
    proba = np.zeros((len(now), 3))
    for j, move in enumerate(np.asarray(est.classes_, dtype=int)):
        np.add.at(proba, (rows, np.clip(now + move, 0, 2)), est.predict_proba(X)[:, j])
    tiebreak = np.zeros_like(proba)
    tiebreak[rows, now] = 1e-9      # an exact tie stays where it is
    return np.array(CLASSES[dim])[(proba + tiebreak).argmax(axis=1)], proba


def class_f1_scorer(dim: str, form: str):
    """Tuning criterion shared by both formulations: macro-F1 of the next-year
    class the predictions imply (a move is turned back into a class)."""
    def score(est, X, y):
        yp = predict_class(est, X, dim, form)[0]
        if form == "direct":
            return f1_score(y, yp, average="macro", zero_division=0)
        now = X[f"{dim}_now"].to_numpy(int)
        truth = np.array(CLASSES[dim])[np.clip(now + np.asarray(y, int), 0, 2)]
        return f1_score(truth, yp, average="macro", zero_division=0)
    return score


def fit_select(name: str, X: pd.DataFrame, y: pd.Series, dim: str, form: str, products: pd.Series) -> tuple:
    """Tune by stratified k-fold on the training rows only, keeping all of a product's
    years in one fold: classes are sticky, so splitting them would reward memorising products."""
    est, grid = model_specs()[name]
    folds = StratifiedGroupKFold(cfg.RQ2_CV_FOLDS, shuffle=True, random_state=cfg.RANDOM_SEED)
    gs = GridSearchCV(est, grid, scoring=class_f1_scorer(dim, form), cv=folds, n_jobs=-1)
    gs.fit(X, y, groups=products)
    return gs.best_estimator_, gs.best_params_, round(float(gs.best_score_), 4)


# ---- Evaluation --------------------------------------------
def method_scores(p: pd.DataFrame, m: str) -> dict:
    out = {"n": int(len(p))}
    for dim in ("abc", "xyz"):
        yt, yp = p[f"{dim}_next"], p[f"{m}_{dim}"]
        out[f"{dim}_accuracy"] = round(float(accuracy_score(yt, yp)), 4)
        out[f"{dim}_macro_f1"] = round(float(f1_score(yt, yp, average="macro", zero_division=0)), 4)
    yt = p["abc_next"] + p["xyz_next"]
    yp = p[f"{m}_abc"] + p[f"{m}_xyz"]
    out["class_accuracy"] = round(float(accuracy_score(yt, yp)), 4)
    out["class_macro_f1"] = round(float(f1_score(yt, yp, average="macro", zero_division=0)), 4)
    now = p["abc"] + p["xyz"]
    movers = yt != now
    out["movers_n"] = int(movers.sum())
    out["movers_accuracy"] = round(float((yt[movers] == yp[movers]).mean()), 4) if movers.any() else None
    # How often a predicted change of class actually happened (precision of change calls)
    called = yp != now
    out["changes_predicted"] = int(called.sum())
    out["change_precision"] = round(float((yp[called] == yt[called]).mean()), 4) if called.any() else None
    return out


def _macro_f1(yt: np.ndarray, yp: np.ndarray, k: int) -> float:
    """Macro-F1 over the labels present in either array (as sklearn), on int codes."""
    cm = np.bincount(yt * k + yp, minlength=k * k).reshape(k, k)
    tp = np.diag(cm)
    denom = 2 * tp + (cm.sum(axis=0) - tp) + (cm.sum(axis=1) - tp)
    present = (cm.sum(axis=0) + cm.sum(axis=1)) > 0
    return float(np.where(denom > 0, 2 * tp / np.maximum(denom, 1), 0)[present].mean())


def significance_vs_persistence(p: pd.DataFrame, m: str, rng) -> dict:
    """Against 'same class as last year'. Most products appear in all three test years, so
    rows are not independent: the p-value comes from a sign-flip permutation of per-product
    differences and the intervals from resampling whole products. The plain McNemar p, which
    treats every row as independent, is kept only for comparison."""
    products = p["product"].to_numpy()
    groups = [np.flatnonzero(products == g) for g in np.unique(products)]
    out = {}
    for dim in ("abc", "xyz", "class"):
        if dim == "class":
            yt = (p["abc_next"] + p["xyz_next"]).to_numpy()
            ym = (p[f"{m}_abc"] + p[f"{m}_xyz"]).to_numpy()
            yb = (p["abc"] + p["xyz"]).to_numpy()
        else:
            yt, ym, yb = p[f"{dim}_next"].to_numpy(), p[f"{m}_{dim}"].to_numpy(), p[dim].to_numpy()
        ok_m, ok_b = ym == yt, yb == yt
        b, c = int((ok_m & ~ok_b).sum()), int((~ok_m & ok_b).sum())
        pval = binomtest(min(b, c), b + c, 0.5).pvalue if b + c else 1.0
        codes = {v: i for i, v in enumerate(sorted(set(yt) | set(ym) | set(yb)))}
        k = len(codes)
        ct, cm_, cb = (np.array([codes[v] for v in a]) for a in (yt, ym, yb))
        per_product = np.array([ok_m[g].sum() - ok_b[g].sum() for g in groups], dtype=float)
        signs = rng.choice([-1.0, 1.0], size=(cfg.PERMUTATION_DRAWS, len(groups)))
        hits = (np.abs(signs @ per_product) >= abs(per_product.sum())).sum()
        diffs, acc_diffs = [], []
        for _ in range(cfg.BOOTSTRAP_DRAWS):
            i = np.concatenate([groups[j] for j in rng.integers(0, len(groups), len(groups))])
            diffs.append(_macro_f1(ct[i], cm_[i], k) - _macro_f1(ct[i], cb[i], k))
            acc_diffs.append(ok_m[i].mean() - ok_b[i].mean())
        out[dim] = {"model_right_baseline_wrong": b, "model_wrong_baseline_right": c,
                    "product_level_p": round(float((1 + hits) / (cfg.PERMUTATION_DRAWS + 1)), 4),
                    "mcnemar_p": round(float(pval), 4),
                    "accuracy_diff_ci95": [round(float(np.percentile(acc_diffs, 2.5)), 4),
                                           round(float(np.percentile(acc_diffs, 97.5)), 4)],
                    "macro_f1_diff_ci95": [round(float(np.percentile(diffs, 2.5)), 4),
                                           round(float(np.percentile(diffs, 97.5)), 4)]}
    return out


def evaluate(panel: pd.DataFrame, segments: list) -> tuple:
    """Expanding-window test: for each of the last target years, tune and train
    on all earlier rows only, then predict that year."""
    target_years = sorted(panel["fy_next"].unique())[-cfg.RQ2_TEST_YEARS:]
    keep = ["product", "sku", "segment", "fy", "fy_next", "abc", "xyz", "abc_now", "xyz_now",
            "abc_next", "xyz_next", "persistence_abc", "persistence_xyz", "page_method_abc", "page_method_xyz"]
    preds, params = [], {}
    for ty in target_years:
        train, test = panel[panel["fy_next"] < ty], panel[panel["fy_next"] == ty]
        Xtr, Xte = feature_matrix(train, segments), feature_matrix(test, segments)
        out = test[keep].copy()
        params[ty] = {"train_rows": int(len(train)), "test_rows": int(len(test))}
        for name in MODELS:
            for form in FORMULATIONS:
                for dim in ("abc", "xyz"):
                    est, best, cv_f1 = fit_select(name, Xtr, target(train, dim, form), dim, form, train["product"])
                    out[f"{name}_{form}_{dim}"] = predict_class(est, Xte, dim, form)[0]
                    params[ty][f"{name}_{form}_{dim}"] = {"params": best, "cv_macro_f1": cv_f1}
        preds.append(out)
    pred = pd.concat(preds, ignore_index=True)

    rng = np.random.default_rng(cfg.RANDOM_SEED)
    pooled = {m: method_scores(pred, m) for m in METHODS}
    by_year = {ty: {m: method_scores(pred[pred["fy_next"] == ty], m) for m in METHODS} for ty in target_years}
    sig = {m: significance_vs_persistence(pred, m, rng) for m in METHODS[1:]}
    conf = {m: {dim: {"labels": CLASSES[dim],
                      "matrix": confusion_matrix(pred[f"{dim}_next"], pred[f"{m}_{dim}"],
                                                 labels=CLASSES[dim]).tolist()}
                for dim in ("abc", "xyz")} for m in ("persistence", f"{SERVED_MODEL}_{SERVED_FORM}")}
    metrics = {"test_target_years": target_years, "pooled": pooled, "by_year": by_year,
               "significance_vs_persistence": sig, "confusion_pooled": conf, "tuning": params,
               "method_labels": METHOD_LABELS}
    return pred, metrics


# ---- Explaining the served tree ----------------------------
def _fmt(kind: str, v: float) -> str:
    return {"pct2": f"{v:.2f}%", "pct1": f"{v:.1f}%", "spct": f"{v:+.0f}%",
            "trend": f"{v:+.1f}% a month", "num2": f"{v:.2f}"}.get(kind, f"{v:.0f}")


def condition_text(col: str, lo: float, hi: float, value: float) -> str:
    """One merged condition on a feature, e.g. 'revenue share 2.13% (above 1.05%)'."""
    if col.startswith("seg_"):
        seg = col[4:]
        return f"segment is {seg}" if lo >= 0.5 else f"segment is not {seg}"
    name, kind = FEATURES[col]
    if kind in ("abc", "xyz"):
        allowed = [c for i, c in enumerate(CLASSES[kind]) if lo < i <= hi]
        return f"{name} is {' or '.join(allowed)}"
    if kind == "flag":
        return name if lo >= 0.5 else f"not in its {name}"
    if kind == "int":
        low = f"at least {int(np.floor(lo)) + 1}" if np.isfinite(lo) else None
        high = f"at most {int(np.floor(hi))}" if np.isfinite(hi) else None
    else:
        low = f"above {_fmt(kind, lo)}" if np.isfinite(lo) else None
        high = f"at most {_fmt(kind, hi)}" if np.isfinite(hi) else None
    rng_txt = f"{low} and {high}" if low and high else (low or high)
    return f"{name} {_fmt(kind, value)} ({rng_txt})"


def decision_path_text(tree: DecisionTreeClassifier, x: np.ndarray, cols: list) -> list:
    t = tree.tree_
    bounds = {}
    for node in tree.decision_path(pd.DataFrame([x], columns=cols)).indices:
        if t.children_left[node] == t.children_right[node]:
            continue
        f, th = t.feature[node], t.threshold[node]
        lo, hi = bounds.get(f, (-np.inf, np.inf))
        bounds[f] = (lo, min(hi, th)) if x[f] <= th else (max(lo, th), hi)
    return [condition_text(cols[f], lo, hi, x[f]) for f, (lo, hi) in bounds.items()]


def client_reasons(r, fy: str) -> list:
    """What the client reads about a product: plain facts, no model output."""
    out = [f"{ABC_WORDS[r['abc']]}: {fmt_inr(r['revenue'])} in FY{fy}, {r['rev_share_pct']:.1f}% of all sales.",
           f"{DEMAND_WORDS[r['xyz']]}; sold in {int(r['months_active'])} of 12 months."]
    if r["is_new"]:
        out.append("No sales last year.")
    else:
        g = r["rev_growth_pct"]     # capped at +300 in the features
        out.append("Sales more than quadrupled on last year." if g >= 300
                   else f"Sales {'up' if g >= 0 else 'down'} {abs(g):.0f}% on last year.")
        if pd.notna(r["prev_abc"]) and r["prev_abc"] != r["abc"]:
            out.append(f"Was a {ABC_WORDS[r['prev_abc']].lower()} last year.")
    return out


def watch_list(feats: pd.DataFrame, fys: list) -> pd.DataFrame:
    """Products worth a look: real changes between the last two fiscal years.
    Moves in demand pattern (XYZ) are left out: many are just CV noise near a cut-off."""
    cols = ["product", "sku", "segment", "abc", "revenue"]
    w = feats.loc[feats["fy"] == fys[-1], cols].merge(
        feats.loc[feats["fy"] == fys[-2], cols], on="product", how="outer", suffixes=("", "_prev"))
    w["sku"] = w["sku"].fillna(w["sku_prev"])
    w["segment"] = w["segment"].fillna(w["segment_prev"])
    now, before = w["revenue"].fillna(0), w["revenue_prev"].fillna(0)
    w["revenue"], w["prev_revenue"] = now, before
    w["change_pct"] = ((now / before - 1) * 100).where(before > 0)
    large = np.maximum(now, before) >= cfg.WATCH_MIN_REVENUE
    sharp = large & (w["change_pct"].abs() >= cfg.WATCH_SHARP_CHANGE_PCT)
    order = {c: i for i, c in enumerate(CLASSES["abc"])}
    step = w["abc"].map(order) - w["abc_prev"].map(order)     # NaN when not sold in one of the years

    def describe(r, s):
        was, is_now = fmt_inr(r.prev_revenue), fmt_inr(r.revenue)
        if pd.isna(r.abc):
            return "Stopped selling", f"{was} last year, no sales this year."
        if pd.isna(r.abc_prev):
            return "New this year", f"No sales last year, {is_now} this year."
        trend = (f"Sales went from {was} to {is_now}." if abs(r.change_pct) >= 500   # a % this big reads as noise
                 else f"Sales {'up' if r.change_pct >= 0 else 'down'} {abs(r.change_pct):.0f}%: {was} to {is_now}.")
        if s != 0:
            # Groups are relative, so a product can rise with falling sales
            note = (" Other products fell further." if s < 0 and r.change_pct < 0
                    else " Other products grew faster." if s > 0 and r.change_pct > 0 else "")
            return ("Moved up" if s < 0 else "Moved down"), (
                f"{ABC_WORDS[r.abc_prev]} last year, {ABC_WORDS[r.abc].lower()} now. {trend}{note}")
        return ("Sales jumped" if r.change_pct >= 0 else "Sales dropped"), trend

    keep = w[(step.fillna(0) != 0) | sharp | (large & (w["abc"].isna() | w["abc_prev"].isna()))].copy()
    described = [describe(r, s) for r, s in zip(keep.itertuples(), step.loc[keep.index].fillna(0))]
    keep["kind"], keep["detail"] = [d[0] for d in described], [d[1] for d in described]
    keep["group_before"], keep["group_now"], keep["fy"] = keep["abc_prev"], keep["abc"], fys[-1]
    keep = keep.assign(size=(keep["revenue"] - keep["prev_revenue"]).abs()).sort_values("size", ascending=False)
    return keep[["sku", "product", "segment", "fy", "kind", "group_before", "group_now",
                 "revenue", "prev_revenue", "change_pct", "detail"]]


def outlook_reasons(r, fy: str, next_fy: str) -> list:
    out = [f"FY{fy}: class {r['class']} — {fmt_inr(r['revenue'])} ({r['rev_share_pct']:.2f}% of revenue, "
           f"cumulative {r['cum_share_pct']:.0f}%); demand CV {r['cv']:.2f}, sold in {int(r['months_active'])} of 12 months."]
    for dim, label in (("abc", f"Value class next year (FY{next_fy})"), ("xyz", "Variability class next year")):
        cls = r[f"pred_{dim}"]
        move = MOVE_WORDS[dim][CLASSES[dim].index(cls) - int(r[f"{dim}_now"])]
        others = [f"{k} {v:.0%}" for k, v in r[f"pred_{dim}_dist"].items() if k != move and v >= 0.05]
        how = (f"expected to {move} ({r[f'pred_{dim}_prob']:.0%} of similar products in training did"
               + (f"; {', '.join(others)}" if others else "") + ")")
        out.append(f"{label}: {cls} — {how}. Similar products: " + join_words(r[f"{dim}_path"]) + ".")
    if r["pred_class"] != r["class"]:
        out.append(f"Expected to move from {r['class']} to {r['pred_class']}.")
    if r["page_class"] != r["class"]:
        out.append(f"The current ABC/XYZ page (last 90 days) shows it as {r['page_class']}.")
    return out


# ---- Segment (category) profile ----------------------------
def segment_profile(feats: pd.DataFrame, products: pd.DataFrame, fys: list) -> pd.DataFrame:
    fy, t = fys[-1], len(fys) - 1
    cur = products.copy()
    rev = cur.groupby("segment")["revenue"].sum()
    seg = pd.DataFrame({"revenue": rev})
    seg["revenue_share_pct"] = rev / rev.sum() * 100
    seg["products"] = cur.groupby("segment").size()
    seg["a_products"] = cur[cur["abc"] == "A"].groupby("segment").size().reindex(seg.index).fillna(0)
    seg["margin_pct"] = cur.groupby("segment")["margin"].sum() / rev * 100
    w = cur["revenue"].clip(lower=0)
    seg["weighted_cv"] = (cur.assign(wcv=w * cur["cv"]).groupby("segment")["wcv"].sum()
                          / cur.assign(w=w).groupby("segment")["w"].sum())
    seg["stable_rev_share_pct"] = (cur[cur["xyz"] == "X"].groupby("segment")["revenue"].sum()
                                   .reindex(seg.index).fillna(0) / rev * 100)
    base_fy = fys[max(0, t - cfg.GROWTH_YEARS)]
    base = feats[feats["fy"] == base_fy].groupby("segment")["revenue"].sum().reindex(seg.index)
    years = t - fys.index(base_fy)
    seg["growth_cagr_pct"] = (((rev / base) ** (1 / years) - 1) * 100).where(base > 0)
    seg["seg_abc"], _ = abc_labels(seg["revenue"])
    seg["seg_xyz"] = xyz_labels(seg["weighted_cv"])
    seg["seg_class"] = seg["seg_abc"] + seg["seg_xyz"]
    seg["label"] = [f"{cfg.SEGMENT_VALUE_LABELS[a]}, {cfg.SEGMENT_STABILITY_LABELS[x]}"
                    for a, x in zip(seg["seg_abc"], seg["seg_xyz"])]
    seg["fy"] = fy
    seg["reasons"] = [[
        f"{fmt_inr(r.revenue)} in FY{fy} ({r.revenue_share_pct:.1f}% of all sales) from "
        f"{int(r.products)} products, {int(r.a_products)} of them top sellers.",
        f"{r.stable_rev_share_pct:.0f}% of its sales come from products with steady demand.",
        (f"Sales {r.growth_cagr_pct:+.1f}% a year since FY{base_fy}; " if pd.notna(r.growth_cagr_pct) else "")
        + f"margin {r.margin_pct:.1f}%.",
    ] for r in seg.itertuples()]
    seg.index.name = "segment"
    return seg.sort_values("revenue", ascending=False)


# ---- Entry point -------------------------------------------
def run(df: pd.DataFrame) -> dict:
    fys = sorted(df["fy"].unique())
    segments = sorted(df["segment"].unique())
    feats = product_year_features(df, fys)
    panel = build_panel(df, feats, fys)
    pred, evaluation = evaluate(panel, segments)

    # Served model: decision trees tuned and trained on every transition
    X_all = feature_matrix(panel, segments)
    cols = list(X_all.columns)
    latest = feats[feats["fy"] == fys[-1]].copy()
    X_latest_df = feature_matrix(latest, segments)
    X_latest = X_latest_df.to_numpy()
    served = {}
    names = [c if c.startswith("seg_") else FEATURES[c][0] for c in cols]
    for dim in ("abc", "xyz"):
        tree, best, cv_f1 = fit_select(SERVED_MODEL, X_all, target(panel, dim, SERVED_FORM), dim, SERVED_FORM,
                                       panel["product"])
        labels, proba = predict_class(tree, X_latest_df, dim, SERVED_FORM)
        now = latest[f"{dim}_now"].to_numpy(int)
        latest[f"pred_{dim}"] = labels
        latest[f"pred_{dim}_prob"] = proba.max(axis=1)
        latest[f"pred_{dim}_dist"] = [{MOVE_WORDS[dim][i - n]: float(q) for i, q in enumerate(row) if q > 0}
                                      for row, n in zip(proba, now)]
        latest[f"{dim}_path"] = [decision_path_text(tree, x, cols) for x in X_latest]
        imp = pd.Series(tree.feature_importances_, index=names).sort_values(ascending=False)
        class_names = [MOVE_WORDS[dim][int(c)] for c in tree.classes_] if SERVED_FORM == "change" else None
        served[dim] = {"params": best, "cv_macro_f1": cv_f1, "depth": int(tree.get_depth()),
                       "leaves": int(tree.get_n_leaves()),
                       "rules": export_text(tree, feature_names=names, class_names=class_names, decimals=2),
                       "importance": {k: round(float(v), 4) for k, v in imp[imp > 0].items()}}

    next_fy = f"{int(fys[-1][:4]) + 1}-{(int(fys[-1][:4]) + 2) % 100:02d}"
    page = page_method_classes(df, fys[-1], pd.Index(latest["product"]))
    latest = latest.merge(page, left_on="product", right_index=True)
    latest["page_class"] = latest["page_abc"] + latest["page_xyz"]
    latest["pred_class"] = latest["pred_abc"] + latest["pred_xyz"]
    latest["pred_prob"] = latest["pred_abc_prob"] * latest["pred_xyz_prob"]
    latest["next_fy"] = next_fy
    latest["outlook_reasons"] = [outlook_reasons(r, fys[-1], next_fy) for _, r in latest.iterrows()]
    latest["prev_abc"] = latest["product"].map(feats[feats["fy"] == fys[-2]].set_index("product")["abc"])
    latest["reasons"] = [client_reasons(r, fys[-1]) for _, r in latest.iterrows()]
    latest = latest.sort_values("revenue", ascending=False)

    seg = segment_profile(feats, latest, fys)
    watch = watch_list(feats, fys)

    trans = []
    for a, b in zip(fys[:-1], fys[1:]):
        p = panel[panel["fy"] == a]
        trans.append({"from": a, "to": b, "products": int(len(p)),
                      "same_abc_pct": round(float((p["abc"] == p["abc_next"]).mean() * 100), 1),
                      "same_xyz_pct": round(float((p["xyz"] == p["xyz_next"]).mean() * 100), 1),
                      "same_class_pct": round(float(((p["abc"] + p["xyz"]) == (p["abc_next"] + p["xyz_next"])).mean() * 100), 1),
                      "not_sold_next_year": int((feats["fy"] == a).sum() - len(p))})
    # How many XYZ changes are just a CV drifting across a cut-off (both years
    # within 0.15 of the single cut-off crossed) rather than a real shift
    moved = panel[panel["xyz"] != panel["xyz_next"]]
    lo, hi = np.minimum(moved["cv"], moved["cv_next"]), np.maximum(moved["cv"], moved["cv_next"])
    near = np.zeros(len(moved), bool)
    for cut in cfg.XYZ_CUTS:
        crossed = (lo < cut) & (hi >= cut)
        others = [c for c in cfg.XYZ_CUTS if c != cut]
        single = ~np.any([(lo < c) & (hi >= c) for c in others], axis=0)
        near |= (crossed & single & (cut - lo <= 0.15) & (hi - cut <= 0.15)).to_numpy()
    metrics = {
        "fy": fys[-1], "next_fy": next_fy,
        "data": {"product_years": int(len(feats)), "transitions": int(len(panel)),
                 "class_counts_latest": latest["class"].value_counts().to_dict(),
                 "year_to_year": trans,
                 "xyz_changes": int(len(moved)),
                 "xyz_changes_near_cutoff_pct": round(float(near.mean() * 100), 1) if len(moved) else None},
        "evaluation": evaluation,
        "served_model": {"name": SERVED_MODEL, "formulation": SERVED_FORM,
                         "method": f"{SERVED_MODEL}_{SERVED_FORM}", **served},
        "predicted_changes": int((latest["pred_class"] != latest["class"]).sum()),
    }
    params = {k: getattr(cfg, k) for k in ["ABC_CUTS", "XYZ_CUTS", "PAGE_WINDOW_DAYS", "RQ2_TEST_YEARS",
                                           "RQ2_CV_FOLDS", "BOOTSTRAP_DRAWS", "PERMUTATION_DRAWS", "RANDOM_SEED"]}
    params["features"] = {k: v[0] for k, v in FEATURES.items()}
    params["segments_one_hot"] = segments
    history = feats[["product", "sku", "segment", "fy", "abc", "xyz", "class", "revenue", "rev_share_pct", "cv"]]
    return {"products": latest, "history": history, "segments": seg, "watch": watch, "test_predictions": pred,
            "metrics": metrics, "params": params, "fys": fys}
