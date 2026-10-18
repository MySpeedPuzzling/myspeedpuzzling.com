"""Client of the internal API (docs/features/internal-api.md § "Puzzle image analysis", § "Puzzle change requests",
§ "Puzzle difficulty labels and estimates").

- Every request reads the token at call time (config.read_token) - it is never stored on the client or logged.
- 429 = slow down (Retry-After, else a growing pause), 5xx / network errors = retry with backoff, other 4xx = typed
  errors: 409 on a store = the picture changed (StaleFingerprint) - the caller skips and continues.
- A gentle pace between calls (`min_interval`): production rate-limits bursts on /internal-api.
"""

from __future__ import annotations

import base64
import logging
import threading
import time
import uuid
from dataclasses import dataclass, field
from typing import Any, Callable, Iterator

import httpx

from . import USER_AGENT
from .config import DEFAULT_BASE_URL, read_token

log = logging.getLogger(__name__)

STORE_BATCH_MAX = 200
CLAIM_LIMIT_MAX = 200
ESTIMATE_BATCH_MAX = 500
LABELS_LIMIT_MAX = 5000


class ApiError(RuntimeError):
    def __init__(self, status: int, message: str, body: Any = None) -> None:
        super().__init__(f"HTTP {status}: {message}")
        self.status = status
        self.body = body


class BadRequest(ApiError):
    """400 - a contract bug on our side (e.g. an invalid `requires`): stop and fix, do not retry."""


class Unauthorized(ApiError):
    pass


class NotFound(ApiError):
    """Unknown puzzle, a hidden (secret) image, or an endpoint that does not exist (yet)."""


class Conflict(ApiError):
    pass


class StaleFingerprint(Conflict):
    """The puzzle's picture changed since it was listed (`409`; for change requests `code image_changed`) - skip it, the
    next claim brings the new picture."""


class AlreadyProposed(Conflict):
    """`409 code already_proposed`: a pipeline change request for this (puzzle, sourceImageFingerprint, tag) exists in
    some status (a rejected one is remembered) - never twice."""


class PendingProposal(Conflict):
    """`409 code pending_proposal`: the puzzle already has a pending change request of more than its names or a merge
    request - one proposal at a time, try again another day."""


class OriginalNotArchived(Conflict):
    """`409 code original_not_archived` on approve: the original could not be copied first - nothing changed, retry later."""


class Invalid(ApiError):
    """422 - an invalid analysis / batch."""


@dataclass
class WorkItem:
    puzzle_id: str
    image_url: str
    fingerprint: str
    name: str = ""
    pieces_count: int | None = None
    manufacturer: dict[str, Any] | None = None
    image_ratio: float | None = None
    analyses: dict[str, Any] = field(default_factory=dict)
    claims: dict[str, Any] = field(default_factory=dict)
    raw: dict[str, Any] = field(default_factory=dict)

    @classmethod
    def from_api(cls, d: dict[str, Any]) -> "WorkItem":
        analyses = d.get("analyses") or {}
        claims = d.get("claims") or {}
        return cls(
            puzzle_id=d["puzzleId"],
            image_url=d.get("imageUrl", ""),
            fingerprint=d.get("imageFingerprint", ""),
            name=d.get("name") or "",
            pieces_count=d.get("piecesCount"),
            manufacturer=d.get("manufacturer"),
            image_ratio=d.get("imageRatio"),
            analyses=analyses if isinstance(analyses, dict) else {},
            claims=claims if isinstance(claims, dict) else {},
            raw=d,
        )

    def has_current(self, analyzer: str, version: str | None = None) -> bool:
        a = self.analyses.get(analyzer)
        if not a or a.get("imageFingerprint") != self.fingerprint:
            return False
        return version is None or a.get("analyzerVersion") == version


