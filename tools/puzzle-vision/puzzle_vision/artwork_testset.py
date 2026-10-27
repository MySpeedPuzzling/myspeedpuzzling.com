"""The artwork **test set**: a uniform random sample of current catalogue pictures, stratified by the first pass, with
a weight per picture - the honest measure of the crops (the `reference` set is a validation set: 1.1 was tuned on it).
Read-only against production (work list + bulk read); nothing is stored there.

    python -m puzzle_vision.artwork_testset strata --profile m3-solo [--write]       # pictures per stratum now
    python -m puzzle_vision.artwork_testset select --profile m3-solo --out DIR      # draw ~200, download, items.json
    python -m puzzle_vision.artwork_testset sheets --out DIR                        # labelling sheets + index.html
    python -m puzzle_vision.artwork_testset import --out DIR                        # Jan's truth.jsonl -> reference/

Strata = `artwork_eval.stratum()` (the first pass's kind and whether the quad snap took it). The allocation leans to
the strata where crops go wrong (Neyman: share × spread of a bad crop, measured 2026-10-26: trim cuts 1.4 %, box face
passes 34 %) so each gets enough pictures; every picture carries `weight` = pictures of its stratum in the catalogue /
pictures drawn from it, so `artwork_eval --set random` prints catalogue rates. Never tune on this set; draw a new one
(another `--seed`) once a version was changed because of it.
"""

from __future__ import annotations

import argparse
import json
import random
from pathlib import Path
from typing import Any

from .artwork_eval import REFERENCE_DIR, STRATA, load_truth, stratum
from .artwork_reference import sheet, stored_corners

# 200 pictures: N_h × sqrt(p(1-p)) with p = the stratum's bad-crop rate (review 2026-10-26), at least 3 a stratum
ALLOCATION = {"flat": 40, "other": 3, "asm_quad": 17, "asm_vlm": 27, "box_quad": 28, "box_vlm": 85}
LABELLED_SETS = ("reference", "tuning", "feedback")
KINDS = ("flat", "box_front", "box_angled", "assembled", "other")
FLAGS = ("round", "shaped", "no_visible_edge", "rotated", "several_pictures", "small", "title_band", "frame")
QUALITY = ("low_resolution", "not_the_picture", "blurry", "glare", "partly_covered")


def allocation(n: int) -> dict[str, int]:
    """ALLOCATION scaled to `n` (largest remainders), at least 1 a stratum."""
    total = sum(ALLOCATION.values())
    raw = {s: ALLOCATION[s] * n / total for s in STRATA}
    out = {s: max(1, int(v)) for s, v in raw.items()}
    for s in sorted(raw, key=lambda s: raw[s] - int(raw[s]), reverse=True)[: max(0, n - sum(out.values()))]:
        out[s] += 1
    return out


def current_artwork(api: Any) -> tuple[dict[str, Any], list[dict[str, Any]]]:
    """(work list items by puzzle id, current artwork rows of the current pictures)."""
    images = {i.puzzle_id: i for i in api.iter_images(status="all", limit=1000)}
    rows = [r for r in api.iter_analyses("artwork", only_current=True, limit=1000)
            if r["puzzleId"] in images and r.get("imageFingerprint") == images[r["puzzleId"]].fingerprint
            and isinstance(r.get("result"), dict) and r["result"].get("bbox")]
    return images, rows


def draw(rows: list[dict[str, Any]], n: int, seed: int, excluded: set[str]) -> tuple[list[dict[str, Any]], dict[str, Any]]:
    """Stratified random draw: (chosen rows with `stratum` and `weight`, per-stratum population / drawn / weight)."""
    by: dict[str, list[dict[str, Any]]] = {s: [] for s in STRATA}
    for r in sorted(rows, key=lambda r: r["puzzleId"]):
        by[stratum(r["result"])].append(r)
    population = {s: len(v) for s, v in by.items()}
    rng = random.Random(seed)
    chosen, strata = [], {}
    for s, want in allocation(n).items():
        pool = [r for r in by[s] if r["puzzleId"] not in excluded]
        take = rng.sample(pool, min(want, len(pool)))
        weight = population[s] / len(take) if take else 0.0
        strata[s] = {"population": population[s], "drawn": len(take), "weight": round(weight, 3)}
        chosen += [r | {"stratum": s, "weight": round(weight, 3)} for r in take]
    rng.shuffle(chosen)  # mark them in a random order, not stratum by stratum
    return chosen, strata


