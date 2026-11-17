# Competition load test (`tools/competition-load`)

Reliability hardening T4: how fast the in-person competition's writes answer under a realistic event - the judges'
saves (`RecordRoundResults` through `POST /{_locale}/official-results/rounds/{roundId}/changes`, the live entry's own
endpoint), the competitors' time proposals (`ProposeRoundTime` through `POST /{_locale}/competitor-proposals/rounds/{roundId}`)
and their phones' heartbeats (`POST /-/competitor-heartbeat`) - over HTTP against the site in **production mode**
(FrankenPHP worker mode, OPcache without timestamps, `APP_ENV=prod`). Goal: p95 < 500 ms for a save and a proposal.
Results: `docs/features/competitions-management/competitor-pages.md` "Load" and `judge-offline.md` "Speed".

**Code only** - the credentials (`out/`) are access rights to a throwaway copy; `out/` is gitignored.

| Piece | What it does |
|---|---|
| `load.sh` | the minimal stack (`<prefix>-web`, `-redis`, `-mercure` on the dev network; no consumer, no object stores), the throwaway database copy, the event, k6 runs with an advisory-lock sampler beside them, `down` |
| `setup.php` | the event in the copy + the credentials: remember-me cookies minted the way the site mints them (Symfony's `SignatureHasher`, the firewall's signature properties, `kernel.secret`) and heartbeat tokens (`CompetitorHeartbeatToken`) |
| `load.js` | the k6 script: judges, competitors, phones; the report (`out/<run>/report.md`, k6's `summary.json`) |
| `judge_timings.py` | the live entry page on a throttled phone (Chrome via Selenium + CDP) - see below |

## Data

In the fixtures' Results Cup (organiser `PLAYER_WITH_STRIPE`): round "Load Round" - solo, `pick` (2 of 3 puzzles;
`ROUND_FORMAT=total load.sh setup` makes it a one-puzzle `total` round), stopwatch running since 10 minutes ago, 600-min
limit; 300 made-up entrants "Load Tester 001-300" at tables 1-300, each linked a day ago to an account of their own;
6 referees "Load Judge 1-6"; competitor pages + time proposals on; no other round of the event running. The judges save
tables 1-200, the proposing competitors sit at 201-300.

## Run

From the worktree root, after any test through the delivery `run.sh <prefix>` made `speedpuzzling_<prefix>_test_template`:

```sh
NAME_PREFIX=rlt4 tools/competition-load/load.sh up        # copy speedpuzzling_rlt4_test_load + stack + event
tools/competition-load/load.sh run single1 single         # the service time (one request at a time)
tools/competition-load/load.sh setup                      # the round anew before every run
tools/competition-load/load.sh run saves1 saves
tools/competition-load/load.sh setup && tools/competition-load/load.sh run props1 proposals
tools/competition-load/load.sh setup && tools/competition-load/load.sh run all1 all
tools/competition-load/load.sh reload-app                 # after a PHP change (OPcache never re-reads in prod mode)
tools/competition-load/load.sh down                       # containers with their volumes + the database copy
```

`WORKERS` (default 16 - production runs 32 per replica, 2 replicas; the shared dev Postgres allows 100 connections and
every worker holds two: the ORM's and the lock store's). `REDIS_IMAGE` / `MERCURE_IMAGE` take a local image instead of
pulling one. More k6 options after the mix: `load.sh run burst all -e PROPOSALS_WINDOW_S=1 -e DURATION=2m`.

| Mix (`MIX`) | What |
|---|---|
| `saves` | 6 judges, each a save every 3-8 s over their own tables (30 % a `result` + `puzzles` group - `GROUPED_SHARE`) |
| `proposals` | 100 competitors (signed in, page open) propose within 10 s (`PROPOSALS`, `PROPOSALS_WINDOW_S`) |
| `all` | the saves + 300 phones' heartbeats every 15 s ±20 % and their idle state poll every 5 min + the 100 proposals at `PROPOSALS_AT` (60 s), each followed by the page's 5 s state poll while the proposal waits |
| `single` | 30 saves, 5 state reads, then 30 × (competitor state, proposal, heartbeat) - one at a time |

Every actor's first request signs in from its remember-me cookie (a session is made) - that is the first state read of
each judge/competitor, not a save or a proposal.

**Reading the report:** p50 / p95 / p99 / max per request kind (k6's whole request time, from the k6 container on the
same Docker network), failures (anything but `applied` / `proposed` / 200), and the **lock sampler**: one psql session
reads `pg_stat_activity` of the copy every ~100 ms - how often somebody waited for an advisory lock
(`CompetitionParticipantsLock`, held by the lock store's own connection) and how many statements ran. A wait shorter than
the sampling period can be missed - compare the contended p95 with the `single` service time too.

The machine runs k6, Postgres, the stack and everything else of the dev environment on one Docker VM: the numbers are an
upper bound on the cost, not lily's capacity.

## Judge page timings

```sh
ROUND_FORMAT=total tools/competition-load/load.sh setup
tools/competition-load/load.sh timings                    # CPU=4 NETWORK_PROFILE=slow4g|fast3g SAVES=20
```

A Selenium Chromium (`<prefix>-selenium`, started and removed by the command) as a 375×812 phone signs in as judge 1 and
opens the round's live entry (300 entries) throttled (CPU 4x, Chrome's "Slow 4G" preset by default): **cold** (fresh
profile, nothing cached - the HTML, the build, the state), **warm** (the service worker controls the page, its live
shell and the HTTP cache are filled) and once more; then 20 × table typed → card open and Save → "All saved"
(in-page `performance.now()`). The page uses the build in `BUILD_DIR` (the worktree's own when it has one, else the main
checkout's) - for numbers worth comparing, `npx encore production` first.
