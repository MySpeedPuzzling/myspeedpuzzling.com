"""`uv run puzzle-vision <command>` (runbook: docs/features/insights/image-pipeline.md).

  setup-check        verify this Mac (python/uv, disk, ml extra, models, token, API, memory)
  run                run the profile (backfill until done / --follow nonstop / --daily), --hours N, --phase X
  status             coverage per analyzer and version, throughput, failures, ETA
  derive             recompute box_text, zeroshot, neighbours, tags, then difficulty_prior - no image touched
  propose-crops      file artwork crop change requests (source image_pipeline, tag artwork_crop)
  crop-review-plan   the /image-crop-review arithmetic (earned threshold, Wilson bound, audit, auto-stop); --apply
  crop-review-approve  approve middle-band crops Claude Code checked by eye
  bench              time a stage on N images against the measured M3 numbers (stores nothing)
  verify             --describe-parity | --end-to-end --limit N
  launchd-plist      render (--install: write) this profile's launchd job
"""

from __future__ import annotations

import argparse
import json
import logging
import os
import signal
import sys
import threading
from dataclasses import asdict
from pathlib import Path
from typing import Any

from . import __version__
from .config import DEFAULT_BASE_URL, Settings, token_provider_for

log = logging.getLogger("puzzle_vision")


# --- context ----------------------------------------------------------------------------------------------------------
class Context:
    def __init__(self, args: argparse.Namespace) -> None:
        from . import profiles

        name = args.profile or os.environ.get("PUZZLE_VISION_PROFILE")
        if not name:
            raise SystemExit(f"which machine? pass --profile ({', '.join(profiles.available())}) or set PUZZLE_VISION_PROFILE")
        self.profile = profiles.load(name)
        self.settings = Settings(base_url=args.base_url, profile_name=self.profile.name, token_provider=token_provider_for(args.base_url))
        self.settings.ensure_dirs()
        self._api = None
        self._ws = None
        self._downloader = None

    @property
    def api(self) -> Any:
        if self._api is None:
            from .api import InternalApi

            self._api = InternalApi(self.settings.base_url, self.settings.token_provider)
        return self._api

    @property
    def workstate(self) -> Any:
        if self._ws is None:
            from .workstate import WorkState

            self._ws = WorkState(self.settings.workstate_path)
        return self._ws

    @property
    def downloader(self) -> Any:
        if self._downloader is None:
            from .download import Downloader

            self._downloader = Downloader(self.settings.images_dir, self.workstate)
        return self._downloader


def setup_logging(settings: Settings | None, verbose: bool) -> None:
    handlers: list[logging.Handler] = [logging.StreamHandler(sys.stderr)]
    if settings is not None:
        settings.log_dir.mkdir(parents=True, exist_ok=True)
        handlers.append(logging.FileHandler(settings.log_dir / f"{settings.profile_name}.log"))
    logging.basicConfig(level=logging.DEBUG if verbose else logging.INFO, handlers=handlers, force=True,
                        format="%(asctime)s %(process)d %(levelname)s %(message)s")
    logging.getLogger("httpx").setLevel(logging.WARNING)


def _print(obj: Any, as_json: bool, text: str | None = None) -> None:
    print(json.dumps(obj, indent=2, ensure_ascii=False, default=str) if as_json or text is None else text)


# --- commands ---------------------------------------------------------------------------------------------------------
def cmd_setup_check(a: argparse.Namespace, ctx: Context) -> int:
    from .checks import run_checks

    checks = run_checks(ctx.profile, ctx.settings, lambda: ctx.api, skip_api=a.skip_api)
    for c in checks:
        print(c.line())
    ok = all(c.ok for c in checks)
    print("setup OK" if ok else "setup INCOMPLETE - fix the FAIL lines (runbook: docs/features/insights/image-pipeline.md)")
    return 0 if ok else 1


def pid_file(ctx: Context) -> Path:
    return ctx.settings.state_dir / f"run-{ctx.profile.name}.pid"


