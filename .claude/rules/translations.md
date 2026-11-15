---
paths:
  - "translations/**"
  - ".claude/skills/missing-translations/**"
---

# Translations and wording

Loaded when you edit the message catalogues. 6 locales: en (source), cs, de, es, fr, ja. Every key a job introduces
exists in all six before the PR / feature request is done (skill `missing-translations` checks at scale).

Words and texts the features fixed on purpose (the reason is in the feature's entry under `.claude/rules/features/`):

- **Never "suspicious"** in anything a person reads - "Needs verification" / "Awaiting verification" (results.md, time verification).
- **Never "rival"** for the compare feature or "Someone at your speed" (players.md, Compare).
- The puzzle code field is called **Brand code** in every locale (catalogue.md, puzzle names and codes).
- E-mail preheaders: key `<email>.preheader`, all 6 locales, under 90 characters (email.md, transactional e-mails).
- Every plural text under `compete` / `online_rounds` renders for every count in every locale - `OnlinePluralMessagesTest` (events.md, online competitions).
- Members-only features: `membership.benefits.items.*` change together with `templates/membership/_benefits.html.twig` (root CLAUDE.md).
- Texts read by the browser go through `browser_translation()` (raw message + the catalogue's locale) so `assets/translation_choice.js` picks plural forms like PHP (players.md, live activity feed).
