#!/usr/bin/env python3
"""ONE command: registry icons x frozen F1 bases -> public/img/badges/** + manifest.json. Strict and deterministic.

    PY=tools/badge-art/.venv/bin/python
    $PY -B tools/badge-art/build.py                                  # everything: icons/svg + all badge art
    $PY -B tools/badge-art/build.py --only streak --out tools/badge-art/work/preview    # preview, never public/
    $PY -B tools/badge-art/build.py --icons-only                     # only regenerate icons/svg/*.svg
    $PY -B tools/badge-art/build.py --record-toolchain               # after a deliberate toolchain upgrade

Steps: check the location, the toolchain (warning), registry.py against src/Value/BadgeType.php, the frozen bases
against bases/bases.json (sha256); draw every registry icon (6 colourways: msp + t1..t5) -> icons/svg; fit each icon
optically into the slot; compose base(under) + icon + base(over) for every badge; optimise the SVG; rasterise WebP
(96/144/192/320/480) and PNG (96/144) from that SVG at each size; for tiered badges the medal data of the detail
sheet (lib/holo.py: back plate + sampled tones, foil and rim masks for t4/t5); write only changed files, delete stale managed files, write manifest.json
(version = short hash of every output). A second run changes nothing.
Exit codes: 0 ok, 1 build/strictness error, 2 bad command line.
"""
import argparse
import gzip
import hashlib
import json
import os
import platform
import re
import sys
import time
from concurrent.futures import ProcessPoolExecutor
from pathlib import Path

os.environ.setdefault("PYTHONDONTWRITEBYTECODE", "1")
sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from lib import paths  # noqa: E402
from lib import compose as CP  # noqa: E402
from lib import holo  # noqa: E402
from lib import raster as RS  # noqa: E402
from lib import svgopt  # noqa: E402
from lib import toolchain  # noqa: E402

WEBP_PX = (96, 144, 192, 320, 480)       # = 2x of the display sizes 48 / 72 / 96 / 160 / 240 (cwebp, raster.py)
PNG_PX = (96, 144)                       # e-mails (absolute URLs)
MANAGED = ("svg", "webp", "png", "holo") # sub-trees of the output directory this build owns
ICON_COLOURWAYS = ("msp", "t1", "t2", "t3", "t4", "t5")   # always drawn (tint integrity needs all six)


class BuildError(Exception):
    pass


# ----------------------------------------------------------------------------------------------- inputs
def load_registry():
    try:
        import registry
    except AttributeError as e:          # a registry line names a drawing function that does not exist
        raise BuildError(f"registry.py: {e}") from None
    probs = registry.problems()
    if probs:
        raise BuildError("\n".join(probs))
    return registry


def php_badge_types():
    """BadgeType enum values and the single-tier ones, parsed from src/Value/BadgeType.php. isTiered() must list the
    single-tier cases in one of the forms `return $this !== self::A;`, `return !in_array($this, [self::A, ...],
    true);` or `return match ($this) { self::A, ... => false, default => true };` - else single is None (warning)."""
    src = paths.BADGE_TYPE_PHP.read_text()
    cases = dict(re.findall(r"case\s+(\w+)\s*=\s*'([^']+)'\s*;", src))
    m = re.search(r"function\s+isTiered\(\)\s*:\s*bool\s*\{(.*?)\n\s*\}", src, re.S)
    single = None
    if m and re.search(r"!==|!\s*in_array|=>\s*false", m.group(1)):
        refs = re.findall(r"self::(\w+)", m.group(1))
        if refs and all(r in cases for r in refs):
            single = {cases[r] for r in refs}
    return set(cases.values()), single


def check_registry(reg):
    values, single = php_badge_types()
    types = {b.type for b in reg.BADGES}
    errs = []
    if types - values:
        errs.append(f"registry.py lists types that BadgeType.php does not have: {sorted(types - values)}")
    if values - types:
        errs.append(f"BadgeType.php has types that registry.py does not build: {sorted(values - types)}")
    warns = []
    if single is None:
        warns.append("could not read BadgeType::isTiered() - check the registry's SINGLE kinds by hand")
    else:
        mine = {b.type for b in reg.BADGES if b.kind == reg.SINGLE}
        if mine != single:
            errs.append(f"single-tier kinds differ: registry.py {sorted(mine)}, BadgeType::isTiered() {sorted(single)}")
    if errs:
        raise BuildError("\n".join(errs))
    return warns


