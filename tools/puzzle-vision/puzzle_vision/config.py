"""Settings: base URL, token, local directories, worker id.

The internal API token lives in the macOS Keychain (`security add-generic-password -s msp-internal-api -a "$USER" -w`)
and is read **at call time** for every request - never cached in a file, never printed, never logged. The env var
MSP_INTERNAL_API_TOKEN overrides it for one-off runs.
"""

from __future__ import annotations

import getpass
import os
import socket
import subprocess
from dataclasses import dataclass, field
from pathlib import Path
from typing import Callable

DEFAULT_BASE_URL = "https://myspeedpuzzling.com"
KEYCHAIN_SERVICE = "msp-internal-api"
TOKEN_ENV = "MSP_INTERNAL_API_TOKEN"


class TokenMissing(RuntimeError):
    """The message never contains a token."""


def keychain_command(account: str | None = None) -> list[str]:
    return ["security", "find-generic-password", "-s", KEYCHAIN_SERVICE, "-a", account or getpass.getuser(), "-w"]


def read_token(run: Callable[..., subprocess.CompletedProcess] = subprocess.run) -> str:
    env = os.environ.get(TOKEN_ENV, "").strip()
    if env:
        return env
    try:
        proc = run(keychain_command(), capture_output=True, text=True, check=False)
    except FileNotFoundError as e:  # not a Mac
        raise TokenMissing(f"`security` not available and {TOKEN_ENV} not set") from e
    token = (proc.stdout or "").strip()
    if proc.returncode != 0 or not token:
        raise TokenMissing(
            f"no internal API token: add it with `security add-generic-password -s {KEYCHAIN_SERVICE} -a \"$USER\" -w` "
            f"(paste it at the prompt) or set {TOKEN_ENV} for one run"
        )
    return token


def env_token_only() -> str:
    token = os.environ.get(TOKEN_ENV, "").strip()
    if not token:
        raise TokenMissing(f"a --base-url other than {DEFAULT_BASE_URL} takes the token from {TOKEN_ENV} only - "
                           "the production token in the Keychain is never sent anywhere else")
    return token


def token_provider_for(base_url: str) -> Callable[[], str]:
    """The Keychain (production) token goes to production only; any other base URL (a local dev API) needs the env var."""
    return read_token if base_url.rstrip("/") == DEFAULT_BASE_URL else env_token_only


def token_present() -> bool:
    try:
        read_token()
    except TokenMissing:
        return False
    return True


def _home() -> Path:
    return Path(os.environ.get("PUZZLE_VISION_HOME") or Path.home())


@dataclass
class Settings:
    base_url: str = DEFAULT_BASE_URL
    profile_name: str = "m3-max"
    cache_dir: Path = field(default_factory=lambda: _home() / "Library/Caches/puzzle-vision")
    state_dir: Path = field(default_factory=lambda: _home() / "Library/Application Support/puzzle-vision")
    log_dir: Path = field(default_factory=lambda: _home() / "Library/Logs/puzzle-vision")
    token_provider: Callable[[], str] = read_token

    def __repr__(self) -> str:  # never anything token-like in a repr
        return f"Settings(base_url={self.base_url!r}, profile={self.profile_name!r}, cache_dir={str(self.cache_dir)!r})"

    @property
    def images_dir(self) -> Path:
        return self.cache_dir / "images"

    @property
    def workstate_path(self) -> Path:
        return self.state_dir / "workstate.sqlite"

    def ensure_dirs(self) -> None:
        for d in (self.cache_dir, self.images_dir, self.state_dir, self.log_dir):
            d.mkdir(parents=True, exist_ok=True)

    @property
    def worker_id(self) -> str:
        return worker_id(self.profile_name)


def worker_id(profile_name: str, hostname: str | None = None) -> str:
    """`<hostname>-<profile>`, at most 64 characters (the claim API's limit)."""
    host = (hostname or socket.gethostname()).split(".", 1)[0].lower() or "mac"
    wid = f"{host}-{profile_name}"
    return wid[-64:]
