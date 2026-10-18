"""Apple Vision analyzers (pyobjc, on-device, Neural Engine): `ocr` and `barcode` on the full image, `vision_labels`
on the artwork crop (image-taxonomy.md §11.1). Never in the same phase as a GPU-saturating stage on one machine
(0.13 -> 14.8 s/img when they shared the GPU, §19).

Coordinates: Vision's boxes have the origin bottom-left; payloads use top-left (x, y, w, h fractions).
"""

from __future__ import annotations

import platform
from dataclasses import dataclass
from typing import Any, Sequence

import numpy as np

from ..imaging import bbox_to_xyxy, to_png_bytes
from .base import Prepared, Stage

OCR_MAX_LINES = 400
LABELS_MAX = 40
LABEL_MIN_CONFIDENCE = 0.1


@dataclass
class OcrLine:
    text: str
    confidence: float
    box: tuple[float, float, float, float]  # Vision: x, y (bottom-left origin), w, h


def to_top_left(box: Sequence[float]) -> list[float]:
    x, y, w, h = (float(v) for v in box)
    return [round(x, 4), round(max(0.0, 1.0 - y - h), 4), round(w, 4), round(h, 4)]


def centre_inside(b: Sequence[float], crop_xyxy: Sequence[float]) -> bool:
    cx, cy = b[0] + b[2] / 2, b[1] + b[3] / 2
    return crop_xyxy[0] <= cx <= crop_xyxy[2] and crop_xyxy[1] <= cy <= crop_xyxy[3]


def text_area_share(lines: list[dict[str, Any]], crop_xyxy: Sequence[float], cells: int = 200) -> float:
    """Share of the artwork crop covered by boxes of text lines whose centre lies inside it."""
    x0, y0, x1, y1 = crop_xyxy
    cw, ch = max(1e-6, x1 - x0), max(1e-6, y1 - y0)
    mask = np.zeros((cells, cells), np.uint8)
    for line in lines:
        b = line["b"]
        if not centre_inside(b, crop_xyxy):
            continue
        lx0, ly0 = (b[0] - x0) / cw, (b[1] - y0) / ch
        lx1, ly1 = (b[0] + b[2] - x0) / cw, (b[1] + b[3] - y0) / ch
        mask[max(0, int(ly0 * cells)):max(0, int(ly1 * cells)) + 1, max(0, int(lx0 * cells)):max(0, int(lx1 * cells)) + 1] = 1
    return round(float(mask.mean()), 4)


def ocr_payload(lines: list[OcrLine], artwork: dict[str, Any] | None, languages: list[str]) -> dict[str, Any]:
    crop_xyxy = bbox_to_xyxy(artwork["bbox"]) if artwork and artwork.get("bbox") else None
    out = []
    for line in lines:
        text = line.text.replace("\x00", "").strip()
        if not text:
            continue
        b = to_top_left(line.box)
        out.append({"t": text, "c": round(float(line.confidence), 2), "b": b,
                    "inArtwork": centre_inside(b, crop_xyxy) if crop_xyxy else None})
    out.sort(key=lambda d: -(d["b"][2] * d["b"][3]))
    truncated = len(out) > OCR_MAX_LINES
    out = out[:OCR_MAX_LINES]
    return {
        "languages": languages,
        "lines": out,
        "truncated": truncated,
        "textAreaShareInArtwork": text_area_share(out, crop_xyxy) if crop_xyxy else None,
    }


def gtin_ok(digits: str) -> bool:
    if not digits.isdigit() or len(digits) not in (8, 12, 13, 14):
        return False
    body, check = digits[:-1], int(digits[-1])
    total = sum(int(d) * (3 if i % 2 == 0 else 1) for i, d in enumerate(reversed(body)))
    return (10 - total % 10) % 10 == check


def barcode_payload(codes: list[tuple[str, str, Sequence[float]]]) -> dict[str, Any]:
    """codes: (symbology as Vision names it, payload string, Vision box)."""
    out = []
    seen = set()
    for sym, value, box in codes:
        sym = sym.replace("VNBarcodeSymbology", "").upper() or "UNKNOWN"
        value = (value or "").replace("\x00", "").strip()
        if not value or (sym, value) in seen:
            continue
        seen.add((sym, value))
        is_gtin = sym in ("EAN13", "EAN8", "UPCE", "UPCA", "ITF14", "GTIN")
        out.append({"sym": sym, "v": value, "b": to_top_left(box), "checksumOk": gtin_ok(value) if is_gtin else None, "reader": "apple"})
    return {"codes": out}