def load_bases(reg):
    """bases.json + every declared layer, verified against its recorded sha256 (frozen = never edited by hand)."""
    decl = json.loads((paths.BASES / "bases.json").read_text())
    layers, warns = {}, []
    for key, b in decl["bases"].items():
        pair = []
        for layer in ("under", "over"):
            p = paths.BASES / b["files"][layer]
            if not p.is_file():
                raise BuildError(f"base '{key}': missing {paths.rel(p)}")
            text = p.read_text()
            if hashlib.sha256(text.encode()).hexdigest() != b["sha256"][layer]:
                raise BuildError(f"base '{key}': {paths.rel(p)} differs from its frozen sha256 in bases.json - revert "
                                 f"it, or re-freeze deliberately (freeze_bases.py --force {key})")
            pair.append(CP.parse_layer(text, paths.rel(p)))
        layers[key] = tuple(pair)
    needed = {b.base_key(t) for b in reg.BADGES for t in b.tiers}
    missing = sorted(needed - set(layers))
    if missing:
        raise BuildError(f"no base declared in bases/bases.json for: {missing} (freeze it with freeze_bases.py - "
                         f"nothing falls back silently)")
    for key in sorted(needed):
        if decl["bases"][key]["status"] != "frozen":
            warns.append(f"base '{key}' is {decl['bases'][key]['status']}: {decl['bases'][key]['title']}")
    return decl, layers, warns


# ----------------------------------------------------------------------------------------------- workers
def icon_job(args):
    """Draw one registry icon: its colourway SVG texts (msp + t1..t5 + the set colours one-off badges need), the
    optical fit in the slot and the rule-5 piece metrics."""
    name, extra, slot, outline_px = args
    import registry
    from frames.f1s import SETS
    from icons import illus
    from icons.colourway import set_colourway
    spec = registry.icon_table()[name]
    illus.PIECES.clear()
    raw = spec["draw"]()
    if raw.name != name:
        return {"icon": name, "error": f"registry: icon {name!r} -> {spec['draw'].__name__}(), which draws an "
                                       f"icon named {raw.name!r}"}
    icon, scale = illus.fit(raw)
    pieces = [dict(illus.piece_metrics(p["side"] * scale, p["sides"], p["R"], p["h"], p["f"], p["stroke"]), border=p.get("border", False))
              for p in illus.PIECES]
    svgs = {key: illus.svg_text(icon, key, comment=icon.notes) for key in ICON_COLOURWAYS}
    for key in extra:
        svgs[key] = set_colourway(svgs["msp"], name, key, SETS[key])
    size, trace = CP.fitted_size(svgs["msp"], slot["bbox_long_side_px"], slot["fit_radius_px"], outline_px)
    return {"icon": name, "types": spec["types"], "svgs": svgs, "size": size, "trace": trace, "pieces": pieces,
            "symmetric": icon.symmetric}


def plan(badges, icons, layers, outline_px):
    """Every badge file to compose: (stem, base under, base over, icon colourway SVG, icon size, outline px)."""
    tasks = []
    for b in badges:
        ic = icons[b.icon]
        for tier in b.tiers:
            under, over = layers[b.base_key(tier)]
            tasks.append((f"{b.type}-{tier}", under, over, ic["svgs"][b.colourway(tier)], ic["size"], outline_px))
    return tasks


def type_entry(b):
    e = {"icon": b.icon, "kind": b.kind, "tiers": list(b.tiers), "title": b.title}
    if b.kind == "single":
        e.update(set_colour=b.set_colour, accent=b.accent or b.set_colour)
    return e


def id_prefix(stem):
    return "b" + hashlib.sha1(stem.encode()).hexdigest()[:5]


def badge_job(args):
    """One badge (type x tier): compose -> optimise -> SVG + every raster (+ the medal data for tiered badges).
    Returns (stem, {relpath: bytes}, unoptimised bytes, holo manifest entry or None)."""
    import numpy as np
    stem, under, over, icon_svg, size, outline_px = args
    master = CP.compose(under, over, CP.icon_group(icon_svg, size, outline_px))
    svg = svgopt.optimise(master, id_prefix(stem)).encode()
    files = {f"svg/{stem}.svg": svg}
    renders = {px: RS.rsvg_png(svg, px) for px in sorted(set(WEBP_PX) | set(PNG_PX))}
    for px in WEBP_PX:
        files[f"webp/{px}/{stem}.webp"] = RS.webp_sharp(renders[px])
    for px in PNG_PX:
        files[f"png/{px}/{stem}.png"] = RS.png_optimized(RS.decode_rgba(renders[px]))
    holo_entry = None
    if stem.rsplit("-", 1)[1] in holo.MEDAL_TIERS:
        holo_files, holo_entry = holo.files_for(stem, np.asarray(RS.render_rgba(svg, holo.HOLO_PX)))
        files.update(holo_files)
    return stem, files, len(master), holo_entry


# ----------------------------------------------------------------------------------------------- outputs
def write_if_changed(path, data):
    path = Path(path)
    if path.is_file() and path.read_bytes() == data:
        return False
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(data)
    return True


