"""What every stage is: one analyzer, one model, loaded in `load()` (heavy imports there, never at module level)."""

from __future__ import annotations

import logging
from dataclasses import dataclass, field
from typing import Any, ClassVar

import numpy as np

from ..api import InternalApi, NotFound, WorkItem, uuid_before
from ..download import LocalImage
from ..workstate import WorkState

log = logging.getLogger(__name__)


class Defer(Exception):
    """Not now (e.g. the artwork gate has not seen this picture yet): hold the claim, no failure."""


class Skip(Exception):
    """Cannot be done for this picture (an upstream result is not current any more): release, no failure."""


@dataclass
class Prepared:
    item: WorkItem
    image: LocalImage
    rgb: np.ndarray
    crop: np.ndarray | None = None
    crop_info: dict[str, Any] | None = None
    upstream: dict[str, dict[str, Any]] = field(default_factory=dict)

    @property
    def subject(self) -> np.ndarray:
        """What the analyzer looks at: the crop when it has one, else the full image."""
        return self.crop if self.crop is not None else self.rgb


class Stage:
    analyzer: ClassVar[str] = ""
    # which upstream payloads `prepare` must fetch (current fingerprint only); `optional_upstream` only when cached
    upstream: ClassVar[tuple[str, ...]] = ()
    optional_upstream: ClassVar[tuple[str, ...]] = ()
    uses_crop: ClassVar[bool] = False
    needs_model_lock: ClassVar[bool] = True
    batch_size: ClassVar[int] = 1

    def __init__(self, version: str, workers: int = 1) -> None:
        self.version = version
        self.workers = workers

    @property
    def model(self) -> str:
        """The envelope's `model`: repo id + revision / quantisation, or tool + OS version."""
        return self.analyzer

    def load(self) -> None:
        """Import the heavy libraries and load the model."""

    def close(self) -> None:
        pass

    def analyse(self, p: Prepared) -> dict[str, Any]:
        raise NotImplementedError

    def analyse_batch(self, ps: list[Prepared]) -> list[dict[str, Any] | Exception]:
        out: list[dict[str, Any] | Exception] = []
        for p in ps:
            try:
                out.append(self.analyse(p))
            except Exception as e:  # noqa: BLE001 - per-image failure
                out.append(e)
        return out


class ArtifactSource:
    """Upstream payloads (artwork, ocr) of the current picture: local cache, then the bulk read starting right before
    the first missing puzzle (claims come in puzzle id order, so one page covers a batch), then one GET per puzzle."""

    def __init__(self, api: InternalApi, workstate: WorkState) -> None:
        self.api = api
        self.workstate = workstate

    def get(self, analyzer: str, item: WorkItem) -> dict[str, Any] | None:
        cached = self.workstate.artifact(analyzer, item.puzzle_id, item.fingerprint)
        return cached["result"] if cached else None

    def ensure(self, analyzer: str, items: list[WorkItem]) -> None:
        missing = sorted({i.puzzle_id for i in items if self.workstate.artifact(analyzer, i.puzzle_id, i.fingerprint) is None})
        if not missing:
            return
        wanted = set(missing)
        if len(missing) > 3:
            after = uuid_before(missing[0])
            last = missing[-1]
            while after:
                page = self.api.analyses_page(analyzer, after=after, limit=min(1000, max(200, len(missing) * 2)))
                for row in page.get("items", []):
                    self._remember(analyzer, row)
                    wanted.discard(row["puzzleId"])
                after = page.get("nextCursor")
                items_page = page.get("items", [])
                if not wanted or not items_page or items_page[-1]["puzzleId"] >= last:
                    break
        for pid in sorted(wanted):
            try:
                data = self.api.puzzle_analyses(pid)
            except NotFound:
                continue
            for row in _analyses_rows(data):
                self._remember(row.get("analyzer", ""), row)

    def _remember(self, analyzer: str, row: dict[str, Any]) -> None:
        if not analyzer or row.get("current") is False or not isinstance(row.get("result"), dict):
            return
        self.workstate.put_artifact(analyzer, row["puzzleId"], row["imageFingerprint"], row.get("analyzerVersion", ""), row["result"])


def _analyses_rows(data: Any) -> list[dict[str, Any]]:
    """`GET /internal-api/puzzles/{id}/image-analysis` - a list of rows, or an object holding one / keyed by analyzer."""
    if isinstance(data, list):
        return data
    if not isinstance(data, dict):
        return []
    for key in ("analyses", "items"):
        value = data.get(key)
        if isinstance(value, list):
            return value
        if isinstance(value, dict):
            rows = []
            for name, row in value.items():
                if isinstance(row, dict):
                    rows.append({"analyzer": name, "puzzleId": data.get("puzzleId", row.get("puzzleId")), **row})
            return rows
    return []
