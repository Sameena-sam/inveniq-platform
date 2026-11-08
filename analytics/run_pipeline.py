# =============================================================
# InvenIQ Sales Intelligence — run the analytics pipeline
#
#   python run_pipeline.py                # prepare data + RQ1 to RQ4, write files and MySQL
#   python run_pipeline.py --no-db        # files only (no XAMPP needed)
#   python run_pipeline.py --data "D:\path\to\workbook.xlsx"
#
# Files go to analytics/output/ (CSV, JSON and Markdown for the thesis);
# MySQL gets the sa_* tables the Sales Intelligence pages read.
# =============================================================
import os
import sys
import json
import argparse
import datetime as dt

import config as cfg
import data_prep
import rq1_regional as rq1
import rq2_category as rq2
import rq3_expansion as rq3
import rq4_backtest as rq4
import sales_actions
import db_writer


def dump_json(obj, path: str) -> None:
    with open(path, "w", encoding="utf-8") as f:
        f.write(json.dumps(obj, indent=2, ensure_ascii=False,
                           default=lambda o: o.item() if hasattr(o, "item") else str(o)))


def write_rq1_files(res: dict, out_dir: str) -> None:
    terr = res["territories"].copy()
    terr["reasons"] = terr["reasons"].map(" | ".join)
    terr.sort_values(["composite_rank", "revenue"], ascending=[True, False]).to_csv(
        os.path.join(out_dir, "rq1_territory_scores.csv"), index_label="location_code")
    res["territory_yearly"].to_csv(os.path.join(out_dir, "rq1_territory_yearly.csv"), index=False)
    hq = res["hq"].copy()
    hq["reasons"] = hq["reasons"].map(" | ".join)
    hq.to_csv(os.path.join(out_dir, "rq1_hq_scores.csv"), index_label="hq")
    dump_json({"params": res["params"], "metrics": res["metrics"]}, os.path.join(out_dir, "rq1_evaluation.json"))
    write_rq1_summary(res, out_dir)