def cmd_run(a: argparse.Namespace, ctx: Context) -> int:
    from .runner import Runner, RunOptions

    opts = RunOptions(profile=ctx.profile, base_url=ctx.settings.base_url, hours=a.hours, phase=a.phase, follow=a.follow,
                      daily=a.daily, idle_minutes=a.idle_minutes, max_images=a.max_images)
    pid = pid_file(ctx)
    pid.write_text(str(os.getpid()))  # this python process - `kill $(cat …)` stops the run cleanly (not uv's pid)
    try:
        rc = Runner(opts, ctx.api, ctx.workstate).run()
    finally:
        pid.unlink(missing_ok=True)
    if rc != 0:
        log.info("run ended early (exit %d) - no derive / crop proposals after it", rc)
        return rc
    if a.daily:
        if ctx.profile.derive_after_daily:
            cmd_derive(argparse.Namespace(only=None, dry_run=False, json=False, force_model=False), ctx)
        if ctx.profile.propose_crops_daily:
            cmd_propose_crops(argparse.Namespace(limit=ctx.profile.propose_crops_daily, dry_run=False, json=False, threshold=None), ctx)
    return rc


def _probe(ctx: Context, analyzer: str) -> bool:
    """Read-only: anything this worker could claim and analyse (so a model is never loaded for nothing)."""
    from .runner import usable_work

    return usable_work(ctx.api, ctx.workstate, ctx.profile.analyzers[analyzer], ctx.settings.worker_id)


class StopOnSignals:
    """SIGTERM / Ctrl-C set an event (a clean stop); the previous handlers come back afterwards."""

    def __init__(self) -> None:
        self.event = threading.Event()
        self._previous: dict[int, Any] = {}

    def __enter__(self) -> threading.Event:
        for sig in (signal.SIGTERM, signal.SIGINT):
            self._previous[sig] = signal.signal(sig, lambda *_: self.event.set())
        return self.event

    def __exit__(self, *exc: object) -> None:
        for sig, previous in self._previous.items():
            signal.signal(sig, previous)


LOCK_TIMEOUT_SECONDS = 3 * 3600


def cmd_stage_worker(a: argparse.Namespace, ctx: Context) -> int:
    from . import stages
    from .lock import LockTimeout, MemoryWatchdog, ModelLock
    from .worker import StageWorker

    signals = StopOnSignals()
    stop = signals.__enter__()
    watchdog = MemoryWatchdog(stop)
    watchdog.start()
    settings = ctx.profile.analyzers[a.analyzer]
    deadline = float(a.deadline) if a.deadline else None
    result: dict[str, Any] = {"analyzer": a.analyzer, "stored": 0}
    lock = None
    try:
        if not _probe(ctx, a.analyzer):
            result["line"] = f"[{a.analyzer}] nothing to do"
            return 0
        if a.gate:
            from .stages.artwork import run_gate

            lock = ModelLock("artwork-gate")
            lock.acquire(timeout=LOCK_TIMEOUT_SECONDS, stop=stop)
            n = run_gate(ctx.api, ctx.downloader, ctx.workstate, settings.version, stop, deadline, a.max_images)
            result.update(gated=n, line=f"[artwork-gate] gated {n} pictures")
            return 0
        stage = stages.create(a.analyzer, settings.version, settings.workers, workstate=ctx.workstate)

        def load() -> None:
            # claimed first, then the lock + the model - only when the claim brought usable work
            nonlocal lock
            if stage.needs_model_lock:
                lock = ModelLock(a.analyzer)
                lock.acquire(timeout=LOCK_TIMEOUT_SECONDS, stop=stop)
            stage.load()

        try:
            stats = StageWorker(stage, settings, ctx.api, ctx.downloader, ctx.workstate, ctx.settings.worker_id,
                                stop=stop, max_images=a.max_images, deadline=deadline, loader=load).run()
        finally:
            stage.close()
        result.update(asdict(stats), line=stats.line(a.analyzer))
        return 3 if watchdog.tripped else 0
    except LockTimeout as e:
        result["line"] = f"[{a.analyzer}] {e}"
        return 0
    finally:
        if lock is not None:
            lock.release()
        stop.set()
        signals.__exit__()
        if a.stats_file:
            Path(a.stats_file).write_text(json.dumps(result, default=str))


