# Competition Public Page - Content Sections

> **Status: implemented** (PR #136 port, 2026-10-07). The source of truth is the code; this document says why it is
> built the way it is.

Many organisers have no website - they announce contests on Facebook and keep everything else in their heads. Content
sections let a maintainer put the rest on MySpeedPuzzling: rules, a FAQ, the venue, photos, sponsors, links, contact.

**Opt-in, nothing else changes.** An event, edition or series page without a visible section renders byte for byte what
it rendered before sections existed and runs the same statements (pinned by `PageSectionsOnPagesTest`). MySpeedPuzzling
never handles payments - a page may only *describe* how to pay.

## What a page shows

One slot, right after the description:

| Page | Sections |
|------|----------|
| Standalone event (`event_detail`) | its own |
| Edition (`edition_detail`) | its own, then its series' (inherited) |
| Series (`competition_series_detail`) | its own |

Each list in `position` order. Hidden sections are not shown. A **venue** shows only on an in-person page (an online
event never offers one; a series venue is left out of an online edition's page). Everything else on the page - header,
"Results by round", puzzles or rounds, "I'm going", marketplace card, participants - stays exactly where main has it.
There is no ordering or hiding of those system parts (the PR's `page_layout` was dropped before it shipped: it re-plumbed
the whole page for every competition, and nothing needed it yet).

**Zero cost without sections.** `GetCompetitionEvents::byId()` and `GetCompetitionSeries::byId()/bySlug()` carry
`hasPageSections` - an `EXISTS` in the statement the page runs anyway, using the same SQL rule as the list
(`GetCompetitionPageSections::sqlShownOnCompetitionPage()` / `sqlShownOnSeriesPage()`, an edition's flag includes its
series' sections). The controllers call `GetCompetitionPageSections` only when the flag is true: one statement more for a
page that has sections, none for the rest. The template slot sits on an existing line with whitespace control so not even
a newline (or a Live Component's line-based id) changes.

## Section types

| Type | Content (`content` jsonb) |
|------|---------|
| `rich_text` | `html` - Quill 2 editor, sanitised HTML |
| `faq` | `items[]` of `question` / `answer` (plain text) - rendered as `<details>` |
| `gallery` | `images[]` of `path` (an upload of this page) / `caption` |
| `venue` | `address`, `mapUrl`, `directions` - in-person pages only |
| `sponsors` | `sponsors[]` of `name`, `url`, `logoPath` (an upload of this page) |
| `links` | `links[]` of `label` / `url` - shown with `utm_source=myspeedpuzzling` like the page's other links |
| `contact` | `email`, `phone`, `note` - typed by the organiser, never taken from the account |

Entity `CompetitionPageSection`: exactly one of `competition` / `series` (constructor invariant, `PageSectionOwner`),
`type`, `position`, `title` (≤ 255), `content` (jsonb), `visible`, `createdAt`, `updatedAt`; both owners `ON DELETE
CASCADE`.

## Security

- **The sanitizer is the boundary.** Every write goes through `PageSectionContentSanitizer` in the handler (the form's
  checks only explain). Rich text uses the `competition_page` html-sanitizer (`config/packages/html_sanitizer.php`):
  h2-h4, p, br, strong/b, em/i, u, s, ul/ol/li, blockquote, `a[href|title]`, `img[src|alt]`. **No `id`, `class` or
  `style` anywhere** - an organiser's `id` could clobber an element of the page (Turbo's `modal-frame`). Links:
  http(s)/mailto only, forced `rel="noopener noreferrer nofollow ugc" target="_blank"` (main's external links open the
  same way). Pictures: only from our own image hosts (`NGINX_PROXY_BASE_URL`, `UPLOADS_BASE_URL`).
- **Lists.** The editor saves Quill's `getSemanticHTML()`: Quill 2's own `root.innerHTML` stores a bullet list as
  `<ol><li data-list="bullet">`, which the sanitizer turns into a numbered list. `getSemanticHTML()` also puts `&nbsp;`
  between all words, so the editor and the sanitizer both turn them into ordinary spaces (text must wrap on phones).
  Verified 2026-10-07 with Quill 2.0.3 in jsdom: bullet + numbered lists survive save → reload → save.
- Plain-text fields are stored as typed (control characters removed, length capped) and escaped by Twig.
- **Pictures** (gallery, sponsor logos) are uploaded by `UploadPageSectionImageController` under
  `competition-pages/<owner id>/` and a section keeps only paths under **its own** owner's prefix - so removing a picture
  can never delete another page's file. Upload: JPEG/PNG/GIF/WebP ≤ 5 MB (`Image` constraint, translated message shown
  under the picture), EXIF/GPS stripped (`ImageOptimizer`), large JPEGs shrunk in the browser first
  (`image_compression.js`; PNG/WebP/GIF left alone so logos keep transparency).
- **Authorisation**: every write is checked with `CompetitionEditVoter` / `CompetitionSeriesEditVoter` on the page that
  **owns** the section (`PageSectionOwner::of($section)`), never on an id the request names separately. An edition's
  editor shows its series' sections read-only (they are changed on the series' editor). Reordering refuses the whole
  request if any id belongs to another page (`PageSectionNotFound`, 404 - nobody learns other pages' ids).
- Every POST carries the page's CSRF token (`PageSectionOwner::csrfTokenId()`, session-backed - organisers are signed in).
  Forms: 303 on success, 422 with the reasons when refused (Turbo never gets a 200 to a form), explicit `action`.
- Editor pages: `noindex, nofollow`, `Cache-Control: private, no-store`.

## The editor

`/en/manage-event-page/{competitionId}` (button "Page content" on the event/edition edit page) and
`/en/manage-series-page/{seriesId}` (on the series management page).

- List of the page's own sections: drag (SortableJS, loaded only by the lazy `page_sections_list_controller.js`) **and**
  Move up / Move down buttons (keyboard and phone friendly). Each change posts the full order
  (`ReorderPageSectionsController`, form-encoded); saves go out one after another; a failure is shown in the page.
- Show / Hide (`ChangePageSectionVisibility`), Edit (`edit_page_section`), Delete (with confirmation).
- "Add section" - the types the page can have.
- An edition lists its series' sections below ("From the series") with a link to the series editor for series
  maintainers.
- Section forms (`page_section_form.html.twig`): plain forms (repeatable rows for FAQ/photos/sponsors/links,
  `repeatable_rows_controller.js`), Quill 2 for rich text (`wysiwyg_controller.js`, Quill imported dynamically only there),
  immediate picture upload (`section_image_upload_controller.js`). All four Stimulus controllers are
  `stimulusFetch: 'lazy'`.

## Messages

| Message | Handler does |
|---------|--------------|
| `AddPageSection(sectionId, competitionId\|seriesId, type, title, content)` | owner XOR, venue only in person (`PageSectionTypeNotAvailable`, 422), sanitise, last position |
| `EditPageSection(sectionId, title, content)` | sanitise; pictures no longer shown → `DeletePageSectionImages` |
| `DeletePageSection(sectionId)` | remove; its pictures → `DeletePageSectionImages` |
| `ChangePageSectionVisibility(sectionId, visible)` | show / hide |
| `ReorderPageSections(competitionId\|seriesId, sectionIds)` | own sections only, foreign id refuses everything; unnamed ones keep their order after the named |
| `DeletePageSectionImages(paths)` | async, dispatched after commit (`DispatchAfterCurrentBusStamp`); deletes nothing still referenced (`GetStoredFileReferences` knows section pictures) |

## Not done (docs/TODO.md)

- Uploads of a form that is never saved stay in storage; pictures of a deleted competition/series (cascade) too - a prune
  of unreferenced `competition-pages/` objects.
- Translations of `page_sections.*` into cs, de, es, fr, ja (English only for now).
- Contact e-mail is published in clear text (harvesting) - obfuscation or "message the organiser".
- Ordering/hiding the page's system parts, if organisers ask for it.
