#!/usr/bin/env python3
"""Badge bake-off — icon round (stopwatch subject) across 4 model families."""
import json, time, urllib.request, shutil, os, sys

API = "http://127.0.0.1:8000"
OUT_DIR = os.path.expanduser("~/Documents/ComfyUI/output")
RESULTS = os.path.join(os.path.dirname(os.path.abspath(__file__)), "bakeoff")
os.makedirs(RESULTS, exist_ok=True)

STYLE = ("semi-flat vector icon, thick dark navy #2b3445 outlines with rounded caps, "
         "white fills with soft coral #EC726F accents, minimal detail, rounded friendly geometry, "
         "centered composition, plain white background, no text, no shadows")
SUBJECT = ("a stopwatch with a round clock face, two clock hands, a small press button on top, "
           "and two tiny horizontal speed-line dashes to the right")
KLEIN_PROMPT = ("Flat vector icon of a stopwatch with a round clock face, two clock hands, "
                "a small press button on top, and two tiny speed-line dashes on the right. "
                "Uniform 2px outlines in exact color #2b3445 with rounded caps and joins. "
                "Fills: white #ffffff base; coral #EC726F accents on the press button and one clock hand. "
                "Semi-flat minimal illustration, rounded friendly geometry, centered, "
                "plain pure white #ffffff background, no text, no shadows, no gradients.")
NEG = "photo, photorealistic, 3d render, gradients, drop shadow, texture, noise, complex background, text, watermark"
SEED = 42