def cmd_status(a: argparse.Namespace, ctx: Context) -> int:
    from .status import render, report

    rep = report(ctx.api, ctx.profile, ctx.workstate, all_analyzers=a.all)
    _print(rep, a.json, render(rep))
    return 0


def cmd_derive(a: argparse.Namespace, ctx: Context) -> int:
    from .derive import Deriver
    from .lock import ModelLock

    versions = dict(ctx.profile.derived_versions)
    if not versions:
        print(f"profile {ctx.profile.name} names no derived versions")
        return 1
    with StopOnSignals() as stop:
        d = Deriver(ctx.api, versions, ctx.settings.cache_dir, model_lock=lambda: ModelLock("derive-zeroshot"),
                    lock_stop=stop, lock_timeout=LOCK_TIMEOUT_SECONDS, report_dir=ctx.settings.state_dir)
        only = [x.strip() for x in a.only.split(",")] if a.only else None
        summary = d.run(only=only, dry_run=a.dry_run, force_model=bool(getattr(a, "force_model", False)))
    _print(summary, True)
    # a blocked difficulty_prior push is not a crash, but a manual run should not look like a clean success
    return 3 if isinstance(summary.get("difficulty_prior"), dict) and summary["difficulty_prior"].get("blocked") else 0


def _gate_margin_fn() -> Any:
    from PIL import Image

    from .stages.artwork import GATE_SIDE, Gate, gate_decision

    gate = Gate()

    def margin(rgb: Any) -> float:
        im = Image.fromarray(rgb)
        im.thumbnail((GATE_SIDE, GATE_SIDE))
        return gate_decision(gate.scores(im))[1]

    return margin


def cmd_propose_crops(a: argparse.Namespace, ctx: Context) -> int:
    from .lock import ModelLock
    from .proposals import crops

    lock = ModelLock("propose-crops")
    with StopOnSignals() as stop:
        lock.acquire(timeout=LOCK_TIMEOUT_SECONDS, stop=stop)
    try:
        summary = crops.propose(ctx.api, lambda url: ctx.downloader.original(url)[0], _gate_margin_fn(),
                                ctx.profile.version("artwork"), a.limit, dry_run=a.dry_run,
                                threshold=a.threshold if a.threshold is not None else crops.PROPOSE_THRESHOLD,
                                workstate=ctx.workstate)
    finally:
        lock.release()
    _print(summary, True)
    return 0


def _crop_requests(ctx: Context) -> list[dict[str, Any]]:
    from .proposals.crops import TAG

    return list(ctx.api.iter_change_requests(source="image_pipeline", tag=TAG, status="all"))


def cmd_crop_review_plan(a: argparse.Namespace, ctx: Context) -> int:
    from .api import ApiError
    from .proposals import review

    p = review.plan(_crop_requests(ctx), min_human=a.min_human, target=a.target, audit_share=a.audit_share, stop_below=a.stop_below,
                    model_version=a.model_version)
    out = p.to_dict()
    if a.apply and p.to_approve:
        applied, failed = [], []
        for entry in p.to_approve:
            try:
                ctx.api.approve_change_request(entry["id"], ["image"], entry["decisionNote"])
                applied.append(entry["id"])
            except ApiError as e:
                failed.append({"id": entry["id"], "error": str(e)})
        out["applied"] = applied
        out["applyFailed"] = failed
    if a.json:
        _print(out, True)
    else:
        print(f"model version: {p.model_version}; human decisions: {p.human_decisions} ({p.human_approved} crops taken)")
        print(p.reason)
        print(f"auto-approve now: {len(p.to_approve)}{' (applied)' if a.apply else ' (dry run - pass --apply)'}; "
              f"audit held for humans: {len(p.audit_held)}; middle band for a look: {len(p.middle_band)}")
        if p.audit_precision is not None:
            print(f"audit precision {p.audit_precision:.1%} on {p.audit_decided} decided audit items")
        for b in p.bands:
            print(f"  {b['band']}: pending {b['pending']}, human +{b['humanApproved']} / -{b['humanRejected']}, "
                  f"auto {b['auto']}, claude {b['claude']}, outdated {b['outdated']}, precision {b['humanPrecision'] if b['humanPrecision'] is not None else '-'}")
        if "applied" in out:
            print(f"applied {len(out['applied'])}, failed {len(out['applyFailed'])}")
    return 2 if p.auto_stopped else 0


