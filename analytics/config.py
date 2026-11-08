# =============================================================
# InvenIQ Sales Intelligence — configuration
# Paths, database settings and every analytical parameter used
# by the pipeline, kept in one place so each run is reproducible
# and every threshold can be quoted in the thesis.
# =============================================================
import json
import os

# ---- Paths -------------------------------------------------
AN_DIR        = os.path.dirname(os.path.abspath(__file__))
APP_DIR       = os.path.dirname(AN_DIR)                    # ...\inveniq
ML_DIR        = os.path.join(APP_DIR, "ml")                # reuses ml_common's loader
OUTPUT_DIR    = os.path.join(AN_DIR, "output")
REFERENCE_DIR = os.path.join(AN_DIR, "reference")
SCHEMA_PATH   = os.path.join(AN_DIR, "sql", "schema_analytics.sql")
COORDS_PATH   = os.path.join(REFERENCE_DIR, "territory_coordinates.csv")

SUMMARY_SHEET = "Sheet1"        # the workbook's own pivot of yearly totals
UPDATES_DIR   = os.path.join(APP_DIR, "sales_updates")    # later exports, e.g. Apr-Oct 2026, one file per period

# ---- DB connection (matches includes/config.php) -----------
DB = dict(host="localhost", user="root", password="", database="inveniq",
          charset="utf8mb4", autocommit=False)

# ---- Line types --------------------------------------------
# Every line belongs to exactly one type. Net figures (revenue, margin)
# include all three, which is how the workbook's own summary is built.
SALE_CATEGORIES   = {"DIRECT", "PARTY", "P PARTY"}
RETURN_CATEGORIES = {"RETURN"}
SAMPLE_CATEGORIES = {"SAMPLE"}
# Exports without a Category column: the bill number's letters give it. In the 2020-26 workbook
# SR bills are returns (5,029 of 5,042) and SAM bills samples; D, P and NG bills are sales.
BILL_PREFIX_CATEGORY = {"D": "DIRECT", "P": "PARTY", "NG": "PARTY", "SR": "RETURN", "SAM": "SAMPLE"}

# Label standardisation (raw value -> clean value)
SEGMENT_MAP = {"Suppliments": "Supplements", "Suppliment": "Supplements"}

# ---- Territory status rules --------------------------------
MIN_LIFETIME_REVENUE = 100_000   # under Rs 1 lakh in total -> "Insufficient data"
GROWTH_YEARS         = 3         # growth = annualised over up to 3 fiscal years
HUB_LOCATION         = "COIMBATORE - HQ"   # largest territory; distance is measured from it

# ---- RQ1: multi-factor regional scoring --------------------
# Each factor is turned into a 0-100 percentile among active territories,
# then combined with these weights (equal by default, as in the proposal).
RQ1_WEIGHTS = {
    "revenue":   0.20,   # net revenue in the latest fiscal year
    "margin":    0.20,   # net margin / net revenue
    "growth":    0.20,   # annualised revenue growth (GROWTH_YEARS)
    "customers": 0.20,   # customer breadth: active customers
    "products":  0.20,   # product breadth: products sold
}
STRONG_SCORE = 65          # composite >= this -> Strong
WEAK_SCORE   = 35          # composite <  this -> Weak (unless flagged)

# Under-performer flags ("untapped potential" rather than "just small")
# Peer gap: revenue below what peers with the same reach earn, by more than
# the reach model's own typical (leave-one-out) error x PEER_GAP_SD.
PEER_GAP_SD = 1.0
# Candidate log-log reach models; the one with the lowest leave-one-out
# error is used. The hub is left out of the fit: it is the home territory,
# not a peer of the others.
PEER_MODEL_SPECS = [["customers"], ["customers", "products"],
                    ["customers", "products", "distance_km"]]
PEER_MODEL_EXCLUDE_HUB = True
# Lost ground: kept under half as much of its best-year revenue as the rest
# of the business kept over the same years (so company-wide decline alone
# does not flag a territory).
LOST_GROUND_FLAG = 0.50

