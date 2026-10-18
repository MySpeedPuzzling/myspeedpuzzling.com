"""launchd job per profile from launchd/com.myspeedpuzzling.puzzle-vision.plist.tmpl: logs to
~/Library/Logs/puzzle-vision/, KeepAlive + `run --follow` on the M1, a nightly `run --daily --hours N` window on the M3.
The token is never in the plist - the job reads it from the Keychain."""

from __future__ import annotations

import shutil
from pathlib import Path
from xml.sax.saxutils import escape

from .profiles import Profile

TOOL_DIR = Path(__file__).resolve().parent.parent
TEMPLATE = TOOL_DIR / "launchd" / "com.myspeedpuzzling.puzzle-vision.plist.tmpl"


def label(profile: Profile) -> str:
    return f"com.myspeedpuzzling.puzzle-vision.{profile.name}"


def arguments(profile: Profile, uv: str) -> list[str]:
    args = [uv, "run", "--extra", "ml", "puzzle-vision", "run", "--profile", profile.name]
    cfg = profile.launchd
    if cfg.get("mode") == "daily":
        args += ["--daily"]
        if cfg.get("hours"):
            args += ["--hours", str(cfg["hours"])]
    else:
        args += ["--follow"]
    return args


def schedule(profile: Profile) -> str:
    cfg = profile.launchd
    if cfg.get("keep_alive"):
        return "<key>KeepAlive</key>\n    <true/>\n    <key>RunAtLoad</key>\n    <true/>"
    return ("<key>StartCalendarInterval</key>\n    <dict>\n"
            f"        <key>Hour</key>\n        <integer>{int(cfg.get('start_hour', 1))}</integer>\n"
            f"        <key>Minute</key>\n        <integer>{int(cfg.get('start_minute', 30))}</integer>\n"
            "    </dict>\n    <key>RunAtLoad</key>\n    <false/>")


def render(profile: Profile, log_dir: Path, uv: str | None = None, tool_dir: Path = TOOL_DIR) -> str:
    uv = uv or shutil.which("uv") or "/opt/homebrew/bin/uv"
    args = "\n".join(f"        <string>{escape(a)}</string>" for a in arguments(profile, uv))
    path = ":".join(dict.fromkeys([str(Path(uv).parent), "/opt/homebrew/bin", "/usr/local/bin", "/usr/bin", "/bin", "/usr/sbin", "/sbin"]))
    text = TEMPLATE.read_text()
    for key, value in {
        "{{LABEL}}": label(profile),
        "{{ARGUMENTS}}": args,
        "{{WORKDIR}}": escape(str(tool_dir)),
        "{{LOG_OUT}}": escape(str(log_dir / f"{profile.name}.out.log")),
        "{{LOG_ERR}}": escape(str(log_dir / f"{profile.name}.err.log")),
        "{{PATH}}": escape(path),
        "{{SCHEDULE}}": schedule(profile),
    }.items():
        text = text.replace(key, value)
    return text


def install_path(profile: Profile) -> Path:
    return Path.home() / "Library" / "LaunchAgents" / f"{label(profile)}.plist"