def cmd_crop_review_approve(a: argparse.Namespace, ctx: Context) -> int:
    """Middle-band crops Claude Code checked by eye. Refuses audit-sample ids (they are the humans'), anything outside
    the middle band, and - before the threshold is earned - more than CLAUDE_DAILY_CAP a day. A 409 (decided
    meanwhile, picture changed) is skipped."""
    from datetime import datetime, timezone

    from .api import Conflict
    from .proposals import review

    raw = _crop_requests(ctx)
    p = review.plan(raw, model_version=a.model_version)
    items = {i.id: i for i in (review.parse_item(d) for d in raw)}
    middle = {e["id"] for e in p.middle_band}
    today = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    used = review.claude_approvals_on(list(items.values()), today)
    rc = 0
    for cid in a.ids:
        item = items.get(cid)
        if item is None or item.status != "pending":
            print(f"{cid}: not a pending artwork_crop request - skipped")
            rc = 1
            continue
        if review.is_audit(cid):
            print(f"{cid}: in the audit sample - left for the moderators")
            rc = 1
            continue
        if cid not in middle:
            print(f"{cid}: not in the middle band of model {p.model_version} - skipped")
            rc = 1
            continue
        if not p.earned and used >= review.CLAUDE_DAILY_CAP:
            print(f"{cid}: today's cap of {review.CLAUDE_DAILY_CAP} approvals by eye is reached (threshold not earned yet) - skipped")
            rc = 1
            continue
        try:
            ctx.api.approve_change_request(cid, ["image"], review.claude_note(item))
        except Conflict as e:
            print(f"{cid}: refused ({e}) - skipped")
            continue
        used += 1
        print(f"{cid}: approved ({review.claude_note(item)})")
    return rc


def _prepared(ctx: Context, analyzer: str, items: list[Any]) -> list[Any]:
    from . import stages
    from .worker import StageWorker

    s = ctx.profile.analyzers.get(analyzer) or next(iter(ctx.profile.analyzers.values()))
    stage = stages.create(analyzer, s.version, 1, workstate=ctx.workstate) if analyzer in stages.STAGES else None
    w = StageWorker(stage, s, ctx.api, ctx.downloader, ctx.workstate, ctx.settings.worker_id) if stage else None
    out = []
    if w is None:
        return out
    for up in stage.upstream:
        w.artifacts.ensure(up, items)
    for i in items:
        p = w._prepare(i, None)
        if p is not None:
            out.append(p)
    return out


