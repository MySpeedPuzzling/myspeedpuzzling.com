"""`describe` - a structured description of the artwork crop (image-taxonomy.md §11.1 + the bake-off's changes, §18).

Model: Qwen3.5-9B 4-bit MLX (`mlx-community/Qwen3.5-9B-MLX-4bit`, Apache-2.0), thinking off (mlx-vlm's default for
the chat template), JSON-schema constrained decoding (llguidance through
`mlx_vlm.structured.build_json_schema_logits_processor`, built once, `clone()` per image), greedy (temperature 0),
`max_tokens` 700, the crop at 512 px long side (no letterbox), unbatched (batching ran out of memory in the bake-off).
System prompt v2 + "never the puzzle brand or publisher" on the artistSignature line (finding 7) = `promptVersion`
"v2.1"; user message: catalogue title + <= 12 OCR lines inside the crop.

Changes against §11.1's draft (all from §18): `none` added to `time` / `weather`, `characters` is a list,
`specialHints` removed, `extraTags` added; `subjects` lowercased + singularised + de-duplicated, "none" / "null" in
`place` / `artistSignature` mapped to null.
"""

from __future__ import annotations

import json
import os
import tempfile
from typing import Any

from PIL import Image

from ..analyzers import QWEN35_9B
from ..imaging import bbox_to_xyxy
from .base import Prepared, Stage

PROMPT_VERSION = "v2.1"
CROP_SIDE = 512
MAX_TOKENS = 700
MAX_OCR_LINES = 12

THEMES = ["animals", "pets", "birds", "sea_underwater", "landscape_nature", "flowers_garden", "countryside_village",
          "city_architecture", "travel_places", "fine_art", "illustration_design", "fantasy_myth", "space_scifi",
          "cartoon_humour", "licensed_entertainment", "seasons_holidays", "food_drink", "transport",
          "hobbies_sport_music", "people_everyday", "maps_education", "collage_collection", "abstract_challenge"]
STYLES = ["photo", "photo_aerial", "photo_macro", "painting_oil_acrylic", "watercolour", "pencil_ink_drawing",
          "illustration_digital", "cartoon_comic", "vector_flat", "render_3d", "vintage_retro_poster", "collage_mixed",
          "pattern_textile", "map_cartographic", "photomosaic", "pixel_art"]
SCENES = ["landscape", "seascape", "cityscape", "street_level", "interior", "still_life", "underwater", "sky_space",
          "garden", "portrait_closeup", "animal_closeup", "crowd_scene", "aerial_view", "allover_pattern", "abstract",
          "map", "collage_grid", "product_flatlay"]
SEASONS = ["none", "spring", "summer", "autumn", "winter"]
HOLIDAYS = ["none", "christmas", "halloween", "easter", "valentines", "thanksgiving", "new_year", "other"]
TIMES = ["none", "day", "golden_hour", "dusk_blue_hour", "night"]
WEATHER = ["none", "sunny", "partly_cloudy", "overcast", "rain", "snow", "fog_mist", "storm"]
MOODS = ["cozy", "serene", "playful", "dramatic", "spooky", "romantic", "nostalgic", "vibrant", "majestic",
         "melancholic", "whimsical", "festive"]
PEOPLE = ["none", "few", "many", "crowd"]
TEXT = ["none", "little", "lots"]
ENUMS = {"themes": THEMES, "style": STYLES, "scene": SCENES, "season": SEASONS, "holiday": HOLIDAYS, "time": TIMES,
         "weather": WEATHER, "mood": MOODS, "people": PEOPLE, "textInArtwork": TEXT}
AREAS = ["sky", "water", "foliageGrass", "buildings", "plainBackground"]
COUNTS = ["distinctObjects", "animals", "people"]
KEYS = ["caption", "altText", "themes", "subjects", "scene", "style", "season", "holiday", "time", "weather", "mood",
        "place", "characters", "artistSignature", "people", "textInArtwork", "counts", "areas", "collageGrid",
        "seekAndFind", "extraTags"]


def _s(maxlen: int | None = None) -> dict[str, Any]:
    d: dict[str, Any] = {"type": "string"}
    if maxlen:
        d["maxLength"] = maxlen
    return d


def _arr(items: dict[str, Any], lo: int, hi: int) -> dict[str, Any]:
    return {"type": "array", "items": items, "minItems": lo, "maxItems": hi}


