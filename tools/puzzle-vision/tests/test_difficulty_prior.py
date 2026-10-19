"""difficulty_prior (plan §5.3) on synthetic data with a planted signal, against the fake internal API."""

import argparse
from datetime import datetime, timezone

import numpy as np
import pytest

from puzzle_vision.api import DifficultyLabel, DifficultyModelRecord
from puzzle_vision.cli import parser
from puzzle_vision.derive import Deriver, difficulty_prior as dp
from puzzle_vision.status import render
from puzzle_vision.payload import vector_to_b64

from .conftest import pid

S_DIM, D_DIM = 24, 12
NOW = lambda: datetime(2026, 10, 19, 3, 0, tzinfo=timezone.utc)  # noqa: E731


def put(server, analyzer, p, result, fp=None, version="1.0.0+test"):
    server.analyses[(analyzer, p)] = {"puzzleId": p, "analyzer": analyzer, "analyzerVersion": version,
                                      "imageFingerprint": fp or server.puzzles[p]["imageFingerprint"],
                                      "analyzedAt": None, "receivedAt": "x", "result": result}


def unit(v):
    return v / np.linalg.norm(v)


def planted(server, n=600, labelled=480, seed=0, noise=None, full_only=(), no_siglip=()):
    """`n` puzzles; the first `labelled` carry a label = a linear function of the (normalised) embeddings + pieces +
    puzzle-level noise the picture cannot see (wider for box photos) + measurement noise shrinking with k."""
    rng = np.random.default_rng(seed)
    bs, bd = rng.normal(size=S_DIM), rng.normal(size=D_DIM)
    truth = {}
    for i in range(n):
        p = server.add_puzzle(i) if pid(i) not in server.puzzles else pid(i)
        pieces = 1000 if i % 5 == 0 else 500
        server.puzzles[p]["piecesCount"] = pieces
        server.puzzles[p]["manufacturer"] = {"id": pid(900 + i % 12), "name": f"Brand {i % 12}"}
        s, d = unit(rng.normal(size=S_DIM)), unit(rng.normal(size=D_DIM))
        kind = "flat" if i % 2 else "box_front"
        signal = 1.2 * float(s @ bs) / np.sqrt(S_DIM) * 3 + 0.8 * float(d @ bd) / np.sqrt(D_DIM) * 3 + (0.1 if pieces == 1000 else 0.0)
        hidden_noise = noise if noise is not None else (0.06 if kind == "flat" else 0.15)
        truth[p] = signal + rng.normal(scale=hidden_noise)
        put(server, "artwork", p, {"kind": kind, "bbox": {"x": 0, "y": 0, "w": 1, "h": 1}})
        if i in no_siglip:
            continue
        if i in full_only:
            put(server, "embed_siglip2_full", p, {"dim": S_DIM, "vector": vector_to_b64(s)})
            continue
        put(server, "embed_siglip2", p, {"dim": S_DIM, "vector": vector_to_b64(s)})
        put(server, "embed_dinov2", p, {"dim": D_DIM, "cls": vector_to_b64(d)})
        put(server, "embed_siglip2_full", p, {"dim": S_DIM, "vector": vector_to_b64(s)})
    for i in range(labelled):
        p = pid(i)
        k = int(rng.integers(1, 60))
        server.label(p, float(truth[p] + rng.normal(scale=0.05 / np.sqrt(k))), k)
        server.labels[p]["addedAt"] = f"2025-{1 + i * 12 // labelled:02d}-{1 + i % 28:02d}T00:00:00+00:00"
    return truth


@pytest.fixture
def big(server):
    planted(server)
    return server


def run(api, **kw):
    return dp.run(api, now=kw.pop("now", NOW), **kw)


def scramble(server, seed=9):
    """Labels that no longer belong to their pictures: the model falls apart (a real regression)."""
    ids = sorted(server.labels)
    values = np.random.default_rng(seed).permutation([server.labels[p]["logDifficulty"] for p in ids])
    for p, v in zip(ids, values):
        server.labels[p]["logDifficulty"] = float(v)


