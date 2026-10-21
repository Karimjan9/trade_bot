"""Local supervised channel models. JSON coefficients only: no pickle or network calls."""
import argparse
import json
from datetime import datetime
from pathlib import Path

import numpy as np
from linear import LogisticRegression, Ridge, StandardScaler

VERSION = "channel-linear-2"


def timestamp(value):
    return datetime.fromisoformat(value.replace("Z", "+00:00")).timestamp()


def sigmoid(value):
    return 1 / (1 + np.exp(-np.clip(value, -35, 35)))


def transformed(rows, artifact):
    raw = np.array([[r["features"].get(k) if r["features"].get(k) is not None else np.nan
                     for k in artifact["features"]] for r in rows], dtype=float)
    raw = np.where(np.isnan(raw), artifact["impute"], raw)
    return (raw - artifact["mean"]) / artifact["scale"]


def predict(rows, artifact):
    x = transformed(rows, artifact)
    logits = x @ artifact["classifier"]["weights"] + artifact["classifier"]["intercept"]
    p = sigmoid(logits * artifact["calibrator"]["weight"] + artifact["calibrator"]["intercept"])
    expected = x @ artifact["regressor"]["weights"] + artifact["regressor"]["intercept"]
    return p, expected


def metrics(returns, selected):
    curve = np.cumsum(np.where(selected, returns, 0.0))
    drawdown = np.maximum.accumulate(np.r_[0, curve])[1:] - curve
    count = int(np.sum(selected))
    return {"count": count, "opportunities": len(returns), "coverage": count / len(returns),
            "total_r": float(curve[-1]), "r_per_opportunity": float(curve[-1] / len(returns)),
            "expectancy_selected_r": float(np.mean(returns[selected])) if count else None,
            "max_drawdown_r": float(np.max(drawdown)),
            "win_rate": float(np.mean(returns[selected] > 0)) if count else None}


