"""The arithmetic behind `/image-crop-review` (implementation-plan.md §2.5) - so the skill never computes by hand.

- **Earn the threshold:** below ~300 human decisions nothing is auto-approved.
- Then the auto band starts at the **lowest confidence** where the human precision of every decision at or above it has
  a **Wilson lower bound >= 99 %** (95 % interval) on at least `min_band_n` decisions.
- **Audit:** a deterministic 5 % of would-be-auto items (by a hash of the change request id - any machine, any run
  picks the same ones) stays pending for humans. When the humans' precision on decided audit items drops below 98 %
  (on >= 20 of them), auto-approval stops and the plan says so.
- **Human decision = decided in the moderators' web review**: `decision.source == "admin_ui"` and outcome approved or
  rejected. Decisions through the internal API (`source "internal_api"`: this plan's `auto:` notes, Claude Code's
  `claude-review:` notes, anything else filed by a script) and `outdated` closures (`source "automatic"`) never earn
  or check the threshold - only people's decisions count toward the ~300 and the precision.
- Middle band (propose threshold .. auto threshold): listed with the before / after URLs for an optional look;
  anything unsure stays for moderators.
"""

from __future__ import annotations

import hashlib
import math
from dataclasses import asdict, dataclass, field
from typing import Any

MIN_HUMAN_DECISIONS = 300
TARGET_LOWER_BOUND = 0.99
AUDIT_SHARE = 0.05
STOP_BELOW = 0.98
MIN_BAND_N = 50
MIN_AUDIT_N = 20
PROPOSE_THRESHOLD = 0.6
AUTO_PREFIX = "auto:"
CLAUDE_PREFIX = "claude-review:"


def wilson_lower(k: int, n: int, z: float = 1.959964) -> float:
    """Lower bound of the Wilson score interval for k successes in n trials."""
    if n <= 0:
        return 0.0
    p = k / n
    denom = 1 + z * z / n
    centre = p + z * z / (2 * n)
    margin = z * math.sqrt(p * (1 - p) / n + z * z / (4 * n * n))
    return max(0.0, (centre - margin) / denom)


def is_audit(change_request_id: str, share: float = AUDIT_SHARE) -> bool:
    h = int(hashlib.sha256(change_request_id.encode()).hexdigest()[:8], 16)
    return (h % 10000) < share * 10000


STATUSES = ("pending", "approved", "rejected", "outdated")
HUMAN_SOURCE = "admin_ui"


class ContractError(ValueError):
    """An item of `GET /internal-api/puzzle-change-requests` without the fields the plan relies on."""


@dataclass
class Item:
    id: str
    puzzle_id: str
    confidence: float
    status: str  # pending | approved | rejected | outdated
    decision_source: str | None  # admin_ui | internal_api | automatic
    note: str
    model_version: str
    original_url: str | None
    proposed_url: str | None
    decided_at: str | None

    @property
    def decided(self) -> bool:
        return self.status in ("approved", "rejected")

    @property
    def human(self) -> bool:
        return self.decided and self.decision_source == HUMAN_SOURCE

    @property
    def auto(self) -> bool:
        return self.decided and self.decision_source == "internal_api" and self.note.startswith(AUTO_PREFIX)

    @property
    def claude(self) -> bool:
        return self.decided and self.decision_source == "internal_api" and self.note.startswith(CLAUDE_PREFIX)

    @property
    def approved(self) -> bool:
        return self.status == "approved"


def parse_item(d: dict[str, Any]) -> Item:
    """Strict: the fields of the list's documented item shape (docs/features/internal-api.md "Puzzle change requests")."""
    for key in ("id", "puzzleId", "status", "confidence", "decision"):
        if key not in d:
            raise ContractError(f"change request item without `{key}`: {sorted(d)}")
    status = d["status"]
    if status not in STATUSES:
        raise ContractError(f"unknown change request status {status!r}")
    decision = d["decision"]
    if status == "pending":
        decision = None
    elif not isinstance(decision, dict) or "source" not in decision or "note" not in decision:
        raise ContractError(f"decided change request {d['id']} without decision.source / decision.note")
    return Item(
        id=str(d["id"]),
        puzzle_id=str(d["puzzleId"]),
        confidence=float(d["confidence"]) if d["confidence"] is not None else -1.0,
        status=status,
        decision_source=decision.get("source") if decision else None,
        note=str((decision or {}).get("note") or ""),
        model_version=str(d.get("modelVersion") or ""),
        original_url=d.get("originalImageUrl"),
        proposed_url=d.get("proposedImageUrl"),
        decided_at=(decision or {}).get("decidedAt"),
    )


@dataclass
class Plan:
    human_decisions: int
    human_approved: int
    earned: bool
    threshold: float | None
    threshold_n: int
    threshold_precision: float | None
    threshold_lower_bound: float | None
    audit_decided: int
    audit_precision: float | None
    auto_stopped: bool
    reason: str
    to_approve: list[dict[str, Any]] = field(default_factory=list)
    audit_held: list[dict[str, Any]] = field(default_factory=list)
    middle_band: list[dict[str, Any]] = field(default_factory=list)
    bands: list[dict[str, Any]] = field(default_factory=list)

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