def json_schema() -> dict[str, Any]:
    """All keys required, in this order (caption first: the model's notes before the tags), no extra keys."""
    nullable = {"anyOf": [{"type": "string", "maxLength": 80}, {"type": "null"}]}
    props = {
        "caption": _s(600),
        "altText": _s(140),
        "themes": _arr({"enum": THEMES}, 1, 3),
        "subjects": _arr(_s(40), 1, 12),
        "scene": {"enum": SCENES},
        "style": {"enum": STYLES},
        "season": {"enum": SEASONS},
        "holiday": {"enum": HOLIDAYS},
        "time": {"enum": TIMES},
        "weather": {"enum": WEATHER},
        "mood": _arr({"enum": MOODS}, 1, 2),
        "place": nullable,
        "characters": _arr(_s(50), 0, 6),
        "artistSignature": nullable,
        "people": {"enum": PEOPLE},
        "textInArtwork": {"enum": TEXT},
        "counts": {"type": "object", "properties": {k: {"type": "integer", "minimum": 0, "maximum": 999} for k in COUNTS},
                   "required": COUNTS, "additionalProperties": False},
        "areas": {"type": "object", "properties": {k: {"type": "integer", "minimum": 0, "maximum": 100} for k in AREAS},
                  "required": AREAS, "additionalProperties": False},
        "collageGrid": {"type": "boolean"},
        "seekAndFind": {"type": "boolean"},
        "extraTags": _arr(_s(40), 0, 8),
    }
    return {"type": "object", "properties": props, "required": KEYS, "additionalProperties": False}


def _l(v: list[str]) -> str:
    return "|".join(v)


THEME_GLOSS = """animals (wild or farm animals) | pets (cats, dogs) | birds | sea_underwater (sea life, coast, harbours) | landscape_nature | flowers_garden | countryside_village (farms, cottages, villages) | city_architecture (cities, streets, buildings, castles, churches) | travel_places (a recognisable destination or landmark) | fine_art (a famous painter's or museum artwork, old masters) | illustration_design (mandalas, patterns, posters, decorative design) | fantasy_myth (dragons, fairies, magic, gothic) | space_scifi | cartoon_humour (funny comic scenes, Wasgij) | licensed_entertainment (ONLY recognisable franchise characters: Disney, Peanuts, Harry Potter...) | seasons_holidays (Christmas, Easter, Halloween, seasonal scenes) | food_drink | transport (cars, trains, ships, planes, balloons) | hobbies_sport_music (books, sport, music, games) | people_everyday (people and daily life) | maps_education | collage_collection (many separate small pictures or items) | abstract_challenge (gradients, geometric shapes, no real subject)"""
SCENE_GLOSS = """landscape | seascape | cityscape | street_level | interior (inside a room or building) | still_life (objects arranged on a table) | underwater | sky_space | garden | portrait_closeup (one person or character fills the picture) | animal_closeup | crowd_scene (many figures, busy scene) | aerial_view | allover_pattern (the same kind of thing repeated over the whole picture) | abstract | map | collage_grid (separate panels in a grid) | product_flatlay (items seen from above)"""
STYLE_GLOSS = """photo | photo_aerial | photo_macro | painting_oil_acrylic (looks painted with a brush, incl. digital paintings) | watercolour | pencil_ink_drawing | illustration_digital (clean digital illustration) | cartoon_comic (outlined cartoon or comic characters) | vector_flat (flat shapes, no shading) | render_3d | vintage_retro_poster | collage_mixed | pattern_textile | map_cartographic | photomosaic | pixel_art"""

SYSTEM_PROMPT = f"""You catalogue jigsaw puzzle pictures. The image is the artwork of one puzzle, cropped from its box image; it may still show box edges, packaging text or a photo of the assembled puzzle - describe only the picture itself. Be factual: mention only what is clearly visible. Never name real people. Name a place, landmark or licensed character only when you are sure.
Answer with ONE JSON object, keys in this order:
caption: 2-3 sentences describing the picture (subject, setting, style, notable details).
altText: one sentence, max 125 characters, for screen readers.
themes: 1-3, what the picture is about, most important first; add a second or third only if it clearly applies. Values: {THEME_GLOSS}
subjects: up to 12 concrete visible things, singular English nouns, most prominent first, no duplicates; species or breed only when sure.
scene: {SCENE_GLOSS}
style: {STYLE_GLOSS}
season: {_l(SEASONS)} | holiday: {_l(HOLIDAYS)} | time: {_l(TIMES)} (none = indoors or not shown) | weather: {_l(WEATHER)}
mood: 1-2 of {_l(MOODS)}
place: named place, landmark or country if recognisable, else null
characters: licensed franchise or named characters (e.g. Disney, Harry Potter, Snoopy), else []
artistSignature: artist name if signed or credited in the picture, never the puzzle brand or publisher, else null
people: {_l(PEOPLE)} (few = 1-3, many = 4-15, crowd = more) | textInArtwork: {_l(TEXT)}
counts: {{distinctObjects, animals, people}} as integers
areas: {{sky, water, foliageGrass, buildings, plainBackground}} as rough % of the picture
collageGrid, seekAndFind: true/false
extraTags: up to 8 more useful search words (art movement, era, objects, colours), else []"""