@dataclass
class AnalysisResult:
    puzzle_id: str
    analyzer: str
    analyzer_version: str
    fingerprint: str
    result: dict[str, Any]
    analyzed_at: str | None = None

    def to_api(self) -> dict[str, Any]:
        d = {
            "puzzleId": self.puzzle_id,
            "analyzer": self.analyzer,
            "analyzerVersion": self.analyzer_version,
            "imageFingerprint": self.fingerprint,
            "result": self.result,
        }
        if self.analyzed_at:
            d["analyzedAt"] = self.analyzed_at
        return d


@dataclass
class StoreOutcome:
    puzzle_id: str
    analyzer: str
    status: str  # stored | stale_fingerprint | not_found | invalid
    message: str | None = None
    current_fingerprint: str | None = None
    errors: dict[str, Any] | None = None


@dataclass
class DifficultyLabel:
    """A training label (`GET /internal-api/puzzle-difficulty-labels`) - aggregates of one puzzle, nothing of a player.

    `log_difficulty` = ln of the puzzle's as-of difficulty: ln(median index) where the app keeps a median (>= 5 indices,
    `label_source` "median"), else the mean ln index (1-4 indices, "mean_log"). `mean_log_index` = sum of ln indices / k
    for every puzzle. `fingerprint` names the picture the label belongs to now (None = no picture) - join embeddings
    on it (`current` results only). Weight a training row by `index_count` (n/(n+10), plan §5.3)."""

    puzzle_id: str
    log_difficulty: float
    label_source: str
    mean_log_index: float
    index_count: int
    pieces_count: int
    manufacturer_id: str | None
    added_at: str | None
    fingerprint: str | None
    raw: dict[str, Any] = field(default_factory=dict)

    @classmethod
    def from_api(cls, d: dict[str, Any]) -> "DifficultyLabel":
        return cls(
            puzzle_id=d["puzzleId"],
            log_difficulty=float(d["logDifficulty"]),
            label_source=d.get("labelSource") or "",
            mean_log_index=float(d.get("meanLogIndex", d["logDifficulty"])),
            index_count=int(d["indexCount"]),
            pieces_count=int(d["piecesCount"]),
            manufacturer_id=d.get("manufacturerId"),
            added_at=d.get("addedAt"),
            fingerprint=d.get("imageFingerprint"),
            raw=d,
        )


@dataclass
class DifficultyEstimate:
    """One item of `PUT /internal-api/puzzle-difficulty-estimates` - log space like the label; log_p10 <= log_difficulty
    <= log_p90 (|value| <= 5); `out_of_fold` = a labelled puzzle estimated by a model that did not see it."""

    puzzle_id: str
    model: str
    model_version: str
    log_difficulty: float
    log_p10: float
    log_p90: float
    out_of_fold: bool
    training_size: int
    fingerprint: str
    computed_at: str | None = None

    def to_api(self) -> dict[str, Any]:
        d: dict[str, Any] = {
            "puzzleId": self.puzzle_id,
            "model": self.model,
            "modelVersion": self.model_version,
            "logDifficulty": float(self.log_difficulty),
            "logP10": float(self.log_p10),
            "logP90": float(self.log_p90),
            "outOfFold": bool(self.out_of_fold),
            "trainingSize": int(self.training_size),
            "imageFingerprint": self.fingerprint,
        }
        if self.computed_at:
            d["computedAt"] = self.computed_at
        return d


@dataclass
class EstimateOutcome:
    puzzle_id: str
    status: str  # stored | stale_fingerprint | hidden | unknown_puzzle | invalid
    message: str | None = None
    current_fingerprint: str | None = None
    errors: dict[str, Any] | None = None


