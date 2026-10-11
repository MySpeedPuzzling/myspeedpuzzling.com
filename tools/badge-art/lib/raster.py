"""Rasterisation: every size is rendered from the SVG at that size by rsvg-convert (never downscaled from a big
raster - small sizes keep crisp, pixel-snapped outlines), then encoded:
  WebP  (site) cwebp -q 90 -m 6 -alpha_q 100 -sharp_yuv: lossy colour with exact (lossless) alpha. sharp_yuv keeps
        saturated 1-3 px details (the T5 crown's gold tips, band and coral gem) that plain 4:2:0 lossy WebP greys at
        96/144 px; at display size the result is indistinguishable from lossless (README "WebP") at ~half the bytes.
        Pillow cannot do sharp_yuv, hence the cwebp CLI (libwebp's reference encoder, recorded in toolchain.json).
  PNG   (e-mail) Pillow optimize=True (zlib level 9), 8-bit RGBA, no metadata chunks - always lossless.
Pipes only (stdin/stdout): no temporary files anywhere. Both encoders are deterministic for a given version.
"""
import io
import subprocess

from PIL import Image


def rsvg_png(svg_bytes, px):
    r = subprocess.run(["rsvg-convert", "-w", str(px), "-h", str(px)], input=svg_bytes, capture_output=True)
    if r.returncode != 0:
        raise RuntimeError(f"rsvg-convert failed ({r.returncode}): {r.stderr.decode(errors='replace').strip()}")
    return r.stdout


def decode_rgba(png_bytes):
    im = Image.open(io.BytesIO(png_bytes))
    im.load()
    return im.convert("RGBA")


def render_rgba(svg_bytes, px):
    return decode_rgba(rsvg_png(svg_bytes, px))


WEBP_ARGS = ["-q", "90", "-m", "6", "-alpha_q", "100", "-sharp_yuv"]


def webp_sharp(png_bytes):
    """Site WebP from an rsvg PNG: lossy colour (q90, method 6, sharp RGB->YUV), lossless alpha."""
    r = subprocess.run(["cwebp", "-quiet", *WEBP_ARGS, "-o", "-", "--", "-"], input=png_bytes, capture_output=True)
    if r.returncode != 0 or not r.stdout:
        raise RuntimeError(f"cwebp failed ({r.returncode}): {r.stderr.decode(errors='replace').strip()}")
    return r.stdout


def png_optimized(im):
    buf = io.BytesIO()
    im.save(buf, "PNG", optimize=True)
    return buf.getvalue()