def ocr_lines_in_crop(ocr: dict[str, Any] | None, artwork: dict[str, Any] | None, n: int = MAX_OCR_LINES) -> list[str]:
    """Up to `n` OCR lines whose centre lies inside the crop, confidence >= 0.5, >= 2 characters, largest first
    (the `ocr` payload's `b` is top-left x, y, w, h)."""
    if not ocr:
        return []
    if artwork and artwork.get("bbox"):
        x0, y0, x1, y1 = bbox_to_xyxy(artwork["bbox"])
    else:
        x0, y0, x1, y1 = 0.0, 0.0, 1.0, 1.0
    out = []
    for line in sorted(ocr.get("lines") or [], key=lambda d: -(d["b"][2] * d["b"][3])):
        bx, by, bw, bh = line["b"]
        cx, cy = bx + bw / 2, by + bh / 2
        text = str(line.get("t", "")).strip()
        if x0 <= cx <= x1 and y0 <= cy <= y1 and float(line.get("c", 0)) >= 0.5 and len(text) >= 2:
            out.append(text)
        if len(out) >= n:
            break
    return out


def user_text(title: str | None, ocr_lines: list[str]) -> str:
    parts = []
    if title:
        parts.append(f"Catalogue title (may not describe the picture): {title}")
    if ocr_lines:
        parts.append("Text read inside the picture (OCR, may contain errors): " + " / ".join(ocr_lines))
    parts.append("Return the JSON object.")
    return "\n".join(parts)


def messages(title: str | None, ocr_lines: list[str]) -> list[dict[str, str]]:
    return [{"role": "system", "content": SYSTEM_PROMPT}, {"role": "user", "content": user_text(title, ocr_lines)}]


_IRREGULAR = {"people": "person", "children": "child", "men": "man", "women": "woman", "mice": "mouse", "geese": "goose",
              "teeth": "tooth", "feet": "foot", "leaves": "leaf", "wolves": "wolf", "knives": "knife", "lives": "life",
              "wives": "wife", "loaves": "loaf", "calves": "calf", "halves": "half", "shelves": "shelf", "oxen": "ox",
              "dice": "die", "cacti": "cactus", "fungi": "fungus"}
_UNCHANGED = {"sheep", "fish", "deer", "moose", "series", "species", "glass", "grass", "dress", "cross", "moss", "chess",
              "bus", "cactus", "octopus", "iris", "lotus", "hibiscus", "asparagus", "bonus", "news", "clothes", "jeans",
              "scissors", "glasses", "pants", "trousers", "binoculars", "aircraft", "swiss", "boots"}


def singular(word: str) -> str:
    """Simple English singular for subject nouns (multi-word: the last word)."""
    w = word.strip().lower()
    if not w:
        return w
    head, _, last = w.rpartition(" ")
    if last in _UNCHANGED or len(last) <= 3:
        s = last
    elif last in _IRREGULAR:
        s = _IRREGULAR[last]
    elif last.endswith("ies") and len(last) > 4:
        s = last[:-3] + "y"
    elif last.endswith(("sses", "shes", "ches", "xes", "zes")):
        s = last[:-2]
    elif last.endswith("oes") and last not in ("shoes", "canoes", "toes"):
        s = last[:-2]
    elif last.endswith("s") and not last.endswith(("ss", "us", "is")):
        s = last[:-1]
    else:
        s = last
    return f"{head} {s}" if head else s


def _null(v: Any) -> Any:
    if v is None:
        return None
    s = str(v).strip()
    return None if s.lower() in ("", "none", "null", "n/a", "unknown") else s