def test_ridge_recovers_a_planted_signal_and_pushes_every_embedded_puzzle(api, big, tmp_path):
    summary = run(api, report_dir=tmp_path)
    m = summary[dp.MODEL]
    assert m["trainingSize"] >= 400 and m["overall"]["spearman"] > 0.9 and m["holdoutSpearman"] > 0.85
    assert m["pushed"] == {"stored": 600, "stale_fingerprint": 0, "hidden": 0, "unknown_puzzle": 0, "invalid": 0}
    assert m["guardSkipped"] == "no previous record of this model"
    assert summary[dp.FALLBACK_MODEL] == {"skipped": "no puzzle for this model tonight"}
    assert len(big.estimates) == 600 and len(big.models) == 1
    model = big.models[0]
    assert (model["model"], model["modelVersion"]) == (dp.MODEL, dp.MODEL_VERSION) and dp.VERSION == "1.0.0+ridge-siglip2-dinov2"
    ev = model["evaluation"]
    assert ev["holdoutSource"] == "newer_from_older" and model["holdoutSpearman"] == pytest.approx(ev["holdoutSpearman"])
    assert ev["holdoutCount"] >= dp.MIN_HOLDOUT_TEST and ev["holdoutTestMinIndices"] == 20
    assert ev["holdouts"]["brands"]["overall"]["spearman"] > 0.85 and ev["testMinIndices"] == 20
    assert ev["folds"]["grouping"].startswith("near-duplicate components") and ev["inputVersions"]["embed_siglip2"] == "1.0.0+test"
    assert {(g["sizeGroup"], g["imageKind"]) for g in ev["groups"]} >= {("500", "flat"), ("500", "box"), ("1000", "all"), ("all", "box")}
    assert ev["overall"]["tierWithinOne"] >= ev["overall"]["averageTierWithinOne"]
    writes = [r["method"] + " " + r["path"] for r in big.requests if r["method"] in ("PUT", "POST")]
    assert writes[0] == "POST /internal-api/puzzle-difficulty-estimates/model" and writes[1] == "PUT /internal-api/puzzle-difficulty-estimates"
    e = big.estimates[pid(1)]
    assert e["outOfFold"] is True and e["logP10"] <= e["logDifficulty"] <= e["logP90"] and e["computedAt"] == "2026-10-19T03:00:00Z"
    assert big.estimates[pid(599)]["outOfFold"] is False and big.estimates[pid(599)]["trainingSize"] == m["trainingSize"]
    assert dp.read_report(tmp_path)["models"][dp.MODEL]["status"] == "recorded"


def test_labelled_puzzles_below_three_indices_are_estimated_by_the_full_model_that_never_saw_them(api, big):
    low = next(p for p in sorted(big.labels) if big.labels[p]["indexCount"] < 3)
    run(api)
    assert big.estimates[low]["outOfFold"] is True and big.estimates[low]["trainingSize"] >= 400


def test_out_of_fold_estimates_never_use_their_own_label(api, server):
    planted(server)
    target = next(p for p in sorted(server.labels) if server.labels[p]["indexCount"] >= 3)
    run(api)
    first = dict(server.estimates[target])
    unlabelled_first = server.estimates[pid(599)]["logDifficulty"]
    server.labels[target]["logDifficulty"] += 3.0  # a wildly wrong label of this one puzzle
    run(api)
    assert server.estimates[target]["logDifficulty"] == pytest.approx(first["logDifficulty"], abs=1e-9)
    assert server.estimates[pid(599)]["logDifficulty"] != unlabelled_first  # the full model did see the change


