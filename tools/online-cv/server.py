"""
The CV worker of the online competitions (docs/features/online-competitions/README.md §5.8, implementation-plan.md
WP 3.1): "is every piece in place?" for a finish photo, as an advisory verdict next to the person who confirms every
result. A small HTTP service on the internal Docker network - never a public port:

    GET  /health  -> {"status": "ok", "version", "busy", "capacity", "allowedHosts"}
    POST /check   {"finishUrl", "emptyMatUrl"?, "referenceUrl"?, "pieceCount"?}  (presigned GET URLs, short-lived)
               -> {"verdict": "complete" | "gaps" | "unsure", "confidence", "gaps": [{"x", "y", "w", "h"}], "reason",
                   "method", "version", "ms", "checkMs", "image": {"width", "height"}, "inputs", "details"}

The checks are completeness.py's (the bench's one implementation): background holes with the empty-mat photo,
reference-grid matching with the reference image, combined when both are there. A false "complete" (a missing piece
passed) is the one error that matters - every doubt answers "unsure".

Rules (an internal service fetching URLs it is handed is an SSRF risk):
- only http(s) URLs of the allowed hosts (ONLINE_CV_ALLOWED_HOSTS - the object stores' hosts; empty = every URL
  refused), no user info; checked for all three inputs before anything is downloaded (403 url_not_allowed);
- no redirect is followed, no proxy from the environment is used (http.client, never urllib's opener);
- every download is size-capped while it is read and has one deadline for the whole of it; photos are read into
  memory and decoded there - nothing is written to disk, nothing is kept after the answer (the container runs with a
  read-only file system);
- at most ONLINE_CV_WORKERS checks run at once and ONLINE_CV_QUEUE more wait; beyond that the answer is 503 busy at
  once (the app tries again later).

A finish photo the store no longer has (4xx) is a permanent 422; the store failing or not answering is a 502 the app
retries. The empty mat and the reference are optional: gone or unreadable (a HEIC catalogue image OpenCV cannot read)
- the check runs without it and `inputs` says why; the store failing - 502 too, never a weaker verdict for a hiccup.

Logs: one JSON line per request on stdout - never a URL (presigned URLs carry signatures).
"""

from __future__ import annotations

import http.client
import json
import math
import os
import signal
import socket
import sys
import threading
import time
import traceback
from dataclasses import dataclass, field
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit

WORKER_VERSION = 1
MAX_BODY_BYTES = 64 * 1024
MAX_URL_LENGTH = 4096
MIN_PIECES = 2
MAX_PIECES = 100_000
DEFAULT_PORTS = {"http": 80, "https": 443}
# The request's field and the name the answers use for each input
INPUTS = (("finishUrl", "finish"), ("emptyMatUrl", "emptyMat"), ("referenceUrl", "reference"))
READ_CHUNK = 64 * 1024

# OpenCV reads its decode limit from the environment - set before cv2 decodes anything (the Dockerfile sets it too)
os.environ.setdefault("OPENCV_IO_MAX_IMAGE_PIXELS", os.environ.get("ONLINE_CV_MAX_PIXELS", "50000000"))

import cv2  # noqa: E402
import numpy as np  # noqa: E402

import completeness  # noqa: E402

VERSION = f"w{WORKER_VERSION}-c{completeness.VERSION}"


@dataclass(frozen=True)
class Config:
    listen_host: str = "0.0.0.0"
    listen_port: int = 8000
    allowed_hosts: frozenset = field(default_factory=frozenset)
    max_bytes: int = 20 * 1024 * 1024
    fetch_timeout: float = 10.0
    queue: int = 8
    workers: int = 1
    threads: int = 2
    max_pixels: int = 50_000_000

    @property
    def capacity(self) -> int:
        return self.workers + self.queue

    @classmethod
    def from_env(cls, env: dict) -> "Config":
        host, _, port = env.get("ONLINE_CV_LISTEN", "0.0.0.0:8000").rpartition(":")

        return cls(
            listen_host=host or "0.0.0.0",
            listen_port=int(port or 8000),
            allowed_hosts=parse_allowed_hosts(env.get("ONLINE_CV_ALLOWED_HOSTS", "")),
            max_bytes=int(env.get("ONLINE_CV_MAX_BYTES", 20 * 1024 * 1024)),
            fetch_timeout=float(env.get("ONLINE_CV_FETCH_TIMEOUT", 10)),
            queue=max(0, int(env.get("ONLINE_CV_QUEUE", 8))),
            workers=max(1, int(env.get("ONLINE_CV_WORKERS", 1))),
            threads=max(1, int(env.get("ONLINE_CV_THREADS", 2))),
            max_pixels=int(env.get("ONLINE_CV_MAX_PIXELS", 50_000_000)),
        )


