#!/usr/bin/env python3
"""The badge-art QA gate. Run it after every build and before every commit.

    PY=tools/badge-art/.venv/bin/python
    $PY -B tools/badge-art/qa.py                     # the full gate (writes work/qa.json + work/contact-48.png)
    $PY -B tools/badge-art/qa.py --icon hourglass    # icon gate for one icon while drawing it (repeatable)

Full gate, in order (any FAIL -> exit 1; WARNINGs never fail):
  golden      rebuild everything into a temporary directory; the committed public/img/badges/** and
              tools/badge-art/icons/svg/*.svg must be byte-identical to it (no missing, extra or changed file);
              likewise the last-piece reveal files (reveal/geometry.py: assets/last_piece_reveal/geometry.json,
              assets/img/last-piece-reveal-tray.svg)
  icons       the IK1 icon gate on every colourway of every registry icon (icons/gate.py: contract, centring,
              symmetry, outline width, dashes, slivers, 24 px legibility), tint integrity, jigsaw rule 5
  selftest    negative controls must FAIL their gate (proves the gate still catches defects)
  optimiser   every shipped SVG vs its unoptimised master at 1024 px: <= 1 level on >= 99.9 % of pixels
  rasters     every PNG pixel-identical to a fresh render of the shipped SVG; every WebP with exact alpha and
              PSNR >= 30 dB (cwebp q90 + sharp_yuv: lowest file 31.8 dB)
  frame fit   (warning) the icon's size in the badge after the optical fit; below 49 % of the badge it reads small
  accent      (warning) visible accent share of the icon < 0.25 (the tier tint carries little of the icon)
  sheet       work/contact-48.png: every badge at 48 px on white, on page grey, and locked (CSS greyscale + 45 %)
--icon NAME runs only the icon gate (+ frame fit, accent, rule 5) on the freshly DRAWN icon and writes
work/qa-icon-NAME.json; it never touches the full report. Exit codes: 0 pass, 1 fail, 2 bad command line.
"""
import argparse
import io
import json
import os
import sys
import tempfile
import time
from concurrent.futures import ProcessPoolExecutor
from pathlib import Path

os.environ.setdefault("PYTHONDONTWRITEBYTECODE", "1")
sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import build  # noqa: E402
from lib import compose as CP  # noqa: E402
from lib import paths  # noqa: E402
from lib import toolchain  # noqa: E402

RULE5 = {"head_frac": (0.29, 0.36), "neck_over_head": (0.62, 0.76), "protrusion_frac": (0.30, 0.40)}
FIT_WARN_PCT = 49.0          # icon bbox below this share of the badge = noticeably smaller than the round icons (53 %)
ACCENT_WARN = 0.25           # visible accent share below this = the tier tint carries little of the icon
FIDELITY_LEVELS, FIDELITY_MIN_SHARE = 1, 99.9
WEBP_MIN_PSNR = 30.0         # cwebp q90 + sharp_yuv: lowest of all 405 files 31.8 dB on white (a 96 px file)
PAGE_GREY = (246, 249, 252)  # site page background (bg-secondary)


# ----------------------------------------------------------------------------------------------- checks
def rule5(pieces):
    """Jigsaw rule 5: one connector per side (knob or socket), identical sizes, the house knob anatomy."""
    issues = []
    for p in pieces:
        allowed = ("out", "in", "flat") if p.get("border") else ("out", "in")
        if len(p["sides"]) != 4 or any(sd not in allowed for sd in p["sides"]):
            issues.append(f"piece S={p['side']:.1f}: sides {p['sides']} (need exactly one knob or socket per side; "
                          f"only a border piece of an assembly may have flat outer sides)")
        elif p.get("border") and sum(sd != "flat" for sd in p["sides"]) < 2:
            issues.append(f"piece S={p['side']:.1f}: border piece with fewer than two connectors {p['sides']}")
        for k, (lo, hi) in RULE5.items():
            if not lo <= p[k] <= hi:
                issues.append(f"piece S={p['side']:.1f}: {k} {p[k]} outside {lo}-{hi}")
        if p["stroke"] and p["fillet_vs_half_stroke"] < 1.0:
            issues.append(f"piece S={p['side']:.1f}: fillet radius < stroke/2 (pinched neck)")
    return issues


def icon_file_job(args):
    from icons import gate
    label, svg, symmetric = args
    return label, gate.check(svg, symmetric=symmetric, label=label)


