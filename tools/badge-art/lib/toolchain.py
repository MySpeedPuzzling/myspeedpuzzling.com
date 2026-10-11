"""The toolchain the committed artifacts were built with (toolchain.json) vs the local one.

Byte-identical output needs the same renderer and encoders: rsvg-convert/cairo draw the pixels, cwebp (WebP) and
Pillow's bundled zlib-ng (PNG) encode them, shapely/GEOS + svgpathtools shape the icons. The build prints a loud
warning when anything differs; qa.py's golden check then shows whether the bytes actually changed.
"""
import importlib.metadata
import json
import platform
import re
import shutil
import subprocess


def rsvg_versions():
    exe = shutil.which("rsvg-convert")
    if not exe:
        raise SystemExit("badge-art: rsvg-convert not found on PATH (macOS: brew install librsvg)")
    out = subprocess.run([exe, "--version"], capture_output=True, text=True, check=True).stdout
    v = {"rsvg-convert": re.search(r"rsvg-convert version (\S+)", out).group(1)}
    for lib in ("cairo", "pango", "harfbuzz", "fontconfig"):
        m = re.search(rf"^\s*{lib} (\S+)", out, re.M)
        if m:
            v[lib] = m.group(1)
    return v


def cwebp_versions():
    exe = shutil.which("cwebp")
    if not exe:
        raise SystemExit("badge-art: cwebp not found on PATH (macOS: brew install webp)")
    out = subprocess.run([exe, "-version"], capture_output=True, text=True, check=True).stdout.split()
    v = {"cwebp": out[0]}
    if "libsharpyuv:" in out:
        v["libsharpyuv (cwebp)"] = out[out.index("libsharpyuv:") + 1]
    return v


def current(include_qa=True):
    from PIL import features
    import shapely
    v = {"python": platform.python_version()}
    v.update(rsvg_versions())
    v.update(cwebp_versions())
    for dist in ("numpy", "pillow", "shapely", "svgpathtools", "fonttools") + (("scipy", "scikit-image") if include_qa
                                                                                else ()):
        v[dist] = importlib.metadata.version(dist)
    v["zlib (pillow)"] = features.version("zlib")
    v["geos (shapely)"] = shapely.geos_version_string
    return v


def compare(path, include_qa=True):
    """-> list of 'name: recorded X, local Y' lines (empty when identical or nothing is recorded)."""
    try:
        recorded = json.loads(path.read_text())["versions"]
    except FileNotFoundError:
        return [f"{path.name} missing: run build.py --record-toolchain"]
    now = current(include_qa)
    keys = [k for k in recorded if include_qa or k not in ("scipy", "scikit-image")]
    return [f"{k}: recorded {recorded.get(k)}, local {now.get(k)}" for k in keys if recorded.get(k) != now.get(k)]


def warn(diffs, stream):
    if not diffs:
        return
    bar = "!" * 100
    print(f"\n{bar}\n!! WARNING: local toolchain differs from toolchain.json - outputs may change by a few bytes or "
          f"pixels.\n!! Run qa.py (golden check) to see whether anything changed. A deliberate upgrade: "
          f"build.py --record-toolchain,\n!! rebuild, review the visual diff, commit toolchain.json + the assets "
          f"together.", file=stream)
    for d in diffs:
        print(f"!!   {d}", file=stream)
    print(f"{bar}\n", file=stream)


def record(path, machine_note):
    data = {"note": "Toolchain the committed badge art was built with (tools/badge-art/build.py "
                    "--record-toolchain). build.py warns when the local versions differ.",
            "machine": machine_note, "versions": current(True)}
    path.write_text(json.dumps(data, indent=1) + "\n")
