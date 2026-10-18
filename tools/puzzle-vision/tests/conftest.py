"""A fake of the internal API (docs/features/internal-api.md § "Puzzle image analysis" + the Phase 1 additions:
claims `requires`, change requests from the pipeline) on httpx.MockTransport - the tests never touch the network."""

from __future__ import annotations

import base64
import io
import json
import time
import uuid
from typing import Any

import httpx
import numpy as np
import pytest
from PIL import Image

from puzzle_vision.analyzers import ALL
from puzzle_vision.api import InternalApi

TOKEN = "test-token-not-real"
BASE = "https://msp.test"
IMG = "https://img.test/original"


def pid(i: int) -> str:
    return str(uuid.UUID(int=(0x0190 << 112) + i))


def png(w: int = 64, h: int = 48, color: tuple[int, int, int] = (200, 30, 30)) -> bytes:
    a = np.zeros((h, w, 3), np.uint8)
    a[:] = color
    a[h // 4: 3 * h // 4, w // 4: 3 * w // 4] = (20, 120, 220)
    buf = io.BytesIO()
    Image.fromarray(a).save(buf, "PNG")
    return buf.getvalue()


def php(value: Any) -> Any:
    """What the app answers for a stored payload: JSON objects are decoded into PHP arrays, so an empty map comes back as []."""
    if isinstance(value, dict):
        return {k: php(v) for k, v in value.items()} if value else []
    if isinstance(value, list):
        return [php(v) for v in value]
    return value


class FakeServer:
    def __init__(self, n: int = 5) -> None:
        self.now = time.time
        self.puzzles: dict[str, dict[str, Any]] = {}
        self.images: dict[str, bytes] = {}
        self.analyses: dict[tuple[str, str], dict[str, Any]] = {}
        self.claims: dict[tuple[str, str], dict[str, Any]] = {}
        self.change_requests: list[dict[str, Any]] = []
        self.requests: list[dict[str, Any]] = []
        self.fail_next: dict[str, list[httpx.Response]] = {}
        self.page_limit_cap: int | None = None
        self.hidden: set[str] = set()
        for i in range(n):
            self.add_puzzle(i)

    def add_puzzle(self, i: int, fp: str | None = None) -> str:
        p = pid(i)
        name = f"img-{i}.png"
        self.puzzles[p] = {"puzzleId": p, "name": f"Puzzle {i}", "piecesCount": 500, "manufacturer": {"id": pid(900), "name": "Brandy"},
                           "imageUrl": f"{IMG}/{name}", "imageFingerprint": fp or f"{i:016x}", "imageRatio": 1.33,
                           "solvedCount": i * 3}
        self.images[name] = png()
        return p

    def change_picture(self, p: str, fp: str) -> None:
        self.puzzles[p]["imageFingerprint"] = fp

    # helpers -------------------------------------------------------------------------------------------------------
    def _item(self, p: str) -> dict[str, Any]:
        d = dict(self.puzzles[p])
        d["analyses"] = {a: {k: r[k] for k in ("analyzerVersion", "imageFingerprint", "analyzedAt", "receivedAt")}
                         for (a, q), r in self.analyses.items() if q == p}
        d["claims"] = {a: {"worker": c["worker"], "expiresAt": "x"} for (a, q), c in self.claims.items() if q == p and c["expires"] > self.now()}
        return d

    def _current(self, a: str, p: str) -> bool:
        r = self.analyses.get((a, p))
        return bool(r) and r["imageFingerprint"] == self.puzzles[p]["imageFingerprint"]

    def _todo(self, a: str, version: str | None, p: str) -> bool:
        r = self.analyses.get((a, p))
        return r is None or r["imageFingerprint"] != self.puzzles[p]["imageFingerprint"] or (version is not None and r["analyzerVersion"] != version)

    def _requires(self, raw: Any, analyzer: str | None) -> tuple[list[str], dict[str, str]]:
        values = raw if isinstance(raw, list) else [x for x in str(raw or "").split(",") if x]
        errors = {}
        for v in values:
            if v not in ALL:
                errors["requires"] = f"unknown analyzer {v}"
            if v == analyzer:
                errors["requires"] = "an analyzer cannot require itself"
        return values, errors

    def _row(self, r: dict[str, Any]) -> dict[str, Any]:
        out = dict(r)
        out["result"] = php(r["result"])
        out["current"] = r["imageFingerprint"] == self.puzzles[r["puzzleId"]]["imageFingerprint"]
        return out

    # transport -----------------------------------------------------------------------------------------------------
    def handler(self, request: httpx.Request) -> httpx.Response:
        url = request.url
        body = json.loads(request.content) if request.content else None
        self.requests.append({"method": request.method, "path": url.path, "params": dict(url.params), "json": body,
                              "auth": request.headers.get("Authorization")})
        if url.host == "img.test":
            name = url.path.rsplit("/", 1)[-1]
            return httpx.Response(200, content=self.images[name]) if name in self.images else httpx.Response(404)
        key = f"{request.method} {url.path}"
        if self.fail_next.get(key):
            return self.fail_next[key].pop(0)
        if request.headers.get("Authorization") != f"Bearer {TOKEN}":
            return httpx.Response(401, json={"error": "Unauthorized"})
        q = dict(url.params)
        path = url.path
        if request.method == "GET" and path == "/internal-api/puzzle-images":
            return self.list_images(q)
        if request.method == "POST" and path == "/internal-api/puzzle-image-claims":
            return self.claim(body)
        if request.method == "POST" and path == "/internal-api/puzzle-image-claims/release":
            return self.release(body)
        if request.method == "POST" and path == "/internal-api/puzzle-image-analyses":
            return self.store(body)
        if request.method == "GET" and path == "/internal-api/puzzle-image-analyses":
            return self.bulk(q)
        if path.startswith("/internal-api/puzzles/") and path.endswith("/image-analysis") and request.method == "GET":
            p = path.split("/")[3]
            if p not in self.puzzles:
                return httpx.Response(404, json={"error": "not found"})
            return httpx.Response(200, json={"puzzleId": p, "analyses": {a: self._row(r) for (a, q2), r in self.analyses.items() if q2 == p}})
        if path.startswith("/internal-api/puzzles/") and request.method == "PUT":
            parts = path.split("/")
            return self.put(parts[3], parts[5], body)
        if path == "/internal-api/puzzle-change-requests" and request.method == "POST":
            return self.propose(body)
        if path == "/internal-api/puzzle-change-requests" and request.method == "GET":
            return self.list_cr(q)
        if path.startswith("/internal-api/puzzle-change-requests/") and request.method == "POST":
            _, _, _, cid, action = path.split("/")
            return self.decide(cid, action, body)
        return httpx.Response(404, json={"error": "No route"})

    def list_images(self, q: dict[str, str]) -> httpx.Response:
        requires, errors = self._requires(q.get("requires"), q.get("analyzer"))
        if errors:
            return httpx.Response(400, json={"error": "Invalid query", "errors": errors})
        status, a, v = q.get("status", "all"), q.get("analyzer"), q.get("version")
        limit, after = int(q.get("limit", 500)), q.get("after")
        ids = [p for p in sorted(self.puzzles) if not after or p > after]
        ids = [p for p in ids if all(self._current(r, p) for r in requires)]
        if status == "missing":
            ids = [p for p in ids if (a, p) not in self.analyses]
        elif status == "stale":
            ids = [p for p in ids if self._todo(a, v, p)]
        page = ids[:limit]
        nxt = page[-1] if len(ids) > limit else None
        return httpx.Response(200, json={"puzzles": [self._item(p) for p in page], "nextCursor": nxt})

    def claim(self, b: dict[str, Any]) -> httpx.Response:
        a, v, w = b["analyzer"], b["version"], b["worker"]
        requires, errors = self._requires(b.get("requires", []), a)
        if errors:
            return httpx.Response(400, json={"error": "Invalid fields", "errors": errors})
        limit, lease = int(b.get("limit", 50)), int(b.get("leaseSeconds", 1800))
        now = self.now()
        fresh, own = [], []
        for p in sorted(self.puzzles):
            if not self._todo(a, v, p) or not all(self._current(r, p) for r in requires):
                continue
            c = self.claims.get((a, p))
            if c is None or c["expires"] <= now:
                fresh.append(p)
            elif c["worker"] == w:
                own.append(p)
        taken = (fresh + own)[:limit]
        for p in taken:
            self.claims[(a, p)] = {"worker": w, "expires": now + lease}
        return httpx.Response(200, json={"puzzles": [self._item(p) for p in taken]})

    def release(self, b: dict[str, Any]) -> httpx.Response:
        n = 0
        for (a, p), c in list(self.claims.items()):
            if c["worker"] != b["worker"] or (b.get("analyzer") and a != b["analyzer"]) or (b.get("puzzleIds") and p not in b["puzzleIds"]):
                continue
            del self.claims[(a, p)]
            n += 1
        return httpx.Response(200, json={"released": n})

    def _store_one(self, it: dict[str, Any]) -> dict[str, Any]:
        p, a = it["puzzleId"], it["analyzer"]
        if p not in self.puzzles:
            return {"status": "not_found"}
        if it["imageFingerprint"] != self.puzzles[p]["imageFingerprint"]:
            return {"status": "stale_fingerprint", "currentFingerprint": self.puzzles[p]["imageFingerprint"]}
        r = it.get("result")
        if not isinstance(r, dict) or not r or len(json.dumps(r, ensure_ascii=False, separators=(",", ":")).encode()) > 65536:
            return {"status": "invalid", "errors": {"result": "bad"}}
        if a == "artwork" and r.get("bbox"):
            b = r["bbox"]
            if not (0 <= b["x"] <= 1 and 0 <= b["y"] <= 1 and b["w"] > 0 and b["h"] > 0 and b["x"] + b["w"] <= 1.000001 and b["y"] + b["h"] <= 1.000001):
                return {"status": "invalid", "errors": {"result.bbox": "outside"}}
        self.analyses[(a, p)] = {"puzzleId": p, "analyzer": a, "analyzerVersion": it["analyzerVersion"], "imageFingerprint": it["imageFingerprint"],
                                 "analyzedAt": it.get("analyzedAt"), "receivedAt": "2026-10-18T10:00:00+00:00", "result": r}
        self.claims.pop((a, p), None)
        return {"status": "stored"}

    def store(self, b: dict[str, Any]) -> httpx.Response:
        items = b.get("items") or []
        if not 1 <= len(items) <= 200:
            return httpx.Response(422, json={"error": "1-200 items", "errors": {"items": "1-200"}})
        out = []
        counts = {"stored": 0, "stale_fingerprint": 0, "not_found": 0, "invalid": 0}
        for i, it in enumerate(items):
            r = self._store_one(it) | {"index": i, "puzzleId": it["puzzleId"], "analyzer": it["analyzer"]}
            counts[r["status"]] += 1
            out.append(r)
        return httpx.Response(200, json={"counts": counts, "items": out})

    def put(self, p: str, a: str, b: dict[str, Any]) -> httpx.Response:
        r = self._store_one({"puzzleId": p, "analyzer": a, **b})
        if r["status"] == "not_found":
            return httpx.Response(404, json={"error": "Puzzle not found"})
        if r["status"] == "stale_fingerprint":
            return httpx.Response(409, json={"error": f"The picture changed - the current imageFingerprint is {r['currentFingerprint']}"})
        if r["status"] == "invalid":
            return httpx.Response(422, json={"error": "Invalid", "errors": r["errors"]})
        return httpx.Response(200, json=self._row(self.analyses[(a, p)]))

    def bulk(self, q: dict[str, str]) -> httpx.Response:
        a, after, limit = q["analyzer"], q.get("after"), int(q.get("limit", 500))
        if self.page_limit_cap:
            limit = min(limit, self.page_limit_cap)
        rows = [self._row(r) for (an, p), r in sorted(self.analyses.items(), key=lambda kv: kv[0][1]) if an == a and (not after or p > after)]
        if q.get("onlyCurrent") in ("1", "true"):
            rows = [r for r in rows if r["current"]]
        page = rows[:limit]
        return httpx.Response(200, json={"analyzer": a, "items": page, "nextCursor": page[-1]["puzzleId"] if len(rows) > limit else None})

    def propose(self, b: dict[str, Any]) -> httpx.Response:
        """SubmitPuzzleChangeRequestController + ImagePipelineProposalRules (docs/features/internal-api.md)."""
        p = b["puzzleId"]
        if p not in self.puzzles or p in self.hidden:
            return httpx.Response(404, json={"error": "No such puzzle with an image to propose for."})
        if b.get("source") == "image_pipeline" and (not b.get("tags") or not b.get("sourceImageFingerprint")):
            return httpx.Response(400, json={"error": "Invalid fields", "errors": {"tags": "needs at least one tag"}})
        base64.b64decode(b["image"], validate=True)
        if b.get("sourceImageFingerprint") != self.puzzles[p]["imageFingerprint"]:
            return httpx.Response(409, json={"error": "The puzzle's picture changed - it is now " + self.puzzles[p]["imageFingerprint"], "code": "image_changed"})
        for cr in self.change_requests:
            if cr["puzzleId"] == p and cr["source"] == "image_pipeline" and cr["sourceImageFingerprint"] == b["sourceImageFingerprint"] and set(cr["tags"]) & set(b.get("tags", [])):
                return httpx.Response(409, json={"error": f"Proposed already: change request {cr['id']}", "code": "already_proposed"})
        if any(cr["puzzleId"] == p and cr["status"] == "pending" for cr in self.change_requests):
            return httpx.Response(409, json={"error": "The puzzle already has a pending change request.", "code": "pending_proposal"})
        cid = pid(5000 + len(self.change_requests))
        self.change_requests.append({"id": cid, "puzzleId": p, "puzzleName": self.puzzles[p]["name"], "status": "pending", "source": b["source"],
                                     "tags": b["tags"], "confidence": b["confidence"], "modelVersion": b["modelVersion"],
                                     "sourceImageFingerprint": b["sourceImageFingerprint"], "originalImageUrl": self.puzzles[p]["imageUrl"],
                                     "proposedImageUrl": f"{IMG}/proposal-{cid}.jpg", "originalArchiveUrl": None,
                                     "createdAt": "2026-10-18T10:00:00+00:00", "decision": None, "_image": b["image"]})
        return httpx.Response(201, json={"changeRequestId": cid, "recordVersion": "rv-" + p[-4:]})

    def list_cr(self, q: dict[str, str]) -> httpx.Response:
        rows = [c for c in self.change_requests if (not q.get("source") or c["source"] == q["source"]) and (not q.get("tag") or q["tag"] in c["tags"])
                and (q.get("status", "all") == "all" or c["status"] == q["status"]) and (not q.get("after") or c["id"] > q["after"])]
        limit = int(q.get("limit", 200))
        page = rows[:limit]
        return httpx.Response(200, json={"changeRequests": [{k: v for k, v in c.items() if not k.startswith("_")} for c in page],
                                         "nextCursor": page[-1]["id"] if len(rows) > limit else None})

    def decide(self, cid: str, action: str, b: dict[str, Any]) -> httpx.Response:
        for c in self.change_requests:
            if c["id"] == cid:
                if c["status"] != "pending":  # approve and reject both check the status
                    return httpx.Response(409, json={"error": "The change request was decided already."})
                c["status"] = "approved" if action == "approve" else "rejected"
                rejected = action == "reject"
                c["decision"] = {"outcome": c["status"], "decidedAt": "2026-10-18T10:00:00+00:00",
                                 "decidedBy": {"playerId": pid(999), "name": "Reviewer"}, "source": "internal_api",
                                 "note": b.get("decisionNote"), "rejectionReason": b.get("rejectionReason") if rejected else None,
                                 "image": None if rejected else ("proposed" if "image" in b.get("selectedFields", []) else "keep")}
                if rejected:
                    c["proposedImageUrl"] = None
                c["_body"] = b
                return httpx.Response(204)
        return httpx.Response(404, json={"error": "not found"})


@pytest.fixture
def server() -> FakeServer:
    return FakeServer()


@pytest.fixture
def sleeps() -> list[float]:
    return []


@pytest.fixture
def api(server: FakeServer, sleeps: list[float]) -> InternalApi:
    return InternalApi(BASE, lambda: TOKEN, transport=httpx.MockTransport(server.handler), min_interval=0, sleep=sleeps.append)