def cmd_bench(a: argparse.Namespace, ctx: Context) -> int:
    from PIL import Image

    from . import stages
    from .analyzers import REQUIRES
    from .bench import time_calls, verdict
    from .lock import ModelLock

    name = a.stage
    analyzer = "artwork" if name == "artwork-gate" else name
    items = ctx.api.list_images(status="all", limit=a.limit, requires=REQUIRES.get(analyzer, ()))["puzzles"]
    lock = ModelLock(f"bench-{name}")
    lock.acquire()
    try:
        prepared = _prepared(ctx, analyzer, items)
        if name == "artwork-gate":
            from .stages.artwork import GATE_SIDE, Gate

            gate = Gate()
            ims = []
            for p in prepared:
                im = Image.fromarray(p.rgb)
                im.thumbnail((GATE_SIDE, GATE_SIDE))
                ims.append(im)
            seconds = time_calls(gate.scores, ims)
        elif name == "artwork":
            from .stages.artwork import ArtworkStage

            st = ArtworkStage(ctx.profile.version("artwork"))
            seconds = time_calls(lambda p: st._vlm_answer(p.rgb), prepared)
        else:
            s = ctx.profile.analyzers[name]
            st = stages.create(name, s.version, s.workers)
            st.load()
            n = max(1, st.batch_size)
            batches = [prepared[i:i + n] for i in range(0, len(prepared), n)]
            seconds = [t / n for t in time_calls(st.analyse_batch, batches, warmup=1)]
            st.close()
    finally:
        lock.release()
    _print(verdict(name, seconds), True)
    return 0


def cmd_verify(a: argparse.Namespace, ctx: Context) -> int:
    if a.describe_parity:
        from . import stages
        from .lock import ModelLock
        from .verify import describe_parity, parity_targets

        version = ctx.profile.version("describe")
        targets = parity_targets(ctx.api, version, a.limit)
        lock = ModelLock("verify-describe-parity")
        lock.acquire()
        try:
            stage = stages.create("describe", version)
            stage.load()

            def run_one(item: Any) -> dict[str, Any]:
                ps = _prepared(ctx, "describe", [item])
                if not ps:
                    raise RuntimeError(f"cannot prepare {item.puzzle_id}")
                return stage.analyse(ps[0])

            result = describe_parity(targets, run_one, ctx.workstate, version, min_n=a.limit)
        finally:
            lock.release()
        _print(result, True)
        return 0 if result["passed"] else 1
    if a.end_to_end:
        from .runner import Runner, RunOptions, gate_open

        opts = RunOptions(profile=ctx.profile, base_url=ctx.settings.base_url, max_images=a.limit)
        r = Runner(opts, ctx.api, ctx.workstate)
        r.install_signals()
        report = {}
        for phase in ctx.profile.phases:
            for analyzer in phase.stages + phase.sidecar:
                if not gate_open(phase.gate, ctx.profile, ctx.workstate):
                    report[analyzer] = {"skipped": f"{phase.gate} not passed"}
                    continue
                report[analyzer] = r.run_stage(analyzer)
        ok = all(v.get("stored", 0) > 0 or "skipped" in v or "nothing to do" in str(v.get("line", "")) for v in report.values())
        _print({"ok": ok, "stages": {k: v.get("line", v) for k, v in report.items()}}, True)
        return 0 if ok else 1
    print("verify needs --describe-parity or --end-to-end")
    return 2


def cmd_launchd_plist(a: argparse.Namespace, ctx: Context) -> int:
    from . import launchd

    text = launchd.render(ctx.profile, ctx.settings.log_dir)
    if a.install:
        path = launchd.install_path(ctx.profile)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text)
        print(f"wrote {path}\nload it: launchctl bootstrap gui/$(id -u) {path}\nunload: launchctl bootout gui/$(id -u) {path}")
    else:
        print(text)
    return 0


