# Delivery state — navigation redesign

**Single source of truth for progress.** Only the orchestrator edits this file. Every session starts by reading it and continues at the first item that is not `verified`. Status flow: `todo → in-progress → implemented → verified` (or `implemented (verify-after: <IDs>)`, `blocked: <reason>` / `blocked: owner`). `implemented` needs evidence; `verified` needs the item's **Verify** (requirements.md) re-run by the orchestrator in the delivery environment with the output summarised here. An item whose Verify needs a later item stays `implemented (verify-after: <IDs>)` with the evidence of what already ran, and becomes `verified` in the phase of the last named ID (requirements.md, Conventions "Verify-after"). A subagent's claim alone never makes an item `verified`.

Evidence = commit hash(es) · test names · command output summary · screenshot paths under `var/navigation-delivery/` (in the worktree) · notes.

## Environment (filled on first start)

| Key | Value |
|---|---|
| Base | `origin/main` at start: hash · date (synced at every phase gate, see run log) |
| Last sync with `origin/main` | |
| Delivery branch / worktree | `feature/navigation-redesign` / `.claude/worktrees/navigation` |
| Delivery DB clone | `speedpuzzling_nav_test` |
| Isolated web URL | `http://127.0.0.1:18080` (container `nav-web`) |
| P0-03 commit to deploy separately | |

## Status

| ID | Item | Status | Evidence | Notes |
|---|---|---|---|---|
| P0-01 | Implementation plan | todo | | |
| P0-02 | Baseline and budget pins | todo | | |
| P0-03 | Navigation click instrumentation (current navigation first) | todo | | |
| F-01 | Navigation registry + guard test | todo | | |
| F-02 | Place resolver | todo | | |
| F-03 | Layout variables, fixed elements, overlays | todo | | |
| F-04 | Meta fixes | todo | | |
| F-05 | Translation hygiene | todo | | |
| COM-01a | Community route skeleton | todo | | |
| LAUNCH-02a | "Where did it go?" skeleton | todo | | |
| NAV-01 | Phone bottom bar | todo | | |
| NAV-02 | Label fallback | todo | | |
| NAV-03 | Phone top bar | todo | | |
| NAV-04 | Desktop top bar | todo | | |
| NAV-05 | Desktop account menu | todo | | |
| NAV-06 | Remove old chrome | todo | | |
| NAV-07 | Turbo behaviour without layout shift | todo | | |
| NAV-08 | Bar-free contexts and keyboard | todo | | |
| NAV-09 | Guests | todo | | |
| NAV-10 | Home = Hub for signed-in players | todo | | |
| NAV-11 | Accessibility | todo | | |
| NAV-12 | No orphaned pages | todo | | |
| NAV-13 | Desktop docked menu, rail and overlay | todo | | |
| NAV-14 | Native-ready navigation | todo | | verify-after: NAV-01, NAV-03, NAV-04, NAV-13, MENU-02b, HELP-01b, CARD-01a, GEST-01, GEST-02, GEST-06, GEST-10, GEST-12, GEST-15 |
| MENU-01 | Menu partial and rows | todo | | |
| MENU-02a | Menu fallback route | todo | | |
| MENU-02b | Phone panel | todo | | |
| MENU-03 | One search for pages and settings | todo | | |
| MENU-04 | Menu completeness guard | todo | | |
| INB-01 | Inbox page | todo | | |
| INB-02 | Badge | todo | | |
| INB-03 | Favorites' times out of the bell | todo | | |
| INB-04a | "New since your last visit" storage | todo | | |
| INB-04b | Favorites default and highlight | todo | | |
| INB-05 | Inbox pointer | todo | | |
| HELP-01a | Help route | todo | | |
| HELP-01b | Help sheet | todo | | |
| HELP-02 | Order and context | todo | | |
| HELP-03 | Guests | todo | | |
| HELP-04 | End-of-page line | todo | | |
| HELP-05 | Phone grey-screen bug | todo | | |
| ADD-01 | Modes and last mode | todo | | |
| ADD-02 | Tools under their intent | todo | | |
| ADD-03 | Running stopwatch pill | todo | | |
| ADD-04 | Stopwatch page | todo | | |
| ADD-05 | Guest Add explainer | todo | | |
| PUZ-01 | Puzzles sub-navigation | todo | | |
| COM-01 | Community landing previews | todo | | |
| COM-02 | Section chips | todo | | |
| PROF-00 | Results route skeleton | todo | | |
| PROF-01 | Own profile, content first | todo | | |
| PROF-02 | Player header tabs | todo | | |
| PROF-03 | Overview content | todo | | |
| PROF-04 | Statistics receives charts/cards | todo | | |
| PROF-05a | Results route and parity | todo | | |
| PROF-05b | Paging | todo | | |
| PROF-05c | History and Back restore | todo | | |
| PROF-05d | Search | todo | | |
| PROF-05e | Old links | todo | | |
| PROF-06 | Rank on rows | todo | | |
| PROF-07 | SEO | todo | | |
| PROF-08 | Canaries and coverage | todo | | |
| CARD-01a | Trigger and behaviour | todo | | |
| CARD-01b | Surfaces | todo | | |
| CARD-01c | Shared actions service | todo | | |
| CARD-02 | Context blocks | todo | | |
| CARD-03 | Content and actions | todo | | |
| CARD-04 | Own card | todo | | |
| CARD-05 | Privacy | todo | | |
| CARD-06 | Budget | todo | | |
| CARD-07 | Measurement | todo | | |
| TEASE-01 | Teaser scope for this release | todo | | |
| GEST-01 | Back closes the top layer | todo | | |
| GEST-02 | Swipe down closes bottom sheets | todo | | |
| GEST-03 | Tap the active tab | todo | | |
| GEST-04 | Pressed states within 100 ms | todo | | |
| GEST-05 | No accidental zoom | todo | | |
| GEST-06 | Installed-app pull-to-refresh | todo | | |
| GEST-07 | Document scroller and motion rules | todo | | |
| GEST-08 | Back measurement | todo | | |
| GEST-09 | Platform attribute | todo | | |
| GEST-10 | Haptics + real iOS switches | todo | | |
| GEST-11 | Pressed feedback per platform | todo | | |
| GEST-12 | Place switches per platform | todo | | |
| GEST-13 | In-page confirmations | todo | | |
| GEST-14 | Instant Back step 1: keep bfcache restores | todo | | |
| GEST-15 | Instant Back step 2: Turbo restores browse pages | todo | | iOS only if GEST-13 is verified (`INSTANT_BACK_IOS`) |
| GEST-16 | Swipe between Home's feeds | todo | | |
| GEST-17 | Thumbnail → puzzle page morph | todo | | |
| GEST-18 | Long-press a truncated tab shows its label | todo | | |
| GEST-19 | Installed-app pull indicator per platform | todo | | |
| SRCH-01 | Pages & settings in the global search | todo | | |
| FOOT-01 | Footer | todo | | |
| LAUNCH-00 | Launch constant | todo | | |
| LAUNCH-01 | What moved | todo | | |
| LAUNCH-02 | "Where did it go?" content | todo | | |
| LAUNCH-03 | Pointers | todo | | |
| LAUNCH-04 | Announcement drafts | todo | | |
| LAUNCH-05 | Watch list | todo | | |
| DOC-01 | Project docs | todo | | |
| DOC-02 | Translations complete | todo | | |
| QA-01 | Screenshot matrix | todo | | |
| QA-02 | Behaviour checks | todo | | |
| QA-03 | Quality gates | todo | | |
| QA-04 | Independent post-implementation review | todo | | |
| QA-05 | Full re-verification | todo | | |
| QA-06 | Device check (owner) | todo | | |

