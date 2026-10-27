"""The analyzers of the image pipeline and what each needs first (implementation-plan.md §1.2,
image-taxonomy.md §11 + §19). One analyzer = one payload = one push; the API enforces `requires` on claims."""

from __future__ import annotations

from dataclasses import dataclass

RAW = (
    "artwork",
    "ocr",
    "barcode",
    "vision_labels",
    "embed_siglip2",
    "embed_siglip2_full",
    "embed_dinov2",
    "embed_dinov2_patches",
    "segmentation",
    "colors",
    "traits",
    "piece_scale",
    "describe",
)
DERIVED = ("box_text", "zeroshot", "neighbours", "tags", "crop_trust")
ALL = RAW + DERIVED


@dataclass(frozen=True)
class AnalyzerInfo:
    name: str
    requires: tuple[str, ...]
    # gpu = GPU-saturating model (MPS / MLX), ane = Apple Vision (Neural Engine), cpu = numpy / OpenCV
    unit: str
    # which part of the image it looks at: full image or the artwork crop
    image: str
    # repo ids of the models it loads (setup-check looks for them in the Hugging Face cache, never loads them)
    models: tuple[str, ...] = ()


SIGLIP2 = "google/siglip2-so400m-patch14-384"
QWEN3_VL_4B = "mlx-community/Qwen3-VL-4B-Instruct-4bit"
QWEN35_9B = "mlx-community/Qwen3.5-9B-MLX-4bit"
DINOV2_L = "facebook/dinov2-large"
EOMT_L = "tue-mps/coco_panoptic_eomt_large_640"

INFO: dict[str, AnalyzerInfo] = {
    "artwork": AnalyzerInfo("artwork", (), "gpu", "full", (SIGLIP2, QWEN3_VL_4B)),
    "ocr": AnalyzerInfo("ocr", (), "ane", "full"),
    "barcode": AnalyzerInfo("barcode", (), "ane", "full"),
    "embed_siglip2_full": AnalyzerInfo("embed_siglip2_full", (), "gpu", "full", (SIGLIP2,)),
    "colors": AnalyzerInfo("colors", ("artwork",), "cpu", "crop"),
    "traits": AnalyzerInfo("traits", ("artwork",), "cpu", "crop"),
    "piece_scale": AnalyzerInfo("piece_scale", ("artwork",), "cpu", "crop"),
    "segmentation": AnalyzerInfo("segmentation", ("artwork",), "gpu", "crop", (EOMT_L,)),
    "embed_dinov2": AnalyzerInfo("embed_dinov2", ("artwork",), "gpu", "crop", (DINOV2_L,)),
    "embed_dinov2_patches": AnalyzerInfo("embed_dinov2_patches", ("artwork",), "gpu", "crop", (DINOV2_L,)),
    "embed_siglip2": AnalyzerInfo("embed_siglip2", ("artwork",), "gpu", "crop", (SIGLIP2,)),
    "vision_labels": AnalyzerInfo("vision_labels", ("artwork",), "ane", "crop"),
    "describe": AnalyzerInfo("describe", ("artwork", "ocr"), "gpu", "crop", (QWEN35_9B,)),
}

# The dependency table of implementation-plan.md §1.2 - profiles must declare exactly this (tests pin it).
REQUIRES: dict[str, tuple[str, ...]] = {name: info.requires for name, info in INFO.items()}

# Inputs of the derived analyzers (image-taxonomy.md §11.2) - no image touched.
DERIVED_INPUTS: dict[str, tuple[str, ...]] = {
    "box_text": ("ocr", "barcode"),
    "zeroshot": ("embed_siglip2", "embed_siglip2_full"),
    "neighbours": ("embed_siglip2", "embed_siglip2_full", "embed_dinov2"),
    "tags": ("describe", "zeroshot", "vision_labels", "colors", "traits", "segmentation", "ocr", "box_text", "artwork"),
    "crop_trust": ("artwork", "embed_siglip2"),
}

# Expected sustained seconds per image on the M3 Max (image-taxonomy.md §19) - `bench` compares against these (±30 %).
M3_SECONDS_PER_IMAGE: dict[str, float] = {
    "artwork": 2.0,
    "artwork-gate": 0.11,
    "ocr": 0.134,
    "barcode": 0.134,
    "vision_labels": 0.134,
    "embed_siglip2": 0.18,
    "embed_siglip2_full": 0.18,
    "embed_dinov2": 0.035,
    "embed_dinov2_patches": 0.035,
    "segmentation": 0.31,
    "colors": 0.078,
    "traits": 0.078,
    "piece_scale": 0.17,  # 0.67 CPU-s an image (2.3 piece counts) on one core, 4 workers
    "describe": 10.75,
}


def schema_major(version: str) -> int:
    """`1.0.0+qwen35-9b-4bit` -> 1 (the payload's `schema` field)."""
    head = version.split("+", 1)[0]
    try:
        return int(head.split(".", 1)[0])
    except ValueError:
        return 1