# --- parser -----------------------------------------------------------------------------------------------------------
def parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="puzzle-vision", description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--version", action="version", version=f"puzzle-vision {__version__}")
    common = argparse.ArgumentParser(add_help=False)
    common.add_argument("--profile", help="machine profile (m3-max, m1-air); default $PUZZLE_VISION_PROFILE")
    common.add_argument("--base-url", default=DEFAULT_BASE_URL, help="API base URL (testing only; production is the default)")
    common.add_argument("-v", "--verbose", action="store_true")
    sub = p.add_subparsers(dest="command", required=True)

    s = sub.add_parser("setup-check", parents=[common])
    s.add_argument("--skip-api", action="store_true")
    s.set_defaults(fn=cmd_setup_check)

    s = sub.add_parser("run", parents=[common])
    s.add_argument("--hours", type=float, help="stop cleanly after N hours")
    s.add_argument("--phase", help="only this phase")
    mode = s.add_mutually_exclusive_group()
    mode.add_argument("--follow", action="store_true", help="never end (launchd KeepAlive): keep claiming new work")
    mode.add_argument("--daily", action="store_true", help="one pass over the daily phases, then derive + crop proposals")
    s.add_argument("--idle-minutes", type=float, default=10.0)
    s.add_argument("--max-images", type=int, help="per stage process (testing)")
    s.set_defaults(fn=cmd_run)

    s = sub.add_parser("stage-worker", parents=[common], help=argparse.SUPPRESS)
    s.add_argument("--analyzer", required=True)
    s.add_argument("--gate", action="store_true")
    s.add_argument("--deadline", type=float)
    s.add_argument("--max-images", type=int)
    s.add_argument("--stats-file")
    s.set_defaults(fn=cmd_stage_worker)

    s = sub.add_parser("status", parents=[common])
    s.add_argument("--json", action="store_true")
    s.add_argument("--all", action="store_true", help="every analyzer of every profile")
    s.set_defaults(fn=cmd_status)

    s = sub.add_parser("derive", parents=[common])
    s.add_argument("--only", help="comma list: box_text,zeroshot,neighbours,tags,difficulty_prior")
    s.add_argument("--dry-run", action="store_true")
    s.add_argument("--force-model", action="store_true",
                   help="difficulty_prior: record + push although the holdout Spearman dropped > 0.05 (a human's decision)")
    s.add_argument("--json", action="store_true")
    s.set_defaults(fn=cmd_derive)

    s = sub.add_parser("propose-crops", parents=[common])
    s.add_argument("--limit", type=int, required=True)
    s.add_argument("--threshold", type=float)
    s.add_argument("--dry-run", action="store_true")
    s.add_argument("--json", action="store_true")
    s.set_defaults(fn=cmd_propose_crops)

    s = sub.add_parser("crop-review-plan", parents=[common])
    s.add_argument("--apply", action="store_true", help="approve the auto band through the API")
    s.add_argument("--json", action="store_true")
    s.add_argument("--min-human", type=int, default=300)
    s.add_argument("--target", type=float, default=0.99)
    s.add_argument("--audit-share", type=float, default=0.05)
    s.add_argument("--stop-below", type=float, default=0.98)
    s.add_argument("--model-version", help="the crop model the threshold is for (default: the newest proposal's)")
    s.set_defaults(fn=cmd_crop_review_plan)

    s = sub.add_parser("crop-review-approve", parents=[common])
    s.add_argument("ids", nargs="+")
    s.add_argument("--model-version")
    s.set_defaults(fn=cmd_crop_review_approve)

    s = sub.add_parser("bench", parents=[common])
    s.add_argument("--stage", required=True, help="artwork-gate, artwork, ocr, describe, ...")
    s.add_argument("--limit", type=int, default=20)
    s.set_defaults(fn=cmd_bench)

    s = sub.add_parser("verify", parents=[common])
    s.add_argument("--describe-parity", action="store_true")
    s.add_argument("--end-to-end", action="store_true")
    s.add_argument("--limit", type=int, default=20)
    s.set_defaults(fn=cmd_verify)

    s = sub.add_parser("launchd-plist", parents=[common])
    s.add_argument("--install", action="store_true", help="write it to ~/Library/LaunchAgents (loading stays manual)")
    s.set_defaults(fn=cmd_launchd_plist)
    return p


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    ctx = Context(args)
    setup_logging(ctx.settings, args.verbose)
    try:
        return int(args.fn(args, ctx) or 0)
    except KeyboardInterrupt:
        return 130


if __name__ == "__main__":
    sys.exit(main())
