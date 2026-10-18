"""`setup-check`: Python / uv, free disk, the ml extra installed, the profile's models downloaded (looked up in the
Hugging Face cache - never loaded), the token present (never printed), the API reachable, memory."""

from __future__ import annotations

import importlib.util
import os
import shutil
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Callable

from .analyzers import INFO
from .config import Settings, TokenMissing
from .lock import free_memory_percent
from .profiles import Profile

MIN_FREE_DISK_GB = 60
ML_MODULES = ("mlx", "mlx_vlm", "llguidance", "torch", "transformers", "Vision", "NaturalLanguage")
# download sizes (GB) for the runbook and the check's message
MODEL_SIZES_GB = {
    "google/siglip2-so400m-patch14-384": 4.5,
    "mlx-community/Qwen3-VL-4B-Instruct-4bit": 3.1,
    "mlx-community/Qwen3.5-9B-MLX-4bit": 6.0,
    "facebook/dinov2-large": 1.2,
    "tue-mps/coco_panoptic_eomt_large_640": 1.3,
}


@dataclass
class Check:
    name: str
    ok: bool
    detail: str

    def line(self) -> str:
        return f"{'PASS' if self.ok else 'FAIL'}  {self.name}: {self.detail}"


def hf_cache() -> Path:
    if os.environ.get("HF_HUB_CACHE"):
        return Path(os.environ["HF_HUB_CACHE"])
    if os.environ.get("HF_HOME"):
        return Path(os.environ["HF_HOME"]) / "hub"
    return Path.home() / ".cache" / "huggingface" / "hub"


def model_downloaded(repo: str, cache: Path | None = None) -> bool:
    snapshots = (cache or hf_cache()) / f"models--{repo.replace('/', '--')}" / "snapshots"
    if not snapshots.is_dir():
        return False
    for snap in snapshots.iterdir():
        files = [f.name for f in snap.iterdir()] if snap.is_dir() else []
        if any(n.endswith((".safetensors", ".bin")) for n in files) and "config.json" in files:
            return True
    return False


def profile_models(profile: Profile) -> list[str]:
    runs = {a for ph in profile.phases for a in ph.stages + ph.sidecar}
    models: list[str] = []
    for a in sorted(runs):
        for m in INFO[a].models:
            if m not in models:
                models.append(m)
    if profile.derived_versions and "google/siglip2-so400m-patch14-384" not in models and profile.derive_after_daily:
        models.append("google/siglip2-so400m-patch14-384")  # zeroshot's text encoder
    return models


def run_checks(profile: Profile, settings: Settings, api_factory: Callable[[], Any] | None,
               cache: Path | None = None, skip_api: bool = False) -> list[Check]:
    out = [Check("python", sys.version_info[:2] == (3, 12), sys.version.split()[0])]
    uv = shutil.which("uv")
    out.append(Check("uv", uv is not None, uv or "not on PATH (brew install uv)"))
    settings.cache_dir.mkdir(parents=True, exist_ok=True)
    free_gb = shutil.disk_usage(settings.cache_dir).free / 1e9
    out.append(Check("free disk", free_gb >= MIN_FREE_DISK_GB, f"{free_gb:.0f} GB free at {settings.cache_dir} (need {MIN_FREE_DISK_GB} GB for the image cache + models)"))
    missing_mods = [m for m in ML_MODULES if importlib.util.find_spec(m) is None]
    out.append(Check("ml extra", not missing_mods, "installed" if not missing_mods else f"missing {', '.join(missing_mods)} - run `uv sync --extra ml`"))
    for repo in profile_models(profile):
        ok = model_downloaded(repo, cache)
        out.append(Check(f"model {repo}", ok, "downloaded" if ok else f"missing (~{MODEL_SIZES_GB.get(repo, '?')} GB): uv run --extra ml hf download {repo}"))
    try:
        settings.token_provider()
        out.append(Check("token", True, "present (Keychain msp-internal-api or MSP_INTERNAL_API_TOKEN)"))
        token_ok = True
    except TokenMissing as e:
        out.append(Check("token", False, str(e)))
        token_ok = False
    if not skip_api and token_ok and api_factory is not None:
        try:
            api = api_factory()
            api.list_images(limit=1)
            out.append(Check("API", True, f"{settings.base_url}/internal-api/puzzle-images answers 200"))
        except Exception as e:  # noqa: BLE001
            out.append(Check("API", False, f"{settings.base_url}: {type(e).__name__}: {e}"))
    free = free_memory_percent()
    out.append(Check("memory", free >= 40, f"{free} % free (stages wait for >= 40 %)"))
    return out