def prune(root, keep, subdirs):
    """Delete files under root/<subdir> that this build did not produce; remove emptied directories."""
    removed = []
    for sub in subdirs:
        base = Path(root) / sub
        if not base.exists():
            continue
        for p in sorted(base.rglob("*"), reverse=True):
            rel = p.relative_to(root).as_posix()
            if p.is_file() and rel not in keep:
                p.unlink()
                removed.append(rel)
            elif p.is_dir() and not any(p.iterdir()):
                p.rmdir()
    return removed


def manifest_bytes(reg, decl, files, partial, holo_entries=None):
    hashes = {k: hashlib.sha256(v).hexdigest() for k, v in sorted(files.items())}
    version = hashlib.sha256("".join(f"{k} {h}\n" for k, h in hashes.items()).encode()).hexdigest()[:12]
    used = sorted({b.base_key(t) for b in reg.BADGES for t in b.tiers}, key=lambda t: (t.startswith("single"), t))
    m = {
        "version": version,
        "generator": "tools/badge-art/build.py",
        "frame": decl["frame"],
        "bases": {k: decl["bases"][k]["status"] for k in used},
        "formats": {"svg": True, "webp": list(WEBP_PX), "png": list(PNG_PX),
                    "webp_encoder": "cwebp " + " ".join(RS.WEBP_ARGS), "png_encoder": "lossless"},
        "types": {b.type: type_entry(b) for b in reg.BADGES},
        # The medal of the detail sheet (lib/holo.py): per tiered type and tier the back plate, the sampled tones, the
        # back's text field (centre x, y and radius in % of the picture), and for t4/t5 the foil and rim masks
        "holo": {stem.rsplit("-", 1)[0]: {} for stem in sorted(holo_entries or {})},
        "files": hashes,
    }
    for stem, entry in sorted((holo_entries or {}).items()):
        btype, tier = stem.rsplit("-", 1)
        m["holo"][btype][tier] = entry
    if partial:
        m["partial"] = True
    return (json.dumps(m, indent=1) + "\n").encode(), version


# ----------------------------------------------------------------------------------------------- run
def run(out=paths.OUT, icons_out=paths.ICONS_SVG, only=None, icons_only=False, jobs=None, log=print):
    """Build into `out` (badge art) and `icons_out` (icon sources). Returns a summary dict."""
    t0 = time.time()
    jobs = jobs or os.cpu_count() or 4
    paths.check_layout()
    reg = load_registry()
    warns = check_registry(reg)
    decl, layers, bwarns = load_bases(reg)
    warns += bwarns
    badges = [b for b in reg.BADGES if not only or b.type in only]
    slot, outline_px = decl["icon_slot"], decl["outline_px"]

    table = reg.icon_table(badges)
    with ProcessPoolExecutor(max_workers=jobs) as ex:
        icons = {r["icon"]: r for r in ex.map(icon_job, [(n, e["extra"], slot, outline_px) for n, e in table.items()])}
        errors = [r["error"] for r in icons.values() if "error" in r]
        if errors:
            raise BuildError("\n".join(errors))
        t_icons = time.time() - t0
        changed = []
        icon_files = {f"{n}-{key}.svg": text.encode() for n, ic in icons.items() for key, text in ic["svgs"].items()}
        if not only:                                  # the icon sources are only (re)written by a full build
            for name, data in icon_files.items():
                if write_if_changed(Path(icons_out) / name, data):
                    changed.append(paths.rel(Path(icons_out) / name))
            stale = sorted(p.name for p in Path(icons_out).glob("*.svg") if p.name not in icon_files)
            for name in stale:
                (Path(icons_out) / name).unlink()
                changed.append(f"deleted {paths.rel(Path(icons_out) / name)}")
        if icons_only:
            return {"changed": changed, "warnings": warns, "seconds": time.time() - t0, "icons": icons,
                    "t_icons": t_icons}

        tasks = plan(badges, icons, layers, outline_px)
        prefixes = [id_prefix(t[0]) for t in tasks]
        if len(set(prefixes)) != len(prefixes):
            raise BuildError("two badges share an SVG id prefix - change id_prefix()")
        files, master_bytes, holo_entries = {}, 0, {}
        for stem, fs, mb, he in ex.map(badge_job, tasks):
            files.update(fs)
            master_bytes += mb
            if he is not None:
                holo_entries[stem] = he

    for rel, data in sorted(files.items()):
        if write_if_changed(Path(out) / rel, data):
            changed.append(paths.rel(Path(out) / rel))
    if not only:
        for rel in prune(out, set(files), MANAGED):
            changed.append(f"deleted {paths.rel(Path(out) / rel)}")
    man, version = manifest_bytes(reg, decl, files, partial=bool(only), holo_entries=holo_entries)
    if write_if_changed(Path(out) / "manifest.json", man):
        changed.append(paths.rel(Path(out) / "manifest.json"))
    unmanaged = sorted(p.relative_to(out).as_posix() for p in Path(out).iterdir()
                       if p.name not in MANAGED + ("manifest.json",))
    if unmanaged:
        warns.append(f"files in {paths.rel(out)} that the build does not own (left untouched): {unmanaged}")
    sizes = {}
    for rel, data in files.items():
        fmt = rel.split("/")[0]
        sizes.setdefault(fmt, [0, 0, 0])
        sizes[fmt][0] += 1
        sizes[fmt][1] += len(data)
        if fmt == "svg":
            sizes[fmt][2] += len(gzip.compress(data, 9, mtime=0))
    return {"version": version, "changed": changed, "warnings": warns, "files": len(files) + 1,
            "badges": len(tasks), "types": len(badges), "sizes": sizes, "master_bytes": master_bytes,
            "seconds": time.time() - t0, "t_icons": t_icons, "icons": icons}


