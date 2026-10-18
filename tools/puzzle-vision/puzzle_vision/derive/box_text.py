"""`box_text` - facts parsed from `ocr` + `barcode` by rules (image-taxonomy.md §11.2): piece counts with their evidence,
multi-packs, dimensions, EANs, product codes, brand / series / special mentions, minimum age, copyright lines,
languages. Raw text stays in `ocr`. Never a change proposal: mismatches with the catalogue are computed on the server
(Phase 5, a moderator list with Dismiss / Ignore)."""

from __future__ import annotations

import re
from typing import Any

from ..stages.apple_vision import gtin_ok

PIECE_WORDS = r"(?:pieces?|pcs\.?|pc\.?|teile|pi[eè]ces|piezas|pezzi|stukjes|st\.|d[ií]lk[uůy]|d[ií]lk[oů]|elementów|elementow|darab|bitar|brikker|palaa|pe[çc]as|kom|piese|parça|ピース|片|pz)"
PIECES_RE = re.compile(rf"(?<![\d.,])(\d{{1,2}}[.,\s]?\d{{3}}|\d{{2,5}})\s*{PIECE_WORDS}(?![a-z])", re.I)
MULTI_RE = re.compile(r"(?<!\d)([2-9])\s*[x×]\s*(\d{2,4})(?!\d)", re.I)
DIM_RE = re.compile(r"(\d{2,3}(?:[.,]\d)?)\s*[x×]\s*(\d{2,3}(?:[.,]\d)?)\s*cm", re.I)
DIGITS_RE = re.compile(r"(?<!\d)(\d[\d ]{6,16}\d)(?!\d)")
CODE_RE = re.compile(r"(?:art\.?|no\.?|nr\.?|ref\.?|item|cat\.?|réf\.?|č\.)\s*[:#]?\s*([0-9][0-9\-./]{2,11}[0-9])", re.I)
AGE_RE = re.compile(r"(?:ages?|ab|dès|des|da|vanaf|od|from|edad|età|eta)\s*(\d{1,2})\s*\+?|(?<![\d])(\d{1,2})\s*\+(?!\d)", re.I)
COPYRIGHT_RE = re.compile(r"(?:©|\(c\)|copyright)\s*\S.*", re.I)

SPECIAL_WORDS: dict[str, list[str]] = {
    "glow_in_dark": ["glow in the dark", "leuchtet im dunkeln", "phosphorescent", "svítí ve tmě", "fluorescent"],
    "double_sided": ["double sided", "double-sided", "doppelseitig", "two sided", "2-sided", "oboustranné"],
    "wasgij": ["wasgij"],
    "impossible_challenge": ["krypt", "impossible", "challenge puzzle"],
    "mystery_escape": ["exit puzzle", "escape puzzle", "escape room"],
    "murder_mystery": ["murder mystery"],
    "seek_and_find": ["seek and find", "wimmel", "find the", "hidden objects"],
    "panorama": ["panorama"],
    "wooden": ["wooden", "holzpuzzle", "wood puzzle", "dřevěné"],
    "three_d": ["3d puzzle", "3-d puzzle", "puzzleball"],
    "family_mixed_pieces": ["family puzzle", "familienpuzzle", "family pieces"],
    "poster_included": ["poster included", "with poster", "inkl. poster", "mit poster", "includes poster"],
    "glitter_foil": ["glitter", "foil", "metallic"],
    "neon": ["neon"],
    "lenticular": ["lenticular", "3d effect", "3d-effekt"],
    "round_outline": ["round puzzle", "rundpuzzle", "circular"],
    "shaped_outline": ["shaped puzzle", "shaped pieces", "konturenpuzzle"],
    "whimsy_pieces": ["whimsy", "whimsies"],
    "mini": ["mini puzzle", "minipuzzle", "mini"],
    "frame_tray": ["frame tray", "rahmenpuzzle"],
}
SERIES = ["Wasgij", "Krypt", "EXIT", "Puzzle Ball", "Spirit Puzzle", "Art Collection", "Premium Collection", "Pieces of History"]


def _norm_pieces(raw: str) -> int | None:
    digits = re.sub(r"[.,\s]", "", raw)
    if not digits.isdigit():
        return None
    v = int(digits)
    return v if 4 <= v <= 60000 else None


def parse(lines: list[str], barcodes: list[dict[str, Any]], brand_names: list[str], languages: list[str]) -> dict[str, Any]:
    text = "\n".join(lines)
    low = text.lower()
    pieces: list[dict[str, Any]] = []
    for m in PIECES_RE.finditer(text):
        v = _norm_pieces(m.group(1))
        if v and v not in [p["v"] for p in pieces]:
            pieces.append({"v": v, "evidence": m.group(0).strip()[:60]})
    multi = None
    for m in MULTI_RE.finditer(text):
        count, each = int(m.group(1)), int(m.group(2))
        if 10 <= each <= 5000:
            multi = {"count": count, "pieces": each}
            break
    dims = None
    m = DIM_RE.search(text)
    if m:
        dims = [float(m.group(1).replace(",", ".")), float(m.group(2).replace(",", "."))]
        dims = [int(d) if d.is_integer() else d for d in dims]
    eans: list[str] = []
    for code in barcodes:
        v = str(code.get("v", ""))
        if code.get("checksumOk") and v not in eans:
            eans.append(v)
    for m in DIGITS_RE.finditer(text):
        d = m.group(1).replace(" ", "")
        if len(d) in (8, 12, 13) and gtin_ok(d) and d not in eans:
            eans.append(d)
    codes = []
    for m in CODE_RE.finditer(text):
        c = m.group(1)
        if c not in codes and c not in eans:
            codes.append(c)
    brands = [b for b in brand_names if b and re.search(rf"(?<!\w){re.escape(b.lower())}(?!\w)", low)]
    age = None
    for m in AGE_RE.finditer(text):
        v = int(m.group(1) or m.group(2))
        if 3 <= v <= 18:
            age = v
            break
    specials = sorted({key for key, ws in SPECIAL_WORDS.items() for w in ws if re.search(rf"(?<!\w){re.escape(w)}(?!\w)", low)})
    if multi:
        specials = sorted(set(specials) | {"multi_pack"})
    series = [s for s in SERIES if re.search(rf"(?<!\w){re.escape(s.lower())}(?!\w)", low)]
    copyright_lines = [m.group(0).strip()[:120] for line in lines for m in [COPYRIGHT_RE.search(line)] if m]
    return {
        "pieces": pieces[:6],
        "multiPack": multi,
        "dimensionsCm": dims,
        "eans": eans[:10],
        "productCodes": codes[:10],
        "brandMentions": sorted(set(brands))[:10],
        "ageMin": age,
        "specialMentions": specials,
        "seriesMentions": series,
        "copyright": copyright_lines[:5],
        "languages": languages,
    }


def from_payloads(ocr: dict[str, Any] | None, barcode: dict[str, Any] | None, brand_names: list[str]) -> dict[str, Any]:
    lines = [str(line.get("t", "")) for line in (ocr or {}).get("lines") or [] if float(line.get("c", 0)) >= 0.3]
    return parse(lines, (barcode or {}).get("codes") or [], brand_names, list((ocr or {}).get("languages") or []))
