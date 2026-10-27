"""SAM 2.1 (Apache-2.0) as an extra hypothesis for the box face pass (artwork 1.2, review proposal 7).

The face pass asks the VLM for the picture's box on the straightened face; a side with no colour step under the VLM's
line is the risky one (cut 13 % of the time, review F2) - 1.2 holds it at the first pass's line (NO_EDGE_FALLBACK),
which leaves more box print in. SAM 2.1, prompted with the VLM's box, answers a mask of "the thing in that box"; where
its edge, a colour step and the VLM's line agree within SAM_AGREE of the face, the VLM's line is believed after all.
SAM never decides alone: on a box its mask can follow the cardboard, not the printed band (the risk the review named).

Only the per-side edge positions of the mask are kept (`mask_edges`, pure numpy - tested without the model), so the
eval memo and the stored payload stay small.
"""

from __future__ import annotations

import os
from typing import Any, Sequence

import numpy as np

SAM2 = "facebook/sam2.1-hiera-small"  # Apache-2.0, 46 M parameters; the face is <= 1024 px, SAM works at 1024
EDGE_BAND = (0.08, 0.92)              # the middle of each side (corners are rounded / covered by badges)


def mask_edges(mask: np.ndarray, band: tuple[float, float] = EDGE_BAND) -> dict[str, Any] | None:
    """Per side of a face-sized boolean mask: where its edge lies, as a trim of that side (0..1 of the face - the
    median over the side's middle `band` of the first masked pixel coming in from that side), and how straight it is
    (`spread` = the interquartile range of those positions, 0..1). None for an empty mask."""
    m = np.asarray(mask, bool)
    if m.ndim != 2 or not m.any():
        return None
    h, w = m.shape
    out: dict[str, Any] = {"area": round(float(m.mean()), 4)}
    spread: dict[str, float] = {}
    for side in ("left", "top", "right", "bottom"):
        r = {"left": m, "top": m.T, "right": m[:, ::-1], "bottom": m[::-1].T}[side]  # rows = positions along the side
        n_along, depth = r.shape
        lo, hi = int(band[0] * n_along), max(int(band[0] * n_along) + 1, int(band[1] * n_along))
        rows = r[lo:hi]
        has = rows.any(axis=1)
        if has.mean() < 0.5:
            out[side] = None  # the mask does not reach across most of this side
            continue
        first = np.argmax(rows[has], axis=1) / depth
        q1, med, q3 = np.percentile(first, [25, 50, 75])
        out[side] = round(float(med), 4)
        spread[side] = round(float(q3 - q1), 4)
    out["spread"] = spread
    return out


class SamMasker:
    """SAM 2.1 through transformers (`Sam2Model`), on MPS when there is one - the stage runs under the model lock like
    every GPU stage. `PUZZLE_VISION_SAM_DEVICE=cpu` forces the CPU (a small test next to a running GPU job)."""

    def __init__(self, model_id: str = SAM2) -> None:
        import torch
        from transformers import Sam2Model, Sam2Processor

        self.torch = torch
        dev = os.environ.get("PUZZLE_VISION_SAM_DEVICE") or ("mps" if torch.backends.mps.is_available() else "cpu")
        self.device = dev
        self.model = Sam2Model.from_pretrained(model_id).to(dev).eval()
        self.proc = Sam2Processor.from_pretrained(model_id)

    def edges(self, face_rgb: np.ndarray, rect: Sequence[float]) -> dict[str, Any] | None:
        """The mask SAM answers for the VLM's box `rect` ([u0, v0, u1, v1], 0..1 of the face) as `mask_edges`, plus
        SAM's own quality estimate `score`."""
        from PIL import Image

        h, w = face_rgb.shape[:2]
        box = [float(rect[0]) * w, float(rect[1]) * h, float(rect[2]) * w, float(rect[3]) * h]
        inputs = self.proc(images=Image.fromarray(face_rgb), input_boxes=[[box]], return_tensors="pt").to(self.device)
        with self.torch.no_grad():
            outputs = self.model(**inputs, multimask_output=False)
        masks = self.proc.post_process_masks(outputs.pred_masks.cpu(), inputs["original_sizes"].cpu())[0]
        mask = masks.reshape(-1, h, w)[0].numpy() > 0
        out = mask_edges(mask)
        if out is not None:
            out["score"] = round(float(outputs.iou_scores.reshape(-1)[0]), 4)
        return out
