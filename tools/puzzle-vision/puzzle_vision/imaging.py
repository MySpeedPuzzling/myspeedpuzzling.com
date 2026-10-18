"""Image decoding, normalisation and the artwork crop (ported from the vision lab: lab.load, crop.py)."""

from __future__ import annotations

import io
import os
import subprocess
import tempfile
from typing import Any, Sequence

import cv2
import numpy as np
from PIL import Image, ImageOps

Image.MAX_IMAGE_PIXELS = 200_000_000  # some originals are 7,700 px on the long side


class UndecodableImage(ValueError):
    pass


def decode(data: bytes) -> Image.Image:
    """Pillow first; HEIC through pillow-heif when installed, else macOS `sips` (ImageIO decodes HEIC/AVIF)."""
    try:
        im = Image.open(io.BytesIO(data))
        im.load()
        return im
    except Exception as first:  # noqa: BLE001 - try the fallbacks
        try:
            import pillow_heif  # type: ignore[import-not-found]

            pillow_heif.register_heif_opener()
            im = Image.open(io.BytesIO(data))
            im.load()
            return im
        except Exception:  # noqa: BLE001
            pass
        with tempfile.TemporaryDirectory() as tmp:
            src, dst = os.path.join(tmp, "in"), os.path.join(tmp, "out.png")
            with open(src, "wb") as f:
                f.write(data)
            try:
                subprocess.run(["sips", "-s", "format", "png", src, "--out", dst], capture_output=True, check=True, timeout=60)
                im = Image.open(dst)
                im.load()
                return im
            except Exception as e:  # noqa: BLE001
                raise UndecodableImage(f"cannot decode image: {first}") from e


def normalise(im: Image.Image) -> Image.Image:
    """EXIF orientation applied (coordinates are fractions of the displayed image), alpha flattened on white, RGB."""
    im = ImageOps.exif_transpose(im)
    if im.mode in ("RGBA", "LA", "P", "PA"):
        im = im.convert("RGBA")
        bg = Image.new("RGBA", im.size, (255, 255, 255, 255))
        bg.alpha_composite(im)
        im = bg
    return im.convert("RGB")


def fit(im: Image.Image, max_side: int) -> Image.Image:
    if max(im.size) > max_side:
        im = im.copy()
        im.thumbnail((max_side, max_side), Image.LANCZOS)
    return im


def load_rgb(path: str, max_side: int | None = None) -> np.ndarray:
    with Image.open(path) as im:
        im = normalise(im)
        if max_side:
            im = fit(im, max_side)
        return np.asarray(im)


# --- geometry -------------------------------------------------------------------------------------------------------
def iou(a: Sequence[float], b: Sequence[float]) -> float:
    """IoU of two [x0, y0, x1, y1] boxes."""
    ix = max(0.0, min(a[2], b[2]) - max(a[0], b[0]))
    iy = max(0.0, min(a[3], b[3]) - max(a[1], b[1]))
    inter = ix * iy
    union = (a[2] - a[0]) * (a[3] - a[1]) + (b[2] - b[0]) * (b[3] - b[1]) - inter
    return inter / union if union > 0 else 0.0


def quad_to_box(q: Sequence[Sequence[float]]) -> list[float]:
    arr = np.asarray(q, dtype=float)
    return [float(arr[:, 0].min()), float(arr[:, 1].min()), float(arr[:, 0].max()), float(arr[:, 1].max())]


def xyxy_to_bbox(b: Sequence[float]) -> dict[str, float]:
    x0, y0, x1, y1 = (min(1.0, max(0.0, float(v))) for v in b)
    return {"x": round(x0, 5), "y": round(y0, 5), "w": round(max(0.0, x1 - x0), 5), "h": round(max(0.0, y1 - y0), 5)}


def bbox_to_xyxy(b: dict[str, float]) -> list[float]:
    return [b["x"], b["y"], b["x"] + b["w"], b["y"] + b["h"]]


def is_skewed(quad: Sequence[Sequence[float]], tol: float = 0.02) -> bool:
    q = np.asarray(quad, float)
    return bool(abs(q[0, 1] - q[1, 1]) > tol or abs(q[3, 1] - q[2, 1]) > tol
                or abs(q[0, 0] - q[3, 0]) > tol or abs(q[1, 0] - q[2, 0]) > tol)


def crop(rgb: np.ndarray, box: Sequence[float], quad: Sequence[Sequence[float]] | None = None, inset: float = 0.01) -> tuple[np.ndarray, str]:
    """The artwork crop: perspective warp for a skewed quad, else the box (inset 1 % against box-edge remnants)."""
    h, w = rgb.shape[:2]
    if quad is not None and len(quad) == 4 and is_skewed(quad):
        q = np.asarray(quad, np.float32) * [w, h]
        c = q.mean(0)
        q = c + (q - c) * (1 - 2 * inset)
        tw = int(max(np.linalg.norm(q[1] - q[0]), np.linalg.norm(q[2] - q[3])))
        th = int(max(np.linalg.norm(q[3] - q[0]), np.linalg.norm(q[2] - q[1])))
        if tw >= 16 and th >= 16:
            m = cv2.getPerspectiveTransform(q.astype(np.float32), np.float32([[0, 0], [tw, 0], [tw, th], [0, th]]))
            return cv2.warpPerspective(rgb, m, (tw, th), flags=cv2.INTER_AREA), "perspective"
    x0, y0, x1, y1 = box
    dx, dy = (x1 - x0) * inset, (y1 - y0) * inset
    X0, Y0 = int((x0 + dx) * w), int((y0 + dy) * h)
    X1, Y1 = int(np.ceil((x1 - dx) * w)), int(np.ceil((y1 - dy) * h))
    if X1 - X0 < 16 or Y1 - Y0 < 16:
        return rgb, "full"
    return rgb[Y0:Y1, X0:X1], "box"


def artwork_crop(rgb: np.ndarray, artwork: dict[str, Any] | None) -> tuple[np.ndarray, dict[str, Any] | None]:
    """Crop by a stored `artwork` payload; answers the crop and the envelope's `crop` field (bbox/corners used)."""
    if not artwork or not artwork.get("bbox"):
        return rgb, None
    box = bbox_to_xyxy(artwork["bbox"])
    corners = artwork.get("corners")
    if corners and isinstance(corners[0], dict):
        corners = [[c["x"], c["y"]] for c in corners]
    out, how = crop(rgb, box, corners)
    return out, {"bbox": artwork["bbox"], "corners": corners, "how": how}


def to_png_bytes(rgb: np.ndarray) -> bytes:
    buf = io.BytesIO()
    Image.fromarray(rgb).save(buf, "PNG")
    return buf.getvalue()


def to_jpeg_bytes(rgb: np.ndarray, quality: int = 92) -> bytes:
    buf = io.BytesIO()
    Image.fromarray(rgb).save(buf, "JPEG", quality=quality, subsampling=0, optimize=True)
    return buf.getvalue()
