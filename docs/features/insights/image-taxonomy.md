# Puzzle box images: what to extract, how to categorise it, what it is for

Status: research + extraction spec, 2026-10-18. Nothing in the app reads any of this yet.
Companion docs: `vision-analysis.md` (artwork detection and cropping, colour and structure features, throughput -
the vision PoC) and `docs/features/internal-api.md` § "Puzzle image analysis" (the per-puzzle, per-analyzer store).
Lab (scripts, outputs, the extra sample): `.claude/worktrees/insights-taxonomy-lab/` (local, not in git).

## 1. Summary and recommendation

We run local, open, commercial-use models over every one of the ~41,300 box images once, then daily for the ~60 new
ones. The aim is to never have to re-run the expensive part. The way to get there is **extract once, derive many**:
store raw outputs that later categories can be computed from, instead of only the categories we can think of today.

**What to extract (the stack, all licence-checked for commercial use):**

| # | Analyzer | Model / tool | Runs on | Cost per image (measured / estimated) |
|---|---|---|---|---|
| 1 | `artwork` | vision PoC (crop + perspective) | full image | see `vision-analysis.md` |
| 2 | `colors`, `traits` | vision PoC, numpy/OpenCV/scikit-image | crop | ~0.1-0.3 s CPU |
| 3 | `ocr` | Apple Vision `VNRecognizeTextRequest` (on-device) | **full image** | 0.14 s median (measured) |
| 4 | `barcode` | Apple Vision `VNDetectBarcodesRequest` (+ zxing-cpp fallback) | **full image** | 0.03 s (measured) |
| 5 | `vision_labels` | Apple Vision classifier (1,303 labels), faces, humans, saliency | crop | 0.03 s (measured) |
| 6 | `embed_siglip2` + `embed_siglip2_full` | SigLIP 2 so400m/14 @384 (Apache-2.0), 1152-d - `embed_siglip2` = the crop, `embed_siglip2_full` = the whole image (two analyzers: the full-image one needs no crop) | crop / full | 0.15-0.45 s (measured, contended) |
| 7 | `embed_dinov2` | DINOv2-L/14 with registers (Apache-2.0), 1024-d | crop | ~0.1 s (base measured 0.05 s) |
| 8 | `segmentation` | EoMT-L COCO panoptic (MIT) - region shares + coarse map | crop | ~0.5 s (estimate, not measured) |
| 9 | `describe` | **Qwen3.5-9B 4-bit** via mlx-vlm (Apache-2.0), JSON-schema constrained decoding, prompt v2 + catalogue title (bake-off §18) | crop (+ OCR text + title as context) | **10.75 s** (measured, idle M3 Max, unbatched) |
| 10 | `box_text` | parser (Python, rules) over `ocr` + `barcode` | - | ~0 |
| 11 | `zeroshot` | SigLIP 2 text side over the taxonomy prompt bank, from stored embeddings | - | seconds for all 41k |
| 12 | `neighbours` | k-NN over stored embeddings (SigLIP 2 + DINOv2) | - | minutes for all 41k |
| 13 | `tags` | fusion of 5, 8, 9, 10, 11 + numeric thresholds -> taxonomy keys with scores | - | ~0 |

Analyzers 10-13 are **derived**: they never touch an image and can be recomputed in minutes whenever the taxonomy or a
rule changes. Analyzers 1-9 are **raw** and versioned; only a new model justifies re-running one, and every one of
them except `describe` re-runs in hours, not days.

**Budget (measured, §19):** ≈ 5.5 days wall-clock with both Macs - the M3 Max runs `artwork` (~1 day) then
`describe` (~5 days), the M1 Air runs every other analyzer (~2.4 days) and then joins `describe`; resumable. Daily: ~60 images (p90 100, max 170) = under 40 minutes all in.

**Biggest payoffs, ranked:** (1) duplicate detection and catalogue checks (786 EAN groups with 2,292 puzzles today,
plus duplicates without an EAN); (2) a **predicted difficulty for the 31,157 puzzles that have results but no
rating** and for every new puzzle - the pilot (§13.6) reached a Spearman of 0.65 on held-out random 500-piece
puzzles from SigLIP embeddings trained on only 158 puzzles; (3) the **difficulty explainer** and "what type of
puzzler you are" (§13); (4) search, filters, theme pages in 6 locales, similar-puzzle recommendations, alt text,
theme badges.

**Storage:** everything fits the existing generic per-analyzer JSON (≤ 64 KB) - embeddings as base64 float16
(3,072 characters for 1152-d). No binary store is needed for phase 1. pgvector (production runs the stock
`postgres:16.14` image, which has no pgvector) becomes worth it only when the site needs live similarity search;
until then the Mac precomputes neighbours. What the API does need: a corrections table, a server-side
`puzzle_tag` materialisation, and a read endpoint for difficulty aggregates (§16).

## 2. Sources

Categorisation (brands, retailers, communities):
- Ravensburger DE puzzle shop and its filters (themes ~120, licences ~110, format, special series, championship puzzles): https://www.ravensburger.de/de-DE/produkte/puzzle
- Puzzle Warehouse themes / types / difficulty: https://www.puzzlewarehouse.com/jigsaw-puzzles-by-keyword/ , https://www.puzzlewarehouse.com/jigsaw-puzzles-By-Type/ , https://www.puzzlewarehouse.com/puzzles-by-difficulty/
- Amazon Puzzles node facets: https://www.amazon.com/s?rh=n%3A166359011
- eBay item specifics (from listings): https://www.ebay.com/b/One-Piece-Contemporary-100-249-Pieces-Jigsaw-Puzzles/19183/bn_97497046
- Cobble Hill: https://www.cobblehillpuzzles.com/ · Buffalo Games: https://www.buffalogames.com/collections/puzzles · Galison: https://www.galison.com/collections/puzzles · Pomegranate: https://www.pomegranate.com/collections/puzzles · Heye: https://www.heye-puzzle.de/ · Schmidt: https://www.schmidtspiele.de/puzzles.html · Trefl: https://www.trefl.com/puzzle , https://treflusa.com/ · Educa: https://www.educaborras.com/en/74126-1000-pieces-puzzles · Clementoni: https://www.clementoni.com/en/ · Jumbo: https://www.jumbo.eu/en/puzzles · Ceaco: https://www.ceaco.com/
- Cut vocabulary: https://www.bitsandpieces.com/blogs/blog-post/jigsaw-puzzle-glossary , https://www.marymaxim.com/blogs/puzzles/puzzle-piece-shapes-ribbon-vs-random-vs-grid , https://www.masterpiecesinc.com/blogs/news/jigsaw-puzzle-cuts-random-vs-ribbon-cut
- Wasgij lines: https://wasgij.com/products/wasgij-retro-original-5-1000-pieces-25007 , https://bubbablueandme.com/2020/04/wasgij-retro-original-4-a-day-to-remember-puzzle/
- Krypt: https://toolsandtoys.net/ravensburger-krypt-puzzle/ , https://www.boardgamebliss.com/products/puzzle-ravensburger-krypt-gradient-631-pieces
- WJPC rules 2024-2026: https://worldjigsawpuzzle.org/wjpc/2026/rules , https://worldjigsawpuzzle.org/wjpc/2025/rules , https://www.worldjigsawpuzzle.org/wjpc/2024/rules
- USA Nationals puzzles: https://toytales.ca/usa-jigsaw-nationals-competition-puzzles-from-ravensburger/ , https://kottke.org/24/07/0045034-speed-jigsaw-puzzle-compe

Difficulty and solving skills:
- Fissler et al. 2018, Jigsaw puzzling taps multiple cognitive abilities (Frontiers in Aging Neuroscience): https://pmc.ncbi.nlm.nih.gov/articles/PMC6174231
- Doherty et al. 2021 (picture vs shape cues are separable, children): https://ueaeprints.uea.ac.uk/id/eprint/73700/2/Accepted_Manuscript.pdf
- Yuan, Xu & Lin 2024, jigsaw difficulty from the image with deep learning (The Visual Computer): https://www.researchgate.net/publication/380181797 , code https://github.com/CunHua-YYT/JigsawSort
- Duncan & Humphreys 1989, visual search and stimulus similarity: https://www.mrc-cbu.cam.ac.uk/bibliography/articles/2331 · Wolfe, Guided Search 6.0: https://search.bwh.harvard.edu/new/pubs/Wolfe2021_GS6.pdf
- Rosenholtz, Li & Nakano 2007, measuring visual clutter: https://pubmed.ncbi.nlm.nih.gov/18217832 · image complexity measures overview: https://peerj.com/articles/7075 · Hasler & Süsstrunk colourfulness: https://infoscience.epfl.ch/record/33994
- Computational solvers (uniform areas and repetition break them): https://web.stanford.edu/class/cs231a/prev_projects_2016/CrisJim231AProject.pdf , https://zenodo.org/records/17767252 , https://mfleck.cs.illinois.edu/my-papers/puzzle-icsp98.pdf , good continuation https://arxiv.org/abs/2410.16857 , Pomeranz et al. 2011 https://www.cs.bgu.ac.il/~ben-shahar/Publications/2011-Pomeranz_Shemesh_and_Ben_Shahar-A_Fully_Automated_Greedy_Square_Jigsaw_Puzzle_Solver.pdf
- Practitioners: https://nautiluspuzzles.com/blogs/news/what-makes-a-jigsaw-puzzle-difficult-5-key-factors , https://eeboo.com/blogs/puzzles/what-makes-a-puzzle-easy-or-difficult , https://www.readersdigest.com.au/culture/how-to-solve-a-jigsaw-puzzle-fast , https://australianjigsawpuzzle.org.au/speed-puzzling-tips/ , https://soonness.com/blogs/fun-reads/worlds-fastest-puzzler , https://www.speedpuzzling.com/tips.html , https://artifactpuzzles.com/blogs/main/how-the-fastest-puzzlers-do-it , https://puzzletalk.substack.com/p/how-i-reached-those-sub-40-min-times , https://members.cruzio.com/~zdino/bookReviews/jigsaw.completion.htm (anecdotal piece-shape log), our own guide https://myspeedpuzzling.com/en/guides/speed-puzzling-tips

Models and licences (Hugging Face model cards, read 2026-10-18):
- Qwen3-VL: https://huggingface.co/Qwen/Qwen3-VL-8B-Instruct (apache-2.0) · Qwen3.5: https://huggingface.co/Qwen/Qwen3.5-9B (apache-2.0) · Qwen2.5-VL-3B research licence (forbidden): https://huggingface.co/Qwen/Qwen2.5-VL-3B-Instruct/blob/main/LICENSE
- SigLIP 2: https://huggingface.co/google/siglip2-so400m-patch14-384 · DINOv2: https://huggingface.co/facebook/dinov2-large · DINOv3 licence: https://ai.meta.com/resources/models-and-libraries/dinov3-license/ · Qwen3-VL-Embedding: https://huggingface.co/Qwen/Qwen3-VL-Embedding-2B · MobileCLIP terms (research only): https://raw.githubusercontent.com/apple/ml-mobileclip/main/LICENSE_MODELS · jina-clip-v2 (cc-by-nc): https://huggingface.co/jinaai/jina-clip-v2
- Molmo2: https://huggingface.co/allenai/Molmo2-8B · InternVL3: https://huggingface.co/OpenGVLab/InternVL3-8B · Gemma 4 licence: https://ai.google.dev/gemma/docs/gemma_4_license · Moondream licences: https://huggingface.co/moondream/moondream3-preview , https://moondream.ai/licenses/model/1.0 · Llama 3.2 use policy (EU multimodal exclusion): https://huggingface.co/meta-llama/Llama-3.2-11B-Vision-Instruct/blob/main/USE_POLICY.md · LFM licence: https://huggingface.co/LiquidAI/LFM2.5-VL-3B/blob/main/LICENSE
- RAM / RAM++: https://github.com/xinyu1205/recognize-anything · Surya weights licence: https://github.com/datalab-to/surya · PaddleOCR: https://www.paddleocr.ai/latest/en/version3.x/algorithm/PP-OCRv5/PP-OCRv5_multi_languages.html · zxing-cpp: https://github.com/zxing-cpp/zxing-cpp
- SegFormer licence (non-commercial): https://github.com/NVlabs/SegFormer/blob/master/LICENSE · Mask2Former: https://github.com/facebookresearch/Mask2Former · SAM 3 licence: https://github.com/facebookresearch/sam3/blob/main/LICENSE · ADE20K terms (secondary): https://huggingface.co/datasets/1aurent/ADE20K/blob/main/README.md
- pgvector: https://github.com/pgvector/pgvector , changelog https://raw.githubusercontent.com/pgvector/pgvector/master/CHANGELOG.md , Dockerfile https://raw.githubusercontent.com/pgvector/pgvector/master/Dockerfile
- mlx-vlm 0.7.8: https://github.com/Blaizzy/mlx-vlm , https://pypi.org/project/mlx-vlm/

Our data (dev DB = production copy, read-only queries, 2026-10-18): 41,282 puzzles, 41,266 with an image; 523,499
results; 6,285 puzzles with a difficulty score (2,004 high, 1,456 medium, 2,825 low confidence); 37,442 puzzles
have results, 31,157 of them no score; 87.0 % of all results are on rated puzzles.

## 3. How the puzzle world categorises puzzles

There is no shared standard. Brands, retailers and trackers each mix four different things into "categories":
**subject** (what is in the picture), **style** (how it is drawn), **product type** (how the puzzle is made or
packed) and **licence / artist** (who it comes from). Our taxonomy keeps them as separate facets.

**Ravensburger (the richest system, and the dominant brand in our data - 6,075 puzzles).** Filters: Age, Special
series, Genre, Theme (~120 values: animals, cats, dogs, horses, birds, flowers, castles, maps, dinosaurs, unicorns,
fantasy, fairies, Halloween, Christmas, winter, underwater world, space, skylines, cities, Paris, New York,
landscapes, beaches, sights, vintage, nostalgia, art, artworks, mandala, watercolours, food, vehicles, ...), Licence
(~110: Disney by film, Harry Potter, Lord of the Rings, Star Wars, Marvel, DC, Pokémon, Nintendo, One Piece, ...),
Format (portrait, landscape, square, panorama, round), Pieces (incl. 2×12, 2×24, 3×49, 4×100, 2×500, 2×1000),
Special series (Krypt, Challenge, EXIT, Puzzle X Crime, Glitter, Neon, Panorama, Larger Pieces, 4-in-a-box,
frame puzzle with seek-and-find, Lost Places, Cottages and Gardens, ...) and **Championship puzzles** (WJPC 2026,
USA Nationals, UKJPA, German / Nordic / Canadian championships).