def find_threshold(human: list[Item], target: float = TARGET_LOWER_BOUND, min_n: int = MIN_BAND_N) -> tuple[float | None, int, int]:
    """The lowest confidence t such that the decisions with confidence >= t reach the Wilson lower bound."""
    ordered = sorted(human, key=lambda i: -i.confidence)
    best: tuple[float | None, int, int] = (None, 0, 0)
    n = k = 0
    for idx, item in enumerate(ordered):
        n += 1
        k += 1 if item.approved else 0
        nxt = ordered[idx + 1].confidence if idx + 1 < len(ordered) else None
        if nxt is not None and nxt == item.confidence:
            continue  # take every decision with the same confidence together
        if n >= min_n and wilson_lower(k, n) >= target:
            best = (item.confidence, n, k)
    return best


def decision_note(item: Item, threshold: float, lower_bound: float, n: int) -> str:
    return (f"auto: confidence {item.confidence:.3f}, band >= {threshold:.3f} (human precision Wilson LB "
            f"{lower_bound:.3f}, n {n}), model {item.model_version}")


def claude_note(item: Item) -> str:
    return f"{CLAUDE_PREFIX} confidence {item.confidence:.3f}, before/after checked by Claude Code, model {item.model_version}"


def bands(items: list[Item], width: float = 0.05, start: float = PROPOSE_THRESHOLD) -> list[dict[str, Any]]:
    out = []
    lo = start
    while lo < 1.0 - 1e-9:
        hi = min(1.0, lo + width)
        inside = [i for i in items if lo <= i.confidence < hi or (hi >= 1.0 and i.confidence >= lo)]
        human = [i for i in inside if i.human]
        k = sum(1 for i in human if i.approved)
        out.append({
            "band": f"{lo:.2f}-{hi:.2f}", "pending": sum(1 for i in inside if i.status == "pending"),
            "humanApproved": k, "humanRejected": len(human) - k,
            "auto": sum(1 for i in inside if i.auto),
            "claude": sum(1 for i in inside if i.claude),
            "outdated": sum(1 for i in inside if i.status == "outdated"),
            "humanPrecision": round(k / len(human), 4) if human else None,
        })
        lo = hi
    return out


def plan(raw_items: list[dict[str, Any]], min_human: int = MIN_HUMAN_DECISIONS, target: float = TARGET_LOWER_BOUND,
         audit_share: float = AUDIT_SHARE, stop_below: float = STOP_BELOW, min_band_n: int = MIN_BAND_N,
         propose_threshold: float = PROPOSE_THRESHOLD) -> Plan:
    items = [i for i in (parse_item(d) for d in raw_items) if i.confidence >= 0]  # no confidence = not a pipeline crop
    human = [i for i in items if i.human]
    k_all = sum(1 for i in human if i.approved)
    p = Plan(human_decisions=len(human), human_approved=k_all, earned=False, threshold=None, threshold_n=0,
             threshold_precision=None, threshold_lower_bound=None, audit_decided=0, audit_precision=None,
             auto_stopped=False, reason="", bands=bands(items, start=propose_threshold))
    pending = sorted((i for i in items if i.status == "pending"), key=lambda i: -i.confidence)
    if len(human) < min_human:
        p.reason = f"threshold not earned yet: {len(human)} of {min_human} human decisions - nothing is auto-approved"
    else:
        t, n, k = find_threshold(human, target, min_band_n)
        if t is None:
            p.reason = f"no confidence band reaches a Wilson lower bound of {target:.0%} yet - nothing is auto-approved"
        else:
            p.earned = True
            p.threshold, p.threshold_n = t, n
            p.threshold_precision = round(k / n, 4)
            p.threshold_lower_bound = round(wilson_lower(k, n), 4)
            audit = [i for i in human if i.confidence >= t and is_audit(i.id, audit_share)]
            p.audit_decided = len(audit)
            if audit:
                p.audit_precision = round(sum(1 for i in audit if i.approved) / len(audit), 4)
            if len(audit) >= MIN_AUDIT_N and p.audit_precision is not None and p.audit_precision < stop_below:
                p.auto_stopped = True
                p.reason = (f"AUTO-APPROVAL STOPPED: human precision on audited items {p.audit_precision:.1%} < "
                            f"{stop_below:.0%} (n {len(audit)}) - report to Jan, leave everything to moderators")
            else:
                p.reason = f"auto band >= {t:.3f}: human precision {p.threshold_precision:.1%}, Wilson LB {p.threshold_lower_bound:.1%}, n {n}"
    for i in pending:
        entry = {"id": i.id, "puzzleId": i.puzzle_id, "confidence": i.confidence, "modelVersion": i.model_version,
                 "originalImageUrl": i.original_url, "proposedImageUrl": i.proposed_url}
        if p.earned and p.threshold is not None and i.confidence >= p.threshold:
            if is_audit(i.id, audit_share):
                p.audit_held.append(entry)
            elif not p.auto_stopped:
                p.to_approve.append(entry | {"decisionNote": decision_note(i, p.threshold, p.threshold_lower_bound or 0.0, p.threshold_n)})
        elif i.confidence >= propose_threshold:
            p.middle_band.append(entry)
    return p