def icon_set_job(args):
    from icons import gate
    icon, svgs = args
    tint = gate.tint_integrity([(f"{icon}-{k}.svg", svgs[k]) for k in build.ICON_COLOURWAYS])
    return icon, tint, gate.accent_area(svgs["msp"])


def selftest_job(svg):
    from icons import gate
    return gate.selftest(svg)


def fidelity_job(args):
    import numpy as np
    from PIL import Image
    from lib.raster import rsvg_png
    stem, under, over, icon_svg, size, outline_px, shipped = args
    master = CP.compose(under, over, CP.icon_group(icon_svg, size, outline_px))
    a = np.asarray(Image.open(io.BytesIO(rsvg_png(master.encode(), 1024))).convert("RGBA")).astype(np.int16)
    b = np.asarray(Image.open(io.BytesIO(rsvg_png(shipped, 1024))).convert("RGBA")).astype(np.int16)
    d = np.abs(a - b).max(-1)
    within = float((d <= FIDELITY_LEVELS).mean() * 100)
    return stem, {"within_1_level_pct": round(within, 4), "max_diff": int(d.max()),
                  "pass": within >= FIDELITY_MIN_SHARE}


def raster_job(args):
    """Every raster of one badge vs a fresh rsvg render of its shipped SVG: PNG pixel-exact; WebP alpha exact and
    PSNR (on white) >= WEBP_MIN_PSNR (catches a broken or mis-set encoder, e.g. a dropped -sharp_yuv / low -q)."""
    import numpy as np
    from lib.raster import decode_rgba, render_rgba
    stem, svg, files = args
    out = {"pass": True, "issues": [], "webp_psnr_min": 99.0}
    for rel, data in files.items():
        px = int(rel.split("/")[1])
        ref = np.asarray(render_rgba(svg, px)).astype(np.float64)
        got = np.asarray(decode_rgba(data)).astype(np.float64)
        if got.shape != ref.shape:
            out["issues"].append(f"{rel}: {got.shape[1]}x{got.shape[0]} px, expected {px}x{px}")
            continue
        if rel.startswith("png/"):
            if not np.array_equal(got, ref):
                out["issues"].append(f"{rel}: not pixel-identical to the SVG render (PNG must be lossless)")
            continue
        if not np.array_equal(got[..., 3], ref[..., 3]):
            out["issues"].append(f"{rel}: alpha differs from the SVG render (WebP alpha must be lossless)")
        a = ref[..., :3] / 255 * ref[..., 3:] / 255 + (1 - ref[..., 3:] / 255)
        g = got[..., :3] / 255 * got[..., 3:] / 255 + (1 - got[..., 3:] / 255)
        mse = float(((a - g) ** 2).mean())
        psnr = 10 * np.log10(1 / mse) if mse > 0 else 99.0
        out["webp_psnr_min"] = round(min(out["webp_psnr_min"], psnr), 2)
        if psnr < WEBP_MIN_PSNR:
            out["issues"].append(f"{rel}: PSNR {psnr:.1f} dB < {WEBP_MIN_PSNR}")
    out["pass"] = not out["issues"]
    return stem, out


def frame_fit(ic, slot):
    size = ic["size"]
    return {"icon_px": round(size, 2), "icon_pct": round(size / 1024 * 100, 2),
            "farthest_px_at_nominal": ic["trace"][0]["farthest_px"], "fit_radius_px": slot["fit_radius_px"],
            "nominal_pct": round(slot["bbox_long_side_px"] / 1024 * 100, 2),
            "warn": size / 1024 * 100 < FIT_WARN_PCT}


def tree(root, pattern="**/*"):
    root = Path(root)
    return {p.relative_to(root).as_posix(): p.read_bytes() for p in sorted(root.glob(pattern)) if p.is_file()}


def golden(tmp, jobs):
    """Rebuild into tmp; committed outputs must match byte for byte."""
    r = build.run(out=Path(tmp) / "badges", icons_out=Path(tmp) / "icons", jobs=jobs, log=lambda *a: None)
    problems = []
    fresh, committed = tree(Path(tmp) / "badges"), tree(paths.OUT)
    committed = {k: v for k, v in committed.items() if k.split("/")[0] in build.MANAGED or k == "manifest.json"}
    for label, new, old in (("public/img/badges", fresh, committed),
                            ("tools/badge-art/icons/svg", tree(Path(tmp) / "icons", "*.svg"),
                             tree(paths.ICONS_SVG, "*.svg"))):
        for k in sorted(set(new) | set(old)):
            if k not in old:
                problems.append(f"{label}/{k}: missing in the commit (rebuild produces it)")
            elif k not in new:
                problems.append(f"{label}/{k}: committed but the build no longer produces it")
            elif new[k] != old[k]:
                problems.append(f"{label}/{k}: differs from a fresh rebuild")
    from reveal import geometry as reveal_geometry      # the last-piece reveal JSON + tray (reveal/geometry.py)
    files, _report, _svg = reveal_geometry.build()
    for p, data in files.items():
        if not p.is_file() or p.read_bytes() != data:
            problems.append(f"{paths.rel(p)}: differs from a fresh rebuild (run reveal/geometry.py)")
    return r, problems