def cmd_strata(a: argparse.Namespace) -> int:
    from .cli import Context

    ctx = Context(argparse.Namespace(profile=a.profile, base_url=a.base_url))
    _, rows = current_artwork(ctx.api)
    counts = {s: 0 for s in STRATA}
    for r in rows:
        counts[stratum(r["result"])] += 1
    print(json.dumps(counts))
    if a.write:
        import datetime as dt

        doc = json.loads((REFERENCE_DIR / "population-strata.json").read_text())
        doc.update(date=dt.date.today().isoformat(), source=f"production artwork rows of the current pictures ({len({r['analyzerVersion'] for r in rows})} version(s))",
                   counts=counts)
        (REFERENCE_DIR / "population-strata.json").write_text(json.dumps(doc, indent=1) + "\n")
    return 0


def cmd_select(a: argparse.Namespace) -> int:
    from .cli import Context
    from .download import Downloader
    from .workstate import WorkState

    out = Path(a.out).expanduser()
    out.mkdir(parents=True, exist_ok=True)
    ctx = Context(argparse.Namespace(profile=a.profile, base_url=a.base_url))
    images, rows = current_artwork(ctx.api)
    excluded = set()
    for name in LABELLED_SETS:
        excluded |= {i["id"] for i in json.loads((REFERENCE_DIR / f"artwork-items-{name}.json").read_text())}
    chosen, strata = draw(rows, a.n, a.seed, excluded)
    dl = Downloader(out / "cache" / "images", WorkState(out / "cache" / "workstate.sqlite"))
    items = []
    for r in chosen:
        w = images[r["puzzleId"]]
        local = dl.get(w.puzzle_id, w.fingerprint, w.image_url)
        items.append({"id": w.puzzle_id, "image": w.image_url.split("/original/", 1)[-1], "fingerprint": w.fingerprint,
                      "pieces": w.pieces_count, "sets": [f"random:{r['stratum']}"], "stratum": r["stratum"], "weight": r["weight"],
                      "width": local.width, "height": local.height, "originalWidth": local.original_width,
                      "originalHeight": local.original_height, "storedVersion": r.get("analyzerVersion"), "stored": r["result"],
                      "path": local.path})
    (out / "items.json").write_text(json.dumps(items, indent=1, ensure_ascii=False))
    (out / "strata.json").write_text(json.dumps({"seed": a.seed, "excluded": sorted(LABELLED_SETS), "strata": strata,
                                                "pictures": len(rows)}, indent=1))
    print(f"{len(items)} pictures of {len(rows)} -> {out / 'items.json'}; per stratum {json.dumps(strata)}")
    return 0


def template_row(item: dict[str, Any]) -> dict[str, Any]:
    """What Jan starts from: the production crop's outline (artwork_eval truth format) - kept when it is on the edge."""
    return {"puzzleId": item["id"], "fingerprint": item["fingerprint"], "kind": (item.get("stored") or {}).get("kind") or "other",
            "corners": [[round(x, 4), round(y, 4)] for x, y in stored_corners(item)], "flags": [], "quality": [], "note": "",
            "checked": False}


def cmd_sheets(a: argparse.Namespace) -> int:
    out = Path(a.out).expanduser()
    items = json.loads((out / "items.json").read_text())
    (out / "sheets").mkdir(exist_ok=True)
    rows = []
    for n, it in enumerate(items, 1):
        name = f"sheets/{n:03d}.jpg"
        s = it.get("stored") or {}
        title = f"{n:03d}  {it['id'][:8]}  {it['width']}x{it['height']} (orig {it['originalWidth']}x{it['originalHeight']})  production crop: {s.get('kind')}, {s.get('method')}"
        sheet(it["path"], stored_corners(it), str(out / name), title)
        rows.append(template_row(it) | {"sheet": name, "n": n})
    (out / "truth-template.jsonl").write_text("".join(json.dumps({k: v for k, v in r.items() if k not in ("sheet", "n")}) + "\n" for r in rows))
    (out / "index.html").write_text(index_html(rows))
    print(f"{len(rows)} sheets in {out / 'sheets'}; mark them in {out / 'index.html'}")
    return 0