# Robustness check of the composite to the choice of weights
SENSITIVITY_DRAWS = 2000
RANDOM_SEED       = 42

# ---- RQ2: product-category identification ------------------
# ABC-XYZ cut-offs, the same as InvenIQ's existing ABC/XYZ page.
ABC_CUTS = (70, 90)      # cumulative revenue share (%) up to which a product is A, then B
XYZ_CUTS = (0.5, 1.0)    # coefficient of variation of monthly demand: X below 0.5, Y below 1.0
# Baseline 2 re-implements that page: velocity over the last 90 days of the
# year, CV over three 30-day windows.
PAGE_WINDOW_DAYS = 90
# Expanding-window evaluation: test on each of the last N target years, training
# only on earlier years; hyper-parameters tuned by stratified k-fold inside training.
RQ2_TEST_YEARS   = 3
RQ2_CV_FOLDS     = 5
BOOTSTRAP_DRAWS  = 1000
PERMUTATION_DRAWS = 10000
# Client watch list: a sales move this big, on a product selling at least this much
WATCH_SHARP_CHANGE_PCT = 50
WATCH_MIN_REVENUE      = 100_000
# Segment (category) stability from the revenue-weighted CV of its products
SEGMENT_VALUE_LABELS     = {"A": "High value", "B": "Mid value", "C": "Marginal"}
SEGMENT_STABILITY_LABELS = {"X": "stable", "Y": "variable", "Z": "erratic"}

# ---- RQ3: expansion decision support -----------------------
# Points out of 100 for each part of a territory x category score
RQ3_POINTS = {"gap": 35, "category": 25, "underserved": 15, "momentum": 15, "margin": 10}
RQ3_GAP_CAP      = 200_000   # a sales gap this size earns full "room to grow" points
RQ3_MIN_GAP      = 50_000    # a smaller gap is not worth recommending
RQ3_SHORTLIST    = 15        # recommendations shown first
RQ3_TOP_PRODUCTS = 5         # a segment's best sellers checked for "not sold here yet"

# ---- RQ4: back-test (criteria in reference/decision_baseline.md) ----
RQ4_CASES   = [("2023-24", "2024-25"), ("2023-24", "2025-26"), ("2024-25", "2025-26")]   # (decide at, judge at)
RQ4_PRIMARY = ("2023-24", "2025-26")    # the one case the verdict rests on
# Client answers from the questionnaire are confidential, so they live in an untracked file:
# reference/client_private.json (format in reference/client_private.example.json).
_PRIVATE_PATH = os.path.join(REFERENCE_DIR, "client_private.json")
_PRIVATE = json.load(open(_PRIVATE_PATH, encoding="utf-8")) if os.path.exists(_PRIVATE_PATH) else {}
RQ4_CLIENT_PLAN   = _PRIVATE.get("rq4_client_plan", {"push": [], "cut back": [], "decided_at": "2023-24"})
STOPPED_BY_CHOICE = set(_PRIVATE.get("stopped_by_choice", []))
REP_LEFT          = set(_PRIVATE.get("rep_left", []))

# ---- Sales Action Plan (set before warning_check first ran) ----
ACTION_WINDOW_MONTHS   = 6          # latest months vs the same months a year earlier
ACTION_MIN_SALES       = 200_000    # a "big account" bought at least this in the earlier months
ACTION_FALL_PCT        = 20         # flagged when down by at least this much
QUIET_GAP_MULTIPLE     = 2          # quiet when past this many times the usual gap between orders
QUIET_MIN_DAYS         = 30         # ...and at least this many days
QUIET_MIN_ORDERS       = 4          # too few orders means no usual gap to compare with
ACTION_MIN_RESULT_DAYS = 30         # days of later sales needed before judging an action
NOT_A_CUSTOMER = r"^(?:UNKNOWN|CASH BILL)$|SAMPLE"   # labels in the customer column, nobody to contact