@dataclass
class DifficultyModelRecord:
    """`POST /internal-api/puzzle-difficulty-estimates/model` - a run of the model with its evaluation.
    `holdout_spearman` is the number the push guard compares (newer puzzles predicted from older ones); `evaluation`
    holds the rest (overall, groups per size group x image kind, holdouts) - a JSON object <= 256 KB."""

    model: str
    model_version: str
    training_size: int
    holdout_spearman: float
    evaluation: dict[str, Any]
    created_at: str | None = None

    def to_api(self) -> dict[str, Any]:
        d: dict[str, Any] = {
            "model": self.model,
            "modelVersion": self.model_version,
            "trainingSize": int(self.training_size),
            "holdoutSpearman": float(self.holdout_spearman),
            "evaluation": self.evaluation,
        }
        if self.created_at:
            d["createdAt"] = self.created_at
        return d


def uuid_before(puzzle_id: str) -> str:
    """The id just before `puzzle_id` in Postgres uuid order (byte order = integer order) - an exclusive `after`
    cursor that still includes `puzzle_id` itself."""
    n = uuid.UUID(puzzle_id).int
    return str(uuid.UUID(int=max(0, n - 1)))


class InternalApi:
    def __init__(
        self,
        base_url: str = DEFAULT_BASE_URL,
        token_provider: Callable[[], str] = read_token,
        transport: httpx.BaseTransport | None = None,
        min_interval: float = 0.3,
        max_retries: int = 8,
        timeout: float = 120.0,
        sleep: Callable[[float], None] = time.sleep,
    ) -> None:
        self.base_url = base_url.rstrip("/")
        self._token_provider = token_provider
        self._client = httpx.Client(base_url=self.base_url, transport=transport, timeout=timeout,
                                    headers={"User-Agent": USER_AGENT, "Accept": "application/json"})
        self.min_interval = min_interval
        self.max_retries = max_retries
        self.sleep = sleep
        self._pace_lock = threading.Lock()
        self._last_call = 0.0

    def close(self) -> None:
        self._client.close()

    def __repr__(self) -> str:
        return f"InternalApi({self.base_url!r})"

    # transport -------------------------------------------------------------------------------------------------------
    def _pace(self) -> None:
        with self._pace_lock:
            wait = self._last_call + self.min_interval - time.monotonic()
            if wait > 0:
                self.sleep(wait)
            self._last_call = time.monotonic()

    def _request(self, method: str, path: str, *, params: dict[str, Any] | None = None, json: Any = None) -> httpx.Response:
        attempt = 0
        while True:
            self._pace()
            headers = {"Authorization": f"Bearer {self._token_provider()}"}
            try:
                resp = self._client.request(method, path, params=params, json=json, headers=headers)
            except httpx.TransportError as e:
                attempt += 1
                if attempt > self.max_retries:
                    raise
                delay = min(300.0, 2.0 ** (attempt - 1))
                log.warning("network error on %s %s (%s) - retry in %.0f s", method, path, type(e).__name__, delay)
                self.sleep(delay)
                continue
            if resp.status_code == 429 or resp.status_code >= 500:
                attempt += 1
                if attempt > self.max_retries:
                    raise ApiError(resp.status_code, f"{method} {path} gave up after {self.max_retries} retries", _body(resp))
                delay = _retry_after(resp)
                if delay is None:
                    delay = min(300.0, (30.0 if resp.status_code == 429 else 1.0) * 2.0 ** (attempt - 1))
                log.info("HTTP %d on %s %s - waiting %.0f s", resp.status_code, method, path, delay)
                self.sleep(delay)
                continue
            if resp.status_code >= 400:
                raise _error(resp, method, path)
            return resp

    # image work list + claims ------------------------------------------------------------------------------------------
    def list_images(self, analyzer: str | None = None, version: str | None = None, status: str = "all",
                    limit: int = 500, after: str | None = None, requires: tuple[str, ...] | list[str] = ()) -> dict[str, Any]:
        params: dict[str, Any] = {"status": status, "limit": limit}
        if analyzer:
            params["analyzer"] = analyzer
        if version:
            params["version"] = version
        if after:
            params["after"] = after
        if requires:
            params["requires"] = ",".join(requires)
        data = self._request("GET", "/internal-api/puzzle-images", params=params).json()
        return {"puzzles": [WorkItem.from_api(p) for p in data.get("puzzles", [])], "nextCursor": data.get("nextCursor")}

    def iter_images(self, **kwargs: Any) -> Iterator[WorkItem]:
        after = kwargs.pop("after", None)
        while True:
            page = self.list_images(after=after, **kwargs)
            yield from page["puzzles"]
            after = page["nextCursor"]
            if not after:
                return

    def claim(self, analyzer: str, version: str, worker: str, limit: int = 50, lease_seconds: int = 1800,
              requires: tuple[str, ...] | list[str] = ()) -> list[WorkItem]:
        body: dict[str, Any] = {"analyzer": analyzer, "version": version, "worker": worker,
                                "limit": max(1, min(CLAIM_LIMIT_MAX, limit)), "leaseSeconds": max(60, min(7200, lease_seconds))}
        if requires:
            body["requires"] = list(requires)
        data = self._request("POST", "/internal-api/puzzle-image-claims", json=body).json()
        return [WorkItem.from_api(p) for p in data.get("puzzles", [])]

    def release(self, worker: str, analyzer: str | None = None, puzzle_ids: list[str] | None = None) -> int:
        body: dict[str, Any] = {"worker": worker}
        if analyzer:
            body["analyzer"] = analyzer
        if puzzle_ids:
            body["puzzleIds"] = list(puzzle_ids)
        return int(self._request("POST", "/internal-api/puzzle-image-claims/release", json=body).json().get("released", 0))

    def store(self, results: list[AnalysisResult]) -> list[StoreOutcome]:
        """Batch store in chunks of <= 200 (one transaction each - a refused item never fails the others)."""
        outcomes: list[StoreOutcome] = []
        for start in range(0, len(results), STORE_BATCH_MAX):
            chunk = results[start:start + STORE_BATCH_MAX]
            data = self._request("POST", "/internal-api/puzzle-image-analyses", json={"items": [r.to_api() for r in chunk]}).json()
            by_index = {int(i.get("index", n)): i for n, i in enumerate(data.get("items", []))}
            for n, r in enumerate(chunk):
                item = by_index.get(n, {})
                outcomes.append(StoreOutcome(
                    puzzle_id=r.puzzle_id, analyzer=r.analyzer, status=item.get("status", "invalid"),
                    message=item.get("message"), current_fingerprint=item.get("currentFingerprint"), errors=item.get("errors"),
                ))
        return outcomes

    def put_analysis(self, r: AnalysisResult) -> dict[str, Any]:
        body = {k: v for k, v in r.to_api().items() if k not in ("puzzleId", "analyzer")}
        return self._request("PUT", f"/internal-api/puzzles/{r.puzzle_id}/image-analysis/{r.analyzer}", json=body).json()

    def puzzle_analyses(self, puzzle_id: str) -> dict[str, Any]:
        return self._request("GET", f"/internal-api/puzzles/{puzzle_id}/image-analysis").json()

    def analyses_page(self, analyzer: str, after: str | None = None, limit: int = 500, only_current: bool = True) -> dict[str, Any]:
        params: dict[str, Any] = {"analyzer": analyzer, "limit": limit}
        if only_current:
            params["onlyCurrent"] = 1
        if after:
            params["after"] = after
        return self._request("GET", "/internal-api/puzzle-image-analyses", params=params).json()

    def iter_analyses(self, analyzer: str, after: str | None = None, limit: int = 500, only_current: bool = True) -> Iterator[dict[str, Any]]:
        """Follows nextCursor until it is null - a short page is not the end (pages also end at ~8 MB)."""
        while True:
            page = self.analyses_page(analyzer, after=after, limit=limit, only_current=only_current)
            yield from page.get("items", [])
            after = page.get("nextCursor")
            if not after:
                return

    # change requests (crop proposals) ----------------------------------------------------------------------------------
    def propose_image_change(self, puzzle_id: str, image: bytes, *, confidence: float, model_version: str,
                             source_image_fingerprint: str, tags: list[str]) -> dict[str, Any]:
        """`201 {changeRequestId, recordVersion}`. `image` = plain base64 of the JPEG (<= 15 MB decoded; a bad image is a
        422 `errors.image`). 404 = unknown puzzle or a hidden picture; 409 `already_proposed` / `image_changed` /
        `pending_proposal`; 400 = bad metadata."""
        body = {
            "puzzleId": puzzle_id,
            "image": base64.b64encode(image).decode("ascii"),
            "source": "image_pipeline",
            "tags": tags,
            "confidence": round(float(confidence), 4),
            "modelVersion": model_version,
            "sourceImageFingerprint": source_image_fingerprint,
        }
        return self._request("POST", "/internal-api/puzzle-change-requests", json=body).json()

    def change_requests_page(self, source: str | None = None, tag: str | None = None, status: str = "all",
                             limit: int = 200, after: str | None = None) -> dict[str, Any]:
        params: dict[str, Any] = {"status": status, "limit": limit}
        if source:
            params["source"] = source
        if tag:
            params["tag"] = tag
        if after:
            params["after"] = after
        data = self._request("GET", "/internal-api/puzzle-change-requests", params=params).json()
        items = data.get("changeRequests") if isinstance(data, dict) else None
        if not isinstance(items, list):
            raise ApiError(200, "GET /internal-api/puzzle-change-requests: no `changeRequests` list in the answer", data)
        return {"items": items, "nextCursor": data.get("nextCursor")}

    def iter_change_requests(self, **kwargs: Any) -> Iterator[dict[str, Any]]:
        after = kwargs.pop("after", None)
        while True:
            page = self.change_requests_page(after=after, **kwargs)
            yield from page["items"]
            after = page["nextCursor"]
            if not after:
                return

    def approve_change_request(self, change_request_id: str, selected_fields: list[str], decision_note: str,
                               record_version: str | None = None) -> None:
        """`decisionNote` (<= 500 characters) becomes the decision log's note, listed back as `decision.note`. 409 =
        decided already / puzzle changed / `image_changed` (a crop of an older picture) / `original_not_archived`."""
        if len(decision_note) > 500:
            raise ValueError("decisionNote can be at most 500 characters")
        body: dict[str, Any] = {"selectedFields": selected_fields, "decisionNote": decision_note}
        if record_version:
            body["recordVersion"] = record_version
        self._request("POST", f"/internal-api/puzzle-change-requests/{change_request_id}/approve", json=body)

    def reject_change_request(self, change_request_id: str, rejection_reason: str, decision_note: str | None = None) -> None:
        """Only a pending request: a decided one answers 409 (Conflict) and nothing changes."""
        body: dict[str, Any] = {"rejectionReason": rejection_reason}
        if decision_note:
            body["decisionNote"] = decision_note
        self._request("POST", f"/internal-api/puzzle-change-requests/{change_request_id}/reject", json=body)

    # difficulty labels, estimates, model record (Phase 5) ---------------------------------------------------------------
    def difficulty_labels_page(self, after: str | None = None, limit: int = 1000) -> dict[str, Any]:
        params: dict[str, Any] = {"limit": max(1, min(LABELS_LIMIT_MAX, limit))}
        if after:
            params["after"] = after
        data = self._request("GET", "/internal-api/puzzle-difficulty-labels", params=params).json()
        labels = data.get("labels") if isinstance(data, dict) else None
        if not isinstance(labels, list):
            raise ApiError(200, "GET /internal-api/puzzle-difficulty-labels: no `labels` list in the answer", data)
        return {"labels": [DifficultyLabel.from_api(x) for x in labels], "nextCursor": data.get("nextCursor")}

    def iter_difficulty_labels(self, limit: int = LABELS_LIMIT_MAX) -> Iterator[DifficultyLabel]:
        """Every label, following nextCursor until it is null. Secret puzzles are never listed."""
        after = None
        while True:
            page = self.difficulty_labels_page(after=after, limit=limit)
            yield from page["labels"]
            after = page["nextCursor"]
            if not after:
                return

    def store_difficulty_estimates(self, estimates: list[DifficultyEstimate]) -> list[EstimateOutcome]:
        """`PUT` in chunks of <= 500 (one transaction each, a refused item never fails the others). Idempotent: the
        current value per puzzle is replaced. `hidden` / `stale_fingerprint` / `unknown_puzzle` = skip, nothing stored."""
        outcomes: list[EstimateOutcome] = []
        for start in range(0, len(estimates), ESTIMATE_BATCH_MAX):
            chunk = estimates[start:start + ESTIMATE_BATCH_MAX]
            data = self._request("PUT", "/internal-api/puzzle-difficulty-estimates", json={"items": [e.to_api() for e in chunk]}).json()
            by_index = {int(i.get("index", n)): i for n, i in enumerate(data.get("items", []))}
            for n, e in enumerate(chunk):
                item = by_index.get(n, {})
                outcomes.append(EstimateOutcome(
                    puzzle_id=e.puzzle_id, status=item.get("status", "invalid"), message=item.get("message"),
                    current_fingerprint=item.get("currentFingerprint"), errors=item.get("errors"),
                ))
        return outcomes

    def record_difficulty_model(self, record: DifficultyModelRecord) -> dict[str, Any]:
        """`201` with the stored record (`id`, `receivedAt`, ...) - it becomes the current one. 422 = invalid fields."""
        return self._request("POST", "/internal-api/puzzle-difficulty-estimates/model", json=record.to_api()).json()

    def current_difficulty_model(self, model: str | None = None) -> dict[str, Any] | None:
        """The latest recorded run (of `model` when given), None before the first one - the push guard's baseline."""
        params = {"model": model} if model else None
        data = self._request("GET", "/internal-api/puzzle-difficulty-estimates/model", params=params).json()
        if not isinstance(data, dict) or "current" not in data:
            raise ApiError(200, "GET /internal-api/puzzle-difficulty-estimates/model: no `current` in the answer", data)
        current = data["current"]
        return current if isinstance(current, dict) else None

    def endpoint_exists(self, path: str) -> bool:
        try:
            self._request("GET", path, params={"limit": 1})
        except NotFound:
            return False
        return True


