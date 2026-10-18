"""Taxonomy v1.0.0 keys (image-taxonomy.md Appendix A) and the zero-shot prompt bank built from them.

Bumping rules (§10/§11): adding keys or changing a prompt = a new bank / minor taxonomy version; `zeroshot` and `tags`
carry `@tax<TAXONOMY_VERSION>` in their analyzerVersion, so a bump makes them stale and `derive` recomputes them from
the stored embeddings in minutes - no image is touched.
"""

from __future__ import annotations

TAXONOMY_VERSION = "1.0.0"
PROMPT_BANK_VERSION = "bank-1.0"

THEMES: dict[str, list[str]] = {
    "animals": ["wildlife", "safari", "farm_animals", "horses", "forest_animals", "polar_animals", "insects_butterflies",
                "reptiles_amphibians", "dinosaurs", "bears", "big_cats", "foxes_wolves"],
    "pets": ["cats", "dogs", "small_pets"],
    "birds": ["owls", "parrots_tropical", "songbirds", "birds_of_prey", "waterbirds", "flamingos"],
    "sea_underwater": ["underwater_life", "coral_reef", "whales_dolphins", "sea_turtles", "beach_coast", "harbour_boats", "lighthouses"],
    "landscape_nature": ["mountains", "lakes_rivers", "waterfalls", "forest_woods", "fields_meadows", "deserts",
                         "sunsets_sunrises", "northern_lights", "autumn_colours", "snowy_landscape"],
    "flowers_garden": ["flowers_bouquet", "flower_fields", "gardens", "trees_plants", "mushrooms", "botanical", "cacti_succulents"],
    "countryside_village": ["cottages", "villages", "farms_barns", "americana_nostalgia", "country_shops"],
    "city_architecture": ["skylines", "streets_cafes", "castles_palaces", "churches_cathedrals", "bridges", "landmarks",
                          "lost_places", "interiors_rooms", "windows_doors"],
    "travel_places": ["postcards_travel", "famous_cities", "islands", "world_wonders"],
    "fine_art": ["old_masters", "impressionism", "japanese_art", "art_nouveau", "modern_art", "folk_art", "religious_art"],
    "illustration_design": ["mandala", "stained_glass", "art_deco", "pattern_design", "contemporary_illustration", "typography_posters"],
    "fantasy_myth": ["dragons", "unicorns", "fairies", "mermaids", "fairy_tales", "magic_wizards", "gothic_dark", "steampunk", "mythology"],
    "space_scifi": ["planets_space", "astronauts", "sci_fi"],
    "cartoon_humour": ["comic_crowd", "wasgij_style", "funny_animals", "kawaii"],
    "licensed_entertainment": ["animation_film", "film_tv", "video_games", "comics_superheroes", "anime_manga", "music_bands", "toys_brands"],
    "seasons_holidays": ["christmas", "halloween", "easter", "valentines", "thanksgiving_harvest", "new_year", "lunar_new_year"],
    "food_drink": ["sweets_desserts", "fruit_vegetables", "kitchen_cooking", "coffee_tea_drinks", "markets_shops"],
    "transport": ["classic_cars", "cars_modern", "trains_railways", "aircraft", "ships_sailing", "trucks_tractors",
                  "bicycles_motorbikes", "hot_air_balloons"],
    "hobbies_sport_music": ["books_libraries", "crafts_sewing", "sports", "music_instruments", "games_toys", "gardening_hobby"],
    "people_everyday": ["family_life", "historical_scenes", "portraits", "culture_traditions", "romance", "professions"],
    "maps_education": ["world_maps", "country_maps", "alphabet_numbers", "science_charts", "anatomy", "history_timelines"],
    "collage_collection": ["collage_grid", "photomosaic", "stamps_labels", "object_flatlay"],
    "abstract_challenge": ["gradient", "monochrome", "abstract_art", "geometric", "optical_illusion"],
}
THEME_LABELS = {
    "animals": "wild or farm animals", "pets": "pets, cats and dogs", "birds": "birds", "sea_underwater": "the sea and underwater life",
    "landscape_nature": "a natural landscape", "flowers_garden": "flowers and gardens", "countryside_village": "a countryside village",
    "city_architecture": "a city and its architecture", "travel_places": "a famous travel destination", "fine_art": "a famous fine art painting",
    "illustration_design": "a decorative illustration or design", "fantasy_myth": "a fantasy scene with magic and mythical creatures",
    "space_scifi": "outer space or science fiction", "cartoon_humour": "a funny cartoon scene", "licensed_entertainment": "characters from a famous film or cartoon franchise",
    "seasons_holidays": "a seasonal holiday scene", "food_drink": "food and drinks", "transport": "vehicles: cars, trains, ships or planes",
    "hobbies_sport_music": "hobbies, sport and music", "people_everyday": "people in everyday life", "maps_education": "a map or an educational chart",
    "collage_collection": "a collage of many separate small pictures", "abstract_challenge": "an abstract pattern or colour gradient with no real subject",
}
STYLE_LABELS = {
    "photo": "a photograph", "photo_aerial": "an aerial photograph from above", "photo_macro": "a macro close-up photograph",
    "painting_oil_acrylic": "an oil or acrylic painting", "watercolour": "a watercolour painting", "pencil_ink_drawing": "a pencil or ink drawing",
    "illustration_digital": "a clean digital illustration", "cartoon_comic": "a cartoon or comic drawing", "vector_flat": "a flat vector illustration",
    "render_3d": "a 3D computer render", "vintage_retro_poster": "a vintage retro poster", "collage_mixed": "a mixed media collage",
    "pattern_textile": "a textile pattern", "map_cartographic": "an illustrated map", "photomosaic": "a photomosaic made of many small photos",
    "pixel_art": "pixel art",
}
SCENES = ["landscape", "seascape", "cityscape", "street_level", "interior", "still_life", "underwater", "sky_space", "garden",
          "portrait_closeup", "animal_closeup", "crowd_scene", "aerial_view", "allover_pattern", "abstract", "map",
          "collage_grid", "product_flatlay"]