# ---------------------------------------------------------------------------------------------------- the allowlist

def parse_allowed_hosts(text: str) -> frozenset:
    """
    `host` or `host:port` entries, comma separated (an IPv6 address in brackets: `[::1]:9000`) -> {(host, port|None)}.
    An entry without a port allows only the scheme's default port. Anything unreadable is left out.
    """
    entries = set()

    for raw in (text or "").split(","):
        entry = raw.strip().lower()

        if entry == "" or "/" in entry or "@" in entry:
            continue

        port = None

        if entry.startswith("["):
            host, _, rest = entry[1:].partition("]")

            if rest.startswith(":"):
                port = rest[1:]
            elif rest != "":
                continue
        elif entry.count(":") == 1:
            host, port = entry.split(":")
        elif ":" in entry:
            continue
        else:
            host = entry

        if host == "":
            continue

        if port is not None:
            if not port.isdigit() or not 0 < int(port) < 65536:
                continue

            port = int(port)

        entries.add((host, port))

    return frozenset(entries)


def url_allowed(url: object, allowed: frozenset) -> bool:
    """An http(s) URL without user info, whose host and port are on the allowlist."""
    if not isinstance(url, str) or url == "" or len(url) > MAX_URL_LENGTH:
        return False

    if any(ord(character) <= 32 or ord(character) == 127 for character in url):
        return False

    try:
        parts = urlsplit(url)
        scheme = parts.scheme.lower()
        host = parts.hostname
        port = parts.port
    except ValueError:
        return False

    if scheme not in DEFAULT_PORTS or host is None or host == "" or "@" in parts.netloc:
        return False

    host = host.lower()
    effective = port if port is not None else DEFAULT_PORTS[scheme]

    if (host, effective) in allowed:
        return True

    return effective == DEFAULT_PORTS[scheme] and (host, None) in allowed


# ---------------------------------------------------------------------------------------------------- downloads

class FetchError(Exception):
    """A download that failed: `transient` - the store failing or not answering (try again later) - or not."""

    def __init__(self, reason: str, status: int | None = None, transient: bool = False):
        super().__init__(reason)
        self.reason = reason
        self.status = status
        self.transient = transient

    def describe(self) -> str:
        if self.reason == "status":
            return f"unavailable: {self.status}"

        return self.reason


def fetch(url: str, max_bytes: int, timeout: float) -> bytes:
    """
    One GET into memory: no redirect followed (http.client never follows one), no proxy, the size capped while
    reading, one deadline for the connect and every read.
    """
    parts = urlsplit(url)
    deadline = time.monotonic() + timeout

    def remaining() -> float:
        left = deadline - time.monotonic()

        if left <= 0:
            raise FetchError("timeout", transient=True)

        return left

    connection_class = http.client.HTTPSConnection if parts.scheme.lower() == "https" else http.client.HTTPConnection
    connection = connection_class(parts.hostname, parts.port, timeout=remaining())
    path = (parts.path or "/") + (f"?{parts.query}" if parts.query else "")

    try:
        connection.request("GET", path, headers={"Accept": "image/*", "User-Agent": f"msp-online-cv/{VERSION}"})
        sock = connection.sock
        sock.settimeout(remaining())
        response = connection.getresponse()

        if response.status != 200:
            # The store failing (5xx), or asking to come back (408, 429) - else it is gone, refused or redirected
            transient = response.status >= 500 or response.status in (408, 429)
            raise FetchError("status", status=response.status, transient=transient)

        declared = response.getheader("Content-Length")

        if declared is not None and declared.isdigit() and int(declared) > max_bytes:
            raise FetchError("too_large")

        chunks = []
        total = 0

        while True:
            left = remaining()

            # The whole body is read (its Content-Length reached): the response closed the socket itself
            if response.isclosed():
                break

            sock.settimeout(left)
            chunk = response.read1(READ_CHUNK)

            if not chunk:
                break

            total += len(chunk)

            if total > max_bytes:
                raise FetchError("too_large")

            chunks.append(chunk)

        return b"".join(chunks)
    except FetchError:
        raise
    except (socket.timeout, TimeoutError):
        raise FetchError("timeout", transient=True)
    except (OSError, http.client.HTTPException) as error:
        raise FetchError("connection", transient=True) from error
    finally:
        connection.close()