**Puzzle Warehouse - the cleanest model: three axes.** Theme (12: Animals & Wildlife, Art & Design, Educational,
Fantasy, Food & Beverage, Holiday & Seasonal, Home & Domestic Life, Nature & Scenery, People & Culture, Places &
Geography, Pop Culture & Entertainment, Transportation), Type (~30: 3D, Altered Image, Alzheimer's & Dementia,
Chunky, Family, Frame, Glitter/Foil, Glow-in-the-Dark, Hidden Image, Large Piece, Lenticular, Magnetic, Mini,
Multi-Pack, Murder Mystery, Panoramic, Photomosaic, Round, Shaped, Wooden, ...), Difficulty (Beginner, Easy, Hard,
Impossible = "monochromes, repeating patterns", Double Sided, World's Largest).

**Amazon** adds Style (Antique, Boho, Gothic, Modern, Retro, Rustic, Whimsical), Shape (round, heart, hexagon,
star, triangle) and "Featured characters & brands". **eBay** uses free-text item specifics (Theme, Character Family,
Features, Type, Shape, Number of Pieces) - inconsistent values. **Cobble Hill** keeps Style separate from theme
(Cartoon, Fantasy, Fine Art, Gradient, Rainbow, Infographic, Nostalgia, Vintage); **Heye** uses styles as its top
level (Art Lab, Cartoon, Fantasy, Fine Art, Photo Art); **Schmidt** and **Pomegranate** navigate mostly by artist;
**Jumbo** gives Jan van Haasteren and Wasgij their own menus; **Buffalo** has series that are really styles (Cities
in Color, NOIR, Vivid, blanc).

**Trackers and communities** store title, brand, piece count, sometimes a free theme - no shared taxonomy. The
community vocabulary that matters for speed: ribbon vs random vs grid cut, whimsy pieces, "busy", "gradient",
"impossible", "low colour", "sky that can't be told from water", repeating patterns.

**Special types worth their own keys:** Wasgij (Original, Mystery, Destiny, Retro, Christmas), Krypt and other
no-image / gradient / monochrome challenges, double-sided, shaped and round outlines, panorama, mystery / escape /
murder-mystery puzzles, glow-in-the-dark, glitter / foil / neon, lenticular, 3D, multi-packs, large-piece / easy
handling (seniors, dementia), family puzzles with mixed piece sizes, seek-and-find frames, collage / photomosaic,
mini puzzles, wooden.

**Competition puzzles:** WJPC uses unpublished puzzles (500 pieces in the individual rounds, 1000 in the finals,
1500 for nations), pairs choose 1 of 2 from a bag; no image criteria are published. USA Nationals use unreleased
Ravensburger / Cobble Hill designs. Fairness comes from "everyone gets the same unseen puzzle", not from an image
rule - which makes a trait profile of the candidate puzzles (and a duplicate check against past competition
puzzles) a genuinely new tool for organisers.

**Consensus across brands (5+ of the big ones):** animals (cats, dogs, birds split out), landscapes / nature,
seasons and holidays (Christmas and Halloween always separate), fantasy, fine art, cities / travel, flowers, food,
film/TV / pop culture, vehicles, space, collage, maps / educational. These form the backbone of §10.

## 4. What makes an image hard, and which solving skill it demands

**Evidence quality.** There is almost no published study timing humans on physical jigsaws against image features -
our ~520k results are likely the largest such dataset anywhere. What holds up:

- **Strong:** large uniform areas, gradients, low contrast and repeated texture are the main difficulty drivers.
  Backed by visual-search theory (search slows as targets resemble distractors: Duncan & Humphreys 1989; colour
  guides attention: Wolfe GS6), by computational solvers failing on the same regions, and by every practitioner
  source (eeBoo, Nautilus, WJPF and USAJPA competitors, our own guide).
- **Moderate:** picture skill and shape skill are separable abilities (Doherty 2021, in children); puzzle skill is
  trainable and task-specific (Fissler 2018: 30 days of puzzling improved puzzling, not general visuospatial
  cognition).
- **Weak / none:** faces, text, glitter finish, collage borders. Treat as hypotheses our data can test.
- **Caveat:** global complexity measures (entropy, JPEG size, clutter) are *not* monotonic with jigsaw difficulty:
  uniform sky is low-complexity and hard, a colourful busy-but-distinct cartoon is high-complexity and easy. The
  right measures are **local, at piece scale**: how many pieces are featureless, how many have a near look-alike,
  how distinguishable a piece is from its best impostor. The vision PoC already computes these
  (`piece_flat_share`, `piece_lookalike_share`, `piece_distinctiveness` on a virtual grid of the real piece count).

**Solving skills (our 10 skill keys), how practitioners use them, and which traits demand them:**

| Skill key | What it is | Image traits that demand it (numeric source) | Basis |
|---|---|---|---|
| `skill.shape_sorting` | sorting / fit-testing by knob-hole pattern when the picture stops helping | uniform area share, sky / water share, monochrome, gradient | Doherty; Duncan & Humphreys; champions' strategy interviews |
| `skill.shade_discrimination` | telling near-identical tones apart | smooth gradient share, low contrast, subtle shade steps, sky vs water | competitors (WaPo), our guide |
| `skill.texture_reading` | separating zones of one colour by texture (trees vs grass, fur) | foliage / grass share, similar-texture lookalikes | WJPF president, AJPA |
| `skill.pattern_memory` | placing repeated motifs by position and shape | repetition score, lookalike share in non-uniform areas, collage of similar items | Yuan 2024; Fissler (working memory) |
| `skill.detail_scanning` | searching many small unique details | object count, edge density + high distinctiveness, seek-and-find, small text | Fissler (scanning) |
| `skill.region_building` | sorting into colour / object zones and building blocks | many distinct colour regions, colourfulness, clear objects (the "easy" baseline) | eeBoo; Wolfe |
| `skill.line_following` | continuing lines and edges (architecture, maps, horizons) | line strength, buildings share, maps | good continuation (arXiv 2410.16857) |
| `skill.box_reference` | using the box picture to place unique content | detail with distinct objects; validated by `box_dependence_score` | a world champion's interview; our box-dependence metric |
| `skill.low_light` | working with dark / low-contrast imagery | dark share, low lightness std | WJPC 2025 finalist quote (anecdotal) |
| `skill.edge_start` | framing first vs starting in the middle when the border is bland | border band uniformity vs interior | practitioners, our guide |

How a player's strength is measured from the data is designed in §13.4.

## 5. What a box image can tell us, and what each item is worth

Value: **A** = feeds a ranked use in §6 now, **B** = clearly useful later, **C** = nice to have.

| Information | Source | Value | Use |
|---|---|---|---|
| Artwork crop, image kind (flat artwork, box front, angled / 3D box, assembled puzzle, other) | vision PoC | A | everything below runs on the crop; catalogue quality (box photos vs clean art) |
| Piece-scale structure (flat, look-alike, distinctiveness, gradients, uniform regions, repetition, edges, regions) | vision PoC `traits` | A | difficulty explainer, puzzler type, filters ("no sky", "low colour") |
| Palette, colour families, colourfulness, dark / light shares | vision PoC `colors` | A | filters by colour, theme pages, explainer |
| Region shares: sky, water / sea / river, foliage / trees / grass, buildings, mountain, people, animals, plain background | segmentation | A | explainer ("40 % sky"), filters, alt text |
| Subjects / objects (species, breeds when sure, vehicles, buildings, landmarks, food, flowers) | VLM + zero-shot + Apple labels | A | search, theme pages, badges, collections |
| Theme, scene type, style | VLM + zero-shot | A | browse, filters, SEO, recommendations |
| Season, holiday, time of day, weather, mood | VLM + zero-shot | B | seasonal pages and newsletters (Christmas, Halloween), recommendations |
| People: none / few / many / crowd, prominent faces (count + area only) | Apple faces/humans + VLM | B | explainer (faces as a trait to test), kids content |
| Licensed franchises / characters | VLM (+ OCR of logos/titles) | B | internal search, licence facet (see §15 for public caution) |
| Place / landmark / country | VLM | B | travel theme pages, "puzzles of Prague" |
| Artist and series names, artwork title, copyright line | OCR (signatures "© 2017 ..."), VLM | B | artist pages, catalogue enrichment (moderated) |
| Seek-and-find density, object counts | VLM counts + seg instances | B | detail-scanning trait, kids content |
| Composition: horizon position, symmetry, focal point vs all-over, border / frame present, collage grid | traits + saliency + VLM | B | explainer, edge-start trait |
| Text **in the artwork** (lettering share) | OCR boxes inside the crop | A | explainer (text is a strong cue), filters |
| Box text: piece count, finished dimensions, brand, EAN digits, product code, age rating, series line, language | OCR + barcode on full image | A | catalogue checks (§6.1) |
| Image quality: resolution, blur, watermark / sticker, multiple products, back of box | traits + labels + VLM | B | moderation queue: "replace this image" |
| Dense caption (2-3 sentences) | VLM | A | alt text (translated to 6 locales from text, no image re-run), search, explanations |
| Image embeddings (semantic + visual) | SigLIP 2, DINOv2 | A | duplicates, similar puzzles, cold-start difficulty, future zero-shot categories |

**Measured on our images (lab, 300 sample images):** OCR finds text on 163/300 images. On those, the brand name is
readable on 43, the title on 55, the **piece count on 17 - and all 17 match the catalogue**. Barcodes are visible on
only 4/300 (2 EAN-13, one QR, one Amazon Code128) - box fronts rarely show the barcode, so **the image will not fill
our ~17,300 missing EANs**; it can confirm or contradict an EAN where a back/side photo exists. OCR also reads the
*artwork's* own lettering (book titles, bird facts, stamps, signs) and artists' signatures and copyright lines - both
valuable (text-in-art trait; artist attribution). About half the images are clean flat artwork; ~30 % are photos of
a box and ~18 % photos of an assembled puzzle (vision PoC hand labels, 78 images).

## 6. Uses, now and later

Ranked by value for effort.

1. **Duplicates and catalogue quality (now, moderators).** `neighbours` gives every puzzle its nearest look-alikes.
   Same artwork + same piece count + same or empty EAN = merge candidate for the existing merge review
   (`docs/features/puzzle-approvals.md`); same EAN + different artwork = a legitimately re-used EAN or a wrong one
   (the add form now accepts re-used EANs, F124 - the image is the arbiter). `box_text` vs catalogue: piece-count
   mismatch, brand mismatch, multi-pack not recorded (2×500 read on the box, 500 in the catalogue), dimensions, age,
   "image shows the assembled puzzle / a box photo / the back" -> "better image wanted". All of it as review items
   through the existing change-proposal flow, never automatic writes (feedback rule: catalogue fixes = proposals).
2. **Cold-start difficulty (now, members).** A predicted tier for the 31,157 unrated puzzles with results and for
   every new puzzle, labelled "Estimated" with a range, replaced by the real score once 5 indices exist. Also feeds
   time prediction (Step 8) when the puzzle has no difficulty. Pilot evidence §13.6.
3. **Difficulty explainer + "what type of puzzler you are" (Insights, members).** §13.
4. **Search and filters in the puzzle database and the marketplace:** theme, subject ("cats"), style, colour,
   season, "no large sky", "low colour challenge", licence (internal), with multilingual synonyms. SigLIP 2 is
   multilingual, so the Mac can score Czech / German / Japanese queries against stored embeddings when building
   synonym lists.
5. **Recommendations:** "puzzles like this" (embedding neighbours filtered by piece count), "train your weak spot"
   (puzzles high in the trait you are weakest at, at your level), "next in this series".
6. **Theme landing pages + SEO in 6 locales:** `/en/puzzles/cats`, `/cs/puzzle/kocky` ... from `tags` (only
   moderated or high-confidence assignments), with counts, fastest times, hardest / easiest per theme. Long-tail SEO
   is our growth engine (`project_seo_overhaul`).
7. **Alt text / accessibility:** the caption (English) translated per locale as text; never the raw OCR.
8. **Badges and achievements by theme** ("50 cat puzzles solved"), personal theme stats on the profile, auto-tags for
   collections ("your collection: 34 % landscapes").
9. **Events:** a trait profile and the difficulty estimate of candidate competition puzzles; a check that a candidate
   is not a near-duplicate of a puzzle already solved by many competitors (memorability); balanced rounds.
10. **Kids content:** cartoon + low piece count + no spooky / violent subjects (VLM mood) for a kids section.
11. **Later, without re-running images:** any new category (zero-shot over stored embeddings), new languages for alt
    text (translate captions), new filters from stored numeric traits, new explainer features from stored grids.

## 7. Model comparison

Only commercial-use licences. Memory = weights + working set on the M3 Max (36 GB, ~27 GB usable by the GPU by
default). Speed measured in the lab where marked (M), otherwise estimated (E). The lab machine was shared with
another agent running models at the same time, so measured numbers are pessimistic.

### 7.1 Vision-language models (caption + structured JSON)

| Model | Licence | Mem (4-bit) | Speed on M3 Max | Fit |
|---|---|---|---|---|
| **Qwen3-VL-8B-Instruct** (`mlx-community/...-4bit`) | apache-2.0 | 5.5 GB weights, **8.3 GB peak** in a batch of 8 (M) | 22-34 s/img unbatched (11-13 tok/s, contended) (M); **12.1 s/img in batch_generate of 8** (50 tok/s generation, 189 tok/s prefill) (M) | **Recommended.** Good captions, multilingual OCR, follows the schema mostly |
| Qwen3-VL-4B-Instruct | apache-2.0 | ~3 GB | 19-24 s/img unbatched, 15-16 tok/s (M) | fallback if the budget must halve; weaker on species / OCR |
| Qwen3.5-9B (image input built in, Feb 2026) | apache-2.0 | ~6 GB | (E) similar to 8B; thinking must be off | claims better OCRBench than Qwen3-VL-30B-A3B; **worth a 50-image bake-off before the backfill** |
| Qwen3-VL-30B-A3B / Qwen3.6-35B-A3B (MoE, 3B active) | apache-2.0 | 17-20 GB | (E) generation as fast as a 3B dense, prefill slower | best quality that fits; too close to the memory ceiling while another model runs |
| Gemma 4 12B | apache-2.0 | ~7.5 GB | (E) | good multilingual captions; alternative |
| Molmo2-8B | apache-2.0 | ~5.5 GB | (E) | strong counting / pointing (seek-and-find counts) |
| Florence-2-large | MIT | ~1.5 GB | (E) < 1 s | fast captions + detection, weak non-Latin OCR; too shallow for our schema |
| Moondream 2 | apache-2.0 | ~2.5 GB | (E) fast | small captioner; Moondream 3 is BSL / custom licence - avoid |
| Excluded | | | | Qwen2.5-VL-3B (research licence), Llama 3.2 Vision (multimodal rights withheld from EU companies), LFM2.5-VL (revenue cap), FLUX klein (non-commercial) |

Observed quality (8 images, Qwen3-VL-8B, lab `out/vlm_q3vl8b.jsonl`): captions are accurate and useful (named
Banff National Park for Peyto Lake, recognised a Disney castle, a Jan van Haasteren picnic, a collage of cat book
covers). Weak spots: it **invents values outside the enum** ("abstract", "pattern_design" as themes) -> use
schema-constrained decoding (mlx-vlm's OpenAI-compatible server supports `json_schema`), or map values in the
parser; it **over-reports difficulty traits** ("strong_lines", "many_distinct_colour_regions" on almost
everything) -> difficulty traits come from numbers, not from the VLM; `image_kind` was "box_front" for nearly all
-> the vision PoC owns image kind; species names read off the artwork text can be wrong (a "Three-Winged Bellbird").

### 7.2 Embeddings

| Model | Licence | Dim | Speed (M) | Fit |
|---|---|---|---|---|
| **SigLIP 2 so400m/14 @384** | apache-2.0 | 1152 | 457 ms/img in batches of 8, 3.5 GB MPS peak, while another model ran (M); expect ~150 ms idle (E) | **Recommended**: zero-shot in many languages, semantic similarity, best difficulty predictor in the pilot |
| **DINOv2-L/14 with registers** | apache-2.0 | 1024 | base measured 52 ms/img, 1.3 GB (M); large ~3× (E) | **Recommended** second opinion for duplicates (pure visual) |
| Qwen3-VL-Embedding-2B | apache-2.0 | ≤ 2048 (Matryoshka) | (E) ~0.3 s | one space for images and text in 30+ languages - candidate for a future text-search analyzer |
| PE-Core-L14 (Meta Perception Encoder) | apache-2.0 | 1024 | (E) | alternative to SigLIP 2 |
| OpenCLIP LAION | MIT weights, data caveat | 1024-1280 | (E) | not needed |
| DINOv3 | custom "DINOv3 License" (commercial seems allowed with conditions, gated) | 1024 | - | avoid until read by a lawyer; DINOv2 is enough |
| Excluded | | | | MobileCLIP / AIMv2 (Apple research-only), jina-clip-v2 (cc-by-nc) |

### 7.3 OCR, barcodes, taggers, segmentation

| Kind | Pick | Licence | Speed (M) | Notes |
|---|---|---|---|---|
| OCR | **Apple Vision `VNRecognizeTextRequest`** (accurate, language auto-detect) | macOS SDK, on our own Mac | 0.14 s median, p90 0.25 s; 3 of 300 calls stalled 27-42 s while the GPU/ANE was busy (M) | 33 languages incl. Czech and Japanese; returns text, confidence and boxes. Fallback: PaddleOCR PP-OCRv5 (Apache-2.0). Avoid Surya (weights licence revenue cap) |
| Barcodes | **Apple Vision `VNDetectBarcodesRequest`** + zxing-cpp (Apache-2.0) as second reader | SDK / Apache-2.0 | 0.03 s (M) | EAN-13/8, UPC-E, ITF-14, Code128, QR; zbar is LGPL and weaker on angles |
| Generic labels | **Apple Vision classifier** (1,303 labels) | SDK | 0.02 s (M) | free and fast; good at sky / cloudy / outdoor / painting / food / mountain; its cat/dog animal detector produced 32 "Dog" hits on 300 images = noisy, use only with VLM agreement |
| Faces / humans | Apple Vision | SDK | 0.01 s (M) | count + area only, no identity (§15) |
| Open-vocab tagger | RAM++ (Apache-2.0, 4,585 tags) | | (E) | **skip**: zero-shot over stored SigLIP embeddings + the VLM cover it, and can be redone any time without images |
| Segmentation | **EoMT-L COCO panoptic** (`tue-mps/coco_panoptic_eomt_large_640`, MIT) or OneFormer COCO (MIT) | MIT, COCO data | (E) ~0.5 s | COCO panoptic has sky, sea, river, water, tree, grass, building, mountain, person, animals. **Avoid ADE20K-trained weights** (SegFormer is non-commercial; ADE20K images are non-commercial-research). SAM 3 (custom licence, gated) only if text-prompted masks are needed. Domain shift on paintings/cartoons is real - shares are approximate, the VLM `areas` estimate is a cross-check |
| Similarity search in Postgres | pgvector 0.8.7 (PostgreSQL licence), `halfvec` up to 4,000 dims indexable | | | not in the official `postgres` image; `pgvector/pgvector:0.8.7-pg16-bookworm` is built FROM the official Debian image (same major, same libc) |

## 8. Lab measurements (what was actually run)

All on the M3 Max, 2026-10-18, sharing the machine with the vision PoC's own model runs; scripts and raw outputs in
`.claude/worktrees/insights-taxonomy-lab/` (`scripts/`, `out/`). Images: the vision PoC's 300-image sample
(read-only) + 148 downloaded for this study (35 pairs of puzzles sharing one EAN, 80 random rated 500-piece puzzles).

| Measurement | Result |
|---|---|
| Apple Vision on 300 full images (OCR + barcode + labels + faces + humans + animals + feature print) | OCR 0.14 s median; everything else < 0.05 s; text on 163/300; piece count on 17/300 (17/17 match); barcode 4/300 |
| SigLIP 2 so400m embeddings, 448 images | 457 ms/img (contended), 3.5 GB MPS peak, 4.8 GB process |
| DINOv2-base embeddings, 448 images | 52 ms/img, 1.3 GB |
| Simple numeric traits (my reimplementation, virtual 500-piece grid) | 92 ms/img CPU, one core |
| Qwen3-VL-8B 4-bit, full schema prompt, 672 px | unbatched 22-34 s/img; batch of 8: 12.1 s/img, 8.3 GB peak; prefill = 44 % of the time (the prompt is ~500 tokens of schema - shorten it) |
| Qwen3-VL-4B 4-bit, same | unbatched 19-24 s/img |
| Zero-shot (SigLIP 2, 44 prompts) | **rankings are right, absolute sigmoid scores are not calibrated**: "birds" never exceeded 0.1 even for "Rare Birds" (it was ranked first). Per-key thresholds must be calibrated on labelled examples, or keys compared within a facet |
| Duplicates (35 same-EAN pairs) | see below |
| Difficulty pilot (238 rated puzzles) | see §13.6 |

**Duplicates.** SigLIP 2 cosine of random pairs: median 0.49, 99.9th percentile 0.78. Of the 35 same-EAN pairs,
~24 look like the same artwork (SigLIP ≥ 0.73, including box photo vs flat artwork and language editions such as
"Bibi & Tina: By the Quarry" / "Am Steinbruch"), 2-3 are **series siblings** (different artwork, same series layout:
"Picturesque Italy - Amalfi" / "- Burano" 0.80, "Holiday Memories - Venice" / "- Paris" 0.71) and ~8 are clearly
different pictures under one EAN (< 0.61). Judged from names and scores, not verified by eye. For 27/35 pairs the
twin is the nearest neighbour among 448 images. DINOv2 separates random pairs much better (median 0.09) and
recognises same-art language editions (0.79-0.96), but **drops true pairs when one side is a box photo** (0.15-0.40)
- so: compare crops, use both embeddings, and treat "SigLIP high + DINOv2 low" as "same subject or series, check".

## 9. Extract once, derive many

### 9.1 Principles

1. **Raw outputs are stored forever and versioned**; derived outputs are cheap and recomputed at will.
2. A raw output is worth storing when (a) it took a model to make, and (b) future questions can be answered from it
   without the image. Embeddings, captions, OCR lines with boxes, region shares and coarse maps, numeric traits,
   VLM JSON over a fixed schema.
3. **Numbers, not only labels.** Every trait is stored as a continuous value; thresholds live in the taxonomy and
   can move. The VLM's labels are one vote among several, never the only source of a numeric trait.
4. **Spatial information in small grids.** A 16×16 map (dominant segmentation class, flatness, lightness, chroma per
   cell; a few KB) lets us later compute "sky along the top border", "uniform border band" (the edge-start trait)
   or "share of featureless pieces inside the sky" without re-running anything.
5. The **master copy** of everything raw lives on the Mac (one SQLite / Parquet store + `.npy` embedding matrices,
   every analyzer version kept) with an S3 backup; production receives the current version of each analyzer through
   the internal API. Production has no history (rows are replaced) - by design.
6. Every raw payload carries `sourceSha256` (bytes of the downloaded original) and `crop` (the `artwork` box it ran
   on), so a content-identical re-upload or a changed crop is visible.

### 9.2 Raw vs derived

| Kind | Analyzers | Recompute when | Needs |
|---|---|---|---|
| Raw, image-bound | `artwork`, `colors`, `traits`, `ocr`, `barcode`, `vision_labels`, `embed_siglip2`, `embed_siglip2_full`, `embed_dinov2`, `segmentation`, `describe` | new image (fingerprint), new model, a formula bug | the image + a model |
| Derived on the Mac (needs a text encoder or the whole corpus) | `zeroshot`, `neighbours`, `tags` (fusion) | taxonomy version, new prompt bank, new puzzles (neighbours) | stored embeddings / raw payloads only |
| Derived on the server (pure rules, PHP) | `puzzle_tag` materialisation incl. corrections, catalogue checks (`box_text` vs current catalogue), numeric-threshold traits, theme counts | every push, every correction, catalogue edits | stored payloads only |

Catalogue checks must be computed **on the server**: the catalogue changes after the analysis (a moderator fixes the
piece count), and the comparison must follow it.

### 9.3 Where embeddings live

| Option | Size for 41k | Pros | Cons |
|---|---|---|---|
| **A. base64 float16 inside the analyzer JSON (recommended now)** | SigLIP 1152-d: 3,072 chars/row (crop + full = 2 rows, 6 KB) -> ~250 MB; DINOv2-L 1024-d: 2.7 KB -> ~110 MB | no new infrastructure, fits 64 KB, versioned like every analyzer | Postgres cannot search it; PHP decoding 41k × 1152 per request is too slow (fine for batch jobs) |
| B. dedicated table with `bytea` | ~95 MB SigLIP + 84 MB DINOv2 | compact | same search limitation, a second write path |
| C. pgvector `halfvec(1152)` + HNSW (or exact scan - 41k rows scan in tens of ms) | ~100 MB | live "similar puzzles", live duplicate check on add, text search later | swap the production image to `pgvector/pgvector:0.8.7-pg16-bookworm` (digest-pinned, same Debian/libc, same major - a deliberate windowed `compose up`); `CREATE EXTENSION vector` in a migration. Not a trigger or function of ours - compatible with the "no DB triggers/functions" rule, like `pg_trgm` |

Recommendation: **A now** (store), **neighbours precomputed on the Mac** (use), **C** when a feature needs live
similarity (duplicate warning in the add form against a just-uploaded photo, text search by meaning). The app then
fills a `halfvec` column from the stored JSON in its own code - no re-run on the Mac.

### 9.4 Size per puzzle

`describe` ~1.5-2 KB, `ocr` p50 0.1 KB / p99 7 KB / max 16 KB (cap 400 lines), `segmentation` ~2-3 KB with the grid,
`colors` + `traits` ~3-6 KB with grids, embeddings ~9 KB, `zeroshot` ~3 KB (300 keys × 4 digits), `neighbours`
~2 KB, rest < 1 KB. **~25 KB per puzzle, ~1 GB JSONB for 41k** (base64 does not compress; captions do).

## 10. The taxonomy

**Facets** (each item belongs to exactly one): `theme` (two levels), `subject` (open, normalised nouns),
`style`, `scene`, `season`, `holiday`, `time`, `weather`, `mood`, `people`, `audience`, `special` (product type),
`licence` (franchise), `trait` (structure, numeric with thresholds), `skill` (derived from traits), `image`
(catalogue/image quality). Artist, series and place names are **entities** (strings normalised later into tables),
not taxonomy keys.

**Key rules.** Stable snake_case keys `<facet>.<key>` (subthemes `theme.<parent>.<child>`), never renamed - a
retired key stays as deprecated. English labels in the registry; translations in the 6 locales are added when a key
is first shown publicly (feedback rule: finished feature = all 6 locales). The taxonomy has a version
(`TAXONOMY_VERSION`, semver); adding keys = minor, changing a definition / threshold = minor + recompute derived,
removing = major. ~300 keys in total (Appendix A); the long tail (species, breeds, landmarks, objects) lives in
`subject` strings and in the embeddings.

**Which analyzer produces what, and the default confidence rule:**

| Facet | Produced by (fusion in `tags`) | Assign when |
|---|---|---|
| theme / subtheme | VLM `themes` + zero-shot rank + Apple labels | VLM says so **and** zero-shot percentile ≥ the key's calibrated threshold; or zero-shot ≥ the high threshold alone; moderated = always |
| subject | VLM `subjects` (normalised: lowercase, singular, synonyms -> one noun), Apple labels, seg instances | VLM lists it, or two other sources agree |
| style, scene | VLM single value + zero-shot within the facet (softmax over the facet's prompts) | agreement, else the higher-confidence one flagged `uncertain` |
| season, holiday, time, weather, mood | VLM + zero-shot | holiday needs both (a wrong "Christmas" is visible) |
| people | Apple faces / humans + seg person instances + VLM | numeric counts; bucketed |
| special | `box_text` (OCR: "2×500", "Wasgij", "Krypt", "glow in the dark", "double-sided", "EXIT"), catalogue (pieces, brand line), VLM | a rule hit in box text or catalogue; VLM alone only proposes |
| licence | VLM `characters` + OCR of logos/titles (+ zero-shot over franchise prompts) | two sources; public use only after moderation (§15) |
| trait | numbers from `traits`, `colors`, `segmentation`, `ocr` (text in artwork) | threshold in Appendix A on the numeric value; the number itself is always stored |
| skill | weighted sum of trait values (Appendix A) | numeric "demand" 0..1 per skill per puzzle |
| image | `artwork` kind, resolution, blur, sticker/watermark, back-of-box | rule |

Thresholds are **calibrated** on a labelled set (§14), per key, aiming at ≥ 90 % precision for anything shown
publicly and ≥ 80 % for internal / search use.

## 11. Analyzers and payload schemas

Common envelope fields in every payload (inside `result`): `schema` (payload schema version), `model` (repo id +
revision / quantisation, or tool + OS version), `sourceSha256`, `crop` (`null` = full image, else the `artwork`
bbox/corners used), `runtimeMs`. `analyzerVersion` (the API field) = `<schema major>.<minor>.<patch>+<model short
id>`, e.g. `1.0.0+qwen3vl8b-4bit`.

**Version bump rules.** Major: output meaning or schema changes (re-run all; consumers check the major). Minor: new
optional fields (re-run optional; missing = null). Patch: a fix that changes some values (re-run all, cheap
analyzers only - for `describe` decide case by case). A new model = a new `+model` suffix and a re-run. Derived
analyzers add `@tax<TAXONOMY_VERSION>`; bumping the taxonomy marks every `tags`/`zeroshot` row stale and the Mac
recomputes them in minutes from the local store.

### 11.1 Raw analyzers

What `tools/puzzle-vision` sends (runbook [`image-pipeline.md`](./image-pipeline.md) §9; every payload with the
envelope above, checked against the 64 KB limit before it is pushed):

`artwork` (full image; vision-analysis.md §9.1, with the box under `bbox` - the field the server validates):
```json
{"kind":"box_front","bbox":{"x":0.12,"y":0.10,"w":0.75,"h":0.61},"corners":[[0.13,0.10],[0.87,0.11],[0.86,0.71],[0.12,0.70]],
 "method":"vlm+quad","confidence":0.93,"kindScores":{"flat":0.04,"box":0.13,"assembled":0.08,"other":0.02},
 "source":{"width":1500,"height":1125},"signals":{"gateMargin":-0.09,"vlmKind":"box_front","vlmBbox":{…},"quadIou":0.93}}
```
`kind` flat | box_front | box_angled | assembled | other; `corners` (top-left, top-right, bottom-right, bottom-left) only
when the quad is skewed, else null; `method` trim (gate-flat → border trim) | vlm | vlm+quad (snapped to an OpenCV
quadrilateral, IoU > 0.75); `confidence` = gate margin for trim, quad IoU for vlm+quad, 0.5 for plain vlm; `source` =
the pixel size analysed (the ≤ 1600 px cached copy); `signals` = the raw agreement signals the crop proposals reuse.

`colors` (crop): vision-analysis.md §9.2 - `palette` (8 × `{hex, share, family, oklab}`), `families`, `dominantShare`,
`paletteEffectiveColors`, `colorEntropyBits`, `colorfulness`, `lightnessMean`, `lightnessStd`, `darkShare`,
`lightShare`, `chromaMean`, `mutedShare`.

`traits` (crop): vision-analysis.md §9.3 (`edgeDensity`, `detail`, `gradientMean`, `flatShare`, `uniformShare`,
`largestUniformRegion`, `uniformRegions`, `smoothGradientShare`, `gradientRegions`, `largestFlatRegion`,
`colorRegions`, `colorRegions80`, `largestColorRegion`, `textShare`, `textChars` (Apple Vision fast text, CPU-only;
null without it), `pieceGrid`, `pieceFlatShare`, `pieceLookalikeShare`, `pieceDistinctiveness` - the piece numbers for
the puzzle's **real** piece count, `regularity`) plus the extract-once additions:
- `grid16` `{"flat":[256 × 0/1],"lightness":[256 × L×100],"chroma":[256 × C×500, ≤ 255],"gradient":[256 × ×500, ≤ 255]}` (~3 KB);
- `borderBand` `{"uniformShare": featureless share of the outer ring of piece cells, "interiorUniformShare": of the rest}`;
- `pieceLevel` `{"real": {"pieces": n, …piece numbers…}, "p500": {…}, "p1000": {…}}` (string keys - a map with number
  keys would come back from the API as a list);
- `symmetry` (best of left-right / top-bottom mirror correlation of lightness, 0..1) and `lineStrength` (share of edge
  pixels on long Hough segments, 0..1).

`ocr` (full image):
```json
{"schema":1,"model":"apple-vision/VNRecognizeTextRequest/rev3/macOS27.0","sourceSha256":"…","crop":null,
 "languages":["en","de"],"lines":[{"t":"500 PIECES","c":0.98,"b":[0.61,0.04,0.30,0.05],"inArtwork":false}, …],
 "truncated":false,"textAreaShareInArtwork":0.031}
```
`b` = x, y, w, h as fractions with the origin top-left (convert from Vision's bottom-left); `inArtwork` = the line's
centre lies inside the `artwork` crop. Cap 400 lines, sorted by area.

`barcode` (full image): `{"codes":[{"sym":"EAN13","v":"4005556173709","b":[…],"checksumOk":true,"reader":"apple"}]}`.

`vision_labels` (crop): `{"labels":[["sky",0.78],…≤40 with confidence ≥ 0.1],"faces":[{"area":0.012,"c":0.9}],
"humans":3,"saliency":{"bbox":[…],"areaShare":0.42}}` - no face crops, no face embeddings, no landmarks.

`embed_siglip2` (crop - needs `artwork`): `{"dim":1152,"dtype":"f16","norm":"l2","vector":"<base64>","preprocess":"384 squash, siglip2 processor"}`,
envelope `crop` = the `artwork` bbox/corners used.
`embed_siglip2_full` (full image, no dependency): the same shape, envelope `crop: null`. Two analyzers since 2026-10-18
(plan §1.1; one analyzer with `crop` + `full` before): a worker computes the full-image embedding before `artwork`
exists, and one analyzer = one payload = one push. Both use the same model and preprocessing, so the vectors are
comparable (full vs crop of the same box).
`embed_dinov2`: `{"dim":1024,"dtype":"f16","norm":"l2","cls":"<base64>","preprocess":"224 short side, centre"}` (crop only).

`segmentation` (crop): `{"classes":{"sky":0.31,"tree":0.18,"grass":0.07,"sea":0.12,"building":0.05,"person":0.01,…≥0.002},
"instances":{"person":4,"dog":1,"bird":7},"grid16":[256 class ids],"classList":"coco-panoptic-133"}`.

`describe` (crop, OCR lines inside the crop passed as context, schema-constrained decoding) - the §18 schema, as sent:
```json
{"caption":"…2-3 factual sentences…","altText":"≤ 140 characters",
 "themes":["landscape_nature","travel_places"],"subjects":["mountain","lake","pine tree","snow"],
 "scene":"landscape","style":"photo","season":"winter","holiday":"none","time":"day","weather":"partly_cloudy",
 "mood":["serene"],"place":"Banff National Park, Canada","characters":[],"artistSignature":null,
 "people":"none","textInArtwork":"none","counts":{"distinctObjects":0,"animals":0,"people":0},
 "areas":{"sky":30,"water":25,"foliageGrass":35,"buildings":0,"plainBackground":10},
 "collageGrid":false,"seekAndFind":false,"extraTags":["alpine","glacier lake"],
 "promptVersion":"v2.1","titleContext":true,"ocrLines":2,"tokens":{"prompt":1230,"generation":305}}
```
Values are restricted to the Appendix A vocabularies by the JSON schema (`none` added to `time` / `weather`);
`subjects` (lowercased, singular, de-duplicated after decoding), `place`, `characters` (a list, ≤ 6), `artistSignature`
and `extraTags` (≤ 8) are free text - `"none"` / `"null"` in `place` / `artistSignature` become null. No
`specialHints` (§18 finding 6: `special` comes from box text and the catalogue). `promptVersion` "v2.1" = the §18
system prompt v2 plus "never the puzzle brand or publisher" on the artistSignature line; `titleContext` = the catalogue
title was in the user message; `ocrLines` = how many OCR lines inside the crop went in (≤ 12); `tokens` = prompt /
generated token counts.

`segmentation` and the embeddings above are sent exactly as described.

### 11.2 Derived analyzers

`box_text` (from `ocr` + `barcode`, rules, versioned): `{"pieces":[{"v":1000,"evidence":"1000 Teile"}],
"multiPack":{"count":2,"pieces":500}|null,"dimensionsCm":[70,50]|null,"eans":["…"],"productCodes":["17370"],
"brandMentions":["Ravensburger"],"ageMin":14|null,"specialMentions":["glow_in_dark"],"seriesMentions":["Krypt"],
"copyright":["© 2017 …"],"languages":["de","en"]}`. Raw text stays in `ocr`; only parsed facts here.

`zeroshot`: `{"taxonomy":"1.0.0","prompts":"bank-1.0","scores":{"theme.pets.cats":0.83,…},"scale":"percentile",
"input":"crop"|"full","corpusSize":41280}` (`input` = the embedding used: the crop's, else the whole image's) -
percentile of the cosine within the corpus (calibrated, comparable across keys), for every key that has prompts
(~250). Recomputed from embeddings in seconds; a new key = new prompts, no image.

`neighbours`: `{"siglip":[["<puzzleId>",0.94],… top 30],"dinov2":[… top 30],"corpusSize":41280}` - recomputed for new
puzzles daily (and the new puzzle inserted into the lists of its neighbours, so their rows change too).

`tags` (the fusion): `{"taxonomy":"1.0.0","assigned":{"theme.pets.cats":{"s":0.91,"src":["describe","zeroshot"]},…},
"candidates":{…below threshold, for moderation queues…},"skills":{"skill.shape_sorting":0.62,…},
"traits":{"trait.large_uniform_areas":0.34,…},"entities":{"subjects":[…],"place":…,"characters":[…],"artist":…}}`
(`assigned` entries may carry `"uncertain": true` when the VLM and zero-shot disagree on a style / scene).

The `ImageAnalyzer` enum therefore has 15 cases (`embed_siglip2_full` added 2026-10-18); every payload stays under 64 KB.

## 12. Run order and budget

### 12.1 Order (one pipeline, two passes, resumable through the stale list)

```
for each analyzer A in pass order: GET /internal-api/puzzle-images?analyzer=A&version=V&status=stale  (paged)
  download original once into a local cache (decode with ImageIO: HEIC 210, AVIF 96, WebP, PNG, JPEG, one GIF)
pass 1 (small models resident together, ~6 GB):
  full image : ocr, barcode                      (Apple Vision, ANE/CPU)
  artwork    : crop + perspective                (vision PoC)
  full image : embed_siglip2_full                (GPU, no dependency)
  crop       : colors, traits (CPU pool), vision_labels, embed_siglip2 (crop), embed_dinov2, segmentation (GPU)
pass 2 (VLM alone, ~8-9 GB, batch 8, grouped by crop shape):
  crop       : describe (with the crop's OCR lines as context)
derive (no images): box_text, zeroshot, neighbours, tags
push: POST /internal-api/puzzle-image-analyses in batches of 200, after each pass
```
Take the shared model lock (`mkdir /tmp/msp-model-lock`, memory-pressure check ≥ 40 % free) per pass; end the
process between passes. Hidden secret puzzles are not listed by the API and get analysed after their reveal.

### 12.2 Budget

| Step | s / image | 41k images |
|---|---|---|
| download (≈ 0.8-1.1 MB per original, ~40 GB total, ≤ 4 req/s) | overlapped | ~3 h at 4 req/s |
| ocr + barcode + labels | 0.25 (M) | 3 h |
| crop | see vision PoC | - |
| colors + traits | 0.1-0.3 (M, CPU, parallel on 8+ cores) | < 1 h wall |
| SigLIP 2 ×2 + DINOv2-L | 0.3-1.0 (M contended / E idle) | 3-11 h |
| segmentation | ~0.5 (E) | 6 h |
| **pass 1 total** | **~1.5-2** | **~17-23 h** |
| describe, as measured (batch 8, long prompt) | 12.1 (M) | 138 h ≈ 5.8 days |
| describe, shortened prompt + 512 px images (E) | 6-8 | 70-90 h ≈ 3-3.8 days |
| describe with Qwen3-VL-4B (E) | 4-6 | 45-70 h |
| derive + push | - | < 1 h |

Daily (last 90 days: 5,819 new puzzles with an image = median 61 per day, p90 100, max 170; plus ~60 image
replacements per 90 days through approved change requests): pass 1 ≈ 3-6 min, describe ≈ 6-35 min. A daily cron on
the Mac is plenty.

**If the budget must shrink:** keep pass 1 complete (cheap, and it carries embeddings = the future); run `describe`
over the rated and most-solved puzzles first (results are concentrated: 87 % of results are on the 6,285 rated
puzzles), then the rest over nights. The one thing not to skip is `describe` itself - captions and subjects are the
only raw output we cannot reconstruct later from embeddings, and alt text / search / theme pages depend on them.

The bake-off ran on 2026-10-18 (§18): `describe` = Qwen3.5-9B 4-bit, constrained decoding, 10.75 s/img; the
measured per-stage resources and the two-machine split are in §19 (they replace the estimates above).

## 13. Difficulty explainer - the study to run once the backfill exists

Goal: finally understand **what makes a puzzle hard**, and use it four ways: global trait effects, per-puzzle
explanations, predicted difficulty for unrated puzzles, and per-player trait effects ("what type of puzzler you are").

### 13.1 Data

- Target: `puzzle_difficulty.difficulty_score` (median of first-attempt indices vs player baselines - already
  normalised for piece count and player skill), **log-transformed**; 6,285 rated puzzles, ~3,460 with medium/high
  confidence. Weight each puzzle by its confidence (e.g. `sample_size / (sample_size + 10)`).
- Secondary targets for the same features: `box_dependence_score`, `memorability_score`, `skill_sensitivity_score`
  (where present) - "busy unique detail -> high box dependence" is a prediction we can test.
- Features: everything numeric from §11 + controls: piece count (log), brand (target-encoded within training folds -
  a proxy for cut style and print), aspect ratio, image kind, image resolution (controls for artefacts: a box photo
  is not the artwork), year added.
- Pulled from production through an aggregate-only endpoint (§16) - no per-player data leaves the server.

### 13.2 Features that must exist as numbers (not labels)

| Group | Numeric features |
|---|---|
| piece scale (real piece count) | flat share, look-alike share, distinctiveness (median and 10th percentile), the same within the sky / water / foliage masks |
| regions | sky, water, foliage+grass, buildings, mountain, plain background, person shares (segmentation); VLM `areas` as a cross-check |
| colour | effective colour count, colour entropy, colourfulness, dominant share, lightness mean/std, dark share, light share, chroma mean, muted share |
| structure | edge density, detail, smooth-gradient share, uniform share, largest uniform region, colour regions (and regions covering 80 %), repetition, line strength, symmetry, border-band uniformity |
| content | text-in-artwork share (OCR boxes inside the crop), object counts, people / faces counts and area, collage grid (0/1), seek-and-find (0/1) |
| semantic (continuous) | zero-shot percentiles for each style and scene key and ~20 difficulty-relevant prompts ("large plain sky", "dense foliage", "repeating pattern", "smooth colour gradient", "dark night scene", ...) |
| embedding | SigLIP 2 (PCA 32-64) - for **prediction** only, never in explanations |

### 13.3 Models

Two models, on purpose:

1. **Predictor** (cold start): ridge / kernel ridge on the SigLIP embedding + all numeric features + controls; then
   gradient boosting (LightGBM or scikit-learn HistGradientBoosting) on the same; pick by validation. Output: a
   predicted log-difficulty with a **prediction interval** from conformal prediction on held-out residuals (honest
   ranges: "estimated Moderate-Challenging").
2. **Explainer**: an interpretable model on the ~40 named numeric features only (no embedding): a monotone-free
   gradient boosting with SHAP values, cross-checked by a regularised linear model on standardised features (the
   linear coefficients give the headline numbers: "+1 SD of uniform area share = +X % time"). It is shown only if its
   accuracy is close to the predictor's (within ~0.1 Spearman) - otherwise the explanation would describe a model
   that does not explain much.

Outputs:
- **Global importances**: SHAP mean |value| per feature and partial-dependence curves, converted to time:
  `exp(effect) - 1` = "+18 % time for large uniform areas (top quartile vs bottom quartile)".
- **Per-puzzle explanation** on the puzzle page (members): the top 2-3 SHAP contributions with a stable sign, phrased
  from the taxonomy labels: "Harder than average because: 40 % plain sky, low contrast, a repeating pattern. Easier
  because: many distinct colour areas." Only features whose sign was stable across all CV folds.
- **Predicted difficulty** for unrated puzzles (cold start), marked "Estimated", with the interval; stored as its own
  table/columns, never mixed into `puzzle_difficulty` (that stays purely data-derived).
- **Per-player trait effects** (§13.4).

### 13.4 Per-player trait effects ("what type of puzzler you are")

For each qualifying first-attempt solo solve, the residual `r = log(seconds / (baseline × puzzle_difficulty))`
(already computable from existing Insights tables). Model `r ~ Σ_k (β_k + b_{player,k}) · trait_k` with per-player
random slopes `b` shrunk towards 0 (a mixed model, or per-player ridge with a strong prior), on standardised traits
- best on the **skill demand** scores (§4, Appendix A) rather than 40 raw features, so each player gets ~10 numbers.
Controls: brand (a player who knows Ravensburger's cut will look like a "sky expert" if sky puzzles are mostly
Ravensburger), piece count, date (players improve - Fissler), first attempt only (repeats are memory).

Show a skill only when the player has ≥ 15 solves with variation in that trait and the slope's interval excludes 0;
phrase it relative to the community: "On puzzles with large plain areas you are 12 % faster than your baseline
predicts - a shape-sorting strength." Weakness -> "train it" recommendations (§6.5). This runs **server-side** (SQL
over existing per-solve data + the pushed trait values; a nightly job next to `recalculate-puzzle-intelligence`), so
per-player data never leaves production.

### 13.5 Validation and the bar for showing it

- **Grouped cross-validation by brand** (a brand's puzzles share cut and print style; random folds would leak them)
  and a **time split** (train on puzzles added before a date, test after - the real cold-start situation).
- Evaluate on medium/high-confidence puzzles; report Spearman, MAE on the score, and tier accuracy (exact and ±1).
- **Noise ceiling**: split each puzzle's indices into two random halves of players and correlate the two medians -
  no model can beat that reliability; report the model as a share of it.
- Bars (proposal): cold-start estimate shown when brand-grouped Spearman ≥ 0.6 **and** ±1-tier accuracy ≥ 80 % on
  the time split; per-puzzle explanations when the explainer reaches ≥ 0.5 Spearman and its top features are stable
  across folds; per-player skills as above. Below the bars: internal / admin only.

### 13.6 Pilot (tiny, clearly a pilot)

238 rated puzzles: the vision PoC's 158 rated sample images (stratified by tier 1-6, all piece counts) + 80 random
rated 500-piece puzzles with ≥ 10 indices. Features computed on the **uncropped** image: 13 simple numeric traits
(my reimplementation, virtual 500-piece grid), 44 SigLIP 2 zero-shot prompt scores, the SigLIP 2 embedding.
Ridge regression on log difficulty. Script: `scripts/pilot_difficulty.py`, `scripts/pilot_check.py`.

| Features | 10-fold CV Spearman | Brand-grouped CV | Train on the 158 stratified -> test on the 80 random |
|---|---|---|---|
| piece count only | -0.06 | - | - |
| 13 numeric traits | 0.36 | - | - |
| 44 zero-shot scores | 0.61 | - | - |
| numeric + zero-shot | 0.65 | 0.62 | 0.46 (MAE 0.219 vs 0.217 baseline - no gain) |
| **SigLIP embedding (1152-d)** | **0.73** | **0.70** | **0.65 (MAE 0.174 vs 0.217 for predicting the mean = 20 % better)** |

Strongest single signals (Spearman with difficulty, n = 238): collage −0.41, "photo of a puzzle box" −0.30
(probably a brand / marketing-image confound), plain white background −0.28, busy cartoon crowd −0.26, painting /
watercolour +0.24, food −0.23, colourfulness −0.22, flat illustration −0.21, piece distinctiveness −0.19 (more
distinct pieces = easier), birds +0.19, large plain sky +0.18, large water +0.17, foliage +0.15, dark night +0.14.
My crude uniform-share threshold showed nothing (−0.06) - on uncropped images and a fixed threshold; the PoC's
piece-level features on crops should do better.

Reading: **the embedding predicts difficulty surprisingly well even from 158 training puzzles**, so cold start is
feasible; the interpretable features carry real but weaker signal and need the crop, segmentation and piece-scale
features of the real backfill. With 3,460-6,285 training puzzles both will improve; the stratified sample inflates
plain CV numbers, which is why the random-80 column is the honest one.

## 14. Human correction loop and quality checks

- **Corrections are their own data**, never edits of analyzer rows: `puzzle_tag_correction` (puzzle, key, verdict
  `confirm` / `reject` / `set`, value, player, when, taxonomy version, note). The server's materialised `puzzle_tag`
  = fused `tags` payload ± corrections; a re-run replaces the payload, the corrections stay and keep winning. A
  correction on a deprecated key is mapped by the taxonomy's replacement table.
- Who: moderators first (`PUZZLE_MODERATION_ACCESS`, earns karma like other catalogue decisions - they are
  contributions); later players with a "Suggest a tag" on the puzzle page, applied by agreement (e.g. 3 players or 1
  moderator).
- Queues: low-confidence candidates for public-facing keys (holidays, licences, themes on SEO pages), catalogue
  mismatches (`box_text`), duplicate candidates (into the existing merge review), "better image wanted".
- **Corrections feed calibration**: they are the labelled set for per-key thresholds and for the next prompt bank.
- Quality checks per run: a 300-image stratified spot-check sheet (the labelled set grows to ~1,000 images: themes,
  style, scene, people, special type, image kind) with precision/recall per key; reliability diagrams for zero-shot
  percentiles; drift report between analyzer versions (share of puzzles whose top theme changed); schema validation
  of every VLM payload (invalid -> one retry with a stricter prompt -> else stored with `"invalid":true`).

## 15. Privacy and legal

- **Faces of real people:** detect counts and areas only; no face crops, no face embeddings, no recognition of who
  someone is; the VLM is told not to name real people (celebrity puzzles are still handled through box text / title).
- **Player-uploaded photos** can show homes, tables, receipts, address labels: raw OCR text is stored for parsing
  and admin debugging only and **never shown or published**; only parsed facts (piece count, EAN, ...) reach any page,
  and only after moderation. The VLM caption of such a photo is checked by the `image_kind` gate before it becomes
  alt text.
- **Licensed characters / franchises:** fine as internal tags and search terms. Public filters and SEO landing pages
  on franchise names are nominative use ("puzzles with Disney characters"), but: no logos, no implied endorsement,
  moderated assignments only, and be ready to remove a page on request. Brand names of puzzle manufacturers are
  already our catalogue facts.
- **Artists' names** (signatures, copyright lines) are public credits; show them only after moderation (OCR misreads
  names).
- **Secret competition puzzles** are invisible to the API until their reveal - nothing leaks through analyses.
- **Model licences:** only Apache-2.0 / MIT / PostgreSQL-licence components are in the recommended stack; the Apple
  Vision framework runs on Jan's own Mac (outputs are ours; worth one look at the macOS licence if this ever moves to
  a server). Record each model's repo id + revision in every payload (`model`) so a licence question can be answered
  per stored row.
- Public repo: this doc names no players.

## 16. What the internal API needs beyond the current generic store

The generic per-analyzer JSON (`puzzle_image_analysis`, ≤ 64 KB, upsert, stale list by version + fingerprint) is the
right backbone and carries **all raw and derived payloads, embeddings included** (base64 float16). Additions:

1. **New `ImageAnalyzer` cases**: `ocr`, `barcode`, `vision_labels`, `embed_siglip2`, `embed_dinov2`,
   `segmentation`, `describe`, `box_text`, `zeroshot`, `neighbours` (plus the existing `artwork`, `colors`,
   `traits`, `tags`); `embed_siglip2_full` split off `embed_siglip2` on 2026-10-18 (§11.1). No larger size limit needed (largest expected: OCR max ~16 KB with the 400-line cap).
2. **`puzzle_tag_correction` table + write path** (moderator UI later; internal API endpoint for bulk-confirming
   from a spot-check sheet).
3. **Server-side materialisation `puzzle_tag`** (puzzle_id, key, score, source, taxonomy_version, corrected) with
   indexes for filters / landing pages - rebuilt by app code after each push and each correction (no DB triggers).
4. **Taxonomy registry in the app** (keys, facet, parent, label keys for translations, public yes/no, threshold),
   versioned, so the server knows what a key means.
5. **Read endpoint for the explainer**: `GET /internal-api/puzzle-difficulties` (puzzle id, score, tier, confidence,
   sample size, box dependence, memorability, skill sensitivity, pieces, brand id) - aggregates only.
6. **A table for predicted difficulty** (puzzle id, predicted score, interval, model version) - kept apart from
   `puzzle_difficulty`.
7. Optional, later: **pgvector** (image swap to `pgvector/pgvector:0.8.7-pg16-bookworm`, digest-pinned) when live
   similarity is wanted; the app fills `halfvec` from the stored JSON.
8. Nice to have: the list endpoint could also return `ean`, `identificationNumber` and the current catalogue
   pieces/brand so the Mac can show a mismatch preview - not required, since the comparison is server-side.

## 17. Open questions for Jan

1. *Decided (Jan, 2026-10-18): the backfill time is fine; the M1 Air joins (§19).* **VLM budget:** accept ~3-6 days of machine time for `describe` over all 41k (nights only, resumable), or run it
   on the rated / most-solved puzzles first?
2. *Done 2026-10-18 (§18): Qwen3.5-9B 4-bit.* **Bake-off** Qwen3-VL-8B vs Qwen3.5-9B before the backfill (50 images, ~1 h) - yes?
3. **pgvector on production:** fine to switch the db image to the pgvector build (same Postgres 16, same Debian)
   when a live-similarity feature comes, or keep similarity precomputed on the Mac forever?
4. *Decided: the production database (through the internal API) is the master copy; originals stay in S3.* **Where the master raw store lives:** the Mac + an S3 bucket backup (proposed), or production only?
5. *Decided: admins and moderators correct.* **Corrections:** moderators only at first, or players' tag suggestions from day one?
6. *Decided: yes, later, if the design is friendly and it is good for SEO.* **Franchise pages for SEO** ("Disney puzzles", "Harry Potter puzzles"): wanted, given the trademark caution?
7. *Decided: members only, through the existing mechanisms.* **Showing estimated difficulty to everyone or members only** (the measured difficulty is members-only today)?
8. *Decided: self-only, part of members' Insights.* **Per-player skill profile**: members-only Insights, and should players be able to hide it?
9. *Decided: only a list for moderators with Dismiss / Ignore, never automatic proposals (boxes say 500 when it is ~550).* **Catalogue checks** from box text (piece count, multi-pack, brand): auto-file change proposals through the
   internal API (like the `puzzle-change-proposal` skill), or only list them for moderators?
10. **Image kind**: should a box photo / assembled-puzzle photo be flagged to players as "better picture wanted"
    (crowd fix), given ~half of the images are not flat artwork?

## 18. Description model bake-off (2026-10-18)

The question from §12.2 / §17.2: which model and which prompting give the best data for `describe`, which runs once
over ~41k images and cannot be rebuilt cheaply. Bake-off folder (local, not in git):
`.claude/worktrees/insights-describe-bakeoff/` - `scripts/` (runner, schema + prompts, ground truth, scorer, stage
benchmark), `out/runs/*.jsonl` (every raw output), `out/scores.json`, `report.html` (image | each model's output side
by side, ground-truth misses in red).

### Setup

- **40 images** from the vision lab's sample, run on the pipeline's **artwork crop** (`det_final.json`): 21 flat
  artwork, 11 box fronts / angled boxes, 8 photos of assembled puzzles. Themes: animals, pets, birds, sea, landscape,
  city, castles, food, fantasy, fine art (Van Gogh, a 19th-century cathedral interior), collage, cartoon / Wasgij,
  abstract, Christmas, Easter, winter, and 8 licensed sets (Disney / Pixar, Moomins, Peanuts ×2, Harry Potter, Masha
  and the Bear, Dog Man, Snow White). The OCR lines inside the crop (Apple Vision, from the taxonomy lab) are passed as
  context, as the pipeline will.
- **Ground truth** made by hand for all 40 (`scripts/gt.py`): core + acceptable themes, must-have subjects (with
  synonyms), acceptable scene / style / season / holiday / time / people values, expected place and character keywords.
  Scores: JSON validity, schema validity (no value outside the vocabulary, all keys), theme recall (core themes found)
  and precision (every predicted theme acceptable), subject recall, per-facet accuracy, **tag accuracy** = mean of theme
  recall, theme precision, scene, style, season, holiday, time. Captions read by eye for hallucinations ("major" = an
  invented object, a wrong identity or a wrong place; miscounts are minor).
- Every config at **temperature 0**, mlx-vlm 0.7.8 / MLX 0.32.4, M3 Max, one model at a time under the shared model
  lock (`mkdir /tmp/msp-model-lock` + ≥ 40 % free memory), a memory watchdog in the runner (abort below 25 % free).
- Consistency: 10 images re-run with the same config.

### Candidates and licences (checked on the model cards / licence files, 2026-10-18)

| Model | Licence | MLX build used | Tested |
|---|---|---|---|
| Qwen3-VL-4B-Instruct | Apache-2.0 | `mlx-community/Qwen3-VL-4B-Instruct-4bit` (cached) | yes |
| Qwen3-VL-8B-Instruct | Apache-2.0 | `mlx-community/Qwen3-VL-8B-Instruct-4bit` (cached) | yes |
| **Qwen3.5-9B** (Feb 2026, image input built in, hybrid thinking - off by default in mlx-vlm) | Apache-2.0 (LICENSE file) | `mlx-community/Qwen3.5-9B-MLX-4bit` (6.0 GB, downloaded) | yes |
| Gemma 4 12B-it | **Apache-2.0** - Gemma 4 moved to Apache 2.0 (ai.google.dev/gemma/docs/gemma_4_license); unlike Gemma 3's "Gemma Terms of Use" there is no separate use policy to accept | `lmstudio-community/gemma-4-12B-it-MLX-4bit` (6.8 GB, downloaded; `mlx-community/gemma-4-12B-4bit` is the base model) | yes |
| InternVL3.5 | Apache-2.0 | MLX builds only at 1B, 30B-A3B and GPT-OSS-20B - no 8B-class build | no |
| Molmo2-8B | Apache-2.0 (Ai2 responsible-use guidelines) | `mlx-community/Molmo2-8B-4bit` exists | no - strongest at pointing / counting, not captions; download budget |
| Qwen3-VL-8B 8-bit / Qwen3.5-9B 8-bit | Apache-2.0 | 9.9 / ~10 GB | no - would have pushed downloads over the 15 GB budget (see "Open") |
| Excluded by licence | | Qwen2.5-VL-3B (research licence), Llama 3.2 Vision (EU multimodal exclusion), LFM2.5-VL (revenue cap), Moondream 3 (BSL) | - |

### Results

Model comparison - **constrained decoding, 512 px, v2 prompt** (glossed vocabulary, below), Gemma 4 with the v1
prompt (dropped before round 2: slowest and blind to franchises):

| Model (4-bit MLX) | JSON / schema valid | Tag accuracy | Theme recall / precision | Subject recall | Characters found | Place found | Major caption errors / 40 | s/img M3 Max (median) | Peak memory | Fits M1 16 GB | M1 Air s/img (est., sustained) |
|---|---|---|---|---|---|---|---|---|---|---|---|
| **Qwen3.5-9B** | 100 % / 100 % | 0.868 | 0.86 / 0.69 | 0.86 | 0.90 | 0.22 | **0** (2 miscounts) | 10.1 | 7.4 GB | yes, tight | ~57 |
| **Qwen3.5-9B + catalogue title** (recommended) | 100 % / 100 % | **0.880** | 0.85 / 0.71 | 0.85 | 0.90 | **0.78** | **0** | 10.75 | 7.4 GB | yes, tight | ~57 |
| Qwen3-VL-8B | 100 % / 100 % | 0.877 | 0.85 / 0.64 | 0.88 | 0.80 | 0.11 | 2 ("Brooklyn Bridge" for a West-coast bay bridge; invented sesame seeds) | 11.2 | 6.8 GB | yes | ~57 |
| Qwen3-VL-4B | 100 % / 100 % | 0.829 | 0.75 / 0.78 | 0.87 | 1.00 | 0.44 (2 of them wrong: Bojnice "Czech Republic", "New York City") | 3 | 6.3 | 4.1 GB | yes, comfortable | ~30 |
| Gemma 4 12B (v1 prompt) | 100 % / 100 % | 0.842 | 0.69 / 0.73 | 0.81 | **0.50** (no Peanuts, Disney, Moomins, Masha) | 0.11 | 4 (a dog in Oz, a "grill" for a samovar, Moomins as bear / fox / rabbit) | 16.3 | 8.0 GB | borderline | ~95 |

Prompting and decoding - Qwen3.5-9B unless the row says otherwise:

| Config | JSON valid | Schema valid | Values outside vocab / img | Tag accuracy | s/img |
|---|---|---|---|---|---|
| plain JSON prompt (v1), 512 px | 97.5 % | 47.5 % | 2.9 | 0.711 | 13.3 |
| plain - Qwen3-VL-4B / Qwen3-VL-8B | 90 % / 95 % | 0 % / 32.5 % | 4.0 / 1.4 | 0.557 / 0.825 | 9.8 / 10.8 |
| constrained, v1 prompt (value lists, no glosses) | 100 % | 100 % | 0 | 0.852 | 10.2 |
| constrained, **v2 prompt** (value lists + short glosses) | 100 % | 100 % | 0 | 0.868 | 10.1 |
| constrained, v2, **768 px** | 100 % | 100 % | 0 | 0.865 | 13.1 |
| constrained, **short prompt** (field names only, values left to the grammar) | 100 % | 100 % | 0 | **0.640** | 9.7 |
| constrained, v2 + **catalogue title** | 100 % | 100 % | 0 | **0.880** (place 0.78) | 10.75 |
| two-pass (free caption, then constrained JSON with the caption), v2 | 100 % | 100 % | 0 | 0.863 (subjects 0.88) | 15.5 |
| batched (`batch_generate`, 4 or 8 letterboxed 512×512) | - | - | - | - | aborted: memory fell from 65-70 % free to 32 % (batch 4) / 17 % (batch 8) |

Consistency: every re-run of 10 images at temperature 0 was **byte-identical (10/10)** - all four models, plain and
constrained, v1 and v2, with and without the title. A run is reproducible from (model revision, prompt version, image,
OCR lines, title).

### What the numbers say

1. **Schema-constrained decoding is a must.** With plain prompting every model wrote JSON with values outside the
   vocabulary in most outputs (`"abstract"`, `"holiday"` as a theme, `"collage_collection"` as a special hint), dropped
   keys and sometimes looped until max tokens (the 4B listing "Sniff, Sniff, …" or Peanuts names). Constrained decoding
   (mlx-vlm's llguidance JSON-schema logits processor) gives 100 % valid output and is **faster** (compact JSON, no
   loops: 4B 5.1 vs 9.8 s/img, 9B 10.2 vs 13.3).
2. **The grammar only restricts; the prompt still has to teach the values.** With the value lists removed from the
   prompt, tag accuracy fell from 0.87 to 0.64. The model ended up wherever the grammar let it (`aerial_view` and
   `photomosaic` everywhere), and dropping ~950 prompt tokens saved only 0.4 s. Glossing the confusing values (v2)
   added +0.016-0.07 on every model. Qwen3-VL-8B had tagged Easter eggs and Christmas cookies as
   `licensed_entertainment` with bare keys, in plain mode too, so that was the vocabulary and not the grammar.
3. **The catalogue title is the cheapest quality win.** With "Catalogue title (may not describe the picture): …" in
   the user message, place recall went from 0.22 to 0.78 (Khao Phing Kan, Zámek Bojnice, Peyto Lake / Banff,
   Aiguille Verte, Liège Cathedral, Røros, Hogwarts) and artists came through (Keith Haring, Van Gogh), for +0.6 s.
   Without it the 8-9B models leave `place` empty ("only when sure"), and the 4B guesses, sometimes wrongly.
4. **512 px is enough.** 768 px gave no gain with the v2 prompt (0.865 vs 0.868) and cost +30 % time.
5. **Two-pass does not pay.** A free caption first, then the constrained JSON with it: tag accuracy 0.863 vs 0.868,
   subject recall +0.02, at +53 % time (15.5 s). Putting `caption` first in the single-pass schema gets most of that
   benefit for free.
6. **Arrays get filled.** Under a grammar, models tend to fill an array to `maxItems`. `specialHints` came out as four
   unrelated values on almost every image, so it was removed from the schema (`special` comes from box text and the
   catalogue, §10). `themes` gets a weak third theme (often `countryside_village`). Downstream, trust themes 1-2 and
   require zero-shot agreement for a third - the fusion rule in §10 already does this.
7. **`artistSignature` picks up the brand** ("Galison" from the box sticker). Add "never the puzzle brand or
   publisher" to the prompt (not re-tested), and keep artist names behind moderation (§15).
8. **Qwen3.5-9B vs Qwen3-VL-8B**: equal tag accuracy (0.868-0.880 vs 0.877) and the same decode speed (41-45 tok/s),
   but Qwen3.5 made no major caption error where the 8B invented two facts. It also names franchises in
   `characters` more reliably and writes the most specific captions (counts, materials, the Wasgij "chef confronted by
   an angry woman"). The 4B is twice as fast but makes up places and objects. Gemma 4 is the slowest (24 tok/s) and
   does not recognise characters (safety tuning or training data), so it is out.
9. **Batching does not work as-is.** `batch_generate` groups images by shape, so crops of different sizes run one by
   one (4 "batched" crops: 10-12 s/img). Letterboxed to a fixed 512×512, batches of 4 and 8 pushed memory from 65-70 %
   free down to 32 % / 17 %, and the watchdog stopped them. Plan with unbatched throughput; batching is a follow-up.

### Recommendation

- **Model: Qwen3.5-9B, 4-bit MLX** (`mlx-community/Qwen3.5-9B-MLX-4bit`, Apache-2.0), thinking off. Record the repo
  revision in `model`: `analyzerVersion` `1.0.0+qwen35-9b-4bit`.
- **Prompt: v2** (the system message below), then the user message: `Catalogue title (may not describe the picture):
  <name>` / `Text read inside the picture (OCR, may contain errors): <up to 12 OCR lines whose centre is inside the
  crop, by area>` / `Return the JSON object.`; the image goes in the user turn. Store `promptVersion: "v2"` and
  `titleContext: true` in the payload.
- **Image: the artwork crop, long side 512 px** (no letterbox).
- **Decoding: JSON-schema constrained** (llguidance via `mlx_vlm.structured.build_json_schema_logits_processor`),
  greedy (temperature 0), `max_tokens` 700 (median output 310 tokens), unbatched. Caption first in the schema: it acts
  as the model's notes before the tags.
- **Expected cost: ~10.75 s/img** on an otherwise idle M3 Max (1,150-1,400 prompt tokens at ~450 tok/s + ~310 output
  tokens at ~44 tok/s), **7.4 GB** peak. 41,266 images ≈ **123 h ≈ 5.1 days** of M3 Max time for `describe`
  alone. The two-machine split and the full backfill timeline are in the next section.
- Fallback if the budget must halve: Qwen3-VL-4B with the same v2 prompt + title (6.3 s, 4.1 GB). It is faster but
  less accurate (0.83, 3 made-up facts in 40). Not recommended for the one-off backfill.

Setup in code (`scripts/run.py`, `scripts/schema.py`):
```python
from mlx_vlm import load, generate
from mlx_vlm.prompt_utils import apply_chat_template
from mlx_vlm.structured import build_json_schema_logits_processor
model, proc = load("mlx-community/Qwen3.5-9B-MLX-4bit")
lp = build_json_schema_logits_processor(proc.tokenizer, DESCRIBE_SCHEMA)          # once per process
msgs = [{"role": "system", "content": SYSTEM_V2},
        {"role": "user", "content": f"Catalogue title (may not describe the picture): {title}\n"
                                    f"Text read inside the picture (OCR, may contain errors): {' / '.join(ocr)}\n"
                                    "Return the JSON object."}]
prompt = apply_chat_template(proc, model.config, msgs, num_images=1)            # enable_thinking defaults to False
out = generate(model, proc, prompt, image=[crop_512_png], max_tokens=700, temperature=0.0,
               logits_processors=[lp.clone()])                                    # clone = fresh matcher per image
```

`DESCRIBE_SCHEMA` (all keys required, in this order, `additionalProperties: false`): `caption` string ≤ 600 ·
`altText` string ≤ 140 · `themes` 1-3 of the 23 theme keys · `subjects` 1-12 strings ≤ 40 · `scene` enum (18) ·
`style` enum (16) · `season` none|spring|summer|autumn|winter · `holiday` none|christmas|halloween|easter|valentines|
thanksgiving|new_year|other · `time` none|day|golden_hour|dusk_blue_hour|night · `weather` none|sunny|partly_cloudy|
overcast|rain|snow|fog_mist|storm · `mood` 1-2 of the 12 moods · `place` string ≤ 80 or null · `characters` 0-6 strings
≤ 50 · `artistSignature` string ≤ 80 or null · `people` none|few|many|crowd · `textInArtwork` none|little|lots ·
`counts` {distinctObjects, animals, people} integers 0-999 · `areas` {sky, water, foliageGrass, buildings,
plainBackground} integers 0-100 · `collageGrid`, `seekAndFind` booleans · `extraTags` 0-8 strings ≤ 40. Changes from
§11.1: `none` added to `time` and `weather` (indoor / abstract pictures), `characters` is a list,
`specialHints` removed, `extraTags` added for the long tail. Post-processing: lowercase + singularise + de-duplicate
`subjects`, and map `"none"` / `"null"` strings in `place` / `artistSignature` to null.

System prompt v2 (≈ 930 tokens):
```text
You catalogue jigsaw puzzle pictures. The image is the artwork of one puzzle, cropped from its box image; it may still show box edges, packaging text or a photo of the assembled puzzle - describe only the picture itself. Be factual: mention only what is clearly visible. Never name real people. Name a place, landmark or licensed character only when you are sure.
Answer with ONE JSON object, keys in this order:
caption: 2-3 sentences describing the picture (subject, setting, style, notable details).
altText: one sentence, max 125 characters, for screen readers.
themes: 1-3, what the picture is about, most important first; add a second or third only if it clearly applies. Values: animals (wild or farm animals) | pets (cats, dogs) | birds | sea_underwater (sea life, coast, harbours) | landscape_nature | flowers_garden | countryside_village (farms, cottages, villages) | city_architecture (cities, streets, buildings, castles, churches) | travel_places (a recognisable destination or landmark) | fine_art (a famous painter's or museum artwork, old masters) | illustration_design (mandalas, patterns, posters, decorative design) | fantasy_myth (dragons, fairies, magic, gothic) | space_scifi | cartoon_humour (funny comic scenes, Wasgij) | licensed_entertainment (ONLY recognisable franchise characters: Disney, Peanuts, Harry Potter...) | seasons_holidays (Christmas, Easter, Halloween, seasonal scenes) | food_drink | transport (cars, trains, ships, planes, balloons) | hobbies_sport_music (books, sport, music, games) | people_everyday (people and daily life) | maps_education | collage_collection (many separate small pictures or items) | abstract_challenge (gradients, geometric shapes, no real subject)
subjects: up to 12 concrete visible things, singular English nouns, most prominent first, no duplicates; species or breed only when sure.
scene: landscape | seascape | cityscape | street_level | interior (inside a room or building) | still_life (objects arranged on a table) | underwater | sky_space | garden | portrait_closeup (one person or character fills the picture) | animal_closeup | crowd_scene (many figures, busy scene) | aerial_view | allover_pattern (the same kind of thing repeated over the whole picture) | abstract | map | collage_grid (separate panels in a grid) | product_flatlay (items seen from above)
style: photo | photo_aerial | photo_macro | painting_oil_acrylic (looks painted with a brush, incl. digital paintings) | watercolour | pencil_ink_drawing | illustration_digital (clean digital illustration) | cartoon_comic (outlined cartoon or comic characters) | vector_flat (flat shapes, no shading) | render_3d | vintage_retro_poster | collage_mixed | pattern_textile | map_cartographic | photomosaic | pixel_art
season: none|spring|summer|autumn|winter | holiday: none|christmas|halloween|easter|valentines|thanksgiving|new_year|other | time: none|day|golden_hour|dusk_blue_hour|night (none = indoors or not shown) | weather: none|sunny|partly_cloudy|overcast|rain|snow|fog_mist|storm
mood: 1-2 of cozy|serene|playful|dramatic|spooky|romantic|nostalgic|vibrant|majestic|melancholic|whimsical|festive
place: named place, landmark or country if recognisable, else null
characters: licensed franchise or named characters (e.g. Disney, Harry Potter, Snoopy), else []
artistSignature: artist name if signed or credited in the picture, else null
people: none|few|many|crowd (few = 1-3, many = 4-15, crowd = more) | textInArtwork: none|little|lots
counts: {distinctObjects, animals, people} as integers
areas: {sky, water, foliageGrass, buildings, plainBackground} as rough % of the picture
collageGrid, seekAndFind: true/false
extraTags: up to 8 more useful search words (art movement, era, objects, colours), else []
```
(For production, add "never the puzzle brand or publisher" to the `artistSignature` line - finding 7.)

### Open / follow-ups

- **Batching** (the only big speed lever left; decode is bandwidth-bound, so 4 streams could give ~2× throughput):
  find the memory spike in `batch_generate`, probably prefill of the whole batch at once - try `prefill_step_size`
  and batches of 2, on letterboxed or aspect-bucketed crops.
- **Fewer output tokens**: the ~80 forced key / punctuation tokens of every answer each cost a decode step (mlx-vlm
  does not fast-forward forced grammar tokens). Short keys mapped back after decoding, or dropping `areas` (the
  segmentation analyzer measures region shares), would save ~10-20 % - re-check quality before adopting.
- **8-bit** (Qwen3.5-9B 8-bit, ~10 GB): not tested, to stay under the 15 GB download budget. Decode would be ~1.6×
  slower (bandwidth-bound), i.e. +2-3 days on the backfill. Worth a 40-image check only if 4-bit's errors turn out to
  matter in the spot-check sheet.
- **Qwen3.5-4B** (3.1 GB, Apache-2.0) was not tested (download budget). It is the natural candidate if the M1 Air is
  ever to run `describe` at a useful speed (~25-30 s/img estimated).
- Check that the M1 and the M3 produce identical output for the same input before letting both claim `describe`.
- Downloads made for this bake-off: Qwen3.5-9B-MLX-4bit 5.98 GB, gemma-4-12B-it-MLX-4bit 6.77 GB (can be deleted),
  EoMT-L COCO panoptic 1.27 GB (for the resource table) = **14.0 GB**, in `~/.cache/huggingface/hub/`.

## 19. Resource use per stage and the two-machine split (2026-10-18)

Measured on the M3 Max (14-core CPU / 30-core GPU, 36 GB, 300 GB/s), each stage alone in its own process under the
shared model lock, in a **sustained loop of 2-4 minutes** over the vision lab's sample (images pre-decoded to
≤ 1600 px like the pipeline cache, so download and decode are excluded). Script `scripts/stages.py`, raw numbers in
`out/stages.jsonl` (bake-off folder `.claude/worktrees/insights-describe-bakeoff/`). "Throttle" = median time per
image in the last 60 s ÷ in the first 30 s (1.0 = steady; < 1 = it got faster after warm-up). CPU cores = process CPU
% ÷ 100; GPU % = `ioreg` IOAccelerator "Device Utilization" (no sudo needed). The compute unit was inferred from those
two numbers plus, for Apple Vision, a forced CPU-only rerun (`usesCPUOnly`): low CPU + low GPU + faster than CPU-only
= Neural Engine.

**M1 Air estimates** (MacBook Air M1, 16 GB, 7- or 8-core GPU, 68 GB/s, fanless) are scaled, not measured:
- GPU compute-bound work (vision encoders, segmentation, VLM prefill) **×5**: llama.cpp's Apple Silicon table gives
  prompt processing of 118 tok/s on the M1 (8-core GPU) vs 568 tok/s on the M3 Max 30-core (7B Q4_0), = 4.8× (5.2×
  on the 7-core GPU).
- VLM token generation (memory-bandwidth-bound) **×4**: 14.2 vs 56.6 tok/s in the same table.
- Neural Engine **×1.5** (11 vs 18 TOPS); CPU single thread **×1.3**, all cores **×2.4** (4P+4E vs 10P+4E).
- **Fanless sustained**: another **×1.33** on GPU and all-core CPU work (the Air drops to roughly 70-80 % after 5-10
  minutes of full load), ×1.1 on Neural Engine / single-thread work. These are estimates. Before the backfill, a
  10-minute loop of `stages.py` per stage on the Air would replace them with measured numbers.
- Memory: macOS gives the GPU about 2/3 of RAM by default (~10.7 GB on a 16 GB Mac). "Fits" below means the stage's
  peak stays clearly under that, with room for macOS (~4-5 GB) and the downloader.

| Stage (analyzer) | Runs on | Unit | M3 Max s/img (sustained) | Throttle | CPU cores | GPU % | Peak RAM | M1 Air s/img (est., sustained) | Fits M1 16 GB |
|---|---|---|---|---|---|---|---|---|---|
| `artwork` kind gate - SigLIP2 so400m fp16 (MPS) | full, 768 px | GPU | **0.11** (0.16 in the last minute) | 1.5 | 0.1 | 96 | 2.4 GB (MPS) | ~0.8 | yes |
| `artwork` box - Qwen3-VL-4B 4-bit (MLX), 672 px, + OpenCV quad snap (~5 ms) | full, ~76 % of images (the rest are flat = trim only) | GPU | **2.6 per VLM image** (2.35 steady) = **~2.0 per puzzle** | 0.6 (warm-up) | 0.3 | 96 | 4.5 GB | ~15 per VLM image = ~11 per puzzle | yes |
| `colors` + `traits` (numpy / OpenCV / scikit-image) | crop | CPU (traits also calls Apple Vision fast OCR) | 0.47 CPU-s per image single-threaded; **0.078 s/img** wall with 6 single-thread workers | 1.0-1.05 | 1 per worker (8.4 if run in one process: BLAS / k-means threads) | 0 | ~0.4 GB per worker | ~0.2 with 6 workers | yes |
| `ocr` + `barcode` + `vision_labels` + faces + humans - Apple Vision | full (labels: crop) | **Neural Engine** (+0.7 CPU core) | **0.134** median, 0.18 mean | 1.0 | 0.7 | 6 | 1.0 GB | ~0.3 | yes |
| same, forced CPU-only (`usesCPUOnly`) - for comparison | full | CPU | 0.20 | 1.0 | 1.0 | 0 | 1.0 GB | - | - |
| `embed_siglip2` - SigLIP2 so400m fp16, crop + full = 2 embeddings, batches of 8 puzzles | crop + full | GPU | **0.36 per puzzle** (first 30 s 1.7: MPS warm-up per new image shape) | 0.2 (warm-up) | 0.1 | 76 | 3.5 GB (MPS) | ~2.4 | yes |
| `embed_dinov2` - DINOv2-**base** measured (fp16, batches of 8); **L/14 ≈ 3.5× FLOPs** | crop | GPU | base 0.010; **L ≈ 0.035** (estimate) | 0.9 | 0.3 | 91 | base 1.3 GB, L ~2 GB | ~0.25 | yes |
| `segmentation` - EoMT-L COCO panoptic 640 fp16 (MPS) + panoptic post-processing | crop | GPU | **0.31** (0.35 in the last minute) | 1.2 | 0.1 | 96 | 2.9 GB (MPS) | ~2.1 | yes |
| `describe` - **Qwen3.5-9B 4-bit**, constrained, 512 px, v2 prompt + title (recommended) | crop + OCR lines + title | GPU | **10.75** unbatched (batching not viable: memory) | 1.0 in 7-minute runs | ~0.3 | ~95 | **7.4 GB** | **~57** (300 generated tokens at ~7.7 tok/s + prefill) | fits, tight (7.4 of ~10.7 GB GPU budget) |
| `describe` - Qwen3-VL-4B 4-bit, same prompt (fallback) | crop | GPU | 6.3 | 1.0 | ~0.3 | ~95 | 4.1 GB | ~30 | yes, comfortable |

Notes from the measurements:
- **Apple Vision collides with GPU jobs.** During the first, accidental run, while Gemma 4 kept the GPU at 98 %,
  Apple Vision took **14.8 s/img instead of 0.13**. The taxonomy lab saw the same thing (27-42 s stalls). So never
  run `ocr` next to a GPU-saturating stage on the same machine. Give it its own phase, or put it on the other machine.
- `colors` + `traits` in a single process grabs 8 threads through BLAS / k-means and gains little from them. Run them
  as N single-threaded workers (`OMP_NUM_THREADS=1`) next to a GPU stage, where they cost the GPU nothing.
- SigLIP2's first ~30 s are 5× slower while MPS compiles kernels for each new input shape. Feeding a fixed 384×384
  input (the processor squashes to that size anyway) avoids recompiles in a long run.
- In the 2-7 minute runs the M3 Max showed **no consistent thermal throttling**. The gate (1.5×) and EoMT (1.2×)
  slowed in their last minute, and one describe run halved its token rate in its last ten images. All three coincide
  with GPU activity outside the lock (other agents / the Docker VM), so they say more about sharing the machine than
  about heat. For a days-long run, pin `caffeinate -i` and keep other GPU work off.

**Ordering dependencies** (the claim API's `requires` filter - built 2026-10-18, docs/features/internal-api.md
"Puzzle image analysis" - so a worker never claims what it cannot run yet; the pipeline profiles enforce the table,
the API only filters):

| Analyzer | Needs first |
|---|---|
| `ocr`, `barcode` | nothing (full image) |
| `embed_siglip2_full` | nothing |
| `artwork` | nothing (gate + VLM on the full image) |
| `colors`, `traits`, `segmentation`, `embed_dinov2`, `embed_siglip2` (crop), `vision_labels` | `artwork` (the crop) |
| `describe` | `artwork` (crop) **and** `ocr` (the OCR lines inside the crop are prompt context) |
| `box_text`, `zeroshot`, `neighbours`, `tags` (derived) | their raw inputs; no image |

So `GET /internal-api/puzzle-images?analyzer=describe&status=stale&requires=artwork,ocr` (only puzzles whose current
image already has those results at the current fingerprint).

**Recommended split**

| Machine | Claims | Order | Wall-clock for 41,266 images |
|---|---|---|---|
| **M3 Max** | `artwork` (gate + VLM), then `describe` | gate first (1.3 h), VLM on the non-flat ones (~23 h), then describe | artwork ~24 h + describe 41,266 × ~10.75 s ≈ 123 h → **≈ 6.1 days** alone (batching was not viable in the bake-off - memory) |
| **M1 Air** | everything else: `ocr` + `barcode` (full), `embed_siglip2_full` + `embed_siglip2` (crop), `vision_labels`, `embed_dinov2`, `segmentation`, `colors` + `traits` | phase A without dependencies: OCR / barcode first (~3.5 h, so `describe` never waits on it), then SigLIP full-image (~14 h); phase B (`requires=artwork`): SigLIP crop + DINOv2-L + EoMT on the GPU (~3.6 s/img), with colors / traits in 4-6 CPU workers alongside | phase A ~17 h + phase B ~41 h ≈ **2.4 days** |

Why this way round:
- `describe` is ~80 % of all compute and has to be one model for the whole catalogue. On the Air it would take ~57 s/img,
  i.e. 27 days alone. The M3 Max must own it.
- `artwork` VLM is 5-6× slower on the Air (~11 s/puzzle) and gates both `describe` and the Air's own phase B, so the
  M3 Max does it first. The Air runs its dependency-free phase A in the meantime and is never idle.
- Everything the Air gets is small (≤ 3.5 GB peak, no stage near the 10.7 GB GPU limit). It includes **Apple Vision,
  which then never shares a GPU with the VLM** (the 14.8 s/img stall above).
- When the Air finishes (~day 2.4), it can join `describe` claims with the same model and prompt (Qwen3.5-9B 4-bit
  fits in 16 GB, 7.4 GB peak). At ~57 s/img that is ~1,500 images a day next to the M3 Max's ~8,000. Of the ~30,000
  images still open at day 2.4, it takes about 1/6, which ends the backfill ~0.5 days earlier (day ~5.5 instead of ~6.1).
  Same model + same prompt + greedy decoding = the same output on either machine (to check: MLX on M1 vs M3 can differ
  in rare float ties).

**Total backfill with both machines: ≈ 5.5 days of wall-clock** (≈ 6.1 if the Air does not join `describe`), set by
the M3 Max (`artwork` ~1 day + `describe` ~5 days); the M1 Air finishes all of pass 1 in ~2.4 days. Each 10 % shaved
off `describe` (shorter output, see the bake-off follow-ups) saves ~0.5 days. The M3 Max column assumes the machine is
otherwise idle - the lab saw 20-50 % slowdowns whenever other GPU work ran next to it. Daily volume afterwards (~60 images, p90 100) is ~15-20 minutes on the
M3 Max alone.

## Appendix A - taxonomy v1.0.0 (keys)

Labels are English drafts; translations follow when a key goes public. "Src" = main producer (V = VLM `describe`,
Z = zero-shot, S = segmentation, T = numeric traits/colors, O = OCR/`box_text`, C = catalogue, A = Apple labels/faces).

### A.1 `theme` (23 top level, ~115 subthemes) - Src V + Z (+ A)

| Top key | Subthemes (`theme.<top>.<sub>`) |
|---|---|
| `animals` | wildlife, safari, farm_animals, horses, forest_animals, polar_animals, insects_butterflies, reptiles_amphibians, dinosaurs, bears, big_cats, foxes_wolves |
| `pets` | cats, dogs, small_pets |
| `birds` | owls, parrots_tropical, songbirds, birds_of_prey, waterbirds, flamingos |
| `sea_underwater` | underwater_life, coral_reef, whales_dolphins, sea_turtles, beach_coast, harbour_boats, lighthouses |
| `landscape_nature` | mountains, lakes_rivers, waterfalls, forest_woods, fields_meadows, deserts, sunsets_sunrises, northern_lights, autumn_colours, snowy_landscape |
| `flowers_garden` | flowers_bouquet, flower_fields, gardens, trees_plants, mushrooms, botanical, cacti_succulents |
| `countryside_village` | cottages, villages, farms_barns, americana_nostalgia, country_shops |
| `city_architecture` | skylines, streets_cafes, castles_palaces, churches_cathedrals, bridges, landmarks, lost_places, interiors_rooms, windows_doors |
| `travel_places` | postcards_travel, famous_cities, islands, world_wonders (the place itself = entity) |
| `fine_art` | old_masters, impressionism, japanese_art, art_nouveau, modern_art, folk_art, religious_art |
| `illustration_design` | mandala, stained_glass, art_deco, pattern_design, contemporary_illustration, typography_posters |
| `fantasy_myth` | dragons, unicorns, fairies, mermaids, fairy_tales, magic_wizards, gothic_dark, steampunk, mythology |
| `space_scifi` | planets_space, astronauts, sci_fi |
| `cartoon_humour` | comic_crowd, wasgij_style, funny_animals, kawaii |
| `licensed_entertainment` | animation_film, film_tv, video_games, comics_superheroes, anime_manga, music_bands, toys_brands |
| `seasons_holidays` | christmas, halloween, easter, valentines, thanksgiving_harvest, new_year, lunar_new_year |
| `food_drink` | sweets_desserts, fruit_vegetables, kitchen_cooking, coffee_tea_drinks, markets_shops |
| `transport` | classic_cars, cars_modern, trains_railways, aircraft, ships_sailing, trucks_tractors, bicycles_motorbikes, hot_air_balloons |
| `hobbies_sport_music` | books_libraries, crafts_sewing, sports, music_instruments, games_toys, gardening_hobby |
| `people_everyday` | family_life, historical_scenes, portraits, culture_traditions, romance, professions |
| `maps_education` | world_maps, country_maps, alphabet_numbers, science_charts, anatomy, history_timelines |
| `collage_collection` | collage_grid, photomosaic, stamps_labels, object_flatlay |
| `abstract_challenge` | gradient, monochrome, abstract_art, geometric, optical_illusion |

### A.2 `style` (16) - Src V + Z

`photo`, `photo_aerial`, `photo_macro`, `painting_oil_acrylic`, `watercolour`, `pencil_ink_drawing`,
`illustration_digital`, `cartoon_comic`, `vector_flat`, `render_3d`, `vintage_retro_poster`, `collage_mixed`,
`pattern_textile`, `map_cartographic`, `photomosaic`, `pixel_art`.

### A.3 `scene` (18) - Src V + Z + S

`landscape`, `seascape`, `cityscape`, `street_level`, `interior`, `still_life`, `underwater`, `sky_space`, `garden`,
`portrait_closeup`, `animal_closeup`, `crowd_scene`, `aerial_view`, `allover_pattern`, `abstract`, `map`,
`collage_grid`, `product_flatlay`.

### A.4 `season`, `holiday`, `time`, `weather`, `mood` (34) - Src V + Z (+ A)

- `season`: spring, summer, autumn, winter.
- `holiday`: christmas, halloween, easter, valentines, thanksgiving, new_year, other.
- `time`: day, golden_hour, dusk_blue_hour, night.
- `weather`: sunny, partly_cloudy, overcast, rain, snow, fog_mist, storm.
- `mood`: cozy, serene, playful, dramatic, spooky, romantic, nostalgic, vibrant, majestic, melancholic, whimsical,
  festive.

### A.5 `people` (5) and `audience` (5) - Src A + S + V; C

- `people`: none, few (1-3), many (4-15), crowd (> 15); `people.prominent_faces` (face area ≥ 5 % of the crop).
- `audience`: young_kids (≤ 60 pieces or cartoon + ≤ 100), kids (≤ 300 + kid-themed), family (mixed piece sizes /
  family lines), adult (default), large_pieces_senior (large-piece lines from box text / catalogue).

### A.6 `special` (22) - Src O + C (+ V proposes)

`wasgij`, `seek_and_find`, `mystery_escape`, `murder_mystery`, `double_sided`, `glow_in_dark`, `glitter_foil`,
`neon`, `lenticular`, `panorama`, `round_outline`, `shaped_outline`, `whimsy_pieces`, `multi_pack`, `impossible_challenge`
(Krypt, "Impossible", "Challenge"), `no_image_solid`, `mini`, `wooden`, `three_d`, `family_mixed_pieces`,
`frame_tray`, `poster_included`.

### A.7 `licence` (25 + open) - Src V + O (+ Z); public only after moderation

`disney`, `pixar`, `disney_princess`, `marvel`, `dc`, `star_wars`, `harry_potter`, `lord_of_the_rings`, `pokemon`,
`nintendo`, `minecraft`, `peanuts`, `looney_tunes`, `moomins`, `asterix`, `paw_patrol`, `frozen`, `barbie`,
`lego`, `one_piece`, `naruto`, `studio_ghibli`, `stranger_things`, `national_geographic`, `museum_licence`; plus
`licence.other` with the free `characters` text.

### A.8 `trait` (24) - Src T + S + O; numeric value always stored, threshold = when the label applies

| Key | Numeric source | Label when (v1 draft, to calibrate) |
|---|---|---|
| `large_uniform_areas` | `traits.uniform_share` | ≥ 0.25 |
| `featureless_pieces` | `piece_flat_share` (real piece count) | ≥ 0.20 |
| `lookalike_pieces` | `piece_lookalike_share` | ≥ 0.30 |
| `distinct_pieces` | `piece_distinctiveness` | top quartile of the corpus |
| `large_sky` | seg `sky` | ≥ 0.25 |
| `large_water` | seg `sea + river + water` | ≥ 0.20 |
| `foliage_texture` | seg `tree + grass + plant` | ≥ 0.30 |
| `smooth_gradients` | `smooth_gradient_share` | ≥ 0.15 |
| `repeating_pattern` | `repetition` | ≥ corpus 90th percentile |
| `busy_detail` | `detail` + `edge_density` + object count | top quintile and distinctiveness ≥ median |
| `low_colour` | `palette_effective_colors` | ≤ corpus 15th percentile |
| `many_colour_regions` | `color_regions_80` | ≥ corpus 75th percentile |
| `colourful` | `colorfulness` | ≥ corpus 75th percentile |
| `dark` | `dark_share` | ≥ 0.40 |
| `bright_white` | `light_share` | ≥ 0.40 |
| `low_contrast` | `lightness_std` | ≤ corpus 15th percentile |
| `text_heavy` | `ocr.textAreaShareInArtwork` | ≥ 0.05 |
| `strong_lines` | line strength | ≥ corpus 80th percentile |
| `faces_prominent` | Apple faces area | ≥ 0.05 |
| `many_objects` | VLM counts / seg instances | ≥ 30 |
| `collage_grid` | VLM + Z `collage` | VLM true and Z ≥ p90 |
| `uniform_border` | border band flat share | ≥ 0.5 |
| `symmetric` | symmetry score | ≥ 0.8 |
| `framed_artwork` | border / frame detected | rule |

### A.9 `skill` demand (10) - derived 0..1 per puzzle from standardised traits (weights v1, refit by §13)

| Skill | Demand = weighted sum of |
|---|---|
| `shape_sorting` | featureless_pieces 0.4, large_uniform_areas 0.3, large_sky 0.15, large_water 0.15 |
| `shade_discrimination` | smooth_gradients 0.5, low_contrast 0.3, low_colour 0.2 |
| `texture_reading` | foliage_texture 0.6, lookalike_pieces (non-flat) 0.4 |
| `pattern_memory` | repeating_pattern 0.6, lookalike_pieces 0.2, collage_grid 0.2 |
| `detail_scanning` | busy_detail 0.5, many_objects 0.3, text_heavy 0.2 |
| `region_building` | many_colour_regions 0.5, colourful 0.3, distinct_pieces 0.2 |
| `line_following` | strong_lines 0.6, buildings share 0.2, map style 0.2 |
| `box_reference` | busy_detail 0.4, distinct_pieces 0.3, many_objects 0.3 |
| `low_light` | dark 0.7, low_contrast 0.3 |
| `edge_start` | uniform_border 1.0 |

### A.10 `image` (10) - Src artwork + T + V + A

`flat_artwork`, `box_front`, `box_angled`, `box_3d`, `assembled_photo`, `other`, `low_resolution` (crop < 600 px
on the long side), `blurry`, `sticker_watermark`, `back_of_box`.

Total: 23 + ~115 + 16 + 18 + 34 + 10 + 22 + 26 + 24 + 10 + 10 ≈ **310 keys**.

## Appendix B - lab files and downloads

- `scripts/download.py` - polite download (≈ 3 req/s) of the 148 extra images; lists `dup_pairs.tsv`, `rated_extra.tsv`.
- `scripts/apple_vision.py` - OCR, barcodes, labels, faces, humans, animals, feature print (pyobjc).
- `scripts/embed.py` - SigLIP 2 / DINOv2 embeddings; `scripts/zeroshot.py`, `scripts/spot_zeroshot.py` - prompt scoring.
- `scripts/traits.py` - simple numeric traits; `scripts/dups.py` - same-EAN pair analysis.
- `scripts/vlm_describe.py`, `scripts/vlm_batch.py`, `scripts/vlm_prompt.py` - Qwen3-VL structured describe, timing.
- `scripts/pilot_difficulty.py`, `scripts/pilot_check.py` - the §13.6 pilot.
- Outputs in `out/` (`apple_vision_test.jsonl`, `siglip.npz`, `dinov2.npz`, `traits.jsonl`, `zeroshot.json`,
  `vlm_*.jsonl`, `vlm_batch.log`).
- **Downloaded:** `facebook/dinov2-base` (~350 MB) and 148 box images (117 MB). Reused from the vision PoC's cache:
  SigLIP 2 so400m, Qwen3-VL-8B/4B 4-bit. Total new model downloads ≈ 0.35 GB.
- **Bake-off lab** (local, git-excluded): `.claude/worktrees/insights-describe-bakeoff/` - `scripts/run.py`, `scripts/schema.py`,
  `scripts/stages.py`, outputs in `out/` (`stages.jsonl`), `report.html` (each crop next to every model's output).
  Downloaded: Qwen3.5-9B-MLX-4bit 5.98 GB, gemma-4-12B-it-MLX-4bit 6.77 GB (deletable), EoMT-L COCO panoptic 1.27 GB.
