# online-cv - the completeness check worker

"Is every piece in place?" for a finish photo of an online round - the **advisory** check of
[docs/features/online-competitions/README.md §5.8](../../docs/features/online-competitions/README.md) (Phase 3). A
person confirms every result; the check only orders the review queue and draws the suspected gaps. A false "complete"
(a missing piece passed) is the one error that matters - every doubt answers `unsure`.

Python 3.12 + OpenCV 4.14 (headless) + numpy, pinned in `requirements.txt`. No model, no GPU, no external service.

| File | What |
|---|---|
| `completeness.py` | the checks - background holes (empty-mat photo vs finish photo), reference-grid matching (the motif vs finish photo), `combine()`, `check_photo()`; every threshold a named option with its reason; `VERSION` (bump on any threshold or rule change). **The one implementation**: the bench (`tools/online-bench/completeness/`) imports it from here |
| `server.py` | the HTTP worker (standard library HTTP server, one process) |
| `Dockerfile` | `python:3.12.15-slim` pinned by digest, non-root (uid 10001), runs with a read-only file system |
| `tests/test_server.py` + `test.sh` | the worker's tests (stdlib `unittest`) in a throwaway container |
| `http_selftest.py` | the bench's synthetic self-test (+ unfinished puzzles) through the worker's HTTP endpoint |

## Interface

```
GET  /health  -> 200 {"status": "ok", "version": "w1-c1", "busy": 0, "capacity": 9, "allowedHosts": 1}

POST /check   {"finishUrl": "...", "emptyMatUrl": "..." | null, "referenceUrl": "..." | null, "pieceCount": 1000 | null}
  200 {"verdict": "complete" | "gaps" | "unsure", "confidence": 0.873,
       "gaps": [{"x": 812, "y": 403, "w": 41, "h": 38}],        # in the finish photo's own pixels
       "reason": "1 hole(s) the colour of the mat", "method": "holes+grid" | "holes" | "grid" | "none",
       "version": "w1-c1", "ms": 441, "checkMs": 376, "image": {"width": 1920, "height": 1080},
       "inputs": {"emptyMat": "used", "reference": "unreadable"},   # used | none | unavailable: 404 | unreadable | too_large
       "details": {"holes": {...}, "grid": {...}}}               # each check's own verdict, or null
```

| Answer | When | The app |
|---|---|---|
| 400 `invalid_request` | not a JSON object, `finishUrl` missing, a URL not a string, `pieceCount` not 2-100 000 | gives up (a bug) |
| 403 `url_not_allowed` + `input` | a URL that is not http(s) on an allowed host, or carries user info - checked for all three before any download | gives up (a misconfiguration) |
| 413 `payload_too_large` | a body over 64 KB | gives up |
| 422 `finish_unavailable` + `status` | the store answers the finish photo with a 4xx (gone, refused) or a redirect | gives up |
| 422 `finish_too_large` / `finish_unreadable` | over the size or pixel cap / not an image OpenCV decodes | gives up |
| 502 `download_failed` + `input`, `status` | the store failing (5xx, 408, 429), not answering in time, refusing the connection - for any input | tries again later |
| 503 `busy` + `Retry-After: 5` | the queue is full | tries again later |
| 500 `check_failed` | a check broke (traceback on stderr) | gives up |

The empty mat and the reference are optional: missing, gone (4xx) or unreadable (a HEIC catalogue image) - the check
runs without it and `inputs` says why. With both: holes + grid combined (complete only when both say complete); with
one: that check alone; with neither, or without the piece count (a round of several puzzles): `unsure`.

**`version`** = `w<WORKER_VERSION>-c<completeness.VERSION>` - the app stores one verdict per media per version.

## Rules

An internal service that fetches the URLs it is handed is an SSRF risk, so:

- **Allowlist** (`ONLINE_CV_ALLOWED_HOSTS`, comma separated `host` or `host:port`, an IPv6 address in brackets): the
  object stores' hosts as the app's presigned URLs name them (path-style: `minio:9000`, `fsn1.your-objectstorage.com`).
  An entry without a port allows only the scheme's default port. **Empty = every URL refused.**