def train(payload):
    cfg = payload["config"]
    rows = sorted(payload["rows"], key=lambda r: (timestamp(r["observed_at"]), r["id"]))
    report = {"method": VERSION, "library": "NumPy " + np.__version__ + "; local regularized linear estimators",
              "label": "net R from frozen counterfactual plan, after costs; not provider claims",
              "rows": len(rows), "candidate_pass": False}
    minimum = cfg["min_train"] + cfg["min_calibration"] + cfg["min_test"]
    if len(rows) < minimum:
        return {"state": "INSUFFICIENT_DATA", "report": report | {"required_at_least": minimum}, "artifact": None}
    a, b = int(len(rows) * .6), int(len(rows) * .8)
    train_rows = [r for r in rows[:a] if timestamp(r["label_end_at"]) < timestamp(rows[a]["observed_at"])]
    cal_rows = [r for r in rows[a:b] if timestamp(r["label_end_at"]) < timestamp(rows[b]["observed_at"])]
    test_rows = rows[b:]
    report["split"] = {"train": len(train_rows), "calibration": len(cal_rows), "test": len(test_rows),
                       "purged": b - len(train_rows) - len(cal_rows),
                       "test_start": test_rows[0]["observed_at"], "test_ids": [r["id"] for r in test_rows],
                       "train_last_label": max((r["label_end_at"] for r in train_rows), default=None),
                       "calibration_start": rows[a]["observed_at"]}
    enough = len(train_rows) >= cfg["min_train"] and len(cal_rows) >= cfg["min_calibration"] and len(test_rows) >= cfg["min_test"]
    for partition in [train_rows, cal_rows]:
        labels = [r["net_r"] > 0 for r in partition]
        enough = enough and min(labels.count(True), labels.count(False)) >= cfg["min_class_samples"]
    if not enough:
        return {"state": "INSUFFICIENT_DATA", "report": report | {"reason": "PURGED_SPLIT_OR_CLASS_SUPPORT"}, "artifact": None}
    keys = sorted(train_rows[0]["features"])
    raw = np.array([[r["features"].get(k) if r["features"].get(k) is not None else np.nan for k in keys] for r in train_rows], dtype=float)
    # Fit all transforms on training rows only; entirely missing columns become zero.
    medians = np.array([np.median(col[~np.isnan(col)]) if np.any(~np.isnan(col)) else 0 for col in raw.T])
    scaler = StandardScaler().fit(np.where(np.isnan(raw), medians, raw))
    x = scaler.transform(np.where(np.isnan(raw), medians, raw))
    y = np.array([r["net_r"] > 0 for r in train_rows], dtype=int)
    classifier = LogisticRegression(C=1.0, max_iter=200).fit(x, y)
    regressor = Ridge(alpha=10).fit(x, [r["net_r"] for r in train_rows])
    scopes = []
    for scope in sorted({r["scope"] for r in train_rows}):
        scope_rows = [r for r in train_rows if r["scope"] == scope]
        positive = sum(r["net_r"] > 0 for r in scope_rows)
        if len(scope_rows) >= cfg["min_selected"] and min(positive, len(scope_rows) - positive) >= 2:
            scopes.append(scope)
    artifact = {"version": VERSION, "feature_version": payload["feature_version"], "features": keys,
                "impute": medians.tolist(), "mean": scaler.mean_.tolist(), "scale": scaler.scale_.tolist(),
                "classifier": {"weights": classifier.coef_[0].tolist(), "intercept": float(classifier.intercept_[0])},
                "regressor": {"weights": regressor.coef_.tolist(), "intercept": float(regressor.intercept_)},
                "scopes": scopes, "max_abs_z": 8.0, "calibrator": {"weight": 1.0, "intercept": 0.0}}
    cal_x = transformed(cal_rows, artifact)
    cal_logits = classifier.decision_function(cal_x).reshape(-1, 1)
    calibrator = LogisticRegression(C=1.0, max_iter=1000).fit(cal_logits, [r["net_r"] > 0 for r in cal_rows])
    artifact["calibrator"] = {"weight": float(calibrator.coef_[0][0]), "intercept": float(calibrator.intercept_[0])}
    p, expected = predict(cal_rows, artifact)
    returns = np.array([r["net_r"] for r in cal_rows])
    # Scope and OOD rules apply during validation exactly as they will at runtime.
    def supported(partition):
        return np.array([r["scope"] in scopes for r in partition]) & (np.max(np.abs(transformed(partition, artifact)), axis=1) <= artifact["max_abs_z"])
    cal_supported = supported(cal_rows)
    choices = []
    for threshold in [.5, .55, .6, .65, .7, .75]:
        # An abstention falls back to the unfiltered plan; it is never counted as a saved loss.
        selected = ~cal_supported | ((p >= threshold) & (expected > 0))
        stats = metrics(returns, selected)
        if stats["count"] >= cfg["min_selected"]:
            choices.append((stats["r_per_opportunity"], threshold, stats))
    if not choices:
        return {"state": "SHADOW_ONLY", "artifact": artifact | {"threshold": .5}, "report": report | {"reason": "CALIBRATION_SELECTION_TOO_SMALL"}}
    _, threshold, cal_metrics = max(choices, key=lambda item: (item[0], -item[1]))
    artifact["threshold"] = threshold
    p_test, er_test = predict(test_rows, artifact)
    test_supported = supported(test_rows)
    test_selected = ~test_supported | ((p_test >= threshold) & (er_test > 0))
    test_r = np.array([r["net_r"] for r in test_rows])
    baseline = metrics(test_r, np.ones(len(test_r), dtype=bool))
    filtered = metrics(test_r, test_selected)
    brier = float(np.mean((p_test - (test_r > 0)) ** 2))
    baseline_brier = float(np.mean((np.mean(y) - (test_r > 0)) ** 2))
    passed = (filtered["count"] >= cfg["min_selected"] and filtered["total_r"] > 0
              and filtered["r_per_opportunity"] > baseline["r_per_opportunity"] + cfg["min_improvement_r_per_opportunity"]
              and filtered["max_drawdown_r"] <= baseline["max_drawdown_r"] and brier < baseline_brier
              and int(np.sum(test_supported)) >= cfg["min_selected"])
    associations = sorted(zip(keys, regressor.coef_.tolist()), key=lambda kv: abs(kv[1]), reverse=True)[:8]
    cohorts = {}
    for key in sorted({r.get("hypothesis", "UNDETERMINED") for r in test_rows}):
        values = [r["net_r"] for r in test_rows if r.get("hypothesis", "UNDETERMINED") == key]
        cohorts[key] = {"count": len(values), "mean_net_r": float(np.mean(values))}
    report.update({"threshold": threshold, "calibration": cal_metrics, "test_baseline": baseline,
                   "test_filtered": filtered, "brier": brier, "baseline_brier": baseline_brier,
                   "supported_test": int(np.sum(test_supported)), "candidate_pass": bool(passed),
                   "associations": [{"feature": k, "net_r_per_standard_deviation": v} for k, v in associations],
                   "market_hypotheses": cohorts, "provider_reasoning_proven": False,
                   "test_predictions": [{"id": r["id"], "p": float(pv), "expected_r": float(ev), "selected": bool(sel)}
                                        for r, pv, ev, sel in zip(test_rows, p_test, er_test, test_selected)]})
    return {"state": "CANDIDATE" if passed else "SHADOW_ONLY", "artifact": artifact, "report": report}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    result = train(json.loads(Path(args.input).read_text(encoding="utf-8")))
    Path(args.output).write_text(json.dumps(result, allow_nan=False), encoding="utf-8")


if __name__ == "__main__":
    main()