def test_cross_fit_predicts_each_row_without_it():
    rng = np.random.default_rng(3)
    X = rng.normal(size=(60, 4))
    y = X @ np.array([1.0, -1.0, 0.5, 0.0])
    w = np.ones(60)
    pred, sizes = dp.cross_fit(X, y, w, dp.kfold(60))
    y2 = y.copy()
    y2[7] = 100.0
    pred2, _ = dp.cross_fit(X, y2, w, dp.kfold(60))
    assert pred2[7] == pytest.approx(pred[7]) and set(sizes) == {48}


def test_an_interval_never_contains_its_own_residual():
    rng = np.random.default_rng(4)
    n_rows = 400
    pred, resid = rng.normal(size=n_rows), rng.normal(scale=0.2, size=n_rows)
    n = np.full(n_rows, 30)
    sizes, kinds = ["500"] * n_rows, ["flat"] * n_rows
    fold = np.arange(n_rows) % 5
    before = dp.fold_bounds(pred, resid, n, sizes, kinds, fold)
    resid2 = resid.copy()
    resid2[fold == 2] = 50.0  # every residual of fold 2 absurd: fold 2's own intervals must not move
    after = dp.fold_bounds(pred, resid2, n, sizes, kinds, fold)
    assert np.allclose(before[fold == 2], after[fold == 2]) and not np.allclose(before[fold != 2], after[fold != 2])


def test_intervals_cover_about_80_percent_and_follow_the_image_kind(api, server):
    planted(server, n=900, labelled=850, seed=5)
    summary = run(api)
    ev = server.models[0]["evaluation"]
    assert 0.72 <= ev["overall"]["window80Hit"] <= 0.9 and 0.4 <= ev["overall"]["window50Hit"] <= 0.62
    assert 0.65 <= ev["holdouts"]["newerFromOlder"]["overall"]["window80Hit"] <= 0.95
    assert 0.65 <= ev["holdouts"]["brands"]["overall"]["window80Hit"] <= 0.95
    flat = next(g for g in ev["intervals"]["groups"] if g["sizeGroup"] == "500" and g["imageKind"] == "flat")
    box = next(g for g in ev["intervals"]["groups"] if g["sizeGroup"] == "500" and g["imageKind"] == "box")
    assert box["q90"] - box["q10"] > 1.5 * (flat["q90"] - flat["q10"])
    labelled = [p for p in server.labels if server.labels[p]["indexCount"] >= 3]
    inside = [server.estimates[p]["logP10"] <= server.labels[p]["logDifficulty"] <= server.estimates[p]["logP90"] for p in labelled]
    assert 0.72 <= np.mean(inside) <= 0.9
    assert summary[dp.MODEL]["pushed"]["stored"] == 900


def test_near_duplicate_artwork_stays_in_one_fold():
    rng = np.random.default_rng(6)
    base = rng.normal(size=(100, 16))
    twins = base[:20] + rng.normal(scale=0.01, size=(20, 16))  # the same picture again (another edition)
    groups, info = dp.near_duplicate_groups(np.vstack([base, twins]))
    _, fold = dp.group_folds(groups)
    assert all(fold[i] == fold[100 + i] for i in range(20)) and info["nearDuplicatePairs"] == 20 and info["components"] == 100
    same, off = dp.near_duplicate_groups(np.ones((50, 16)) + rng.normal(scale=1e-4, size=(50, 16)))
    assert off["grouping"].startswith("off") and len(set(same.tolist())) == 50
    brands = [f"b{i % 7}" for i in range(100)] + [f"b{i % 7}" for i in range(20)]
    brand_groups, _ = dp.near_duplicate_groups(np.vstack([base, twins]), extra=brands)
    assert len(set(brand_groups.tolist())) == 7
    crossing = brands[:100] + ["b0"] * 20  # a picture licensed to two brands joins them into one held-out group
    assert len(set(dp.near_duplicate_groups(np.vstack([base, twins]), extra=crossing)[0].tolist())) < 7


