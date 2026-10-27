"""Machine profiles (profiles/*.toml): which analyzers this Mac runs, in which phases, at which versions.

A profile names, per analyzer, the version (`analyzerVersion` - **one version per analyzer at a time**, compared for
equality by the API) and its `requires` (must equal analyzers.REQUIRES). Phases run in order; within a phase the
`stages` run one after another (one stage = one process = one model), the `sidecar` CPU stages run alongside them in
single-threaded worker processes.
"""

from __future__ import annotations

import tomllib
from dataclasses import dataclass, field, replace
from pathlib import Path

from . import analyzers as A

PROFILES_DIR = Path(__file__).resolve().parent.parent / "profiles"


class ProfileError(ValueError):
    pass


@dataclass(frozen=True)
class AnalyzerSettings:
    name: str
    version: str
    requires: tuple[str, ...]
    claim_limit: int = 50
    seconds_per_image: float = 1.0
    workers: int = 1
    # the profile's version of each required analyzer: a result of another version (an older `artwork` while a new
    # one is being rolled out) is never used - its crop would be stored under this stage's version for good
    upstream_versions: tuple[tuple[str, str], ...] = ()

    def lease_seconds(self) -> int:
        """A claimed batch is worked on after the last ~20 % of the batch before it (the pre-fetch) and may run up to
        2-3× slower than measured (Jan uses the M3 meanwhile): 3× the batch's measured time, plus the store interval
        and a margin. The API takes 60-7200 s."""
        from .worker import FLUSH_SECONDS

        est = int(self.claim_limit * self.seconds_per_image * 3) + int(FLUSH_SECONDS) + 120
        return max(60, min(7200, est))

    @property
    def slow(self) -> bool:
        """Slow stages (> 5 s an image) store every result at once - a crash or an expired lease loses nothing."""
        return self.seconds_per_image > 5


@dataclass(frozen=True)
class Phase:
    name: str
    stages: tuple[str, ...]
    sidecar: tuple[str, ...] = ()
    gate: str | None = None  # a workstate marker that must exist first (e.g. describe-parity)


@dataclass(frozen=True)
class Profile:
    name: str
    description: str
    analyzers: dict[str, AnalyzerSettings]
    phases: tuple[Phase, ...]
    daily_phases: tuple[str, ...]
    derive_after_daily: bool = False
    propose_crops_daily: int = 0
    launchd: dict = field(default_factory=dict)
    derived_versions: dict[str, str] = field(default_factory=dict)
    # `puzzle-vision finished-photos` (finished_photo_stage.py): version, per_puzzle, workers - GPU machines only
    finished_photo: dict = field(default_factory=dict)

    def phase(self, name: str) -> Phase:
        for p in self.phases:
            if p.name == name:
                return p
        raise ProfileError(f"profile {self.name} has no phase {name!r} (phases: {', '.join(p.name for p in self.phases)})")

    def version(self, analyzer: str) -> str:
        if analyzer in self.analyzers:
            return self.analyzers[analyzer].version
        if analyzer in self.derived_versions:
            return self.derived_versions[analyzer]
        raise ProfileError(f"profile {self.name} has no version for {analyzer}")


def load(name_or_path: str | Path) -> Profile:
    path = Path(name_or_path)
    if not path.suffix:
        path = PROFILES_DIR / f"{name_or_path}.toml"
    if not path.exists():
        raise ProfileError(f"no profile {name_or_path!r} ({path})")
    data = tomllib.loads(path.read_text())
    return parse(data)


def parse(data: dict) -> Profile:
    name = data["name"]
    analyzers: dict[str, AnalyzerSettings] = {}
    for key, cfg in data.get("analyzers", {}).items():
        if key not in A.INFO:
            raise ProfileError(f"{name}: unknown raw analyzer {key!r}")
        requires = tuple(cfg.get("requires", []))
        if tuple(sorted(requires)) != tuple(sorted(A.REQUIRES[key])):
            raise ProfileError(f"{name}: {key} requires {list(requires)}, the dependency table says {list(A.REQUIRES[key])}")
        version = cfg["version"]
        if len(version) > 50:
            raise ProfileError(f"{name}: {key} version longer than 50 characters")
        analyzers[key] = AnalyzerSettings(
            name=key,
            version=version,
            requires=requires,
            claim_limit=int(cfg.get("claim_limit", 50)),
            seconds_per_image=float(cfg.get("seconds_per_image", 1.0)),
            workers=int(cfg.get("workers", 1)),
        )
    for key, settings in list(analyzers.items()):
        ups = tuple((r, analyzers[r].version) for r in settings.requires if r in analyzers)
        if ups:
            analyzers[key] = replace(settings, upstream_versions=ups)
    phases = []
    for p in data.get("phases", []):
        stages = tuple(p.get("stages", []))
        sidecar = tuple(p.get("sidecar", []))
        for s in stages + sidecar:
            if s not in analyzers:
                raise ProfileError(f"{name}: phase {p['name']} runs {s!r}, which has no [analyzers.{s}] section")
        for s in sidecar:
            if A.INFO[s].unit != "cpu":
                raise ProfileError(f"{name}: only CPU stages may run as a sidecar next to GPU stages ({s} is {A.INFO[s].unit})")
        phases.append(Phase(name=p["name"], stages=stages, sidecar=sidecar, gate=p.get("gate")))
    derived_versions = dict(data.get("derived", {}).get("versions", {}))
    for k in derived_versions:
        if k not in A.DERIVED:
            raise ProfileError(f"{name}: unknown derived analyzer {k!r}")
    daily = data.get("daily", {})
    return Profile(
        name=name,
        description=data.get("description", ""),
        analyzers=analyzers,
        phases=tuple(phases),
        daily_phases=tuple(daily.get("phases", [p.name for p in phases])),
        derive_after_daily=bool(daily.get("derive", False)),
        propose_crops_daily=int(daily.get("propose_crops", 0)),
        launchd=dict(data.get("launchd", {})),
        derived_versions=derived_versions,
        finished_photo=_finished_photo(name, data.get("finished_photo", {})),
    )


def _finished_photo(name: str, cfg: dict) -> dict:
    """The optional `[finished_photo]` table: `version` (required when the table exists, <= 50 characters, the same on
    every profile that has it - tests pin it), `per_puzzle` (photos per puzzle, 1-20) and `workers` (CPU processes)."""
    if not cfg:
        return {}
    version = str(cfg.get("version") or "")
    if not version or len(version) > 50:
        raise ProfileError(f"{name}: [finished_photo] needs a version of at most 50 characters")
    per_puzzle = int(cfg.get("per_puzzle", 4))
    if not 1 <= per_puzzle <= 20:
        raise ProfileError(f"{name}: [finished_photo] per_puzzle must be 1-20")
    return {"version": version, "per_puzzle": per_puzzle, "workers": max(1, int(cfg.get("workers", 3)))}


def available() -> list[str]:
    return sorted(p.stem for p in PROFILES_DIR.glob("*.toml"))