def post(path, payload):
    req = urllib.request.Request(API + path, data=json.dumps(payload).encode(),
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.load(r) if r.length else {}

def free_memory():
    try:
        post("/free", {"unload_models": True, "free_memory": True})
        time.sleep(2)
    except Exception as e:
        print(f"  (free_memory failed: {e})", flush=True)

def run(name, graph, timeout=900):
    print(f"\n=== {name}", flush=True)
    t0 = time.time()
    try:
        res = post("/prompt", {"prompt": graph})
    except Exception as e:
        print(f"  ENQUEUE FAILED: {e}", flush=True)
        return {"name": name, "status": "enqueue_failed", "error": str(e)}
    pid = res.get("prompt_id")
    if not pid:
        print(f"  REJECTED: {json.dumps(res)[:500]}", flush=True)
        return {"name": name, "status": "rejected", "error": json.dumps(res)[:500]}
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
            dt = time.time() - t0
            files = []
            for node_out in h.get("outputs", {}).values():
                for img in node_out.get("images", []):
                    src = os.path.join(OUT_DIR, img.get("subfolder", ""), img["filename"])
                    dst = os.path.join(RESULTS, f"{name}.png")
                    try:
                        shutil.copy(src, dst)
                        files.append(dst)
                    except Exception as e:
                        print(f"  copy failed {src}: {e}", flush=True)
            print(f"  DONE in {dt:.0f}s -> {files}", flush=True)
            return {"name": name, "status": "ok", "seconds": round(dt), "files": files}
        if st.get("status_str") == "error":
            msgs = [m for m in st.get("messages", []) if m[0] == "execution_error"]
            err = (msgs[0][1].get("exception_message", "?") if msgs else "?")[:400]
            print(f"  ERROR after {time.time()-t0:.0f}s: {err}", flush=True)
            return {"name": name, "status": "error", "error": err}
    print(f"  TIMEOUT after {timeout}s", flush=True)
    return {"name": name, "status": "timeout"}

def save(prefix, images_ref):
    return {"class_type": "SaveImage", "inputs": {"filename_prefix": prefix, "images": images_ref}}

# ---------- graphs ----------

def g_sdxl_vector_lora():
    p = f"color icon, flat vector icon of {SUBJECT}, {STYLE}"
    return {
        "1": {"class_type": "CheckpointLoaderSimple", "inputs": {"ckpt_name": "sd_xl_base_1.0.safetensors"}},
        "2": {"class_type": "LoraLoader", "inputs": {"model": ["1", 0], "clip": ["1", 1],
              "lora_name": "Vector_illustration_XL.safetensors", "strength_model": 0.9, "strength_clip": 0.9}},
        "3": {"class_type": "CLIPTextEncode", "inputs": {"text": p, "clip": ["2", 1]}},
        "4": {"class_type": "CLIPTextEncode", "inputs": {"text": NEG, "clip": ["2", 1]}},
        "5": {"class_type": "EmptyLatentImage", "inputs": {"width": 1024, "height": 1024, "batch_size": 1}},
        "6": {"class_type": "KSampler", "inputs": {"seed": SEED, "steps": 25, "cfg": 7.0,
              "sampler_name": "euler", "scheduler": "normal", "denoise": 1.0,
              "model": ["2", 0], "positive": ["3", 0], "negative": ["4", 0], "latent_image": ["5", 0]}},
        "7": {"class_type": "VAEDecode", "inputs": {"samples": ["6", 0], "vae": ["1", 2]}},
        "8": save("bake_sdxl-vectorlora", ["7", 0]),
    }

def g_zimage(unet_gguf=False, icons_lora=False):
    p = f"flat vector icon of {SUBJECT}, {STYLE}"
    if icons_lora:
        p = "ICREDM, ICONS, app icon, " + p
    g = {}
    if unet_gguf:
        g["1"] = {"class_type": "UnetLoaderGGUF", "inputs": {"unet_name": "z_image_turbo-Q8_0.gguf"}}
    else:
        g["1"] = {"class_type": "UNETLoader", "inputs": {"unet_name": "z_image_turbo_bf16.safetensors", "weight_dtype": "default"}}
    g["3"] = {"class_type": "CLIPLoader", "inputs": {"clip_name": "qwen_3_4b.safetensors", "type": "lumina2", "device": "default"}}
    model_ref, clip_ref = ["1", 0], ["3", 0]
    if icons_lora:
        g["11"] = {"class_type": "LoraLoader", "inputs": {"model": ["1", 0], "clip": ["3", 0],
                   "lora_name": "Icons_Redmond_ZImageTurbo.safetensors", "strength_model": 1.0, "strength_clip": 1.0}}
        model_ref, clip_ref = ["11", 0], ["11", 1]
    g["2"] = {"class_type": "ModelSamplingAuraFlow", "inputs": {"model": model_ref, "shift": 3.0}}
    g["4"] = {"class_type": "CLIPTextEncode", "inputs": {"text": p, "clip": clip_ref}}
    g["5"] = {"class_type": "ConditioningZeroOut", "inputs": {"conditioning": ["4", 0]}}
    g["6"] = {"class_type": "EmptySD3LatentImage", "inputs": {"width": 1024, "height": 1024, "batch_size": 1}}
    g["7"] = {"class_type": "KSampler", "inputs": {"seed": SEED, "steps": 9, "cfg": 1.0,
              "sampler_name": "res_multistep", "scheduler": "simple", "denoise": 1.0,
              "model": ["2", 0], "positive": ["4", 0], "negative": ["5", 0], "latent_image": ["6", 0]}}
    g["8"] = {"class_type": "VAELoader", "inputs": {"vae_name": "z_image_ae.safetensors"}}
    g["9"] = {"class_type": "VAEDecode", "inputs": {"samples": ["7", 0], "vae": ["8", 0]}}
    suffix = "gguf" if unet_gguf else ("iconslora" if icons_lora else "bf16")
    g["10"] = save(f"bake_zimage-{suffix}", ["9", 0])
    return g

def g_klein(size="9b", steps=8):
    if size == "9b":
        unet = {"class_type": "UnetLoaderGGUF", "inputs": {"unet_name": "flux-2-klein-9b-Q8_0.gguf"}}
        clip = {"class_type": "CLIPLoaderGGUF", "inputs": {"clip_name": "Qwen3-8B-Q8_0.gguf", "type": "flux2"}}
    else:
        unet = {"class_type": "UnetLoaderGGUF", "inputs": {"unet_name": "flux-2-klein-4b-Q8_0.gguf"}}
        clip = {"class_type": "CLIPLoader", "inputs": {"clip_name": "qwen_3_4b.safetensors", "type": "flux2", "device": "default"}}
    return {
        "1": unet, "2": clip,
        "3": {"class_type": "VAELoader", "inputs": {"vae_name": "flux2-vae.safetensors"}},
        "4": {"class_type": "CLIPTextEncode", "inputs": {"text": KLEIN_PROMPT, "clip": ["2", 0]}},
        "5": {"class_type": "ConditioningZeroOut", "inputs": {"conditioning": ["4", 0]}},
        "6": {"class_type": "CFGGuider", "inputs": {"model": ["1", 0], "positive": ["4", 0], "negative": ["5", 0], "cfg": 1.0}},
        "7": {"class_type": "KSamplerSelect", "inputs": {"sampler_name": "euler"}},
        "8": {"class_type": "Flux2Scheduler", "inputs": {"steps": steps, "width": 1024, "height": 1024}},
        "9": {"class_type": "RandomNoise", "inputs": {"noise_seed": SEED}},
        "10": {"class_type": "EmptyFlux2LatentImage", "inputs": {"width": 1024, "height": 1024, "batch_size": 1}},
        "11": {"class_type": "SamplerCustomAdvanced", "inputs": {"noise": ["9", 0], "guider": ["6", 0],
               "sampler": ["7", 0], "sigmas": ["8", 0], "latent_image": ["10", 0]}},
        "12": {"class_type": "VAEDecode", "inputs": {"samples": ["11", 0], "vae": ["3", 0]}},
        "13": save(f"bake_klein{size}", ["12", 0]),
    }

def g_qwen_edit_t2i():
    return {
        "1": {"class_type": "UnetLoaderGGUF", "inputs": {"unet_name": "qwen-image-edit-2511-Q6_K.gguf"}},
        "2": {"class_type": "LoraLoaderModelOnly", "inputs": {"model": ["1", 0],
              "lora_name": "Qwen-Image-Edit-2509-Lightning-4steps-V1.0-bf16.safetensors", "strength_model": 1.0}},
        "3": {"class_type": "ModelSamplingAuraFlow", "inputs": {"model": ["2", 0], "shift": 3.1}},
        "4": {"class_type": "CFGNorm", "inputs": {"model": ["3", 0], "strength": 1.0}},
        "5": {"class_type": "CLIPLoaderGGUF", "inputs": {"clip_name": "Qwen2.5-VL-7B-Instruct-Q8_0.gguf", "type": "qwen_image"}},
        "6": {"class_type": "TextEncodeQwenImageEditPlus", "inputs": {"clip": ["5", 0], "prompt": KLEIN_PROMPT}},
        "7": {"class_type": "TextEncodeQwenImageEditPlus", "inputs": {"clip": ["5", 0], "prompt": ""}},
        "8": {"class_type": "EmptySD3LatentImage", "inputs": {"width": 1024, "height": 1024, "batch_size": 1}},
        "9": {"class_type": "KSampler", "inputs": {"seed": SEED, "steps": 4, "cfg": 1.0,
              "sampler_name": "euler", "scheduler": "simple", "denoise": 1.0,
              "model": ["4", 0], "positive": ["6", 0], "negative": ["7", 0], "latent_image": ["8", 0]}},
        "10": {"class_type": "VAELoader", "inputs": {"vae_name": "qwen_image_vae.safetensors"}},
        "11": {"class_type": "VAEDecode", "inputs": {"samples": ["9", 0], "vae": ["10", 0]}},
        "12": save("bake_qwen-edit-t2i", ["11", 0]),
    }

# ---------- run ----------
results = []
results.append(run("sdxl-vectorlora", g_sdxl_vector_lora()))
free_memory()
results.append(run("zimage-turbo-bf16", g_zimage()))
results.append(run("zimage-turbo-iconslora", g_zimage(icons_lora=True)))
results.append(run("zimage-turbo-gguf", g_zimage(unet_gguf=True)))
free_memory()
results.append(run("klein4b", g_klein("4b")))
free_memory()
results.append(run("klein9b", g_klein("9b")))
free_memory()
results.append(run("qwen-edit-t2i", g_qwen_edit_t2i(), timeout=1500))
free_memory()

print("\n===== BAKE-OFF SUMMARY =====")
for r in results:
    line = f"{r['name']:26s} {r['status']:8s}"
    if r.get("seconds") is not None:
        line += f" {r['seconds']:4d}s"
    if r.get("error"):
        line += f"  {r['error'][:160]}"
    print(line)
print(f"\nResults dir: {RESULTS}")
sys.exit(0 if all(r["status"] == "ok" for r in results) else 1)
