import pytest

from puzzle_vision import analyzers as A
from puzzle_vision import profiles


@pytest.fixture(params=["m3-max", "m1-air"])
def profile(request):
    return profiles.load(request.param)


def test_both_profiles_load(profile):
    assert profile.phases and profile.daily_phases


def test_requires_match_the_dependency_table(profile):
    for name, s in profile.analyzers.items():
        assert tuple(sorted(s.requires)) == tuple(sorted(A.REQUIRES[name]))
    assert A.REQUIRES["describe"] == ("artwork", "ocr")
    assert A.REQUIRES["ocr"] == () and A.REQUIRES["embed_siglip2_full"] == () and A.REQUIRES["artwork"] == ()
    for crop in ("colors", "traits", "segmentation", "embed_dinov2", "embed_siglip2", "vision_labels"):
        assert A.REQUIRES[crop] == ("artwork",)


def test_one_version_per_analyzer_across_machines():
    m3, m1 = profiles.load("m3-max"), profiles.load("m1-air")
    for name in set(m3.analyzers) & set(m1.analyzers):
        assert m3.version(name) == m1.version(name), name
    assert m3.derived_versions == m1.derived_versions
    assert m3.version("artwork") == "1.0.0+siglip2-q3vl4b"
    assert m3.version("describe") == "1.0.0+qwen35-9b-4bit"
    assert m1.version("embed_siglip2") == "1.0.0+so400m-14-384" == m1.version("embed_siglip2_full")
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
