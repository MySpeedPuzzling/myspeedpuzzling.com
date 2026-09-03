#!/usr/bin/env python3
"""Round 3: per-tier polish prompts (fix the fill-override bug from round 2)."""
import os, shutil
from PIL import Image, ImageDraw
from badge_pipeline import OUT, COMFY_IN, run_graph, g_klein_edit, free_memory
from badge_pipeline2 import floodfill_cutout

COMMON = ("Redraw this achievement badge as a polished flat vector illustration. "
          "Keep the exact same jigsaw puzzle piece shape and connector knob positions, "
          "the same uniform dark navy #2b3445 outline, and the same stopwatch icon with "
          "white clock face and coral accents in the center. Hand-illustrated feel with smooth "
          "rounded linework of uniform weight. Pure white background. "
          "Do not change the geometry, do not add or remove elements, no text. ")

TIER_FILL = {
    1: "The badge body fill is flat very light gray #f6f9fc — keep it flat and light, no gradient, no other body colors.",
    2: "The badge body fill is flat coral #EC726F — keep it flat solid coral, no gradient, no blue.",
    3: "The badge body fill is a smooth soft diagonal gradient: coral #EC726F at the top-left blending gently into sky blue #69b3fe at the bottom-right.",
    4: "The badge body fill is a smooth soft diagonal gradient: coral #EC726F at the top-left blending gently into deep indigo #4e54c8 at the bottom-right.",
    5: ("The badge body fill is a rich smooth diagonal gradient: coral #EC726F at the top-left blending into deep indigo #4e54c8 "
        "at the bottom-right, decorated with a few small white four-pointed sparkle stars — keep the sparkles."),
}

def main():
    badges = [os.path.join(OUT, f"badge-tier{t}.png") for t in range(1, 6)]
    polished, finals = [], []
    for t, b in zip(range(1, 6), badges):
        flat = Image.new("RGBA", (1024, 1024), (255, 255, 255, 255))
        flat.alpha_composite(Image.open(b).convert("RGBA"))
        in_name = f"set_tier{t}_input.png"
        flat.convert("RGB").save(os.path.join(COMFY_IN, in_name))
        files = run_graph(f"polish v3 tier {t}", g_klein_edit(in_name, prompt=COMMON + TIER_FILL[t]))
        if files:
            dst = os.path.join(OUT, f"polished3-tier{t}.png")
            shutil.copy(files[0], dst)
            polished.append(dst)
            finals.append(floodfill_cutout(dst, os.path.join(OUT, f"final3-tier{t}.png"), thresh=25))
        else:
            polished.append(None); finals.append(None)
    free_memory()

    TH = 384
    sheet = Image.new("RGB", (TH * 5, (TH + 40) * 2), (245, 246, 250))
    d = ImageDraw.Draw(sheet)
    d.text((10, 4), "input (composed, deterministic)", fill=(43, 52, 69))
    for i, p in enumerate(badges):
        im = Image.open(p).convert("RGBA")
        bg = Image.new("RGBA", im.size, (255, 255, 255, 255)); bg.alpha_composite(im)
        sheet.paste(bg.convert("RGB").resize((TH, TH)), (i * TH, 40))
    d.text((10, TH + 44), "v3 polished (per-tier prompts, bg removed)", fill=(43, 52, 69))
    for i, p in enumerate(finals):
        if not p: continue
        im = Image.open(p).convert("RGBA")
        bg = Image.new("RGBA", im.size, (255, 255, 255, 255)); bg.alpha_composite(im)
        sheet.paste(bg.convert("RGB").resize((TH, TH)), (i * TH, TH + 80))
    sp = os.path.join(OUT, "speed-demon-v3-sheet.png")
    sheet.save(sp)
    print(f"\nSHEET V3 -> {sp}", flush=True)

if __name__ == "__main__":
    main()