SEASONS = ["spring", "summer", "autumn", "winter"]
HOLIDAYS = ["christmas", "halloween", "easter", "valentines", "thanksgiving", "new_year", "other"]
TIMES = ["day", "golden_hour", "dusk_blue_hour", "night"]
WEATHER = ["sunny", "partly_cloudy", "overcast", "rain", "snow", "fog_mist", "storm"]
MOODS = ["cozy", "serene", "playful", "dramatic", "spooky", "romantic", "nostalgic", "vibrant", "majestic", "melancholic", "whimsical", "festive"]
SPECIAL = ["wasgij", "seek_and_find", "mystery_escape", "murder_mystery", "double_sided", "glow_in_dark", "glitter_foil", "neon",
           "lenticular", "panorama", "round_outline", "shaped_outline", "whimsy_pieces", "multi_pack", "impossible_challenge",
           "no_image_solid", "mini", "wooden", "three_d", "family_mixed_pieces", "frame_tray", "poster_included"]
LICENCES = ["disney", "pixar", "disney_princess", "marvel", "dc", "star_wars", "harry_potter", "lord_of_the_rings", "pokemon",
            "nintendo", "minecraft", "peanuts", "looney_tunes", "moomins", "asterix", "paw_patrol", "frozen", "barbie", "lego",
            "one_piece", "naruto", "studio_ghibli", "stranger_things", "national_geographic", "museum_licence"]
LICENCE_WORDS: dict[str, list[str]] = {
    "disney": ["disney", "mickey", "minnie", "donald duck", "goofy", "winnie the pooh", "lion king", "stitch"],
    "pixar": ["pixar", "toy story", "lightning mcqueen", "finding nemo", "monsters inc", "ratatouille", "buzz lightyear"],
    "disney_princess": ["princess", "cinderella", "snow white", "ariel", "rapunzel", "belle", "aurora", "moana", "jasmine"],
    "marvel": ["marvel", "spider-man", "spiderman", "avengers", "iron man", "hulk", "thor", "captain america"],
    "dc": ["batman", "superman", "wonder woman", "justice league", "dc comics", "joker"],
    "star_wars": ["star wars", "darth vader", "yoda", "mandalorian", "skywalker", "stormtrooper"],
    "harry_potter": ["harry potter", "hogwarts", "hermione", "dumbledore", "gryffindor"],
    "lord_of_the_rings": ["lord of the rings", "hobbit", "middle-earth", "gandalf", "frodo"],
    "pokemon": ["pokemon", "pokémon", "pikachu"], "nintendo": ["nintendo", "mario", "zelda", "luigi"],
    "minecraft": ["minecraft"], "peanuts": ["peanuts", "snoopy", "charlie brown", "woodstock"],
    "looney_tunes": ["looney tunes", "bugs bunny", "tweety", "daffy duck"], "moomins": ["moomin", "moomins"],
    "asterix": ["asterix", "obelix"], "paw_patrol": ["paw patrol"], "frozen": ["frozen", "elsa", "olaf", "anna and elsa"],
    "barbie": ["barbie"], "lego": ["lego"], "one_piece": ["one piece", "luffy"], "naruto": ["naruto"],
    "studio_ghibli": ["ghibli", "totoro", "spirited away", "howl's moving castle"], "stranger_things": ["stranger things"],
    "national_geographic": ["national geographic"], "museum_licence": ["museum"],
}
IMAGE_KINDS = {"flat": "image.flat_artwork", "box_front": "image.box_front", "box_angled": "image.box_angled",
               "assembled": "image.assembled_photo", "other": "image.other"}


def words(key: str) -> str:
    return key.replace("_", " ")


def prompt_bank() -> dict[str, str]:
    """key -> English prompt (SigLIP text side gets "this is <prompt>.")."""
    bank: dict[str, str] = {}
    for top, subs in THEMES.items():
        bank[f"theme.{top}"] = f"a jigsaw puzzle picture of {THEME_LABELS[top]}"
        for sub in subs:
            bank[f"theme.{top}.{sub}"] = f"a jigsaw puzzle picture of {words(sub)}"
    for k, label in STYLE_LABELS.items():
        bank[f"style.{k}"] = label
    for k in SCENES:
        bank[f"scene.{k}"] = f"a picture of a {words(k)} scene"
    for k in SEASONS:
        bank[f"season.{k}"] = f"a {k} scene"
    for k in HOLIDAYS:
        if k != "other":
            bank[f"holiday.{k}"] = f"a {words(k)} holiday scene"
    for k in TIMES:
        bank[f"time.{k}"] = "a daytime scene" if k == "day" else f"a scene at {words(k)}"
    for k in WEATHER:
        bank[f"weather.{k}"] = f"a scene with {words(k)} weather"
    for k in MOODS:
        bank[f"mood.{k}"] = f"a {k} picture"
    for k in LICENCES:
        bank[f"licence.{k}"] = f"a picture with {words(k)} characters"
    bank["licence.museum_licence"] = "a famous artwork from a museum collection"
    return bank


def facet_keys(prefix: str) -> list[str]:
    return [k for k in prompt_bank() if k.startswith(prefix + ".") and k.count(".") == 1]