def test_newer_puzzles_with_an_older_twin_are_left_out_of_the_time_holdout(api, server):
    planted(server)
    for i in range(0, 40):  # a newer puzzle (i + 440) is a near copy of an older one (i)
        src, dst = pid(i), pid(440 + i)
        for a in ("embed_siglip2", "embed_dinov2", "embed_siglip2_full"):
            server.analyses[(a, dst)]["result"] = dict(server.analyses[(a, src)]["result"])
    ev = run(api)
    trained_twins = sum(server.labels[pid(440 + i)]["indexCount"] >= 3 and server.labels[pid(i)]["indexCount"] >= 3 for i in range(40))
    assert server.models[0]["evaluation"]["holdouts"]["newerFromOlder"]["testDroppedNearDuplicates"] == trained_twins >= 30
    assert ev[dp.MODEL]["pushed"]["stored"] == 600


def test_push_guard_blocks_a_comparable_regression_and_force_model_overrides_it(api, server, tmp_path):
    planted(server, n=900, labelled=850, seed=5)
    good = run(api, report_dir=tmp_path)[dp.MODEL]
    assert server.models[0]["evaluation"]["holdoutCount"] >= dp.GUARD_MIN_TEST
    stored_before = {p: dict(e) for p, e in server.estimates.items()}
    scramble(server)
    summary = run(api, report_dir=tmp_path)
    m = summary[dp.MODEL]
    assert "blocked" in m and "blocked" in summary and m["previousHoldoutSpearman"] == pytest.approx(good["holdoutSpearman"])
    assert m["holdoutSpearman"] < good["holdoutSpearman"] - 0.05 and m["guardSkipped"] is None
    assert len(server.models) == 1 and server.estimates == stored_before  # nothing recorded, nothing pushed
    lines = dp.blocked_lines(tmp_path)
    assert len(lines) == 1 and "blocked since 2026-10-19T03:00:00Z" in lines[0]
    later = lambda: datetime(2026, 10, 20, 3, 0, tzinfo=timezone.utc)  # noqa: E731
    run(api, report_dir=tmp_path, now=later)
    assert "blocked since 2026-10-19T03:00:00Z" in dp.blocked_lines(tmp_path)[0]  # since the FIRST blocked night
    assert "difficulty_prior blocked since" in render({"profile": "m3-max", "coverage": {}, "thisMachine": {}, "difficultyPriorBlocked": lines})

    forced = run(api, force_model=True, report_dir=tmp_path)[dp.MODEL]
    assert "blocked" not in forced and forced["pushed"]["stored"] == 900
    assert len(server.models) == 2 and server.models[-1]["evaluation"]["guard"]["forced"] is True
    assert dp.blocked_lines(tmp_path) == []


def test_blocked_run_exits_3_from_the_cli_path(api, server, tmp_path, monkeypatch):
    from puzzle_vision import cli

    monkeypatch.setattr(Deriver, "run", lambda self, **kw: {"difficulty_prior": {"blocked": "x"}})

    class Ctx:
        class profile:
            derived_versions = {"box_text": "1.0.0+rules"}

        class settings:
            cache_dir = tmp_path
            state_dir = tmp_path

    Ctx.api = api
    assert cli.cmd_derive(argparse.Namespace(only=None, dry_run=False, json=True, force_model=False), Ctx) == 3


def guarded_previous(api, **evaluation):
    ev = {"holdoutSource": "newer_from_older", "holdoutTestMinIndices": 20, "holdoutCount": 300} | evaluation
    size = ev.pop("trainingSize", None)
    api.record_difficulty_model(DifficultyModelRecord(dp.MODEL, "1.0.0", size or 822, 0.999, ev))


def test_push_guard_does_not_compare_against_a_lucky_small_run(api, server):
    planted(server, n=900, labelled=850, seed=5)
    guarded_previous(api, holdoutCount=31)
    m = run(api)[dp.MODEL]
    assert "blocked" not in m and "only 31 holdout test puzzles" in m["guardSkipped"]
    assert server.models[-1]["evaluation"]["guard"]["skipped"] == m["guardSkipped"] and m["pushed"]["stored"] == 900