def contact_sheet(reg, fits, path):
    import numpy as np
    from PIL import Image, ImageDraw, ImageFont
    from lib.raster import render_rgba
    px, gap, label_w = 48, 10, 230
    cols = 5
    width = label_w + 3 * gap + (2 * cols + 1) * (px + gap) + gap
    height = 40 + len(reg.BADGES) * (px + gap) + gap
    sheet = Image.new("RGB", (width, height), "white")
    dr = ImageDraw.Draw(sheet)
    font = ImageFont.load_default(size=12)
    x_white, x_grey = label_w, label_w + cols * (px + gap) + 2 * gap
    x_lock = x_grey + cols * (px + gap) + 2 * gap
    dr.rectangle([x_grey - gap // 2, 0, x_grey + cols * (px + gap) - gap // 2, height], fill=PAGE_GREY)
    dr.text((x_white, 12), "48 px on white  (t1 .. t5 / single)", fill=(43, 52, 69), font=font)
    dr.text((x_grey, 12), "48 px on page grey #f6f9fc", fill=(43, 52, 69), font=font)
    dr.text((x_lock - 4, 12), "locked", fill=(43, 52, 69), font=font)
    for r, b in enumerate(reg.BADGES):
        y = 40 + r * (px + gap)
        fit = fits[b.icon]
        mark = "  (!) small" if fit["warn"] else ""
        kind = "" if b.kind == "tiered" else f"  [one-off {b.set_colour}/{b.accent or b.set_colour}]"
        dr.text((gap, y + 8), b.type + kind, fill=(43, 52, 69), font=font)
        dr.text((gap, y + 26), f"{b.icon}, icon {fit['icon_pct']:.1f} %{mark}", fill=(107, 116, 136), font=font)
        for c, tier in enumerate(b.tiers):
            im = render_rgba((paths.OUT / "svg" / f"{b.type}-{tier}.svg").read_bytes(), px)
            for x0 in (x_white, x_grey):
                sheet.paste(im, (x0 + c * (px + gap), y), im)
            if c == 0:
                a = np.asarray(im).astype(np.float64)
                g = a[..., 0] * 0.2126 + a[..., 1] * 0.7152 + a[..., 2] * 0.0722
                lk = np.dstack([g, g, g, a[..., 3] * 0.45]).round().astype(np.uint8)
                lim = Image.fromarray(lk, "RGBA")
                sheet.paste(lim, (x_lock, y), lim)
    path.parent.mkdir(parents=True, exist_ok=True)
    sheet.save(path)


# ----------------------------------------------------------------------------------------------- icon gate
def run_icon_gate(ex, icons, slot):
    """icons: {icon: build.icon_job result}. -> (per-icon results, failures, warnings)."""
    files = []
    for ic in icons.values():
        for k in ic["svgs"]:                       # msp + t1..t5 + the set colourways one-off badges use
            files.append((f"{ic['icon']}-{k}.svg", ic["svgs"][k], ic["symmetric"]))
    checks = dict(ex.map(icon_file_job, files))
    sets = {icon: (tint, acc) for icon, tint, acc in
            ex.map(icon_set_job, [(ic["icon"], ic["svgs"]) for ic in icons.values()])}
    results, fails, warns = {}, [], []
    for name, ic in icons.items():
        per_file = {k: checks[f"{name}-{k}.svg"] for k in ic["svgs"]}
        tint, acc = sets[name]
        r5 = rule5(ic["pieces"])
        fit = frame_fit(ic, slot)
        ok = all(r["pass"] for r in per_file.values()) and tint["pass"] and not r5
        results[name] = {"types": ic["types"], "pass": ok, "files": per_file, "tints": tint, "rule5_issues": r5,
                         "pieces": ic["pieces"], "accent_area": acc, "frame_fit": fit}
        for k, r in per_file.items():
            if not r["pass"]:
                fails.append(f"icon {name}-{k}.svg: failed {[g for g, v in r['gates'].items() if not v]} "
                             f"{r['contract_issues']}")
        if not tint["pass"]:
            fails.append(f"icon {name}: tint integrity {tint['issues']} dE face {tint['accent_dE_vs_face']} "
                         f"steps {tint['accent_dE_tier_steps']}")
        fails += [f"icon {name}: rule 5: {x}" for x in r5]
        if fit["warn"]:
            warns.append(f"frame fit: {name} icon is {fit['icon_pct']:.1f} % of the badge (nominal "
                         f"{fit['nominal_pct']:.0f} %, farthest point {fit['farthest_px_at_nominal']:.0f} px vs plate "
                         f"radius {fit['fit_radius_px']:.0f} px) - a squarer/boxier silhouette shrinks; below "
                         f"{FIT_WARN_PCT:.0f} % it reads small next to round icons")
        if acc < ACCENT_WARN:
            warns.append(f"accent: {name} visible accent share {acc:.2f} < {ACCENT_WARN} (rule of thumb; it still "
                         f"passes 24 px accent visibility {per_file['msp']['legibility']['accent24']})")
    return results, fails, warns


def print_icon_table(results):
    print(f"\n{'icon':16s} {'types':18s} {'result':6s} {'files':>5s} {'tints':>5s} {'rule5':>5s} {'icon %':>7s} "
          f"{'accent':>6s}  24px ink/edge/accent/thinfill (msp)")
    for name, r in results.items():
        lg = r["files"]["msp"]["legibility"]
        print(f"{name:16s} {', '.join(r['types']):18s} {'PASS' if r['pass'] else 'FAIL':6s} "
              f"{sum(f['pass'] for f in r['files'].values()):>3d}/{len(r['files'])} {str(r['tints']['pass']):>5s} "
              f"{'ok' if not r['rule5_issues'] else 'FAIL':>5s} {r['frame_fit']['icon_pct']:6.1f}% "
              f"{r['accent_area']:6.2f}  {lg['ink24']}/{lg['edge24']}/{lg['accent24']}/{lg['thinfill']}")


# ----------------------------------------------------------------------------------------------- main
def main(argv):
    ap = argparse.ArgumentParser(description="Badge-art QA gate (README.md).")
    ap.add_argument("--icon", action="append", metavar="NAME", help="icon gate only, for this icon (repeatable)")
    ap.add_argument("--jobs", type=int, help="worker processes (default: all CPU cores)")
    a = ap.parse_args(argv)
    paths.check_layout()
    jobs = a.jobs or os.cpu_count() or 4
    reg = build.load_registry()
    decl = json.loads((paths.BASES / "bases.json").read_text())
    slot, outline_px = decl["icon_slot"], decl["outline_px"]
    t0 = time.time()

    if a.icon:
        known = reg.icon_table()
        bad = [n for n in a.icon if n not in known]
        if bad:
            print(f"qa.py: unknown icon {', '.join(bad)} - register it in registry.py first\n"
                  f"  known: {', '.join(known)}", file=sys.stderr)
            return 2
        with ProcessPoolExecutor(max_workers=jobs) as ex:
            icons = {r["icon"]: r for r in ex.map(build.icon_job, [(n, known[n]["extra"], slot, outline_px)
                                                                     for n in dict.fromkeys(a.icon)])}
            errs = [r["error"] for r in icons.values() if "error" in r]
            if errs:
                print("\n".join(errs), file=sys.stderr)
                return 1
            results, fails, warns = run_icon_gate(ex, icons, slot)
        print_icon_table(results)
        paths.WORK.mkdir(exist_ok=True)
        for name, r in results.items():
            (paths.WORK / f"qa-icon-{name}.json").write_text(json.dumps(r, indent=1, default=float) + "\n")
        for w in warns:
            print(f"WARNING: {w}")
        for f in fails:
            print(f"FAIL: {f}")
        print(f"\n{'FAIL' if fails else 'PASS'} (icon gate only, {time.time() - t0:.0f} s) - reports in "
              f"{paths.rel(paths.WORK)}/qa-icon-*.json; the full gate is qa.py without --icon")
        return 1 if fails else 0

    toolchain.warn(toolchain.compare(paths.TOOLCHAIN), sys.stdout)
    report = {"warnings": [], "failures": []}
    with tempfile.TemporaryDirectory(prefix="badge-art-golden-") as tmp:
        print("golden: rebuilding everything into a temporary directory ...", flush=True)
        t = time.time()
        try:
            built, gold = golden(tmp, jobs)
        except build.BuildError as e:
            print(f"BUILD FAILED\n{e}", file=sys.stderr)
            return 1
        report["golden"] = {"pass": not gold, "problems": gold, "seconds": round(time.time() - t, 1),
                            "files": built["files"], "version": built["version"]}
        report["failures"] += [f"golden: {p}" for p in gold]
        report["warnings"] += built["warnings"]
        print(f"golden: {'PASS' if not gold else 'FAIL'} - {built['files']} files, version {built['version']} "
              f"({report['golden']['seconds']} s)" + "".join(f"\n   {p}" for p in gold[:30]))
        _, layers, _ = build.load_bases(reg)
        with ProcessPoolExecutor(max_workers=jobs) as ex:
            print("icons: gate on every colourway ...", flush=True)
            t = time.time()
            results, fails, warns = run_icon_gate(ex, built["icons"], slot)
            report["icons"] = results
            report["failures"] += fails
            report["warnings"] += warns
            ctrl = ex.submit(selftest_job, built["icons"]["speed"]["svgs"]["msp"]).result()
            report["selftest"] = ctrl
            missed = [k for k, v in ctrl.items() if not v["caught"]]
            report["failures"] += [f"selftest: the {k} gate missed its negative control" for k in missed]
            print(f"icons: {sum(r['pass'] for r in results.values())}/{len(results)} pass, selftest "
                  f"{len(ctrl) - len(missed)}/{len(ctrl)} controls caught ({time.time() - t:.0f} s)")
            t = time.time()
            tasks = [t + ((Path(tmp) / "badges" / "svg" / f"{t[0]}.svg").read_bytes(),)
                     for t in build.plan(reg.BADGES, built["icons"], layers, outline_px)]
            fid = dict(ex.map(fidelity_job, tasks))
            t2 = time.time()
            badge_dir = Path(tmp) / "badges"
            rjobs = []
            for stem in fid:
                files = {f"{fmt}/{px}/{stem}.{fmt}": (badge_dir / fmt / str(px) / f"{stem}.{fmt}").read_bytes()
                         for fmt, sizes in (("webp", build.WEBP_PX), ("png", build.PNG_PX)) for px in sizes}
                rjobs.append((stem, (badge_dir / "svg" / f"{stem}.svg").read_bytes(), files))
            rast = dict(ex.map(raster_job, rjobs))
        report["rasters"] = rast
        rbad = [i for v in rast.values() for i in v["issues"]]
        report["failures"] += [f"rasters: {i}" for i in rbad]
        print(f"rasters: {len(rast) - sum(not v['pass'] for v in rast.values())}/{len(rast)} badges ok - PNG "
              f"pixel-exact, WebP alpha exact, WebP PSNR min {min(v['webp_psnr_min'] for v in rast.values()):.1f} dB "
              f"(>= {WEBP_MIN_PSNR}) ({time.time() - t2:.0f} s)")
        report["optimiser"] = fid
        worst = min(fid.values(), key=lambda v: v["within_1_level_pct"])
        bad = [s for s, v in fid.items() if not v["pass"]]
        report["failures"] += [f"optimiser: {s} only {fid[s]['within_1_level_pct']} % of pixels within "
                               f"{FIDELITY_LEVELS} level of the master" for s in bad]
        print(f"optimiser: {len(fid) - len(bad)}/{len(fid)} SVGs within {FIDELITY_LEVELS} level on >= "
              f"{FIDELITY_MIN_SHARE} % of pixels (worst {worst['within_1_level_pct']} %, max diff "
              f"{max(v['max_diff'] for v in fid.values())}) ({time.time() - t:.0f} s)")
    print_icon_table(results)
    fits = {name: r["frame_fit"] for name, r in results.items()}
    sheet = paths.WORK / "contact-48.png"
    if not gold:
        contact_sheet(reg, fits, sheet)
    report["seconds"] = round(time.time() - t0, 1)
    paths.WORK.mkdir(exist_ok=True)
    (paths.WORK / "qa.json").write_text(json.dumps(report, indent=1, default=float) + "\n")
    print()
    for w in report["warnings"]:
        print(f"WARNING: {w}")
    for f in report["failures"]:
        print(f"FAIL: {f}")
    print(f"\n{'FAIL' if report['failures'] else 'PASS'} - {len(report['failures'])} failures, "
          f"{len(report['warnings'])} warnings, {report['seconds']:.0f} s. Report {paths.rel(paths.WORK / 'qa.json')}"
          + (f", sheet {paths.rel(sheet)} (look at it)" if not gold else " (no sheet: golden failed)"))
    return 1 if report["failures"] else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
