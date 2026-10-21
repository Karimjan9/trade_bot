"""Deterministic synthetic tests, never mixed into the application's trading history."""
import copy
import unittest
from datetime import datetime, timedelta, timezone

import numpy as np

from train import metrics, predict, train


def dataset(count=240):
    at = datetime(2026, 1, 1, tzinfo=timezone.utc)
    rows = []
    for i in range(count):
        x = 1 if i % 2 else -1
        rows.append({"id": i + 1, "observed_at": (at + timedelta(minutes=i * 5)).isoformat(),
                     "label_end_at": (at + timedelta(minutes=i * 5 + 1)).isoformat(),
                     "features": {"alignment_15": x, "spread_r": .03, "missing": None},
                     "net_r": 1.8 if x > 0 else -1.05, "scope": "XAUUSD:BUY:fixed_tp",
                     "hypothesis": "TREND_ALIGNED" if x > 0 else "COUNTER_TREND"})
    return {"rows": rows, "feature_version": "causal-market-1", "config": {
        "min_train": 60, "min_calibration": 20, "min_test": 20,
        "min_selected": 10, "min_class_samples": 5, "min_improvement_r_per_opportunity": .01}}


class TrainingTests(unittest.TestCase):
    def test_predictive_model_improves_untouched_test_and_exports_json_coefficients(self):
        payload = dataset()
        result = train(payload)
        self.assertEqual(result["state"], "CANDIDATE")
        report = result["report"]
        self.assertGreater(report["test_filtered"]["r_per_opportunity"], report["test_baseline"]["r_per_opportunity"])
        self.assertLess(report["brier"], report["baseline_brier"])
        p, expected = predict(payload["rows"][-2:], result["artifact"])
        self.assertLess(p[0], .5)
        self.assertGreater(p[1], .5)
        self.assertLess(expected[0], 0)
        self.assertGreater(expected[1], 0)
        self.assertEqual(report["associations"][0]["feature"], "alignment_15")
        self.assertFalse(report["provider_reasoning_proven"])

    def test_later_market_reversal_does_not_activate_good_training(self):
        payload = dataset()
        for row in payload["rows"][192:]:
            row["net_r"] = -1.05 if row["features"]["alignment_15"] > 0 else 1.8
        result = train(payload)
        self.assertEqual(result["state"], "SHADOW_ONLY")
        self.assertFalse(result["report"]["candidate_pass"])

    def test_test_values_and_labels_do_not_change_fitted_transforms_or_threshold(self):
        original = dataset()
        changed = copy.deepcopy(original)
        for row in changed["rows"][192:]:
            row["features"]["alignment_15"] = 1000
            row["net_r"] = -10
        self.assertEqual(train(original)["artifact"], train(changed)["artifact"])
        result = train(changed)
        self.assertEqual(result["report"]["supported_test"], 0)
        self.assertEqual(result["report"]["test_filtered"], result["report"]["test_baseline"])

    def test_overlapping_label_windows_are_purged_before_both_boundaries(self):
        payload = dataset()
        payload["rows"][143]["label_end_at"] = payload["rows"][145]["observed_at"]
        payload["rows"][191]["label_end_at"] = payload["rows"][193]["observed_at"]
        split = train(payload)["report"]["split"]
        self.assertEqual(split["purged"], 2)
        self.assertEqual(split["train"], 143)
        self.assertEqual(split["calibration"], 47)
        self.assertLess(split["train_last_label"], split["calibration_start"])

    def test_short_or_single_class_history_is_not_a_trained_filter(self):
        self.assertEqual(train(dataset(50))["state"], "INSUFFICIENT_DATA")
        payload = dataset()
        for row in payload["rows"]:
            row["net_r"] = 1
        self.assertEqual(train(payload)["state"], "INSUFFICIENT_DATA")

    def test_skipped_winners_count_as_lost_opportunities(self):
        result = metrics(np.array([1., 10., -1.]), np.array([True, False, True]))
        self.assertEqual(result["r_per_opportunity"], 0)
        self.assertEqual(result["count"], 2)
        self.assertEqual(result["max_drawdown_r"], 1)


if __name__ == "__main__":
    unittest.main()