- No redirect is followed (`http.client`, never urllib's opener), no proxy from the environment, http(s) only.
- Every download: size cap while reading (`ONLINE_CV_MAX_BYTES`), one deadline for connect + all reads
  (`ONLINE_CV_FETCH_TIMEOUT`), decoded in memory, at most `ONLINE_CV_MAX_PIXELS`. **Nothing is written to disk** (the
  container runs `read_only`) and nothing is kept after the answer.
- No public port: the app reaches it on the Docker network. Logs: one JSON line per request - never a URL (presigned
  URLs carry signatures).

| Env | Default | |
|---|---|---|
| `ONLINE_CV_LISTEN` | `0.0.0.0:8000` | |
| `ONLINE_CV_ALLOWED_HOSTS` | (empty - refuses everything) | the stores' hosts |
| `ONLINE_CV_MAX_BYTES` | 20 MiB | per download |
| `ONLINE_CV_FETCH_TIMEOUT` | 10 s | per download, connect included |
| `ONLINE_CV_WORKERS` | 1 | checks at the same time |
| `ONLINE_CV_QUEUE` | 8 | requests waiting beyond them - then 503 |
| `ONLINE_CV_THREADS` | 2 | OpenCV's threads per check |
| `ONLINE_CV_MAX_PIXELS` | 50 000 000 | also `OPENCV_IO_MAX_IMAGE_PIXELS` in the image |

## The app

`ONLINE_CV_URL` (empty = the check is off - production until Jan's yes; docs/features/feature_flags.md). Locally: the
compose service `online-cv` in the profile `cv` (built from this folder, no published port, `read_only`,
`ONLINE_CV_ALLOWED_HOSTS: "minio:9000"`): `docker compose --profile cv up -d online-cv`, `ONLINE_CV_URL` set in
`.env.local`. From the host: `docker compose exec web curl -s http://online-cv:8000/health`.

## Run

```sh
tools/online-cv/test.sh                       # the tests, ~20 s
docker build -t msp-online-cv tools/online-cv # the image (~440 MB)

# The bench's self-test through the HTTP endpoint: the worker + a client on their own network
docker network create cv-selftest
docker run -d --rm --name cv --network cv-selftest --read-only -e ONLINE_CV_ALLOWED_HOSTS=fixtures:8080 msp-online-cv
docker run --rm --name fixtures --network cv-selftest -v "$PWD/tools":/tools:ro python:3.12.15-slim \
  sh -c 'pip install -q -r /tools/online-cv/requirements.txt && python /tools/online-cv/http_selftest.py --worker http://cv:8000 --serve-host fixtures'
docker rm -f cv; docker network rm cv-selftest
```

`http_selftest.py` answers two passes: the empty mat + the clean motif (holes + grid combined) and the empty mat alone
(holes) - the confusion matrices per condition (contrasting mat, an arm over the puzzle, a table close to the motif,
unfinished), the false "complete" and the time per photo. Its exit code is 1 when anything incomplete passed.
**Loose pieces (2026-10-14):** an unfinished puzzle whose missing part is a band along an edge leaves a smaller, clean
rectangle with no mat inside - the holes check alone said `complete` for 4 of 6 such synthetic cases. Since
`HolesOptions.loose_area` / `loose_min_side` (README §5.8: "puzzle-coloured blobs outside it are loose pieces")
anything not the mat outside the outline, as large as a third of a piece and no sliver, makes it `unsure`: 0 of 6
since, the bench's 90 cases unchanged. **What remains:** the unplaced pieces kept out of the frame (in the box, on
another table) - the holes check cannot see that the rectangle is too small; only the reference grid (its shape) or the
puzzle's expected aspect could. Anything put on the mat after its empty photo (the box, a phone) also makes the check
`unsure` - the footage day (spikes.md S1) shows how often that happens on real tables.