def main(argv):
    ap = argparse.ArgumentParser(description="Build the MSP badge art (README.md).")
    ap.add_argument("--out", type=Path, help=f"badge art output directory (default {paths.rel(paths.OUT)})")
    ap.add_argument("--icons-out", type=Path, help=f"icon SVG output directory (default {paths.rel(paths.ICONS_SVG)})")
    ap.add_argument("--only", action="append", metavar="TYPE", help="build only this BadgeType value (repeatable); "
                    "needs --out outside public/img/badges (a preview never replaces committed art)")
    ap.add_argument("--icons-only", action="store_true", help="only regenerate the icon sources (icons/svg)")
    ap.add_argument("--jobs", type=int, help="worker processes (default: all CPU cores)")
    ap.add_argument("--record-toolchain", action="store_true", help="write the local versions to toolchain.json")
    a = ap.parse_args(argv)

    paths.check_layout()
    if a.record_toolchain:
        toolchain.record(paths.TOOLCHAIN, platform.platform())
        print(f"recorded {paths.rel(paths.TOOLCHAIN)}")
        return 0
    out = (a.out or paths.OUT).resolve()
    icons_out = (a.icons_out or paths.ICONS_SVG).resolve()
    if a.only:
        import registry
        known = [b.type for b in registry.BADGES]
        bad = [t for t in a.only if t not in known]
        if bad:
            print(f"build.py: unknown type {', '.join(bad)}\n  known: {', '.join(known)}", file=sys.stderr)
            return 2
        if a.out is None or out == paths.OUT.resolve():
            print("build.py: --only builds a preview; give --out outside public/img/badges "
                  "(e.g. --out tools/badge-art/work/preview)", file=sys.stderr)
            return 2
        if a.icons_only:
            print("build.py: --only and --icons-only cannot be combined (icon sources are always built in full)",
                  file=sys.stderr)
            return 2
    toolchain.warn(toolchain.compare(paths.TOOLCHAIN, include_qa=False), sys.stderr)
    print(f"badge-art build\n  repo  : {paths.REPO}\n  out   : {paths.rel(out)}\n  icons : {paths.rel(icons_out)}"
          + (f"\n  only  : {', '.join(a.only)} (preview)" if a.only else ""))
    try:
        r = run(out, icons_out, only=set(a.only or ()), icons_only=a.icons_only, jobs=a.jobs)
    except BuildError as e:
        print(f"\nBUILD FAILED\n{e}", file=sys.stderr)
        return 1
    for w in r["warnings"]:
        print(f"WARNING: {w}", file=sys.stderr)
    if a.icons_only:
        print(f"icons: {len(r['icons'])} icons, {sum(len(i['svgs']) for i in r['icons'].values())} colourway SVGs, "
              f"{len(r['changed'])} files changed, "
              f"{r['seconds']:.1f} s")
        return 0
    s = r["sizes"]
    print(f"types {r['types']}, badges {r['badges']}, files {r['files']} (incl. manifest), "
          f"{len(r['changed'])} changed, version {r['version']}, {r['seconds']:.1f} s (icons {r['t_icons']:.1f} s)")
    print(f"  svg  {s['svg'][0]:4d} files {s['svg'][1] / 1024:8.1f} kB (gzip {s['svg'][2] / 1024:7.1f} kB; "
          f"unoptimised {r['master_bytes'] / 1024:8.1f} kB)")
    for fmt in ("webp", "png", "holo"):
        if fmt in s:
            print(f"  {fmt:4s} {s[fmt][0]:4d} files {s[fmt][1] / 1024:8.1f} kB")
    for c in r["changed"][:20]:
        print(f"  ~ {c}")
    if len(r["changed"]) > 20:
        print(f"  ~ ... {len(r['changed']) - 20} more")
    print("next: run qa.py (icon gate + golden check + contact sheet) before committing")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
