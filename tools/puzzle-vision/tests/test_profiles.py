import pytest

from puzzle_vision import analyzers as A
from puzzle_vision import profiles


@pytest.fixture(params=["m3-max", "m1-air", "m3-solo"])
def profile(request):
    return profiles.load(request.param)


def test_both_profiles_load(profile):
    assert profile.phases and profile.daily_phases


def test_requires_match_the_dependency_table(profile):
    for name, s in profile.analyzers.items():
        assert tuple(sorted(s.requires)) == tuple(sorted(A.REQUIRES[name]))
    assert A.REQUIRES["describe"] == ("artwork", "ocr")
    assert A.REQUIRES["ocr"] == () and A.REQUIRES["embed_siglip2_full"] == () and A.REQUIRES["artwork"] == ()
    for crop in ("colors", "traits", "segmentation", "embed_dinov2", "embed_dinov2_patches", "embed_siglip2", "vision_labels"):
        assert A.REQUIRES[crop] == ("artwork",)


def test_one_version_per_analyzer_across_machines():
    m3, m1 = profiles.load("m3-max"), profiles.load("m1-air")
    loaded = [profiles.load(n) for n in profiles.available()]
    assert {"m3-max", "m1-air", "m3-solo"} <= {p.name for p in loaded}
    for a in loaded:
        for b in loaded:
            for name in set(a.analyzers) & set(b.analyzers):
                assert a.version(name) == b.version(name), (a.name, b.name, name)
            assert a.derived_versions == b.derived_versions, (a.name, b.name)
    assert m3.version("artwork") == "1.1.0+siglip2-q3vl4b"
    # every analyzer that reads the artwork crop moved with artwork 1.1 (its crops changed)
    assert m3.version("describe") == "1.1.0+qwen35-9b-4bit"  # + choiceProbs
    assert m1.version("embed_siglip2") == "1.0.1+so400m-14-384" and m1.version("embed_siglip2_full") == "1.0.0+so400m-14-384"
    for name in ("colors", "traits", "segmentation", "embed_dinov2", "embed_siglip2"):
        assert m1.version(name).startswith("1.0.1+"), name
    assert m1.version("vision_labels") == "1.1.0+apple-vision"  # + aesthetics, feature print, horizon
    assert all(v.split("+")[0].count(".") == 2 and len(v) <= 50 for v in list(m1.derived_versions.values()))


def test_the_split():
    m3, m1 = profiles.load("m3-max"), profiles.load("m1-air")
    runs = lambda p: {a for ph in p.phases for a in ph.stages + ph.sidecar}  # noqa: E731
    assert runs(m3) == {"artwork", "describe"}
    assert runs(m1) == set(A.RAW) - {"artwork"}
    assert runs(m3) | runs(m1) == set(A.RAW)
    assert m1.phase("describe").gate == "describe-parity"
    assert m1.phase("A").stages[:2] == ("ocr", "barcode")  # OCR first: the M3's describe waits on it
    assert m1.phase("B").sidecar == ("colors", "traits")
    assert m1.launchd["keep_alive"] is True and m3.launchd["mode"] == "daily"


def test_one_mac_runs_everything_with_the_launch_path_first():
    solo = profiles.load("m3-solo")
    runs = [a for ph in solo.phases for a in ph.stages + ph.sidecar]
    assert sorted(runs) == sorted(A.RAW) and len(runs) == len(set(runs))
    assert [ph.name for ph in solo.phases] == ["artwork", "embed", "apple", "features", "cpu", "describe"]
    # CPU only, no model lock: `run --phase cpu` may run beside the describe run
    assert solo.phase("cpu").stages == ("piece_scale",) and all(A.INFO[s].unit == "cpu" for s in solo.phase("cpu").stages)
    # what derive/difficulty_prior.py reads is done before anything else after the crops
    assert solo.phase("artwork").stages == ("artwork",)
    assert set(solo.phase("embed").stages) == {"embed_siglip2_full", "embed_siglip2", "embed_dinov2", "embed_dinov2_patches"}
    assert solo.phase("apple").stages == ("ocr", "barcode") and not solo.phase("apple").sidecar
    assert solo.phase("features").stages == ("segmentation", "vision_labels")
    assert solo.phase("features").sidecar == ("colors", "traits")
    assert solo.phases[-1].stages == ("describe",) and solo.phases[-1].gate is None
    # every stage's `requires` is produced by an earlier phase (or the same one, earlier) - one Mac waits on nobody
    done: set[str] = set()
    for ph in solo.phases:
        for a in ph.stages:
            assert set(A.REQUIRES[a]) <= done, a
            done.add(a)
        for a in ph.sidecar:
            assert set(A.REQUIRES[a]) <= done - set(ph.stages), a
            done.add(a)
    assert solo.daily_phases == tuple(ph.name for ph in solo.phases) and solo.derive_after_daily
    assert solo.launchd["mode"] == "daily" and solo.launchd["keep_alive"] is False
    m1 = profiles.load("m1-air")
    m1_runs = {a for ph in m1.phases for a in ph.stages + ph.sidecar}
    for a, s in solo.analyzers.items():  # the M3 is never slower than the Air on what the Air runs
        assert a not in m1_runs or s.seconds_per_image <= m1.analyzers[a].seconds_per_image, a
        assert 60 <= s.lease_seconds() <= 7200


def test_apple_vision_never_runs_beside_a_gpu_stage():
    for name in profiles.available():
        for ph in profiles.load(name).phases:
            assert all(A.INFO[s].unit == "cpu" for s in ph.sidecar)


def test_wrong_requires_or_gpu_sidecar_is_refused():
    base = {"name": "x", "analyzers": {"colors": {"version": "1.0.0+a", "requires": []}}, "phases": []}
    with pytest.raises(profiles.ProfileError):
        profiles.parse(base)
    bad = {"name": "x", "analyzers": {"segmentation": {"version": "1.0.0+a", "requires": ["artwork"]}},
           "phases": [{"name": "B", "stages": [], "sidecar": ["segmentation"]}]}
    with pytest.raises(profiles.ProfileError):
        profiles.parse(bad)


def test_lease_covers_two_batches():
    s = profiles.load("m3-max").analyzers["describe"]
    assert s.lease_seconds() >= s.claim_limit * s.seconds_per_image * 2
    assert 60 <= profiles.load("m1-air").analyzers["ocr"].lease_seconds() <= 7200
