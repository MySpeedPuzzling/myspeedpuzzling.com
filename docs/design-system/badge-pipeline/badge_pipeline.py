#!/usr/bin/env python3
"""Speed Demon full-set experiment: A) AI grid  B) deterministic frames + AI icon  C) AI polish pass.

Usage: badge_pipeline.py frames|run
  frames — render the 5 tier frames only (fast, for visual verification)
  run    — full end-to-end: matting -> pipeline A -> compose B -> pipeline C -> sheets
"""
import json, os, shutil, sys, time, urllib.request
from PIL import Image, ImageDraw, ImageFilter

API = "http://127.0.0.1:8000"
ROOT = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(ROOT, "badge-set")
COMFY_IN = os.path.expanduser("~/Documents/ComfyUI/input")
COMFY_OUT = os.path.expanduser("~/Documents/ComfyUI/output")
os.makedirs(OUT, exist_ok=True)

NAVY = (0x2b, 0x34, 0x45, 255)
CORAL = (0xEC, 0x72, 0x6F)
SKY = (0x69, 0xb3, 0xfe)
INDIGO = (0x4e, 0x54, 0xc8)
GRAY = (0xf6, 0xf9, 0xfc)
SEED = 42

# ---------------- deterministic frame renderer ----------------
# tier -> (tabs on [top, right, bottom, left], fill spec)
TIERS = {
    1: ([0, 0, 0, 0], ("flat", GRAY)),
    2: ([1, 0, 0, 0], ("flat", CORAL)),
    3: ([1, 1, 0, 0], ("grad", CORAL, SKY)),
    4: ([1, 1, 1, 0], ("grad", CORAL, INDIGO)),
    5: ([1, 1, 1, 1], ("grad_sparkle", CORAL, INDIGO)),
}

def diagonal_gradient(size, c1, c2):
    g = Image.new("RGB", (64, 64))
    px = g.load()
    for y in range(64):
        for x in range(64):
            t = (x + y) / 126.0
            px[x, y] = tuple(int(a + (b - a) * t) for a, b in zip(c1, c2))
    return g.resize(size, Image.BILINEAR)

def render_frame(tier, final=1024):
    W = final * 2                       # supersample 2x
    B = int(W * 0.55)                   # body size
    r_corner = int(B * 0.16)
    knob_d = int(B * 0.25)
    off = int(B * 0.075)                # knob center offset from edge line
    x0 = (W - B) // 2
    y0 = (W - B) // 2
    x1, y1 = x0 + B, y0 + B
    cx, cy = W // 2, W // 2

    mask = Image.new("L", (W, W), 0)
    d = ImageDraw.Draw(mask)
    d.rounded_rectangle([x0, y0, x1, y1], radius=r_corner, fill=255)
    tabs = TIERS[tier][0]
    centers = {  # side -> (knob center for tab, for socket)
        0: ((cx, y0 - off), (cx, y0 + off)),
        1: ((x1 + off, cy), (x1 - off, cy)),
        2: ((cx, y1 + off), (cx, y1 - off)),
        3: ((x0 - off, cy), (x0 + off, cy)),
    }
    rr = knob_d // 2
    for side in range(4):               # tabs first (add), sockets after (cut)
        if tabs[side]:
            px, py = centers[side][0]
            d.ellipse([px - rr, py - rr, px + rr, py + rr], fill=255)
    for side in range(4):
        if not tabs[side]:
            px, py = centers[side][1]
            d.ellipse([px - rr, py - rr, px + rr, py + rr], fill=0)

    stroke = 17                          # MaxFilter/MinFilter kernel -> ~16px ring at 2x
    ring = Image.new("L", (W, W), 0)
    grown = mask.filter(ImageFilter.MaxFilter(stroke))
    shrunk = mask.filter(ImageFilter.MinFilter(stroke))
    ring.paste(255, (0, 0), grown)
    ring.paste(0, (0, 0), shrunk)

    fill_spec = TIERS[tier][1]
    if fill_spec[0] == "flat":
        fill_img = Image.new("RGB", (W, W), fill_spec[1])
    else:
        fill_img = diagonal_gradient((W, W), fill_spec[1], fill_spec[2])

    canvas = Image.new("RGBA", (W, W), (0, 0, 0, 0))
    canvas.paste(fill_img, (0, 0), mask)
    navy_img = Image.new("RGBA", (W, W), NAVY)
    canvas.paste(navy_img, (0, 0), ring)

    if fill_spec[0] == "grad_sparkle":
        sp = ImageDraw.Draw(canvas)
        def star(cx_, cy_, r):
            pts = [(cx_, cy_ - r), (cx_ + r * .22, cy_ - r * .22), (cx_ + r, cy_),
                   (cx_ + r * .22, cy_ + r * .22), (cx_, cy_ + r), (cx_ - r * .22, cy_ + r * .22),
                   (cx_ - r, cy_), (cx_ - r * .22, cy_ - r * .22)]
            sp.polygon(pts, fill=(255, 255, 255, 235))
        star(x0 + B * .26, y0 + B * .22, B * .052)
        star(x0 + B * .78, y0 + B * .16, B * .034)
        star(x0 + B * .74, y0 + B * .80, B * .044)
        for dx, dy, r in [(.18, .64, .013), (.84, .46, .011)]:
            sp.ellipse([x0 + B * dx - B * r, y0 + B * dy - B * r,
                        x0 + B * dx + B * r, y0 + B * dy + B * r], fill=(255, 255, 255, 220))

    return canvas.resize((final, final), Image.LANCZOS)