def test_push_guard_does_not_compare_when_the_training_size_moved_a_lot(api, server):
    planted(server, n=900, labelled=850, seed=5)
    guarded_previous(api, trainingSize=5000)
    m = run(api)[dp.MODEL]
    assert "blocked" not in m and "training size changed from 5000" in m["guardSkipped"]


def test_push_guard_does_not_compare_another_holdout_source_or_threshold(api, server):
    planted(server, n=900, labelled=850, seed=5)
    guarded_previous(api, holdoutTestMinIndices=10)
    assert "holdoutTestMinIndices changed" in run(api)[dp.MODEL]["guardSkipped"]
    guarded_previous(api, holdoutSource="cross_fit")
    assert "holdoutSource changed" in run(api)[dp.MODEL]["guardSkipped"]


def test_push_guard_lets_a_small_drop_through(api, server):
    planted(server, n=900, labelled=850, seed=5)
    run(api)
    previous = server.models[-1]
    server.models[-1] = previous | {"holdoutSpearman": previous["holdoutSpearman"] + 0.04}
    m = run(api)[dp.MODEL]
    assert m["pushed"]["stored"] == 900 and server.models[-1]["evaluation"]["guard"]["compared"] is True


def test_another_models_record_is_not_the_baseline(api, big):
    api.record_difficulty_model(DifficultyModelRecord("ridge-pieces-only", "0.1.0", 500, 0.99, {"overall": {"spearman": 0.99}}))
    assert run(api)[dp.MODEL]["previousHoldoutSpearman"] is None and len(big.estimates) == 600


def test_below_the_minimum_training_size_nothing_is_recorded_or_pushed(api, server):
    planted(server, n=150, labelled=150)
    summary = run(api)
    assert "skipped" in summary and "needs 200" in summary[dp.MODEL]["skipped"] and "no model has 200" in summary["skipped"]
    assert server.models == [] and server.estimates == {}
    assert not any(r["method"] in ("PUT", "POST") for r in server.requests)


def test_dry_run_records_and_pushes_nothing(api, big):
    summary = run(api, dry_run=True)
    assert summary["dryRun"] and summary[dp.MODEL]["estimates"] == 600 and big.models == [] and big.estimates == {}


def test_outcomes_hidden_and_stale_are_skipped_and_counted(api, big):
    big.hidden.add(pid(598))
    original = api.store_difficulty_estimates

    def store(estimates):
        big.change_picture(pid(597), "fedcba9876543210")  # the picture changed while the model was fitted
        return original(estimates)

    api.store_difficulty_estimates = store
    summary = run(api)
    assert summary[dp.MODEL]["pushed"] == {"stored": 598, "stale_fingerprint": 1, "hidden": 1, "unknown_puzzle": 0, "invalid": 0}
    assert pid(598) not in big.estimates and pid(597) not in big.estimates


def test_fallback_model_has_its_own_record_for_puzzles_without_crop_embeddings(api, server):
    planted(server, full_only=range(560, 590), no_siglip=range(590, 600))
    summary = run(api)
    assert summary["fallback"] == 30 and summary[dp.MODEL]["pushed"]["stored"] == 560
    assert summary[dp.FALLBACK_MODEL]["pushed"]["stored"] == 30
    assert server.estimates[pid(570)]["model"] == dp.FALLBACK_MODEL and server.estimates[pid(1)]["model"] == dp.MODEL
    assert pid(595) not in server.estimates
    assert [m["model"] for m in server.models] == [dp.MODEL, dp.FALLBACK_MODEL]
    assert api.current_difficulty_model(dp.FALLBACK_MODEL)["evaluation"]["overall"]["spearman"] > 0.5


def test_fallback_runs_alone_in_phase_a(api, server):
    planted(server, full_only=range(0, 600))  # no artwork crop embedded yet anywhere
    summary = run(api)
    assert "needs 200" in summary[dp.MODEL]["skipped"] and "skipped" not in summary
    assert summary[dp.FALLBACK_MODEL]["pushed"]["stored"] == 600 and [m["model"] for m in server.models] == [dp.FALLBACK_MODEL]


