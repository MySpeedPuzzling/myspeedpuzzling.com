#!/usr/bin/env python3
"""Round 2: flood-fill cutout (replaces neural matting), re-compose, Klein polish across ALL 5 tiers."""
import os, shutil, sys
from PIL import Image, ImageDraw
from badge_pipeline import (OUT, COMFY_IN, ROOT, run_graph, g_klein_edit, free_memory,
                            render_all_frames, compose_set, EDIT_PROMPT)

def floodfill_cutout(src_path, dst_path, thresh=40):
    """Remove only background-connected near-white pixels; keep interior white fills."""
    im = Image.open(src_path).convert("RGB")
    pad = Image.new("RGB", (im.width + 2, im.height + 2), (255, 255, 255))
    pad.paste(im, (1, 1))
    MAGIC = (255, 0, 255)
    ImageDraw.floodfill(pad, (0, 0), MAGIC, thresh=thresh)
    rgba = pad.convert("RGBA")
    px = rgba.load()
    for y in range(rgba.height):
        for x in range(rgba.width):
            if px[x, y][:3] == MAGIC:
                px[x, y] = (0, 0, 0, 0)
    out = rgba.crop((1, 1, rgba.width - 1, rgba.height - 1))
    out.save(dst_path)
    print(f"floodfill cutout -> {dst_path}", flush=True)
    return dst_path

def main():
    frames = [os.path.join(OUT, f"frame-tier{t}.png") for t in range(1, 6)]
    if not all(os.path.exists(p) for p in frames):
        frames = render_all_frames()

    # 1) proper icon cutout via flood fill (keeps white clock face)
    icon_src = os.path.join(ROOT, "bakeoff", "klein9b.png")
    icon_rgba = floodfill_cutout(icon_src, os.path.join(OUT, "icon-rgba-v2.png"))

    # 2) re-compose all 5 tiers with the fixed icon
    badges = compose_set(frames, icon_rgba)

    # 3) Klein polish pass on every tier
    polished = []
    for t, b in zip(range(1, 6), badges):
        flat = Image.new("RGBA", (1024, 1024), (255, 255, 255, 255))
        flat.alpha_composite(Image.open(b).convert("RGBA"))
        in_name = f"set_tier{t}_input.png"
        flat.convert("RGB").save(os.path.join(COMFY_IN, in_name))
        files = run_graph(f"polish tier {t}", g_klein_edit(in_name))
        if files:
            dst = os.path.join(OUT, f"polished-tier{t}.png")
            shutil.copy(files[0], dst)
            polished.append(dst)
        else:
            polished.append(None)
    free_memory()

    # 4) background cutout on polished outputs -> final RGBA assets
    finals = []
    for t, p in zip(range(1, 6), polished):
        if p:
            finals.append(floodfill_cutout(p, os.path.join(OUT, f"final-tier{t}.png"), thresh=25))
        else:
            finals.append(None)

    # 5) sheet v2: composed row vs polished row
    TH = 384
    sheet = Image.new("RGB", (TH * 5, (TH + 40) * 2), (245, 246, 250))
    d = ImageDraw.Draw(sheet)
    d.text((10, 4), "B v2 — deterministic frames + flood-fill icon (composed input)", fill=(43, 52, 69))
    for i, p in enumerate(badges):
        im = Image.open(p).convert("RGBA")
        bg = Image.new("RGBA", im.size, (255, 255, 255, 255)); bg.alpha_composite(im)
        sheet.paste(bg.convert("RGB").resize((TH, TH)), (i * TH, 40))
    d.text((10, TH + 44), "C v2 — after Klein 9B polish pass (final, bg removed)", fill=(43, 52, 69))
    for i, p in enumerate(finals):
        if not p: continue
        im = Image.open(p).convert("RGBA")
        bg = Image.new("RGBA", im.size, (255, 255, 255, 255)); bg.alpha_composite(im)
        sheet.paste(bg.convert("RGB").resize((TH, TH)), (i * TH, TH + 80))
    sp = os.path.join(OUT, "speed-demon-v2-sheet.png")
    sheet.save(sp)
    print(f"\nSHEET V2 -> {sp}", flush=True)

if __name__ == "__main__":
    main()
