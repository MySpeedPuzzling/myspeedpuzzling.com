"""THE badge registry - the ONE place that says what gets built.

One line per badge type: the BadgeType enum value (src/Value/BadgeType.php, = the output file stem), the icon name
(= icons/svg/<icon>-<colourway>.svg), its drawing function in icons/draw.py, and its kind:
  TIERED   five files <type>-t1 ... <type>-t5 on the frozen F1 tier bases (BadgeTier 1..5); the icon accent takes
           the tier tint (IK1 colourways t1..t5);
  SINGLE   one file <type>-single on the F1S-A ribbon-seal one-off base in `set_colour` (coral | sky | lavender |
           teal, frames/f1s.py SETS); the icon accent is `accent` (same choices; default = the set colour - owner:
           chosen per badge by its meaning, but trying to follow the set colour). Coral = the house -msp colourway.
The build is strict: the set of types must equal the cases of BadgeType, the SINGLE types must be exactly the ones
BadgeType::isTiered() excludes, an icon name always means one drawing function (several badges MAY share an icon,
e.g. a one-off set reusing a tiered icon), and nothing else in the pipeline lists icons or types.

Add a badge: draw a function in icons/draw.py, add ONE line here, run build.py and qa.py (README "Add a badge").
"""
from dataclasses import dataclass
from typing import Callable, Optional

from frames.f1s import SETS
from icons import draw as D

TIERS = ("t1", "t2", "t3", "t4", "t5")      # BadgeTier 1..5 (Bronze, Silver, Gold, Platinum, Diamond)
TIERED, SINGLE = "tiered", "single"          # Badge.kind; SINGLE is also the file tier of one-off badges


@dataclass(frozen=True)
class Badge:
    type: str                          # BadgeType enum value = output file stem
    icon: str                          # icon name: icons/svg/<icon>-<colourway>.svg
    draw: Callable                     # drawing function in icons/draw.py (returns an illus.Icon named `icon`)
    kind: str                          # TIERED | SINGLE
    title: str                         # English name (docs, contact sheet)
    set_colour: Optional[str] = None   # SINGLE: the one-off base's set colour
    accent: Optional[str] = None       # SINGLE: the icon accent colour (default: the set colour)

    @property
    def tiers(self):
        return TIERS if self.kind == TIERED else (SINGLE,)

    def base_key(self, tier):
        """bases/bases.json key of the frozen base this badge tier sits on."""
        return tier if self.kind == TIERED else f"single-{self.set_colour}"

    def colourway(self, tier):
        """Icon colourway: the tier tint (t1..t5), or for one-offs the accent set colour (coral = the -msp file)."""
        if self.kind == TIERED:
            return tier
        accent = self.accent or self.set_colour
        return "msp" if accent == "coral" else accent


BADGES = (
    #     BadgeType value         icon               drawing function     kind     English name
    Badge("puzzles_solved",       "explorer",        D.explorer,          TIERED,  "Puzzle Explorer"),
    Badge("pieces_solved",        "piece-cruncher",  D.piece_cruncher,    TIERED,  "Piece Cruncher"),
    Badge("speed_500_pieces",     "speed",           D.speed,             TIERED,  "Speed Demon 500"),
    Badge("speed_1000_pieces",    "speed-1000",      D.speed_1000,        TIERED,  "Speed Demon 1000"),
    Badge("streak",               "streak",          D.streak,            TIERED,  "On Fire"),
    Badge("team_player",          "team-spirit",     D.team_spirit,       TIERED,  "Team Spirit"),
    Badge("zen_puzzler",          "zen-puzzler",     D.zen_puzzler,       TIERED,  "Zen Puzzler"),
    Badge("first_try",            "first-try",       D.first_try,         TIERED,  "First Try"),
    Badge("unboxed",              "unboxed",         D.unboxed_eye,       TIERED,  "Unboxed"),
    Badge("brand_explorer",       "brand-explorer",  D.brand_explorer,    TIERED,  "Brand Explorer"),
    Badge("marathoner",           "marathoner",      D.marathoner,        TIERED,  "Marathoner"),
    Badge("photographer",         "photographer",    D.photographer,      TIERED,  "Photographer"),
    Badge("steady_hands",         "steady-hands",    D.steady_hands,      TIERED,  "Steady Hands"),
    Badge("librarian",            "librarian",       D.librarian,         TIERED,  "Librarian"),
    Badge("weekend_puzzler",      "weekend-puzzler", D.weekend_puzzler,   TIERED,  "Weekend Puzzler"),
    Badge("cataloger",            "cataloger",       D.cataloger,         TIERED,  "Cataloger"),
    Badge("muscle_memory",        "muscle-memory",   D.muscle_memory,     TIERED,  "Muscle Memory"),
    Badge("muscle_memory_unboxed", "muscle-memory-unboxed", D.muscle_memory_unboxed, TIERED,
          "Muscle Memory (unboxed)"),
    Badge("night_owl",            "night-owl",       D.night_owl,         TIERED,  "Night Owl"),
    Badge("competitor",           "competitor",      D.competitor,        TIERED,  "Competitor"),
    Badge("online_competitor",    "online-competitor", D.online_competitor, TIERED, "Online Competitor"),
    Badge("supporter",            "supporter",       D.supporter,         SINGLE,  "Supporter", set_colour="coral"),
)


def icon_table(badges=BADGES):
    """Every icon the given badges use, in registry order: {icon: {"draw", "types", "extra"}}; extra = the set
    colourways (beyond msp + t1..t5) that one-off badges need for it."""
    table = {}
    for b in badges:
        e = table.setdefault(b.icon, {"draw": b.draw, "types": [], "extra": set()})
        e["types"].append(b.type)
        e["extra"].update(c for c in (b.colourway(t) for t in b.tiers) if c not in ("msp",) + TIERS)
    for e in table.values():
        e["extra"] = sorted(e["extra"])
    return table


def problems():
    """Internal consistency of this file (the build also checks it against BadgeType.php and bases.json)."""
    out, seen, draws = [], set(), {}
    for b in BADGES:
        if b.type in seen:
            out.append(f"registry: type {b.type!r} is listed twice")
        seen.add(b.type)
        if draws.setdefault(b.icon, b.draw) is not b.draw:
            out.append(f"registry: icon {b.icon!r} is drawn by two different functions")
    for b in BADGES:
        if not callable(b.draw):
            out.append(f"registry: {b.type}: draw is not a function")
        if b.kind not in (TIERED, SINGLE):
            out.append(f"registry: {b.type}: kind {b.kind!r} is neither TIERED nor SINGLE")
        elif b.kind == TIERED and (b.set_colour or b.accent):
            out.append(f"registry: {b.type}: set_colour/accent are for SINGLE badges only")
        elif b.kind == SINGLE:
            if b.set_colour not in SETS:
                out.append(f"registry: {b.type}: set_colour {b.set_colour!r} is not one of {sorted(SETS)}")
            if b.accent is not None and b.accent not in SETS:
                out.append(f"registry: {b.type}: accent {b.accent!r} is not one of {sorted(SETS)}")
    return out