def test_fingerprint_join_leaves_out_rows_and_labels_of_another_picture():
    lab = lambda p, fp: DifficultyLabel(p, 0.1, "median", 0.1, 9, 500, "m", "2025-01-01", fp)  # noqa: E731
    pictures = {"a": ("fa", 500), "b": ("fb", 500), "c": ("fc", None)}
    v = vector_to_b64(np.ones(4))
    rows = {
        "artwork": [{"puzzleId": "a", "imageFingerprint": "fa", "result": {"kind": "box_angled"}},
                    {"puzzleId": "b", "imageFingerprint": "old", "result": {"kind": "flat"}}],
        "embed_siglip2": [{"puzzleId": "a", "imageFingerprint": "fa", "result": {"vector": v}},
                          {"puzzleId": "b", "imageFingerprint": "old", "result": {"vector": v}}],
        "embed_dinov2": [{"puzzleId": "a", "imageFingerprint": "fa", "result": {"cls": v}},
                         {"puzzleId": "b", "imageFingerprint": "fb", "result": {"vector": v}}],
        "embed_siglip2_full": [{"puzzleId": "zz", "imageFingerprint": "x", "result": {"vector": v}}],
    }
    corpus = dp.build_corpus([lab("a", "fa"), lab("b", "older"), lab("c", "fc"), lab("hidden", "fh")], pictures, lambda a: rows[a])
    assert corpus.puzzles["a"].label is not None and corpus.puzzles["a"].kind == "box"
    assert corpus.puzzles["b"].label is None and corpus.puzzles["b"].kind == "unknown"
    assert corpus.puzzles["c"].pieces == 500 and corpus.puzzles["c"].label is not None  # pieces from the label
    assert corpus.has_primary("a") and not corpus.has_fallback("b") and "b" in corpus.dino
    assert corpus.dropped["labelOfAnotherPicture"] == 1 and corpus.dropped["labelWithoutListedPicture"] == 1
    assert corpus.dropped["embed_siglip2OfAnotherPicture"] == 1 and corpus.dropped["embed_siglip2_fullOfAnotherPicture"] == 1


def test_only_the_newest_analyzer_version_is_used():
    pictures = {p: (f"f{p}", 500) for p in "abcd"}
    old, new = vector_to_b64(np.ones(8)), vector_to_b64(np.ones(4))
    rows = {"artwork": [], "embed_dinov2": [], "embed_siglip2_full": [], "embed_siglip2": [
        {"puzzleId": "a", "imageFingerprint": "fa", "analyzerVersion": "1.0.0+old", "receivedAt": "2026-10-01", "result": {"vector": old}},
        {"puzzleId": "b", "imageFingerprint": "fb", "analyzerVersion": "1.10.0+new", "receivedAt": "2026-10-02", "result": {"vector": new}},
        {"puzzleId": "c", "imageFingerprint": "fc", "analyzerVersion": "1.10.0+new", "receivedAt": "2026-10-03", "result": {"vector": new}},
        {"puzzleId": "d", "imageFingerprint": "fd", "analyzerVersion": "1.9.0+mid", "receivedAt": "2026-10-04", "result": {"vector": old}},
    ]}
    corpus = dp.build_corpus([], pictures, lambda a: rows[a])
    assert sorted(corpus.siglip) == ["b", "c"] and corpus.versions["embed_siglip2"] == "1.10.0+new"
    assert corpus.dropped["embed_siglip2OlderVersion"] == 2