def write_rq1_summary(res: dict, out_dir: str) -> None:
    """Readable results for drafting the thesis (tables + reasons)."""
    t, m = res["territories"], res["metrics"]
    fy = res["fys"][-1]
    act = t[t["status"] == "Active"].sort_values("composite_rank")
    lines = [f"# RQ1 regional performance — FY{fy}", "",
             "Segments: " + ", ".join(f"{k} {v}" for k, v in m["segment_counts"].items() if v), "",
             "| # | Territory | HQ | Segment | Revenue | Margin % | Growth %/yr | Customers | Products | Score | Revenue rank | Rank range (P10-P90) |",
             "|---:|---|---|---|---:|---:|---:|---:|---:|---:|---:|---|"]
    for r in act.itertuples():
        lines.append(f"| {int(r.composite_rank)} | {r.territory} | {r.hq} | {r.segment} | {rq1.fmt_inr(r.revenue)} | "
                     f"{r.margin_pct:.1f} | {r.growth_cagr_pct:+.1f} | {int(r.customers)} | {int(r.products)} | "
                     f"{r.composite_score:.1f} | {int(r.revenue_rank)} | {int(r.rank_low)}-{int(r.rank_high)} |")
    lines += ["", "## Revenue-only ranking vs multi-factor, by year", "",
              "| FY | Active | Spearman | Multi-factor under-performers | Revenue bottom-k (all with sales) | Only multi-factor | Only revenue | Only multi-factor, baseline from active territories only | Lapsed (not in a revenue ranking) |",
              "|---|---:|---:|---|---|---|---|---|---|"]
    for c in m["baseline_comparison_by_year"]:
        u = c["under_performers"]
        lines.append(f"| {c['fy']} | {c['active_units']} | {c['spearman_composite_vs_revenue_rank']} | "
                     f"{', '.join(u['multi_factor'])} | {', '.join(u['revenue_bottom_k'])} | "
                     f"{', '.join(u['surfaced_only_by_multi_factor']) or '—'} | "
                     f"{', '.join(x['name'] for x in u['revenue_bottom_but_not_under_performing']) or '—'} | "
                     f"{', '.join(c['under_performers_active_only']['surfaced_only_by_multi_factor']) or '—'} | "
                     f"{', '.join(x['name'] for x in c['lapsed_invisible_to_revenue_ranking']) or '—'} |")
    pm = m["peer_model"].get("chosen", {})
    sens = m["weight_sensitivity"]
    lines += ["", "## Model checks", "",
              f"- Peer (reach) model: features {pm.get('features')}, n = {pm.get('n')}, "
              f"in-sample R² {pm.get('r2_in_sample')}, leave-one-out median error {pm.get('loo_median_abs_pct_error')}%, "
              f"flag threshold {pm.get('flag_threshold_pct')}%.",
              f"- Weight sensitivity ({sens['draws']} random weightings): mean Spearman vs equal weights "
              f"{sens['spearman_vs_equal_weights_mean']} (5th percentile {sens['spearman_vs_equal_weights_p5']}); "
              f"segments unchanged in {sens['segment_stability_mean_pct']}% of draws on average.",
              "- Ranking stability (Spearman, consecutive years): " + "; ".join(
                  f"{s['from']}→{s['to']} revenue {s['revenue_rank_spearman']}"
                  + (f", composite {s['composite_rank_spearman']}" if "composite_rank_spearman" in s else "")
                  for s in m["rank_stability"]), "",
              "## Reasons per territory", ""]
    for r in t.sort_values(["composite_rank", "revenue"], ascending=[True, False]).itertuples():
        lines.append(f"**{r.territory}** ({r.hq}) — {r.segment}")
        lines += [f"- {x}" for x in r.reasons] + [""]
    with open(os.path.join(out_dir, "rq1_summary.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(lines))


def write_rq2_files(res: dict, out_dir: str) -> None:
    drop = ["abc_path", "xyz_path", "pred_abc_dist", "pred_xyz_dist"]
    prod = res["products"].drop(columns=drop).copy()
    prod["reasons"] = prod["reasons"].map(" | ".join)
    prod["outlook_reasons"] = prod["outlook_reasons"].map(" | ".join)
    prod.to_csv(os.path.join(out_dir, "rq2_product_classes.csv"), index=False)
    res["watch"].to_csv(os.path.join(out_dir, "rq2_watch_list.csv"), index=False)
    res["history"].to_csv(os.path.join(out_dir, "rq2_class_history.csv"), index=False)
    seg = res["segments"].copy()
    seg["reasons"] = seg["reasons"].map(" | ".join)
    seg.to_csv(os.path.join(out_dir, "rq2_segment_profile.csv"))
    res["test_predictions"].to_csv(os.path.join(out_dir, "rq2_test_predictions.csv"), index=False)
    dump_json({"params": res["params"], "metrics": res["metrics"]}, os.path.join(out_dir, "rq2_evaluation.json"))
    write_rq2_summary(res, out_dir)


def write_rq2_summary(res: dict, out_dir: str) -> None:
    """Readable results for drafting the thesis (model comparison, rules, segments)."""
    m, ev = res["metrics"], res["metrics"]["evaluation"]
    labels, sm = ev["method_labels"], m["served_model"]
    lines = [f"# RQ2 product-category identification — FY{m['fy']} (outlook FY{m['next_fy']})", "",
             f"{m['data']['transitions']} product-year transitions; test years "
             f"{', '.join(ev['test_target_years'])} (each trained only on earlier years).", "",
             "## Next-year class prediction, pooled test years", "",
             "| Method | ABC acc | ABC macro-F1 | XYZ acc | XYZ macro-F1 | Class acc | Class macro-F1 | Changers caught | Change calls right | Product-level p vs last year (class) | Row-level McNemar p (for comparison) |",
             "|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|"]
    for k, s in ev["pooled"].items():
        sig = ev["significance_vs_persistence"].get(k, {}).get("class", {})
        calls = f"{s['change_precision']} (of {s['changes_predicted']})" if s["change_precision"] is not None else "—"
        lines.append(f"| {labels[k]} | {s['abc_accuracy']:.3f} | {s['abc_macro_f1']:.3f} | {s['xyz_accuracy']:.3f} | "
                     f"{s['xyz_macro_f1']:.3f} | {s['class_accuracy']:.3f} | {s['class_macro_f1']:.3f} | "
                     f"{s['movers_accuracy'] if s['movers_accuracy'] is not None else '—'} | {calls} | {sig.get('product_level_p', '—')} | {sig.get('mcnemar_p', '—')} |")
    lines += ["", "## Class accuracy by test year", "",
              "| Method | " + " | ".join(ev["test_target_years"]) + " |",
              "|---|" + "---:|" * len(ev["test_target_years"])]
    for k in ev["pooled"]:
        lines.append(f"| {labels[k]} | " + " | ".join(f"{ev['by_year'][y][k]['class_accuracy']:.3f}"
                                                      for y in ev["test_target_years"]) + " |")
    lines += ["", "## Year-to-year stability of classes", "",
              "| From → to | Products | Same ABC % | Same XYZ % | Same class % |", "|---|---:|---:|---:|---:|"]
    lines += [f"| {t['from']} → {t['to']} | {t['products']} | {t['same_abc_pct']} | {t['same_xyz_pct']} | "
              f"{t['same_class_pct']} |" for t in m["data"]["year_to_year"]]
    lines += ["", f"{m['data']['xyz_changes_near_cutoff_pct']}% of the {m['data']['xyz_changes']} XYZ changes "
              f"are a CV drifting across a cut-off (both years within 0.15 of it).", "",
              f"## Served model: {labels[sm['method']]}", ""]
    for dim in ("abc", "xyz"):
        d = sm[dim]
        lines += [f"### {dim.upper()} — depth {d['depth']}, {d['leaves']} leaves, CV macro-F1 {d['cv_macro_f1']}, "
                  f"params {d['params']}", "", "```", d["rules"].rstrip(), "```", ""]
    lines += ["## Segments (categories)", "",
              "| Segment | Class | Label | Revenue share % | Products (A) | Stable revenue % | Growth %/yr | Margin % |",
              "|---|---|---|---:|---:|---:|---:|---:|"]
    for s, r in res["segments"].iterrows():
        g = f"{r.growth_cagr_pct:+.1f}" if r.growth_cagr_pct == r.growth_cagr_pct else "—"
        lines.append(f"| {s} | {r.seg_class} | {r.label} | {r.revenue_share_pct:.1f} | {int(r.products)} ({int(r.a_products)}) | "
                     f"{r.stable_rev_share_pct:.0f} | {g} | {r.margin_pct:.1f} |")
    moves = res["products"][res["products"]["pred_class"] != res["products"]["class"]]
    lines += ["", f"## Predicted class changes for FY{m['next_fy']} ({len(moves)})", ""]
    lines += [f"- **{r['product']}** ({r['segment']}): {r['class']} → {r['pred_class']}" for _, r in moves.iterrows()]
    lines += ["", f"## Client watch list, FY{m['fy']} ({len(res['watch'])})", ""]
    lines += [f"- **{r['product']}** ({r['kind']}): {r['detail']}" for _, r in res["watch"].iterrows()]
    with open(os.path.join(out_dir, "rq2_summary.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(lines) + "\n")


def write_rq3_files(res: dict, out_dir: str) -> None:
    cells = res["cells"].sort_values(["rank", "score"], ascending=[True, False]).copy()
    cells["reasons"] = cells["reasons"].map(" | ".join)
    # action_plan holds customer names: the app needs them, the thesis files must not have them
    cells.drop(columns="action_plan").to_csv(os.path.join(out_dir, "rq3_expansion_cells.csv"), index=False)
    res["win_back"].to_csv(os.path.join(out_dir, "rq3_win_back.csv"), index=False)
    dump_json({"params": res["params"], "metrics": res["metrics"]}, os.path.join(out_dir, "rq3_evaluation.json"))
    write_rq3_summary(res, out_dir)


def write_rq3_summary(res: dict, out_dir: str) -> None:
    """Readable results for drafting the thesis (shortlist, checks, win-backs)."""
    m, cfgp = res["metrics"], res["params"]
    short = res["cells"][res["cells"]["eligible"]].nsmallest(cfgp["RQ3_SHORTLIST"], "rank")
    st = m["stability"]
    lines = [f"# RQ3 expansion decision support — FY{m['fy']}", "",
             f"{m['cells_scored']} territory x category pairs scored, {m['cells_eligible']} worth recommending "
             f"(gap of at least {rq1.fmt_inr(cfgp['RQ3_MIN_GAP'])}). Points: {cfgp['RQ3_POINTS']}.", "",
             "| # | Territory | Category | Score | Possible extra sales | Share vs usual % | Points: gap / category / under-served / trend / margin | Stays in shortlist % |",
             "|---:|---|---|---:|---:|---|---|---:|"]
    for r in short.itertuples():
        lines.append(f"| {int(r.rank)} | {r.territory} | {r.segment} ({r.seg_class}) | {r.score:.1f} | {rq1.fmt_inr(r.gap_value)} | "
                     f"{r.share_pct:.1f} vs {r.peer_share_pct:.1f} | {r.points_gap:.0f} / {r.points_category:.0f} / "
                     f"{r.points_underserved:.0f} / {r.points_momentum:.0f} / {r.points_margin:.0f} | {r.shortlist_stability_pct:.0f} |")
    lines += ["", "## Checks", "",
              f"- Shortlist makeup: territories {m['shortlist_territories']}; categories {m['shortlist_segments']}.",
              f"- With {st['weight_draws']} random weightings, on average {st['shortlist_overlap_random_weights_mean']:.0%} of the "
              f"shortlist stays (5th percentile {st['shortlist_overlap_random_weights_p5']:.0%}).",
              f"- Halving the gap cap keeps {st['shortlist_overlap_cap_half']:.0%}; doubling it keeps {st['shortlist_overlap_cap_double']:.0%}.",
              f"- Overlap with simply backing the biggest existing sales: {st['overlap_with_biggest_existing_sales']:.0%}.",
              f"- Action plans for the shortlist: {m['shortlist_customers_to_approach']} existing customer openings; about "
              f"{rq1.fmt_inr(m['shortlist_from_existing_customers'])} could come from them at typical spend, "
              f"{rq1.fmt_inr(m['shortlist_from_new_customers'])} would need new customers.", "",
              "## Territories to win back", ""]
    lines += [f"- **{r.territory}** ({r.hq}): best year FY{r.best_fy} at {rq1.fmt_inr(r.best_sales)}, last sale {r.last_sale:%b %Y}. Bought: {r.bought}."
              + (f" Client: {r.why_stopped.lower()}." if r.why_stopped else "")
              for r in res["win_back"].itertuples()]
    lines += ["", "## Reasons, shortlist", ""]
    for r in short.itertuples():
        lines.append(f"**{int(r.rank)}. {r.territory}, {r.segment}**")
        lines += [f"- {x}" for x in r.reasons] + [""]
    with open(os.path.join(out_dir, "rq3_summary.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(lines))


def write_rq4_files(res: dict, out_dir: str) -> None:
    keep = ["origin", "target", "method", "rank", "territory", "segment", "sales", "sales_base", "sales_after", "expected_after",
            "excess", "hit", "gap_value", "score", "eligible"]
    res["picks"][keep].to_csv(os.path.join(out_dir, "rq4_picks.csv"), index=False)
    dump_json({"params": res["params"], "metrics": res["metrics"]}, os.path.join(out_dir, "rq4_evaluation.json"))
    write_rq4_summary(res, out_dir)


def write_rq4_summary(res: dict, out_dir: str) -> None:
    """Readable results for drafting the thesis."""
    m = res["metrics"]
    labels = {"system": "System top 15", "baseline_biggest_sales": "Baseline: biggest current sales",
              "room_to_grow_only": "Room to grow only"}
    inr = rq1.fmt_inr
    lines = ["# RQ4 back-test", "",
             f"**Verdict on the primary case (decide FY{m['primary_case'][0]}, judge FY{m['primary_case'][1]}): {m['verdict']}.** "
             f"Criteria: `reference/decision_baseline.md`. Baseline status: {m['baseline_status']}.", ""]
    f = m["forward"]
    if f:
        lines += [f"**Forward test, picks made before the new months arrived: {f['verdict']}.** See the last case below.", ""]
    for c in m["cases"] + ([f] if f else []):
        lines += [f"## {c.get('heading') or 'Decide FY' + c['origin'] + ', judge FY' + c['target']}", "",
                  f"Pool of {c['pairs_in_pool']} pairs moved {c['pool_change_pct']:+.1f}%; {c['pool_hit_rate']:.0%} of them beat that. "
                  f"Eligible pairs ({c['pairs_eligible']}): {c['eligible_hit_rate']:.0%}.", "",
                  "| Method | Hits | Hit rate | Sales before | Sales after | Beyond moving with the pool | Share of predicted gap realised |",
                  "|---|---:|---:|---:|---:|---:|---:|"]
        for k, s in c["methods"].items():
            real = f"{s['share_of_gap_realised']:.0%}" if s["share_of_gap_realised"] is not None else "—"
            lines.append(f"| {labels[k]} | {s['hits']} of {s['picks']} | {s['hit_rate']:.0%} | {inr(s['sales_before'])} | "
                         f"{inr(s['sales_after'])} | {inr(s['excess'])} | {real} |")
        lines += ["", f"- Random picks from eligible pairs: {c['random_eligible_mean_hit_rate']:.0%} on average; "
                      f"system vs random p = {c['p_system_vs_random_eligible']}.",
                  f"- Random picks from all pairs: {c['random_all_mean_hit_rate']:.0%}; p = {c['p_system_vs_random_all']}.",
                  f"- System vs baseline, Fisher exact (one-sided): p = {c['fisher_p_system_vs_baseline']}.", ""]
    lines += ["## The client's own plan (territory level, descriptive)", "",
              f"From the questionnaire: push {', '.join(cfg.RQ4_CLIENT_PLAN['push']) or 'none'} and cut back {', '.join(cfg.RQ4_CLIENT_PLAN['cut back']) or 'none'} from FY2024-25. Outcomes were known before "
              "the plan was received, so this is not a test.", "",
              "| Territory | Plan | Period | Change | Rest of the business | RQ1 at the time |", "|---|---|---|---:|---:|---|"]
    lines += [f"| {r['territory']} | {r['plan']} | {r['period']} | {r['change_pct']:+.1f}% | {r['rest_change_pct']:+.1f}% | {r['rq1_then']} |"
              for r in m["client_plan"]]
    lines += ["", "## Territories flagged by RQ1: what happened next", "",
              "| Decide → judge | Group | Territories | Stopped selling | Outgrew the rest of the business |", "|---|---|---|---:|---:|"]
    for t in m["territories"]:
        for key, name in (("flagged_untapped", "Flagged untapped"), ("revenue_bottom_all", "Revenue bottom, all with sales"),
                          ("revenue_bottom_active", "Revenue bottom, active only")):
            g = t[key]
            lines.append(f"| {t['origin']} → {t['target']} | {name} | {', '.join(g['territories'])} | "
                         f"{g['stopped_selling']} of {g['n']} | {g['outgrew_rest_of_business']} of {g['n']} |")
    lines += [""] + [f"- {t['origin']} → {t['target']}: score vs later growth, Spearman {t['spearman_score_vs_later_growth']}; "
                     f"revenue vs later growth, {t['spearman_revenue_vs_later_growth']}; active territories outgrowing the rest: "
                     f"{t['active_outgrew_rest_of_business']}." for t in m["territories"]]
    with open(os.path.join(out_dir, "rq4_summary.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(lines) + "\n")


def write_action_files(res: dict, out_dir: str) -> None:
    """Counts and checks only: the customer lists hold names, so they stay in MySQL for the page."""
    dump_json({"params": res["params"], "metrics": res["metrics"]}, os.path.join(out_dir, "sales_actions_evaluation.json"))
    m, inr = res["metrics"], rq1.fmt_inr
    w = m["warning_check"]
    a, q = w["accounts_fell_20pct_again"], w["quiet_bought_nothing_next_6_months"]
    row = lambda name, c: (f"| {name} | {c['flagged']} / {c['others']} | {c['flagged_share']:.0%} | {c['others_share']:.0%} | "
                           f"{c['difference']:+.0%} ({c['interval_95'][0]:+.0%} to {c['interval_95'][1]:+.0%}) | {'Yes' if c['holds_up'] else 'No'} |")
    lines = ["# Sales Action Plan", "",
             f"Sales up to {m['as_of']}. Extension for the client, beyond the research questions.", "",
             f"- {m['accounts_flagged']} big accounts are buying less, {inr(m['accounts_fall_total'])} below the same months last year.",
             f"- {m['quiet_flagged']} more customers are well past their usual reorder time, worth {inr(m['quiet_value_total'])} over the last 12 months.", "",
             f"## Did these flags mean anything in past years? ({len(w['checkpoints'])} half-year checkpoints)", "",
             "Flags use only earlier sales, then the next six months are checked. Holds up: flagged customers did worse "
             "and the 95% interval, resampling whole customers, excludes zero.", "",
             "| Check | Customer-periods, flagged / others | Flagged | Others | Difference (95% interval) | Holds up |",
             "|---|---:|---:|---:|---:|---|",
             row("Big account fell 20% or more again", a), row("Customer bought nothing in the next six months", q), ""]
    with open(os.path.join(out_dir, "sales_actions_summary.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(lines))


def main():
    # joblib probes physical cores with `wmic`, which Windows 11 no longer ships, and prints
    # the failure's traceback. A core limit below the logical CPU count skips the probe
    # (inherited by its worker processes); half the logical CPUs ~ the physical cores.
    os.environ.setdefault("LOKY_MAX_CPU_COUNT", str(max(1, (os.cpu_count() or 2) // 2)))
    ap = argparse.ArgumentParser(description="InvenIQ sales-intelligence pipeline")
    ap.add_argument("--data", help="path to the sales workbook (default: app root)")
    ap.add_argument("--no-db", action="store_true", help="write files only, skip MySQL")
    args = ap.parse_args()
    run_id = dt.datetime.now().strftime("%Y%m%d-%H%M%S")

    print("=" * 60)
    print(f"InvenIQ Sales Intelligence pipeline — run {run_id}")
    print("=" * 60)
    print("[1/7] Preparing data (clean, reconcile, features) ...")
    df, master, ty, hy, rep = data_prep.prepare(args.data)
    full = df[df["fy"].isin(rep["complete_fys"])]       # RQ1-RQ4 compare whole fiscal years
    print(f"        {rep['clean_rows']:,} lines, {rep['entities']['locations']} locations, "
          f"{rep['entities']['segments']} segments; reconciliation max diff "
          f"Rs {rep['reconciliation_max_abs_difference']:,.2f}")
    if rep["partial_fys"]:
        print(f"        part-year FY{', FY'.join(rep['partial_fys'])} used for the Sales Action Plan only")

    print("[2/7] RQ1 regional performance ...")
    res1 = rq1.run(master, ty, hy)
    write_rq1_files(res1, cfg.OUTPUT_DIR)
    seg = res1["metrics"]["segment_counts"]
    print("        " + ", ".join(f"{k}: {v}" for k, v in seg.items() if v))

    print("[3/7] RQ2 product categories (ABC-XYZ + classifiers; a few minutes) ...")
    res2 = rq2.run(full)
    write_rq2_files(res2, cfg.OUTPUT_DIR)
    pooled = res2["metrics"]["evaluation"]["pooled"]
    served = res2["metrics"]["served_model"]["method"]
    print(f"        next-year class accuracy: same-as-last-year {pooled['persistence']['class_accuracy']:.1%}, "
          f"served tree {pooled[served]['class_accuracy']:.1%}, "
          f"best {max(pooled.values(), key=lambda s: s['class_accuracy'])['class_accuracy']:.1%}")

    print("[4/7] RQ3 expansion opportunities ...")
    res3 = rq3.run(full, res1, res2)
    write_rq3_files(res3, cfg.OUTPUT_DIR)
    print(f"        {res3['metrics']['cells_eligible']} of {res3['metrics']['cells_scored']} territory-category pairs "
          f"worth recommending; {res3['metrics']['win_back_territories']} territories to win back")

    print("[5/7] RQ4 back-test ...")
    res4 = rq4.run(full, df)
    write_rq4_files(res4, cfg.OUTPUT_DIR)
    print(f"        verdict on the primary case: {res4['metrics']['verdict']}")
    if res4["metrics"]["forward"]:
        print(f"        forward test on the new months: {res4['metrics']['forward']['verdict']}")

    print("[6/7] Sales action plan ...")
    res5 = sales_actions.run(df)
    write_action_files(res5, cfg.OUTPUT_DIR)
    print(f"        {res5['metrics']['accounts_flagged']} big accounts buying less; {res5['metrics']['quiet_flagged']} customers gone quiet")

    print("[7/7] Writing results to MySQL ...")
    if args.no_db:
        print("        skipped (--no-db)")
    else:
        try:
            counts = db_writer.write_all({"rq1": res1, "rq2": res2, "rq3": res3, "rq4": res4, "actions": res5}, run_id, {
                "data_from": rep["date_range"][0], "data_to": str(full["bill_date"].max().date())})
            print("        " + ", ".join(f"{k} {v}" for k, v in counts.items()))
        except Exception as e:
            print(f"        ! MySQL not updated ({e}).\n"
                  f"          Start XAMPP MySQL with the 'inveniq' database imported, or use --no-db.")
            sys.exit(1)

    print(f"\nDONE. Files in {cfg.OUTPUT_DIR}")
    print("Open the pages:  http://localhost/inveniq/pages/regional-performance.php")
    print("                 http://localhost/inveniq/pages/product-categories.php")
    print("                 http://localhost/inveniq/pages/expansion-opportunities.php")
    print("                 http://localhost/inveniq/pages/sales-action-plan.php")


if __name__ == "__main__":
    main()