def validate(row: dict[str, Any], item: dict[str, Any] | None) -> list[str]:
    errors = []
    if item is None:
        return ["not in items.json"]
    if row.get("fingerprint") != item["fingerprint"]:
        errors.append("another picture")
    c = row.get("corners")
    if not (isinstance(c, list) and len(c) == 4 and all(isinstance(p, list) and len(p) == 2 and all(isinstance(v, (int, float)) and -0.05 <= v <= 1.05 for v in p) for p in c)):
        errors.append("corners: four [x, y] fractions")
    if row.get("kind") not in KINDS:
        errors.append(f"kind: one of {', '.join(KINDS)}")
    if not row.get("checked"):
        errors.append("not marked yet")
    return errors


def cmd_import(a: argparse.Namespace) -> int:
    out = Path(a.out).expanduser()
    items = {i["id"]: i for i in json.loads((out / "items.json").read_text())}
    truth = load_truth([out / "truth.jsonl"])
    problems = {pid: e for pid, row in truth.items() if (e := validate(row, items.get(pid)))}
    if problems:
        for pid, e in problems.items():
            print(pid, "; ".join(e))
        print(f"{len(problems)} of {len(truth)} rows need a fix - nothing imported")
        return 2
    keep = [{k: v for k, v in i.items() if k != "path"} for i in items.values() if i["id"] in truth]
    (REFERENCE_DIR / "artwork-items-random.json").write_text(json.dumps(keep, indent=1, ensure_ascii=False) + "\n")
    (REFERENCE_DIR / "artwork-truth-random.jsonl").write_text(
        "".join(json.dumps({k: v for k, v in truth[i["id"]].items() if k != "checked"}) + "\n" for i in keep))
    print(f"{len(keep)} marked pictures -> reference/artwork-*-random.*; score a version with "
          "`python -m puzzle_vision.artwork_eval --set random`")
    return 0