def postprocess(obj: dict[str, Any]) -> dict[str, Any]:
    out = dict(obj)
    subjects: list[str] = []
    for s in obj.get("subjects") or []:
        n = singular(str(s))
        if n and n not in subjects:
            subjects.append(n)
    out["subjects"] = subjects
    out["place"] = _null(obj.get("place"))
    out["artistSignature"] = _null(obj.get("artistSignature"))
    chars: list[str] = []
    for c in obj.get("characters") or []:
        c = str(c).strip()
        if c and c.lower() not in [x.lower() for x in chars]:
            chars.append(c)
    out["characters"] = chars
    for key in ("themes", "mood", "extraTags"):
        seen: list[Any] = []
        for v in obj.get(key) or []:
            if v not in seen:
                seen.append(v)
        out[key] = seen
    return out


def violations(o: dict[str, Any]) -> list[str]:
    """Values outside the vocabularies + missing keys (constrained decoding should make this empty)."""
    bad = [f"missing:{k}" for k in KEYS if k not in o]
    for k, allowed in ENUMS.items():
        v = o.get(k)
        for x in (v if isinstance(v, list) else [v]):
            if x not in allowed:
                bad.append(f"{k}={x}")
    return bad


def parse(text: str) -> dict[str, Any]:
    t = (text or "").strip()
    if t.startswith("```"):
        t = t.split("\n", 1)[1] if "\n" in t else t
        t = t.rsplit("```", 1)[0]
    i, j = t.find("{"), t.rfind("}")
    if i < 0 or j < 0:
        raise ValueError("no JSON object in the model's answer")
    return json.loads(t[i:j + 1])


def build_payload(model_text: str, ocr_lines: list[str], title: str | None, tokens: dict[str, int] | None = None) -> dict[str, Any]:
    obj = postprocess(parse(model_text))
    bad = violations(obj)
    if bad:
        raise ValueError("describe output outside the schema: " + ", ".join(bad[:5]))
    result = {k: obj[k] for k in KEYS}
    result.update({"promptVersion": PROMPT_VERSION, "titleContext": bool(title), "ocrLines": len(ocr_lines)})
    if tokens:
        result["tokens"] = tokens
    return result


# Fields compared by `verify --describe-parity` - the model's answer, not timings or token counts
PARITY_FIELDS = tuple(KEYS) + ("promptVersion", "titleContext", "ocrLines")


def parity_key(result: dict[str, Any]) -> str:
    return json.dumps({k: result.get(k) for k in PARITY_FIELDS}, sort_keys=True, ensure_ascii=False, separators=(",", ":"))


class DescribeStage(Stage):
    analyzer = "describe"
    upstream = ("artwork", "ocr")
    uses_crop = True

    @property
    def model(self) -> str:
        return f"{QWEN35_9B} constrained {CROP_SIDE}px prompt {PROMPT_VERSION} temp 0"

    def load(self) -> None:
        from mlx_vlm import generate, load
        from mlx_vlm.prompt_utils import apply_chat_template
        from mlx_vlm.structured import build_json_schema_logits_processor

        self._generate = generate
        self._template = apply_chat_template
        self.net, self.proc = load(QWEN35_9B)
        tok = self.proc.tokenizer if hasattr(self.proc, "tokenizer") else self.proc
        self.logits = build_json_schema_logits_processor(tok, json_schema())  # once per process

    def answer(self, crop_rgb: Any, title: str | None, ocr_lines: list[str]) -> tuple[str, dict[str, int]]:
        prompt = self._template(self.proc, self.net.config, messages(title, ocr_lines), num_images=1)
        im = Image.fromarray(crop_rgb)
        im.thumbnail((CROP_SIDE, CROP_SIDE), Image.LANCZOS)
        with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as f:
            im.save(f.name)
            path = f.name
        try:
            r = self._generate(self.net, self.proc, prompt, image=[path], max_tokens=MAX_TOKENS, temperature=0.0,
                               verbose=False, logits_processors=[self.logits.clone()])  # clone = a fresh matcher per image
        finally:
            os.unlink(path)
        tokens = {"prompt": int(getattr(r, "prompt_tokens", 0) or 0), "generation": int(getattr(r, "generation_tokens", 0) or 0)}
        return (r.text if hasattr(r, "text") else str(r)), tokens

    def analyse(self, p: Prepared) -> dict[str, Any]:
        lines = ocr_lines_in_crop(p.upstream.get("ocr"), p.upstream.get("artwork"))
        title = p.item.name or None
        text, tokens = self.answer(p.subject, title, lines)
        return build_payload(text, lines, title, tokens)