def decode(data: bytes, max_pixels: int) -> np.ndarray:
    """A photo decoded in memory - FetchError `unreadable` / `too_large` when it can not be used."""
    try:
        image = cv2.imdecode(np.frombuffer(data, np.uint8), cv2.IMREAD_COLOR)
    except cv2.error:
        image = None

    if image is None or image.ndim != 3 or image.shape[0] < 8 or image.shape[1] < 8:
        raise FetchError("unreadable")

    if image.shape[0] * image.shape[1] > max_pixels:
        raise FetchError("too_large")

    return image


# ---------------------------------------------------------------------------------------------------- the check

class Refusal(Exception):
    def __init__(self, status: int, body: dict, headers: dict | None = None):
        super().__init__(body.get("error", "refused"))
        self.status = status
        self.body = body
        self.headers = headers or {}


def validate(payload: object) -> dict:
    if not isinstance(payload, dict):
        raise Refusal(400, {"error": "invalid_request", "message": "the body must be a JSON object"})

    request = {}

    for field_name, _ in INPUTS:
        value = payload.get(field_name)

        if value is None and field_name != "finishUrl":
            request[field_name] = None
            continue

        if not isinstance(value, str) or value.strip() == "" or len(value) > MAX_URL_LENGTH:
            raise Refusal(400, {"error": "invalid_request", "message": f"{field_name} must be a URL"})

        request[field_name] = value

    pieces = payload.get("pieceCount")

    if pieces is not None and (isinstance(pieces, bool) or not isinstance(pieces, int) or not MIN_PIECES <= pieces <= MAX_PIECES):
        raise Refusal(400, {"error": "invalid_request", "message": f"pieceCount must be a whole number {MIN_PIECES}-{MAX_PIECES} or null"})

    request["pieceCount"] = pieces

    return request


def json_safe(value):
    """Plain JSON types from what OpenCV and numpy hand back."""
    if isinstance(value, dict):
        return {str(key): json_safe(item) for key, item in value.items()}

    if isinstance(value, (list, tuple)):
        return [json_safe(item) for item in value]

    if isinstance(value, np.ndarray):
        return json_safe(value.tolist())

    if isinstance(value, np.bool_):
        return bool(value)

    if isinstance(value, np.integer):
        return int(value)

    if isinstance(value, (np.floating, float)):
        number = float(value)

        return None if math.isnan(number) or math.isinf(number) else number

    return value


class Worker:
    """The admission (running + waiting), the compute slots and the check itself - shared by the request threads."""

    def __init__(self, config: Config):
        self.config = config
        self._lock = threading.Lock()
        self._admitted = 0
        self._compute = threading.BoundedSemaphore(config.workers)

    @property
    def busy(self) -> int:
        with self._lock:
            return self._admitted

    def admit(self) -> bool:
        with self._lock:
            if self._admitted >= self.config.capacity:
                return False

            self._admitted += 1

            return True

    def release(self) -> None:
        with self._lock:
            self._admitted -= 1

    def check(self, request: dict) -> dict:
        started = time.monotonic()
        config = self.config

        for field_name, name in INPUTS:
            if request[field_name] is not None and not url_allowed(request[field_name], config.allowed_hosts):
                raise Refusal(403, {"error": "url_not_allowed", "input": name})

        if not self.admit():
            raise Refusal(503, {"error": "busy"}, {"Retry-After": "5"})

        try:
            return self._check(request, started)
        finally:
            self.release()

    def _check(self, request: dict, started: float) -> dict:
        config = self.config

        try:
            finish = decode(fetch(request["finishUrl"], config.max_bytes, config.fetch_timeout), config.max_pixels)
        except FetchError as error:
            if error.transient:
                raise Refusal(502, {"error": "download_failed", "input": "finish", "status": error.status})

            if error.reason == "status":
                raise Refusal(422, {"error": "finish_unavailable", "status": error.status})

            raise Refusal(422, {"error": "finish_too_large" if error.reason == "too_large" else "finish_unreadable"})

        images = {}
        inputs = {}

        for field_name, name in INPUTS[1:]:
            if request[field_name] is None:
                images[name] = None
                inputs[name] = "none"
                continue

            try:
                images[name] = decode(fetch(request[field_name], config.max_bytes, config.fetch_timeout), config.max_pixels)
                inputs[name] = "used"
            except FetchError as error:
                if error.transient:
                    raise Refusal(502, {"error": "download_failed", "input": name, "status": error.status})

                images[name] = None
                inputs[name] = error.describe()

        with self._compute:
            computing = time.monotonic()
            verdict, method, details = completeness.check_photo(finish, images["emptyMat"], images["reference"], request["pieceCount"])
            check_ms = round((time.monotonic() - computing) * 1000)

        return {
            "verdict": verdict.verdict,
            "confidence": round(float(verdict.confidence), 3),
            "gaps": [{key: int(round(float(box[key]))) for key in ("x", "y", "w", "h")} for box in verdict.gaps],
            "reason": verdict.reason,
            "method": method,
            "version": VERSION,
            "ms": round((time.monotonic() - started) * 1000),
            "checkMs": check_ms,
            "image": {"width": int(finish.shape[1]), "height": int(finish.shape[0])},
            "inputs": inputs,
            "details": json_safe(details),
        }


