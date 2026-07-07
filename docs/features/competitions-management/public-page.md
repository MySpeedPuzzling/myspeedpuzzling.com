# Competition Public Page & Content Editor

> **Status: implemented.** This document describes the design; the source of truth is always the source code. Implementation notes: the editor uses plain form controllers + SortableJS list (not a Live Component — nested repeatable rows are simpler and more robust as forms), Quill 2 as the WYSIWYG (lazy-loaded), and sections are stored in `competition_page_section` with layout ordering in `competition.page_layout` / `competition_series.page_layout`.

Many organizers have no website — they announce contests on Facebook and keep results in spreadsheets. The goal: **a manager should need nothing but MSP** to present a competition — standalone, recurring series, online or offline. The public event page becomes composable: a set of automatic sections (registration, schedule, puzzles, participants, results) plus manager-authored content sections, ordered and edited from the management system.

The one permanent exception: **MSP never handles payments** (see [registration.md](registration.md)) — fee collection stays with the organizer; the page only *describes* how to pay.

## Page Composition Model

A public event page is a sequence of **sections**. Two kinds:

1. **System sections** — rendered from platform data, automatically present when relevant, not editable as content (only shown/hidden and ordered):
   - Header/hero (name, logo, dates, location + country flag, online/recurring badges) — always first, not hideable
   - Registration card (when `registrationManaged`) or external registration button
   - Schedule (auto-generated from rounds: name, category badge, start time, time limit)
   - Puzzles (existing grid, respecting hide-until-round-starts)
   - Results (published round standings — see [results.md](results.md))
   - Participants (existing component)
2. **Content sections** — manager-authored blocks, freely added, ordered, and removed:

| Block type | Content |
|-----------|---------|
| **Rich text** | Heading + WYSIWYG body — about, rules, prizes, what to bring, accessibility notes… the general-purpose block |
| **FAQ** | List of question/answer pairs, rendered as an accordion |
| **Image / gallery** | 1–n uploaded images (S3, same `UploaderHelper` pipeline as logos), optional captions |
| **Venue** | Address text + map link (Google Maps URL); shown with directions icon. Offline events only |
| **Sponsors** | Grid of logo + name + optional link |
| **Links** | Labeled external links (Facebook group, livestream, hotel booking…) — with automatic `utm_source` like existing links |
| **Contact** | Organizer contact info (email, phone, socials) — explicitly opt-in, never auto-published from the account |

### New entity: CompetitionPageSection

```
CompetitionPageSection
  id: UUID (PK)
  competition: ?Competition (FK)        ← standalone/edition page
  series: ?CompetitionSeries (FK)       ← series page (exactly one of the two set)
  type: PageSectionType enum            ← rich_text | faq | gallery | venue | sponsors | links | contact
  position: int
  title: ?string
  content: jsonb                        ← type-specific payload (sanitized on write)
  createdAt / updatedAt
```

System-section visibility and ordering live in a single `pageLayout` jsonb column on `Competition`/`CompetitionSeries` (ordered list of `{section: "schedule"|"puzzles"|…|"custom:<uuid>", visible: bool}`). One column, no extra join table; missing entries fall back to the default order so existing pages render unchanged.

### Recurring events (series)

Content is authored **once at series level** and inherited by every edition — that's where recurring organizers save effort (rules, venue, sponsors rarely change per edition). Each edition can additionally have its own sections (edition-specific announcements), rendered after the inherited ones. Edition pages show: edition header → edition sections → inherited series sections → system sections.

## The Editor

`/en/manage-event-page/{competitionId}` and `/en/manage-series-page/{seriesId}` (`CompetitionEditVoter` / series voter — managers and admins). A Live Component (`CompetitionPageEditor`):

```
┌──────────────────────────────────────────────────────┐
│ Page editor — "Minnesota Puzzle Palooza"   [View page]│
│                                                       │
│ ≡ Header                                   (always)   │
│ ≡ Registration                             [👁 shown]  │
│ ≡ 📝 About the contest                     ✏️ 🗑 [👁]  │
│ ≡ Schedule                                 [👁 shown]  │
│ ≡ 📍 Venue — Roseville Library             ✏️ 🗑 [👁]  │
│ ≡ ❓ FAQ (6 questions)                      ✏️ 🗑 [👁]  │
│ ≡ Puzzles                                  [👁 shown]  │
│ ≡ Results                                  [👁 shown]  │
│ ≡ Participants                             [👁 hidden] │
│ ≡ 🤝 Sponsors (3)                           ✏️ 🗑 [👁]  │
│                                                       │
│ [+ Add section ▾]                                     │
└──────────────────────────────────────────────────────┘
```

- **Drag-to-reorder** (Stimulus + SortableJS, `data-live-ignore` around the drag container; order persisted via a `LiveAction`)
- **Edit in place** — clicking ✏️ expands the block's form inside the list (pattern from `ManageCompetitionParticipants` inline editing)
- **Show/hide** any section without deleting it (draft content, seasonal sections)
- Changes save immediately; the public page reflects them on next load — no separate publish step (the competition's admin approval already gates public visibility overall)

### Rich text editing & safety

- WYSIWYG editor rendered in a Stimulus controller. Stored as HTML in the block payload
- **Server-side sanitization is the security boundary** — `symfony/html-sanitizer` with a strict allow-list (headings, paragraphs, lists, bold/italic, links with `rel="noopener nofollow"`, images only from our S3 host). Applied in the message handler on every write; the editor's client-side rules are convenience only
- Uploaded images go through the existing `UploaderHelper` S3 flow with size/type validation

## Messages & Handlers

- `AddPageSection(sectionId, competitionId|seriesId, type, title, content)`
- `EditPageSection(sectionId, title, content)`
- `DeletePageSection(sectionId)`
- `ReorderPageSections(competitionId|seriesId, orderedLayout)` — persists the full `pageLayout` list in one write

Queries: `GetCompetitionPageSections` (merged + ordered: edition-own, series-inherited, system placeholders) used by both the public page and the editor.

## Access Control

| Action | Who |
|--------|-----|
| View public page | Everyone (competition must be approved) |
| Edit page content & layout | Competition/series maintainers and admins (existing voters) |

Content sections do NOT go through admin re-approval (the competition itself was approved; maintainers are trusted, same as they are today for description/links). Abuse handling stays reactive: admins can edit/delete any section.

## Edge Cases

- **No content sections** → page renders exactly as today (system sections in default order). Zero migration impact
- **Online events** → Venue block type is unavailable; everything else identical
- **Deleting a section** referenced in `pageLayout` → layout entries pointing to missing sections are ignored on render and pruned on next save
- **Series → edition inheritance conflicts** — editions cannot edit inherited sections (edit them on the series); the editor shows inherited blocks greyed with a "manage on series page" link
- **Locale:** content sections are single-language, authored in whatever language the organizer writes (organizer content is not translated; platform chrome around it stays localized)

## Testing Strategy

- Sanitizer tests: script/style/iframe/event-handler stripping, allowed tags survive, image host allow-list
- Layout merge tests: default order fallback, hidden sections, orphaned layout entries, series inheritance ordering
- Handler tests: add/edit/delete/reorder, competition XOR series invariant
- Rendering tests: page with no sections identical to current output

## Implementation Sequence

1. `CompetitionPageSection` entity + `pageLayout` column + migration
2. `GetCompetitionPageSections` + public page rendering of ordered sections (system sections refactored into includable partials)
3. Editor Live Component: add/edit/delete for rich text + FAQ + links blocks
4. Reordering + show/hide
5. Remaining block types: gallery (S3 upload), venue, sponsors, contact
6. Series-level authoring + edition inheritance