def index_html(rows: list[dict[str, Any]]) -> str:
    """One local page to mark every sheet: the production outline is pre-filled per corner in % of the image; mark
    "on the edge" when it is within ~1 % of the artwork's edge, else type the corners read off the zooms. Progress is
    kept in the browser; "Download truth.jsonl" saves the file next to items.json."""
    data = json.dumps(rows)
    kinds = json.dumps(KINDS)
    flags = json.dumps(FLAGS)
    quality = json.dumps(QUALITY)
    return """<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Artwork test set</title><style>
:root{--bg:#f6f6f4;--fg:#1d1d1b;--muted:#6b6b66;--card:#fff;--line:#ddd;--ok:#1f7a3a}
@media (prefers-color-scheme:dark){:root{--bg:#161615;--fg:#ecece8;--muted:#a3a39c;--card:#22221f;--line:#3a3a36;--ok:#5cc37f}}
body{margin:0;background:var(--bg);color:var(--fg);font:14px/1.45 -apple-system,system-ui,sans-serif}
header{position:sticky;top:0;background:var(--bg);border-bottom:1px solid var(--line);padding:10px 16px;z-index:2;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
main{padding:16px;max-width:1500px;margin:auto}.card{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:12px;margin:0 0 16px}
.card.done{border-color:var(--ok)}img{max-width:100%;height:auto;display:block}.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:8px}
input[type=number]{width:62px}label{white-space:nowrap}.muted{color:var(--muted)}button{font:inherit;padding:6px 12px}
</style></head><body><header><strong>Artwork test set</strong><span id="progress" class="muted"></span>
<button id="download">Download truth.jsonl</button><span class="muted">Corners in % of the image (x, y), read off the corner zooms (grid 1 %). The outline must sit on the artwork's real edge: nothing of the picture outside, no box print inside.</span></header>
<main id="list"></main><script>
const ROWS=""" + data + """, KINDS=""" + kinds + """, FLAGS=""" + flags + """, QUALITY=""" + quality + """, KEY="artwork-testset-v1";
let saved={};try{saved=JSON.parse(localStorage.getItem(KEY)||"{}")}catch(e){}
function state(r){return saved[r.puzzleId]||{kind:r.kind,corners:r.corners.map(p=>p.map(v=>Math.round(v*1000)/10)),flags:[],quality:[],note:"",checked:false,edge:null}}
function store(){try{localStorage.setItem(KEY,JSON.stringify(saved))}catch(e){};progress()}
function progress(){const n=ROWS.filter(r=>(saved[r.puzzleId]||{}).checked).length;document.getElementById("progress").textContent=n+" of "+ROWS.length+" marked"}
const list=document.getElementById("list");
ROWS.forEach(r=>{const s=state(r);const c=document.createElement("section");c.className="card"+(s.checked?" done":"");
const names=["TL","TR","BR","BL"];
c.innerHTML='<div class="muted">'+r.n+' / '+ROWS.length+' - '+r.puzzleId+'</div><img loading="lazy" src="'+r.sheet+'" alt="sheet '+r.n+'">'+
'<div class="row">'+names.map((nm,i)=>'<label>'+nm+' x <input type="number" step="0.1" data-c="'+i+'" data-a="0" value="'+s.corners[i][0]+'"> y <input type="number" step="0.1" data-c="'+i+'" data-a="1" value="'+s.corners[i][1]+'"></label>').join("")+'</div>'+
'<div class="row"><label>kind <select data-k>'+KINDS.map(k=>'<option'+(k===s.kind?' selected':'')+'>'+k+'</option>').join("")+'</select></label>'+
FLAGS.map(f=>'<label><input type="checkbox" data-f="'+f+'"'+(s.flags.includes(f)?' checked':'')+'> '+f+'</label>').join("")+'</div>'+
'<div class="row">'+QUALITY.map(q=>'<label><input type="checkbox" data-q="'+q+'"'+(s.quality.includes(q)?' checked':'')+'> '+q+'</label>').join("")+
'<label>note <input type="text" size="50" data-n value="'+(s.note||"").replace(/"/g,"&quot;")+'"></label></div>'+
'<div class="row"><button data-edge="1">Outline is on the edge</button><button data-edge="0">Corners corrected</button><span data-st class="muted">'+(s.checked?(s.edge?"marked: on the edge":"marked: corrected"):"not marked")+'</span></div>';
function read(){const t=saved[r.puzzleId]||state(r);c.querySelectorAll("input[data-c]").forEach(i=>{t.corners[+i.dataset.c][+i.dataset.a]=parseFloat(i.value)});
t.kind=c.querySelector("[data-k]").value;t.flags=[...c.querySelectorAll("input[data-f]:checked")].map(i=>i.dataset.f);
t.quality=[...c.querySelectorAll("input[data-q]:checked")].map(i=>i.dataset.q);t.note=c.querySelector("[data-n]").value;saved[r.puzzleId]=t;return t}
c.addEventListener("change",()=>{read();store()});
c.querySelectorAll("button[data-edge]").forEach(b=>b.addEventListener("click",()=>{const t=read();t.checked=true;t.edge=b.dataset.edge==="1";
c.classList.add("done");c.querySelector("[data-st]").textContent=t.edge?"marked: on the edge":"marked: corrected";store()}));
list.appendChild(c)});
document.getElementById("download").addEventListener("click",()=>{const lines=ROWS.map(r=>{const t=saved[r.puzzleId]||state(r);
return JSON.stringify({puzzleId:r.puzzleId,fingerprint:r.fingerprint,kind:t.kind,corners:t.corners.map(p=>p.map(v=>Math.round(v*10)/1000)),
flags:t.flags,quality:t.quality,note:t.note,checked:!!t.checked,onEdge:t.edge})}).join("\\n")+"\\n";
const a=document.createElement("a");a.href=URL.createObjectURL(new Blob([lines],{type:"application/json"}));a.download="truth.jsonl";a.click()});
progress();
</script></body></html>
"""


def main(argv: list[str] | None = None) -> int:
    from .config import DEFAULT_BASE_URL

    p = argparse.ArgumentParser(prog="python -m puzzle_vision.artwork_testset")
    sub = p.add_subparsers(dest="cmd", required=True)
    s = sub.add_parser("strata")
    s.add_argument("--profile", default="m3-solo")
    s.add_argument("--base-url", default=DEFAULT_BASE_URL)
    s.add_argument("--write", action="store_true", help="update reference/population-strata.json")
    s = sub.add_parser("select")
    s.add_argument("--profile", default="m3-solo")
    s.add_argument("--base-url", default=DEFAULT_BASE_URL)
    s.add_argument("--n", type=int, default=sum(ALLOCATION.values()))
    s.add_argument("--seed", type=int, default=20261027)
    s.add_argument("--out", required=True)
    for name in ("sheets", "import"):
        s = sub.add_parser(name)
        s.add_argument("--out", required=True)
    a = p.parse_args(argv)
    return {"strata": cmd_strata, "select": cmd_select, "sheets": cmd_sheets, "import": cmd_import}[a.cmd](a)


if __name__ == "__main__":
    raise SystemExit(main())