def _body(resp: httpx.Response) -> Any:
    try:
        return resp.json()
    except ValueError:
        return resp.text[:500]


def _retry_after(resp: httpx.Response) -> float | None:
    value = resp.headers.get("Retry-After")
    if not value:
        return None
    try:
        return max(0.0, float(value))
    except ValueError:
        return None


def _error(resp: httpx.Response, method: str, path: str) -> ApiError:
    body = _body(resp)
    message = body.get("error") if isinstance(body, dict) and isinstance(body.get("error"), str) else f"{method} {path}"
    text = str(body.get("error", "") if isinstance(body, dict) else body).lower()
    status = resp.status_code
    if status == 400:
        return BadRequest(status, message, body)
    if status in (401, 403):
        return Unauthorized(status, "the internal API refused the token (missing, wrong or the API is closed)", None)
    if status == 404:
        return NotFound(status, message, body)
    if status == 409:
        code = body.get("code") if isinstance(body, dict) else None
        coded = {"already_proposed": AlreadyProposed, "image_changed": StaleFingerprint, "pending_proposal": PendingProposal,
                 "original_not_archived": OriginalNotArchived}
        if code in coded:
            return coded[code](status, message, body)
        # the image analysis store answers a changed picture with a plain 409 naming the current fingerprint
        if "fingerprint" in text:
            return StaleFingerprint(status, message, body)
        return Conflict(status, message, body)
    if status == 422:
        return Invalid(status, message, body)
    return ApiError(status, message, body)