# ---------------------------------------------------------------------------------------------------- HTTP

class Handler(BaseHTTPRequestHandler):
    server_version = f"msp-online-cv/{VERSION}"
    sys_version = ""

    def log_message(self, format, *args):  # noqa: A002 - the base class's name
        # One JSON line per request instead (log())
        pass

    def log(self, status: int, started: float, **fields) -> None:
        line = {
            "at": datetime.now(timezone.utc).isoformat(timespec="milliseconds"),
            "method": self.command,
            # The path only, never a query string
            "path": urlsplit(self.path).path,
            "status": status,
            "ms": round((time.monotonic() - started) * 1000),
            **{key: value for key, value in fields.items() if value is not None},
        }
        print(json.dumps(line), flush=True)

    def answer(self, status: int, body: dict, headers: dict | None = None) -> None:
        data = json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")

        for name, value in (headers or {}).items():
            self.send_header(name, value)

        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):  # noqa: N802
        started = time.monotonic()
        worker: Worker = self.server.worker

        if urlsplit(self.path).path == "/health":
            self.answer(200, {
                "status": "ok",
                "version": VERSION,
                "busy": worker.busy,
                "capacity": worker.config.capacity,
                "allowedHosts": len(worker.config.allowed_hosts),
            })
            self.log(200, started)

            return

        self.answer(404, {"error": "not_found"})
        self.log(404, started, error="not_found")

    def do_POST(self):  # noqa: N802
        started = time.monotonic()
        worker: Worker = self.server.worker

        if urlsplit(self.path).path != "/check":
            self.answer(404, {"error": "not_found"})
            self.log(404, started, error="not_found")

            return

        try:
            request = validate(self.read_json())
            result = worker.check(request)
        except Refusal as refusal:
            self.answer(refusal.status, refusal.body, refusal.headers)
            self.log(refusal.status, started, error=refusal.body.get("error"), input=refusal.body.get("input"))

            return
        except Exception:  # noqa: BLE001 - a bug in a check answers 500, the worker stays up
            traceback.print_exc(file=sys.stderr)
            self.answer(500, {"error": "check_failed"})
            self.log(500, started, error="check_failed")

            return

        self.answer(200, result)
        self.log(200, started, verdict=result["verdict"], check=result["method"], gaps=len(result["gaps"]), checkMs=result["checkMs"])

    def read_json(self) -> object:
        length = self.headers.get("Content-Length")

        if length is None or not length.strip().isdigit():
            raise Refusal(400, {"error": "invalid_request", "message": "a Content-Length is required"})

        if int(length) > MAX_BODY_BYTES:
            # The body is not read - the connection closes with the answer
            self.close_connection = True
            raise Refusal(413, {"error": "payload_too_large"})

        body = self.rfile.read(int(length))

        try:
            return json.loads(body)
        except (ValueError, UnicodeDecodeError):
            raise Refusal(400, {"error": "invalid_request", "message": "the body is no JSON"})


def make_server(config: Config) -> ThreadingHTTPServer:
    server = ThreadingHTTPServer((config.listen_host, config.listen_port), Handler)
    server.daemon_threads = True
    server.worker = Worker(config)

    return server


def main() -> int:
    config = Config.from_env(dict(os.environ))
    cv2.setNumThreads(config.threads)
    server = make_server(config)

    # PID 1 in a container ignores SIGTERM unless it is handled - stop serving, let the running checks finish
    signal.signal(signal.SIGTERM, lambda *_: threading.Thread(target=server.shutdown, daemon=True).start())

    print(json.dumps({
        "at": datetime.now(timezone.utc).isoformat(timespec="milliseconds"),
        "event": "listening",
        "version": VERSION,
        "port": config.listen_port,
        "allowedHosts": len(config.allowed_hosts),
        "workers": config.workers,
        "queue": config.queue,
    }), flush=True)

    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()

    return 0


if __name__ == "__main__":
    sys.exit(main())
