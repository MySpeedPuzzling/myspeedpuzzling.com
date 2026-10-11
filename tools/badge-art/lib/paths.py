"""Every path the pipeline touches, resolved explicitly and checked - no path is derived silently.

TOOL is this tool's directory (tools/badge-art); REPO is the repository root, which must be exactly two levels above
it and contain composer.json + src/Value/BadgeType.php. If the tool is copied anywhere else, every command stops with
an error instead of writing into a wrong place. Outputs go only to the directories named here or to an explicit
--out given on the command line; scratch work goes to a TemporaryDirectory (removed afterwards) or WORK.
"""
from pathlib import Path

TOOL = Path(__file__).resolve().parent.parent
REPO = TOOL.parent.parent

BASES = TOOL / "bases"                       # frozen frame bases (committed) + bases.json declaration
ICONS_SVG = TOOL / "icons" / "svg"           # generated icon SVG sources (committed)
TOOLCHAIN = TOOL / "toolchain.json"          # reference toolchain the committed artifacts were built with
WORK = TOOL / "work"                         # gitignored: QA report, contact sheet, previews
OUT = REPO / "public" / "img" / "badges"     # generated badge art (committed)
BADGE_TYPE_PHP = REPO / "src" / "Value" / "BadgeType.php"


def check_layout(need_bases=True):
    """Fail loudly unless tools/badge-art sits in the MySpeedPuzzling repository at tools/badge-art."""
    need = [REPO / "composer.json", BADGE_TYPE_PHP] + ([BASES / "bases.json"] if need_bases else [])
    missing = [p for p in need if not p.is_file()]
    if TOOL.name != "badge-art" or TOOL.parent.name != "tools" or missing:
        raise SystemExit(
            "badge-art: unexpected location.\n"
            f"  tool dir  : {TOOL}\n  repo root : {REPO}\n"
            + "".join(f"  missing   : {p}\n" for p in missing)
            + "  tools/badge-art must live at <repo>/tools/badge-art of the MySpeedPuzzling repository.")


def rel(p):
    """Repository-relative display path (absolute when outside the repository, e.g. a temp dir)."""
    p = Path(p).resolve()
    try:
        return str(p.relative_to(REPO))
    except ValueError:
        return str(p)
