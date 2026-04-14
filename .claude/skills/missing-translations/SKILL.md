---
name: missing-translations
description: Find missing translation keys across locales and fill them in. Use when the user asks to check/fill missing translations, sync translation files, or mentions `translations-missing.json`.
allowed-tools: Bash, Read, Edit, Write, Glob, Agent
---

# Fill Missing Translations

Locales: **cs, en, de, es, fr, ja**. Translation files live in `translations/` as `{domain}.{locale}.yml` (e.g. `messages.cs.yml`, `emails.de.yml`, `validators.fr.yml`). Keys are nested YAML; the check tool reports them flattened with dot notation.

## Workflow

### 1. Generate the report

```bash
docker compose exec web php tools/check-translations.php
```

Writes `translations-missing.json` at repo root. If the summary shows 0 missing keys, stop and tell the user — there's nothing to do.

### 2. Understand the JSON structure

```json
{
  "messages.marketplace.listing.new_title": {
    "filled": [{"en": "New listing"}, {"cs": "Nová nabídka"}],
    "missing": ["de", "es", "fr", "ja"]
  }
}
```

- **Top-level key** = `{domain}.{nested.key.path}`. First segment is the **domain** (= filename prefix). Remainder is the nested YAML path.
- `filled` = locales that already have this key, with their values (use as translation source).
- `missing` = locales where the key must be added.

Map back to file + path:
- `messages.marketplace.listing.new_title` → file `translations/messages.{locale}.yml`, path `marketplace → listing → new_title`.

### 3. Translate and insert

Preferred: parallelize by **locale** — one agent per missing locale. Each agent receives the full JSON plus its target locale, translates every key missing for that locale, and edits only that locale's YAML files.

Guidance for each agent:
- Source of truth: English (`en`) if filled, otherwise Czech (`cs`), otherwise any filled locale.
- Preserve existing YAML structure, indentation (2 spaces), and ordering — insert the new key in a sensible alphabetical/grouped spot near sibling keys, not appended blindly at the bottom.
- Match tone/formality of surrounding translations in that locale.
- ICU/placeholder tokens (`{count}`, `%name%`, `|`) must be copied verbatim.
- Do NOT invent keys that aren't in the JSON. Do NOT touch `filled` locales.
- Language rule for the project: features are English-only unless translation is explicitly requested, but this skill IS the explicit request — translate every missing locale.

### 4. Verify

Run in this order, fix any failures before continuing:

```bash
docker compose exec web php bin/console lint:yaml translations
docker compose exec web php tools/check-translations.php
```

The lint must report "All YAML files contain valid syntax." The re-check must report "Found 0 keys with missing translations."

### 5. Report back

Short summary: how many keys filled, per-locale count, any keys skipped and why.
