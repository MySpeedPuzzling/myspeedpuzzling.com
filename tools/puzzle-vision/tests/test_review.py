import pytest

from puzzle_vision.proposals import review


def item(i, conf, status="pending", note=None):
    return {"id": f"cr-{i:05d}", "puzzleId": f"p{i}", "confidence": conf, "status": status, "modelVersion": "artwork-crop 1.0.0",
            "recordVersion": f"rv{i}", "originalImageUrl": f"https://img/o{i}", "proposedImageUrl": f"https://img/p{i}",
            "decision": None if status == "pending" else {"by": "mod", "at": "2026-10-20T10:00:00+00:00", "outcome": status, "note": note}}


def test_wilson_lower_bound_known_values():
    assert review.wilson_lower(0, 0) == 0
    assert review.wilson_lower(380, 380) == pytest.approx(0.99, abs=0.0005)
    assert review.wilson_lower(379, 380) < 0.99 < review.wilson_lower(400, 400)
    assert review.wilson_lower(50, 100) == pytest.approx(0.4038, abs=0.001)


def test_nothing_is_auto_approved_before_300_human_decisions():
    items = [item(i, 0.99, "approved") for i in range(299)] + [item(1000 + i, 0.995) for i in range(10)]
    p = review.plan(items)
    assert not p.earned and p.to_approve == [] and "299 of 300" in p.reason
    assert len(p.middle_band) == 10


def test_auto_and_claude_decisions_never_earn_the_threshold():
    items = [item(i, 0.99, "approved", note="auto: confidence 0.990") for i in range(400)]
    items += [item(500 + i, 0.99, "approved", note="claude-review: confidence 0.990") for i in range(100)]
    assert review.plan(items).human_decisions == 0


def test_threshold_is_the_lowest_band_with_a_99_percent_lower_bound():
    items = [item(i, 0.97 + (i % 30) / 1000, "approved") for i in range(450)]          # 0.970-0.999, all right
    items += [item(1000 + i, 0.80 + i / 1000, "rejected" if i % 10 == 9 else "approved") for i in range(150)]  # 90 % right below
    items += [item(2000 + i, c) for i, c in enumerate([0.995, 0.975, 0.9, 0.7, 0.5])]
    p = review.plan(items)
    assert p.earned and 0.969 < p.threshold <= 0.971 and p.threshold_lower_bound >= 0.99
    approve_conf = sorted(e["confidence"] for e in p.to_approve + p.audit_held)
    assert approve_conf == [0.975, 0.995]
    assert [e["confidence"] for e in p.middle_band] == [0.9, 0.7]  # 0.5 is below the propose threshold
    for e in p.to_approve:
        assert e["decisionNote"].startswith("auto: confidence ") and "band >=" in e["decisionNote"] and "artwork-crop 1.0.0" in e["decisionNote"]


def test_audit_items_are_deterministic_and_about_five_percent():
    ids = [f"cr-{i}" for i in range(20000)]
    share = sum(review.is_audit(i) for i in ids) / len(ids)
    assert 0.04 < share < 0.06
    assert [review.is_audit(i) for i in ids[:50]] == [review.is_audit(i) for i in ids[:50]]


def test_audit_held_items_stay_pending_for_humans():
    items = [item(i, 0.99, "approved") for i in range(450)]
    items += [item(10000 + i, 0.995) for i in range(400)]
    p = review.plan(items)
    held = {e["id"] for e in p.audit_held}
    assert held and all(review.is_audit(i) for i in held) and not held & {e["id"] for e in p.to_approve}


def test_auto_stop_when_audited_precision_drops_below_98_percent():
    items = [item(i, 0.99, "approved") for i in range(2000)]
    audit_ids = [i for i in range(2000, 40000) if review.is_audit(f"cr-{i:05d}")][:40]
    items += [item(i, 0.99, "rejected" if n < 4 else "approved") for n, i in enumerate(audit_ids)]  # 90 % on audited
    items += [item(90000 + i, 0.995) for i in range(30)]
    p = review.plan(items)
    assert p.earned and p.auto_stopped and p.to_approve == [] and "STOPPED" in p.reason
    assert p.audit_precision < 0.98


def test_bands_report():
    items = [item(1, 0.62, "approved"), item(2, 0.63, "rejected"), item(3, 0.99, "approved", note="auto: x"), item(4, 0.97)]
    bands = {b["band"]: b for b in review.bands([review.parse_item(i) for i in items])}
    assert bands["0.60-0.65"]["humanApproved"] == 1 and bands["0.60-0.65"]["humanRejected"] == 1
    assert bands["0.95-1.00"]["auto"] == 1 and bands["0.95-1.00"]["pending"] == 1