## Phase gates

Order per requirements.md "Phase order" (the plan may refine it). A gate passes with `implemented (verify-after: …)` rows listed in its evidence. Gate = phpstan · cs-fix (no diff) · paratest (Project Test Suite) · schema validation on a scratch DB from committed migrations · cache:warmup · phase review file `delivery/reviews/<phase>.md` with all findings resolved.

| Phase | Items | Status | Evidence |
|---|---|---|---|
| P0 | P0-01…03 | todo | |
| F | F-01…05 (F-03 verify-after NAV-01, NAV-13; F-04 verify-after PROF-05a, MENU-03), LAUNCH-00, NAV-14 foundation (verify-after, see its row), GEST-09 | todo | |
| Skeletons | COM-01a, ADD-05, HELP-01a, LAUNCH-02a, MENU-02a, PROF-00 | todo | |
| Layers | GEST-01, GEST-02 (verified on the achievement sheet and a test page), GEST-13 | todo | |
| ADD-03 + INB | ADD-03 (verify-after NAV-03, NAV-04), INB-02, INB-01, INB-03, INB-04a, INB-04b, INB-05 | todo | |
| MENU + Help sheet | MENU-01, MENU-02b, MENU-03, HELP-01b | todo | |
| NAV (alongside old chrome) | NAV-01…05, 07…11, 13 (NAV-11 verify-after NAV-06) + F-03 overlap checks | todo | |
| Removal | NAV-06, NAV-12, MENU-04 | todo | |
| HELP | HELP-02…05 | todo | |
| ADD | ADD-01, ADD-02, ADD-04 | todo | |
| PUZ + COM | PUZ-01, COM-01, COM-02 | todo | |
| PROF | PROF-03, PROF-01, PROF-02, PROF-04, PROF-05a…e, PROF-06…08 | todo | |
| CARD + TEASE | CARD-*, TEASE-01 | todo | |
| GEST | GEST-03…07 | todo | |
| Platform feel | GEST-10, GEST-11, GEST-12, GEST-16, GEST-17, GEST-18, GEST-19 | todo | |
| Instant Back | GEST-14, GEST-15, GEST-08 (iOS included only when GEST-13 is verified; decision logged) | todo | |
| SRCH + FOOT | SRCH-01, FOOT-01 | todo | |
| LAUNCH + DOC | LAUNCH-01…05, DOC-01, DOC-02 | todo | |
| QA | QA-01…06 | todo | |

## Decisions log
Decisions taken during delivery where the spec was silent (date · ID · decision · reason). Never reverse a README decision here; if one is impossible, mark the item `blocked` with options.

| Date | ID | Decision | Reason |
|---|---|---|---|

## Run log
One line per session and per phase: date · what was done · next step.

| Date | Note |
|---|---|

## Final report
(written at the end: what shipped, evidence summary, blocked items with options, owner steps — README §11)