def render_all_frames():
    paths = []
    for t in range(1, 6):
        p = os.path.join(OUT, f"frame-tier{t}.png")
        render_frame(t).save(p)
        paths.append(p)
        print(f"frame tier {t} -> {p}", flush=True)
    sheet = Image.new("RGBA", (5 * 512, 512), (245, 246, 250, 255))
    for i, p in enumerate(paths):
        sheet.paste(Image.open(p).resize((512, 512)), (i * 512, 0))
    sp = os.path.join(OUT, "frames-sheet.png")
    sheet.convert("RGB").save(sp)
    print(f"frames sheet -> {sp}", flush=True)
    return paths

# ---------------- comfy helpers ----------------
def post(path, payload):
    req = urllib.request.Request(API + path, data=json.dumps(payload).encode(),
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.load(r) if r.length else {}

def run_graph(name, graph, timeout=1200):
    print(f"\n=== {name}", flush=True)
    t0 = time.time()
    res = post("/prompt", {"prompt": graph})
    pid = res.get("prompt_id")
    if not pid:
        print(f"  REJECTED: {json.dumps(res)[:600]}", flush=True)
        return None
    while time.time() - t0 < timeout:
        time.sleep(2)
        try:
            with urllib.request.urlopen(f"{API}/history/{pid}", timeout=30) as r:
                h = json.load(r).get(pid)
        except Exception:
            continue
        if not h:
            continue
        st = h.get("status", {})
        if st.get("completed"):
            files = []
            for node_out in h.get("outputs", {}).values():
                for img in node_out.get("images", []):
                    files.append(os.path.join(COMFY_OUT, img.get("subfolder", ""), img["filename"]))
            print(f"  DONE in {time.time()-t0:.0f}s -> {files}", flush=True)
            return files
        if st.get("status_str") == "error":
            msgs = [m for m in st.get("messages", []) if m[0] == "execution_error"]
            print(f"  ERROR: {(msgs[0][1].get('exception_message','?') if msgs else '?')[:400]}", flush=True)
            return None
    print("  TIMEOUT", flush=True)
    return None

def free_memory():
    try:
        post("/free", {"unload_models": True, "free_memory": True})
        time.sleep(2)
    except Exception:
        pass

# ---------------- pipeline jobs ----------------
GRID_PROMPT = (
    "A horizontal row of five achievement badges on a pure white background, evenly spaced. "
    "Each badge is a jigsaw puzzle piece with a rounded square body and one connector knob on each of its four sides, "
    "drawn as a flat vector illustration with uniform dark navy #2b3445 outlines. "
    "All five badges contain the same small stopwatch icon at the center, drawn with navy outlines, white fill and a coral accent. "
    "Badge 1: all four connectors are concave sockets cut inward; body filled flat very light gray #f6f9fc. "
    "Badge 2: the top connector is a convex tab sticking out, the other three are concave sockets; body filled flat coral #EC726F. "
    "Badge 3: top and right connectors are convex tabs, bottom and left are concave sockets; body filled with a diagonal gradient from coral #EC726F to sky blue #69b3fe. "
    "Badge 4: top, right and bottom connectors are convex tabs, only left is a concave socket; body diagonal gradient from coral #EC726F to indigo #4e54c8. "
    "Badge 5: all four connectors are convex tabs; body rich diagonal gradient from coral #EC726F to indigo #4e54c8 with small white sparkles. "
    "Flat vector style, no text, no shadows, crisp uniform 2px outlines."
)

EDIT_PROMPT = (
    "Redraw this badge as a polished flat vector illustration. Keep the exact same jigsaw puzzle piece shape, "
    "the same connector knob positions, the same diagonal coral #EC726F to sky blue #69b3fe gradient fill, "
    "the same dark navy #2b3445 outline and the same stopwatch icon in the center. "
    "Make the linework feel hand-illustrated: smooth rounded outlines with uniform weight, gentle soft shading inside fills. "
    "Pure white background. Do not change the geometry, do not add or remove elements, no text."
)

def klein_loaders(g):
    g["1"] = {"class_type": "UnetLoaderGGUF", "inputs": {"unet_name": "flux-2-klein-9b-Q8_0.gguf"}}
    g["2"] = {"class_type": "CLIPLoaderGGUF", "inputs": {"clip_name": "Qwen3-8B-Q8_0.gguf", "type": "flux2"}}
    g["3"] = {"class_type": "VAELoader", "inputs": {"vae_name": "flux2-vae.safetensors"}}
    return g

def g_klein_grid(width=2048, height=512, steps=8):
    g = klein_loaders({})
    g.update({
        "4": {"class_type": "CLIPTextEncode", "inputs": {"text": GRID_PROMPT, "clip": ["2", 0]}},
        "5": {"class_type": "ConditioningZeroOut", "inputs": {"conditioning": ["4", 0]}},
        "6": {"class_type": "CFGGuider", "inputs": {"model": ["1", 0], "positive": ["4", 0], "negative": ["5", 0], "cfg": 1.0}},
        "7": {"class_type": "KSamplerSelect", "inputs": {"sampler_name": "euler"}},
        "8": {"class_type": "Flux2Scheduler", "inputs": {"steps": steps, "width": width, "height": height}},
        "9": {"class_type": "RandomNoise", "inputs": {"noise_seed": SEED}},
        "10": {"class_type": "EmptyFlux2LatentImage", "inputs": {"width": width, "height": height, "batch_size": 1}},
        "11": {"class_type": "SamplerCustomAdvanced", "inputs": {"noise": ["9", 0], "guider": ["6", 0],
               "sampler": ["7", 0], "sigmas": ["8", 0], "latent_image": ["10", 0]}},
        "12": {"class_type": "VAEDecode", "inputs": {"samples": ["11", 0], "vae": ["3", 0]}},
        "13": {"class_type": "SaveImage", "inputs": {"filename_prefix": "set_A_grid", "images": ["12", 0]}},
    })
    return g

def g_matting(input_name):
    return {
        "1": {"class_type": "LoadImage", "inputs": {"image": input_name}},
        "2": {"class_type": "InspyrenetRembg", "inputs": {"image": ["1", 0], "torchscript_jit": "default"}},
        "3": {"class_type": "SaveImage", "inputs": {"filename_prefix": "set_B_icon_rgba", "images": ["2", 0]}},
    }

def g_klein_edit(input_name, steps=8, prompt=None):
    g = klein_loaders({})
    g.update({
        "4": {"class_type": "LoadImage", "inputs": {"image": input_name}},
        "5": {"class_type": "ImageScaleToTotalPixels", "inputs": {"image": ["4", 0], "upscale_method": "lanczos",
               "megapixels": 1.0, "resolution_steps": 1}},
        "6": {"class_type": "VAEEncode", "inputs": {"pixels": ["5", 0], "vae": ["3", 0]}},
        "7": {"class_type": "CLIPTextEncode", "inputs": {"text": prompt or EDIT_PROMPT, "clip": ["2", 0]}},
        "8": {"class_type": "ReferenceLatent", "inputs": {"conditioning": ["7", 0], "latent": ["6", 0]}},
        "9": {"class_type": "ConditioningZeroOut", "inputs": {"conditioning": ["8", 0]}},
        "10": {"class_type": "CFGGuider", "inputs": {"model": ["1", 0], "positive": ["8", 0], "negative": ["9", 0], "cfg": 1.0}},
        "11": {"class_type": "KSamplerSelect", "inputs": {"sampler_name": "euler"}},
        "12": {"class_type": "Flux2Scheduler", "inputs": {"steps": steps, "width": 1024, "height": 1024}},
        "13": {"class_type": "RandomNoise", "inputs": {"noise_seed": SEED}},
        "14": {"class_type": "EmptyFlux2LatentImage", "inputs": {"width": 1024, "height": 1024, "batch_size": 1}},
        "15": {"class_type": "SamplerCustomAdvanced", "inputs": {"noise": ["13", 0], "guider": ["10", 0],
               "sampler": ["11", 0], "sigmas": ["12", 0], "latent_image": ["14", 0]}},
        "16": {"class_type": "VAEDecode", "inputs": {"samples": ["15", 0], "vae": ["3", 0]}},
        "17": {"class_type": "SaveImage", "inputs": {"filename_prefix": "set_C_polish", "images": ["16", 0]}},
    })
    return g

# ---------------- composition ----------------
def compose_set(frame_paths, icon_rgba_path):
    icon = Image.open(icon_rgba_path).convert("RGBA")
    bbox = icon.getbbox()
    icon = icon.crop(bbox)
    badges = []
    for t, fp in zip(range(1, 6), frame_paths):
        frame = Image.open(fp).convert("RGBA")
        W = frame.width
        body = int(W * 0.55)
        target = int(body * 0.52)
        ic = icon.copy()
        scale = min(target / ic.width, target / ic.height)
        ic = ic.resize((max(1, int(ic.width * scale)), max(1, int(ic.height * scale))), Image.LANCZOS)
        out = frame.copy()
        out.alpha_composite(ic, ((W - ic.width) // 2, (W - ic.height) // 2))
        p = os.path.join(OUT, f"badge-tier{t}.png")
        out.save(p)
        badges.append(p)
        print(f"composed tier {t} -> {p}", flush=True)
    return badges

def final_sheet(a_grid, badges, c_before, c_after):
    TH = 384
    rows = 3
    sheet = Image.new("RGB", (TH * 5, TH * rows + 40 * rows), (245, 246, 250))
    d = ImageDraw.Draw(sheet)
    y = 0
    d.text((10, y + 4), "A — pure AI grid (Klein 9B, one prompt)", fill=(43, 52, 69))
    if a_grid:
        g = Image.open(a_grid).convert("RGB")
        g = g.resize((TH * 5, int(g.height * (TH * 5 / g.width))))
        sheet.paste(g, (0, y + 40 + max(0, (TH - g.height) // 2)))
    y += TH + 40
    d.text((10, y + 4), "B — deterministic frames + AI icon (composed)", fill=(43, 52, 69))
    for i, p in enumerate(badges):
        im = Image.open(p).convert("RGBA")
        bg = Image.new("RGBA", im.size, (255, 255, 255, 255))
        bg.alpha_composite(im)
        sheet.paste(bg.convert("RGB").resize((TH, TH)), (i * TH, y + 40))
    y += TH + 40
    d.text((10, y + 4), "C — AI polish pass over composed tier-3 (left: input, right: Klein output)", fill=(43, 52, 69))
    for i, p in enumerate([c_before, c_after]):
        if p and os.path.exists(p):
            im = Image.open(p).convert("RGBA")
            bg = Image.new("RGBA", im.size, (255, 255, 255, 255))
            bg.alpha_composite(im)
            sheet.paste(bg.convert("RGB").resize((TH, TH)), (i * TH, y + 40))
    p = os.path.join(OUT, "speed-demon-set-sheet.png")
    sheet.save(p)
    print(f"\nFINAL SHEET -> {p}", flush=True)
    return p

# ---------------- main ----------------
def main():
    stage = sys.argv[1] if len(sys.argv) > 1 else "run"
    frames = render_all_frames()
    if stage == "frames":
        return

    # 1) matting of the round-1 winning icon (Klein 9B stopwatch)
    icon_src = os.path.join(ROOT, "bakeoff", "klein9b.png")
    shutil.copy(icon_src, os.path.join(COMFY_IN, "set_icon_src.png"))
    matte_files = run_graph("matting (Inspyrenet)", g_matting("set_icon_src.png"))
    icon_rgba = None
    if matte_files:
        icon_rgba = os.path.join(OUT, "icon-rgba.png")
        shutil.copy(matte_files[0], icon_rgba)

    # 2) pipeline A: one-shot AI grid
    a_files = run_graph("pipeline A: Klein 9B 5-badge grid", g_klein_grid())
    a_grid = None
    if a_files:
        a_grid = os.path.join(OUT, "A-grid.png")
        shutil.copy(a_files[0], a_grid)

    # 3) pipeline B: compose
    badges = []
    if icon_rgba:
        badges = compose_set(frames, icon_rgba)

    # 4) pipeline C: polish pass on composed tier-3
    c_after = None
    c_before = badges[2] if len(badges) >= 3 else None
    if c_before:
        flat = Image.new("RGBA", (1024, 1024), (255, 255, 255, 255))
        flat.alpha_composite(Image.open(c_before).convert("RGBA"))
        flat.convert("RGB").save(os.path.join(COMFY_IN, "set_tier3_input.png"))
        c_files = run_graph("pipeline C: Klein 9B polish pass", g_klein_edit("set_tier3_input.png"))
        if c_files:
            c_after = os.path.join(OUT, "C-polish.png")
            shutil.copy(c_files[0], c_after)
    free_memory()

    final_sheet(a_grid, badges, c_before, c_after)
    print("\nDONE. Results in", OUT, flush=True)

if __name__ == "__main__":
    main()