def test_the_most_common_dimension_wins_within_a_version():
    pictures = {p: (f"f{p}", 500) for p in "abc"}
    rows = {"artwork": [], "embed_dinov2": [], "embed_siglip2_full": [], "embed_siglip2": [
        {"puzzleId": "a", "imageFingerprint": "fa", "analyzerVersion": "1", "result": {"vector": vector_to_b64(np.ones(8))}},
        {"puzzleId": "b", "imageFingerprint": "fb", "analyzerVersion": "1", "result": {"vector": vector_to_b64(np.ones(4))}},
        {"puzzleId": "c", "imageFingerprint": "fc", "analyzerVersion": "1", "result": {"vector": vector_to_b64(np.ones(4))}},
    ]}
    corpus = dp.build_corpus([], pictures, lambda a: rows[a])
    assert sorted(corpus.siglip) == ["b", "c"] and corpus.dropped["embed_siglip2OtherDimension"] == 1


def test_a_stale_embedding_row_on_the_server_is_not_used(api, big):
    big.analyses[("embed_dinov2", pid(3))]["imageFingerprint"] = "0000000000000bad"  # an older picture's vector
    summary = run(api)
    assert summary["withPrimaryFeatures"] == 599 and big.estimates[pid(3)]["model"] == dp.FALLBACK_MODEL


def test_runs_are_reproducible(api, big):
    run(api)
    first = {p: dict(e) for p, e in big.estimates.items()}
    run(api)
    assert first == big.estimates


def test_a_non_finite_number_is_never_an_estimate():
    p = dp.Puzzle("a", "fa", 500)
    assert dp._estimate("a", p, dp.MODEL, float("nan"), np.zeros(4), True, 10, "t") is None
    assert dp._estimate("a", p, dp.MODEL, 0.1, np.array([np.nan, 0, 0, 0.2]), True, 10, "t") is None
    assert dp._estimate("a", p, dp.MODEL, 0.1, np.array([-0.1, 0, 0.1, 0.2]), True, 10, "t").log_p90 == 0.2


def test_tiers_match_difficulty_tier_from_score():
    scores = np.array([0.5, 0.7499, 0.75, 0.8999, 0.9, 1.0, 1.0999, 1.1, 1.2499, 1.25, 1.4499, 1.45, 3.0])
    assert dp.tier(np.log(scores)).tolist() == [1, 1, 2, 2, 3, 3, 3, 4, 4, 5, 5, 6, 6]
    on_cut = [round(float(np.log(x)), 6) for x in (0.75, 0.90, 1.10, 1.25, 1.45)]  # stored logs rounded to 6 decimals
    assert dp.tier(on_cut).tolist() == [2, 3, 4, 5, 6]
    assert [dp.size_group(x) for x in (100, 300, 350, 500, 504, 1000, 1200, 1500, 3000)] == ["<=300", "<=300", "odd", "500", "odd", "1000", "odd", "1500+", "1500+"]
    assert [dp.image_kind({"kind": k}) for k in ("flat", "box_front", "box_angled", "assembled", "other")] + [dp.image_kind(None)] == ["flat", "box", "box", "assembled", "other", "unknown"]


def test_deriver_runs_difficulty_prior_and_cli_has_force_model(api, big, tmp_path):
    summary = Deriver(api, {"box_text": "1.0.0+rules"}, tmp_path, report_dir=tmp_path).run(only=["difficulty_prior"])
    assert summary["difficulty_prior"][dp.MODEL]["pushed"]["stored"] == 600
    args = parser().parse_args(["derive", "--only", "difficulty_prior", "--force-model"])
    assert args.force_model is True and isinstance(args, argparse.Namespace)


def test_a_crash_in_difficulty_prior_does_not_end_the_derive_run(api, big, tmp_path, monkeypatch, caplog):
    def boom(*a, **kw):
        raise ValueError("synthetic failure")

    monkeypatch.setattr(dp, "run", boom)
    summary = Deriver(api, {"box_text": "1.0.0+rules"}, tmp_path).run(only=["box_text", "difficulty_prior"])
    assert summary["difficulty_prior"] == {"error": "ValueError: synthetic failure"} and "box_text" in summary
    assert any(r.levelname == "ERROR" and "difficulty_prior failed" in r.message for r in caplog.records)