def vision_labels_payload(labels: list[tuple[str, float]], faces: list[tuple[float, float]], humans: int,
                          saliency: list[Sequence[float]]) -> dict[str, Any]:
    """labels: (identifier, confidence); faces: (area share, confidence); saliency: Vision boxes of salient objects."""
    kept = sorted(((str(i), round(float(c), 3)) for i, c in labels if c >= LABEL_MIN_CONFIDENCE), key=lambda x: -x[1])[:LABELS_MAX]
    sal = None
    if saliency:
        boxes = [to_top_left(b) for b in saliency]
        x0 = min(b[0] for b in boxes)
        y0 = min(b[1] for b in boxes)
        x1 = max(b[0] + b[2] for b in boxes)
        y1 = max(b[1] + b[3] for b in boxes)
        sal = {"bbox": [round(x0, 4), round(y0, 4), round(x1 - x0, 4), round(y1 - y0, 4)],
               "areaShare": round(min(1.0, sum(b[2] * b[3] for b in boxes)), 4)}
    return {
        "labels": [[i, c] for i, c in kept],
        "faces": [{"area": round(float(a), 4), "c": round(float(c), 2)} for a, c in faces],
        "humans": int(humans),
        "saliency": sal,
    }


def macos() -> str:
    return platform.mac_ver()[0] or "unknown"


# --- pyobjc glue (only on a Mac with the ml extra) --------------------------------------------------------------------
def _handler(png: bytes) -> Any:
    import Vision  # type: ignore[import-not-found]
    from Foundation import NSData  # type: ignore[import-not-found]

    return Vision.VNImageRequestHandler.alloc().initWithData_options_(NSData.dataWithBytes_length_(png, len(png)), None)


def _box(o: Any) -> tuple[float, float, float, float]:
    b = o.boundingBox()
    return (float(b.origin.x), float(b.origin.y), float(b.size.width), float(b.size.height))


def detect_languages(text: str) -> list[str]:
    try:
        from NaturalLanguage import NLLanguageRecognizer  # type: ignore[import-not-found]
    except Exception:  # noqa: BLE001
        return []
    if not text.strip():
        return []
    rec = NLLanguageRecognizer.alloc().init()
    rec.processString_(text)
    hyp = rec.languageHypothesesWithMaximum_(3) or {}
    return [str(k) for k, v in sorted(dict(hyp).items(), key=lambda kv: -float(kv[1])) if float(v) >= 0.2]


class OcrStage(Stage):
    analyzer = "ocr"
    optional_upstream = ("artwork",)  # only when already cached: ocr runs before artwork exists on the M1

    @property
    def model(self) -> str:
        return f"apple-vision/VNRecognizeTextRequest/rev3/accurate/macOS{macos()}"

    def analyse(self, p: Prepared) -> dict[str, Any]:
        import Vision  # type: ignore[import-not-found]

        req = Vision.VNRecognizeTextRequest.alloc().init()
        req.setRecognitionLevel_(Vision.VNRequestTextRecognitionLevelAccurate)
        req.setUsesLanguageCorrection_(True)
        req.setAutomaticallyDetectsLanguage_(True)
        _handler(to_png_bytes(p.rgb)).performRequests_error_([req], None)
        lines = []
        for o in req.results() or []:
            cand = o.topCandidates_(1)
            if cand:
                lines.append(OcrLine(str(cand[0].string()), float(cand[0].confidence()), _box(o)))
        return ocr_payload(lines, p.upstream.get("artwork"), detect_languages(" ".join(line.text for line in lines)))


class BarcodeStage(Stage):
    analyzer = "barcode"

    @property
    def model(self) -> str:
        return f"apple-vision/VNDetectBarcodesRequest/macOS{macos()}"

    def analyse(self, p: Prepared) -> dict[str, Any]:
        import Vision  # type: ignore[import-not-found]

        req = Vision.VNDetectBarcodesRequest.alloc().init()
        _handler(to_png_bytes(p.rgb)).performRequests_error_([req], None)
        return barcode_payload([(str(o.symbology()), str(o.payloadStringValue() or ""), _box(o)) for o in req.results() or []])


class VisionLabelsStage(Stage):
    analyzer = "vision_labels"
    upstream = ("artwork",)
    uses_crop = True

    @property
    def model(self) -> str:
        return f"apple-vision/VNClassifyImageRequest+faces+humans+attention-saliency/macOS{macos()}"

    def analyse(self, p: Prepared) -> dict[str, Any]:
        import Vision  # type: ignore[import-not-found]

        cls = Vision.VNClassifyImageRequest.alloc().init()
        face = Vision.VNDetectFaceRectanglesRequest.alloc().init()
        hum = Vision.VNDetectHumanRectanglesRequest.alloc().init()
        sal = Vision.VNGenerateAttentionBasedSaliencyImageRequest.alloc().init()
        _handler(to_png_bytes(p.subject)).performRequests_error_([cls, face, hum, sal], None)
        labels = [(str(o.identifier()), float(o.confidence())) for o in cls.results() or []]
        faces = [(_box(o)[2] * _box(o)[3], float(o.confidence())) for o in face.results() or []]
        humans = len(hum.results() or [])
        salient: list[tuple[float, float, float, float]] = []
        for o in sal.results() or []:
            for s in o.salientObjects() or []:
                salient.append(_box(s))
        return vision_labels_payload(labels, faces, humans, salient)
