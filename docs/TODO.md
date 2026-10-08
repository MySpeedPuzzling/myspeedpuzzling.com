# TODO

Open follow-ups, one place to come back to. Tick an item when it ships, delete a section when it is empty.
Feature-sized plans keep their own checklist in `docs/features/<feature>/` - this file is for the loose ends
that would otherwise be forgotten. Newest section on top.

## Events page (`docs/features/events-page/README.md`, shipped 2026-10-08)

- [ ] Post-launch measurement ~2026-11-05: `?view=calendar` loads (Tempo) and the GA events `events_scope` / `events_view` / `events_search` / `events_calendar_day` / `events_calendar_month` vs the README baseline (7 days before launch: 1,004 views from 404 IPs, filters from 115, calendar from 32)
- [ ] Phase 2 ideas: "N sellers bringing puzzles" tag from Marketplace at events (`docs/features/marketplace/11-events.md`), "Live results" tag on events happening now
- [ ] Notifications for followed series (new edition announced, registration opens); "N of your favourite puzzlers are going" (then add the page to the blocklist/private-profile canaries)
- [ ] Data clean-up (via the internal API / organisers): series without editions or duplicated, undated editions duplicating dated ones, online one-time events with no dates that are really series (`ConvertCompetitionToSeriesController`), annual championships entered as separate one-time events
- [ ] An index endpoint instead of the embedded index once the page passes ~1,000 occurrences
- [ ] `CountryRegion` could replace the region grouping copies in `MarketplaceListing` / `ManageCompetitionParticipants`
- [ ] Admin approve/reject routes have no CSRF check (admin-only POST, unchanged) - separate hardening

## Puzzle picker - feedback from a puzzler (`docs/features/puzzle-picker/README.md`, e-mail 2026-09-20)

A second puzzler asked for much the same, so it is not a one-off wish.

- [ ] The whole result list, not just 5 more: the page shows the total of matches, but "Show more" only reveals the
      over-fetched rows (README "Show 5 more"). Wanted most when the results are sorted (e.g. by the gap to the
      prediction). The README's way out: chained Turbo Frames `?seed=…&offset=6&limit=5`, the seeded order already
      makes paging stable (`GetPuzzlePickerSuggestions` takes `LIMIT :limit OFFSET :offset`).
- [ ] A short explanation of what each preset ("Surprise me", "Quick one", "Rating grind", …) and each filter does,
      next to it - for accessibility, not only for newcomers.
- [ ] Pick from the puzzles people bring to a meetup. Today it needs a temporary custom collection (members only,
      one owner). Idea: a shared collection where each attendee adds the puzzles they bring, which the picker then
      uses as its source like any collection. Check overlap with "Marketplace at events" (`11-events.md`, sellers
      mark listings they are *bringing*) before designing a second "bringing" concept.
- [ ] "Similar puzzles" (more like a puzzle I liked / want to improve on): needs image/category classification
      (small models, embeddings) - not before the more important backlog items. Same person offered help with
      embedding models (no training, existing models only).

## Outdated puzzle requests (`docs/features/puzzle-approvals.md`, "Outdated requests", PR #247)

- [ ] `GetPendingPuzzleProposals` (the puzzle page's "pending proposal" badge, `blocksNewProposal()`, the pending list)
      and `GetPuzzleMergeRequests::sqlNoSecretPuzzle()` still match a merge request by its reported ids only. A pending
      request naming a puzzle merged into this one since (resolved through `puzzle_redirect` everywhere else -
      `MergeRequestPuzzles`) is not shown on this puzzle's page and does not block a new suggestion there.
- [ ] The history line of an outdated merge request names a puzzle deleted by a merge as "Puzzle 018d…" -
      `GetPuzzleHistory::knownNames()` reads `puzzle_name` only, not a merge's `details.mergedPuzzleNames`.

## Inbox promises (simona@ backlog, 2026-10-07)

What a reply to a player promised to build. Each has an MSP Mailer follow-up: tell the player when it ships.

- [x] First tries (and unboxed) filter for pairs and teams, on the profile and the puzzle leaderboard, kept across
      tabs (2026-10-08, `docs/features/pairs-and-teams/README.md` "Filters"). With it Allison's other report: the
      "with…" pair/team filter no longer hides the Solo tab. Promised to Gav (MSP #90, follow-up F45) and Allison
      (MSP #107, F46) - tell them once it is deployed.
- [ ] Move several puzzles at once on a collection page (select, then "Move to collection"), not only one by one or
      through multiscan. Promised to Allison (MSP #107, follow-up F50).

## Participants spreadsheet (`docs/features/competitions-management/participants-spreadsheet.md` §13)

- [ ] Pilot: the Wisconsin organiser tries the sheet on their next event (D14) - e-mail drafted in MSP Mailer for Jan.
- [ ] Manual pass the automated checks could not do: Safari macOS + iOS (clipboard through the hidden textarea, Cmd+D,
      the sticky table), a real Android phone, Windows + Excel, VoiceOver and NVDA on the grid (selection, checkbox
      names, the hidden textarea briefly focused), a Japanese IME's first keystroke on a cell (Enter/F2 first is the
      documented path). Clipboard samples from real Excel (Mac/Windows), Numbers and Google Sheets: paste them into the
      spike's `capture.html` (kept in the delivery notes) and add them to `tests/participants-sheet-core/tsv_fixtures.mjs`.
- [ ] Export / print of the current tab (pairs with members, tables and results) - today the participants export and the
      results desk export per round.
- [ ] A change log with restore (D11): `participant_sheet_change_receipt` already keeps who sent what (90 days) - a
      "what changed today, by whom" panel when organisers ask.
- [ ] Highlight rows another organiser changed (they update live today, with an announcement only).
- [ ] People tab: "Make a pair/team ▾ → existing", "Split → Move to a new pair/team" (§5), hiding single round columns
      (15 rounds at WJPC); phone chips: "Replace with…" after a no-show.
- [ ] Registration actions are sent at once, not kept offline like the sheet's edits; and merging a self-joined row into
      an imported row of the same person.
- [ ] The import confirm, the check-in page and self join/leave do not publish the sheet's live topic - open sheets
      catch up within 30 s (version check); publish from them if organisers notice.

## Managed registration (`docs/features/competitions-management/registration.md`)

- [x] Registration status / paid / checked-in as optional columns of the participants spreadsheet's People tab
      (participants-spreadsheet.md D10) - the import reads nothing from the export's registration columns today.
- [ ] Notify maintainers about a new registration, a cancelled *paid* registration (refund talk) and a listed name
      picked on a managed event ("connected by …" in the participants sheet - review 2 A-F10, documented trade-off);
      optional daily digest.
- [ ] Payment deadline / automatic release of unpaid spots; a response deadline for a spot offered from the waitlist.
- [ ] A verified e-mail before registering (throwaway accounts can fill a capacity).
- [ ] Check-in tolerant of venue Wi-Fi (client-side search, optimistic taps) - it is a Live component today.
- [ ] Event JSON-LD `offers` pointing at the event page with availability (sold out / waitlist) while managed.
- [ ] Series edition cards: an internal "Register" button for a managed edition (the external link is hidden).
- [ ] API v1 (`CompetitionDetailResponseProvider`, `CompetitionListResponseProvider`) returns `registrationLink: null`
      for a managed event - clients cannot tell registration happens on MSP. Additive field, e.g. `registrationManaged`
      plus the event page URL (no BC break); left unchanged in the port on purpose.

## Event page content sections (`docs/features/competitions-management/public-page.md`)

- [ ] Prune unreferenced `competition-pages/<owner>/` objects: uploads of a section form that was never saved, and the
      pictures of a deleted competition/series (its sections cascade, the files stay). `GetStoredFileReferences` already
      knows section pictures; a daily cron like `myspeedpuzzling:prune-photo-stash`, with an age threshold - needs a cron
      row on lily. Until then the per-player upload limit (60/h) and the 40-picture cap per section bound the leftovers.
- [ ] A gallery photo also embedded by URL in a rich-text `<img>` is not seen by `GetStoredFileReferences`: removing it
      from the gallery deletes the file and the rich text image breaks (edge case, review 2).
- [ ] Contact section publishes the e-mail address in clear text - obfuscate it or offer "message the organiser" via MSP
      chat instead.
- [ ] If organisers ask: order/hide the page's own parts (puzzles, participants, ...) - deliberately not built
      (the PR's `page_layout` was dropped in the port).
- [ ] The section form's other repeatable rows (photo caption, sponsor name/URL, link label/URL) have an `aria-label`
      and a placeholder but no visible label - the FAQ rows got real labels after the browser verification; the same
      pattern there if organisers find the rows unclear once something is typed.

## Official round results (`docs/features/competitions-management/official-results.md`)

- [ ] Unfinished (pieces placed) and did-not-start results onto players' profiles - after phase 1b of
      `unfinished-results-plan.md` (today only finished results are offered as "Add to my profile").
- [ ] Rounds with several puzzles: one total result per entry today - a result per puzzle when organisers ask.
- [ ] Derive table numbers from the table layout tool (`table_spot`) instead of typing them.
- [ ] Results from timing devices without the round stopwatch (import of a device's export).
- [x] Cron row on lily.srv for `myspeedpuzzling:prune-round-result-change-receipts` - daily at 03:23 (lily.srv 3cf56d0, 2026-10-07).
- [ ] Live entry quick add: the event's people come with the page (`GetLiveResultsEventPeople`) - somebody added to
      the event by another device during the session is not offered until a reload (rare; typing the name in creates a
      second person, as before).
- [x] The participants spreadsheet (`participants-spreadsheet.md`, its own PR): its result / table / qualified cells
      send `RecordRoundResults` changes (the live entry's and the desk's write path), not a separate changeset type.
- [ ] Advance with the country rule: entries sharing the K-th place of a country across groups are ordered by the
      advancement seed (relative result, round order, name) - highlight such ties for the organiser like the desk's
      per-round helper does, if organisers ask.
- [ ] "Take out of this round" exists on the results desk only - the live entry (a referee quick-added the wrong
      person) could offer it too.
- [ ] The live entry's seating recommendation follows the one rule (`tablesReadiness`) but keeps its own wording
      (`live_results.tables.*`) - switch it to `seating.readiness.*` with the live page's next change.
- [ ] An event that never numbers tables: "none of this event's rounds use table numbers" in one click (today per round
      on the seating page; past rounds never show the step any more).
- [ ] An event-wide results export (every round of the event in one file) - today the results desk exports one round.
- [ ] A "disqualified" result (with a reason) - today the organiser clears the result or marks "did not start".
- [ ] Check-in → "did not start": when a round starts, offer to mark its entrants who were not checked in (managed
      registration) as did not start - the organiser decides, nothing automatic.
- [ ] `result_entered_at` is `TIMESTAMP(0)` and the results desk's late-answer guard compares with a strict `<`: two
      saves of one entry within the same second can briefly show the older one (the 1.5 s own-write resync puts it
      right) - harmless; compare with `<=` plus the result value, or store milliseconds, if it ever confuses anybody.

## Referees (`docs/features/competitions-management/live-results.md` "Referees")

- [ ] "My events" does not list the events a player referees (its cards are organiser cards with edit/delete) - a
      referee opens the link they were given. A "Referee at" list with a Live entry button when referees ask for it.
- [ ] A newly added referee is not told (no notification/e-mail) - the organiser hands them the link.
- [ ] Referees on the results desk (read-only ranking) if events want referees to check what others entered.
- [ ] A referee's live updates withhold every private player (the update has no viewer); a referee on a private
      player's allow list keeps her code from the page's own state (`keepWithheldPlayers()`), but an entry they first
      see through an update (a quick add on another device) shows her code only after the next state fetch (≤ 60 s).

## Seating (`docs/features/competitions-management/seating.md`)

- [ ] Two rounds running at once in one hall (WJPC semifinals): table numbers are unique per round only - the
      organiser gives the second round "First table number 101"; a shared check across simultaneous rounds if asked.
- [ ] Pairs/teams seated by MySpeedPuzzling times: a pair's own pair time beats the mean of its members' solo times, so
      pairs that puzzled together come first - calibrate (e.g. scale members' solo times by the typical pair speed-up)
      if organisers notice.
- [ ] Seat-by-drag on phones works through Move up / Move down only (SortableJS touch drag is there, but small screens
      make it awkward) - a "Move to table…" action if organisers seat on phones.

## Time verification (`docs/features/suspicious-time-review.md`)

- [ ] Result detail of a private player's queued time: the queue card links no result detail for a private player
      (the detail 404s for anybody the player hides from). Settled decision 1 allows a moderator to see that one time
      while it is queued - open the detail for `REVIEW_SUSPICIOUS_TIMES` + a case in the queue, or show what is needed
      on the card.
- [ ] Admin funnel: `/admin/duplicate-results` ("Your results" e-mails in numbers, `GetResultReviewContactsOverview`)
      counts every contact, but nothing tells the e-mails with verification content apart and "all resolved" knows
      duplicate cases only - count the e-mails that carried notices (`suspicious_notice_ids`) and the reactions to
      them (fixed / says correct / left it), or link the queue's Numbers tab.
- [ ] Undo of an automatic duplicate removal (`result_auto_removal`, docs/features/duplicate-results.md) restores a
      flagged time with the same id but without its `suspicious_time_case` / notices (they cascaded with the deleted
      copy): the next scan's flag reconciliation gives it a new manual case and the notice run tells its players again,
      although they were told about the mark before. Rare (a marked time that was also an automatically removed copy);
      restore the case and notices from the removal snapshot, or record the restored notices as already told.
- [ ] After a few weeks of decisions: read Numbers (precision per reason, what players did) and decide the thresholds of
      `SuspiciousTimeClassifier::VERSION` 2 (`--dry-run` first); also whether pair/team fast times and "Needs
      verification" from the result detail (origin `moderator`) are worth a version ("Not in v1").

## Round reveal delay (`docs/features/competitions-management/README.md` "Automatic reveal delay")

- [ ] Deploy it by the checklist in README "Deploying and rolling back": nothing pending in the next 24 hours (the
      `+ interval '10 minutes'` form of the query), every web/api/consumer container on the new image, then the delay
      query - re-sync each round it lists with `PATCH /internal-api/rounds/{id}` and `{}`.
- [ ] Once deployed, tell the organiser who asked for it (she added 5 minutes to her round starts to get 15 minutes
      before the reveal). She can put each start back to the real one and set the delay to 15. An earlier start with
      a longer delay that keeps the moment asks for no confirmation.
- [ ] The internal API's `"confirmReveal": true` is a blanket yes (as since PR #240): a list of revealed puzzles that
      grew between the 409 and the resend is applied unseen. Bind it to the list like the web form does: the 409
      answers the list's `SecretRevealPreview::hash()`, the resend sends it back (`confirmedRevealHash`).
- [ ] API v1 competition detail does not expose a round's reveal delay or its puzzles' reveal moments - add them
      (additive) if an API client ever needs to show when a secret puzzle comes out.

## Participant import (`docs/features/competitions-management/participant-import-preview.md`)

- [ ] `.xls` / `.ods` uploads; localized header aliases ("Jméno", "Nom", …); a header row chosen by hand; remembering
      the mapping per event.
- [ ] A team renamed in the file (all its members under a new name) is planned as move + delete, not as a rename.
- [ ] Clearing field values (country, external id) from empty cells in full sync - empty cells never clear today (D14).
- [ ] A connected player the file wants to change for a participant who has one is refused with a row message (never
      taken over) - offer a way to change it on purpose (the participants sheet's profile column, or an explicit mapping). External ids are
      updated unless another participant of the event has the file's one.
- [ ] Code page guess: names that defeat the neighbour rule (Norwegian "Øystein", French "Anaïs" next to vowels) can tip
      a small Western file to Windows-1250 - the Encoding select fixes it; watch for reports.

## API usage statistics (`docs/features/api/usage-statistics.md`)

- [ ] API-wide rate limiting: one Redis Lua script (GCRA / sliding window) on `kernel.request` in `redis-state`, keyed by `ApiCaller`, limits chosen from `api_caller_day.peak_requests_per_minute` once there are a few weeks of data; fold `api_puzzle_search` into it (design in the doc, "Later: rate limiting").
- [ ] Look at the busiest-minute numbers on `/admin/api-usage` around mid-November 2026 and decide the limits.

## Participant management after the edit-form bug (`docs/features/competitions-management/participants.md`)

- [ ] Wisconsin 2026 participant data repair: pending organiser's answer, script kept privately by Jan.
- [x] Import reads `round_names` (what the template/export write) and still `round_name`; rows of the same person add
      up; unknown rounds and columns are reported; the export carries `participant_id` and one `team_name: <round>`
      column per pair/team round, so an export imported back changes nothing; same-named people are never merged.
- [x] Import accepts `.xlsx` only - CSV/TSV/TXT, a sheet chooser, column mapping, a preview and full sync shipped
      (`participant-import-preview.md`).
- [x] Bulk editor (spreadsheet-like grid of all participants: name, country, external id, player, one checkbox per
      round, team per round) - proposal in PR #241.
- [x] `RoundTableManager` Live actions check `CompetitionEditVoter` on every request and only touch rows, tables
      and spots of their own round.
- [ ] Re-import behaviours that predate the rework (review of PR #242):
      - [x] a matched soft-deleted participant is restored unless its row says `status = deleted` - now shown on the
        preview as "Restore (removed on …)" before anything is written;
      - [ ] a matched self-joined participant becomes `imported` (`markAsImported()`), so leaving the event later only
        disconnects them - re-importing an export does this to every self-joined participant on it;
      - [x] a player who left (their self-joined row is soft-deleted and never matched) was signed up again by an old
        file - such a row is now skipped with a message.

## Organiser fixes 2026-10-07 (Ou La La Puzzles report, `docs/features/competitions-management/README.md`)

- [ ] Prod: "Ou La La SPC No. 17" exists twice - the duplicate (slug `ou-la-la-spc-no-17`, no date, no rounds) was
      invisible on the series page; it is now listed as "Date not set". Jan asks the organiser which one to keep - no
      data was changed.
- [ ] Two saves taking the same slug at the same moment: the handler re-check catches a save that finished in between,
      a truly simultaneous one fails at flush with a unique violation (series, editions) = 500. Standalone events have
      no DB constraint at all (`series_id` NULL) - consider a partial unique index `custom_competition_standalone_slug`
      on `competition (slug) WHERE series_id IS NULL` after checking prod for duplicates.
- [ ] `AddEditionHandler` still generates its own slug unique only within the series (could equal a standalone event's
      - harmless since `/en/events/{slug}` prefers the standalone event, but `CompetitionSlugGenerator` should do it).
- [ ] Missing translation keys seen on the way (not competition pages): `collections.no_move_target_member`
      (`collections/move.html.twig`), `wish_list.remove.title` and `wish_list.already_in_wishlist`
      (`wishlist/add_item.html.twig`).

## Round time zones and secret-puzzle reveal (`docs/features/competitions-management/README.md`)

- [ ] With the deploy, right after it: `myspeedpuzzling:backfill-round-puzzle-reveals` (dry run), read the list, then
      `--write` - until then old rows have `hides_everywhere = false` and a round move moves only the event page.
- [ ] Wisconsin State Jigsaw Puzzle Championship 2026: confirm the 4 round times with the organiser, set the
      rounds' `timezone` to `America/Chicago`, re-sync the 2 Team Relay secret puzzles (SQL in the PR description).
- [ ] Rename the random (hex) image names of secret puzzles to SEO names once revealed or approved.
- [ ] Existence signals of secret puzzles still open (nothing of the puzzle itself, but they tell that something is
      there): `MergeUnapprovedPuzzleController`
      `puzzleExists`, results counts and the edition's `puzzle_count`, the image aspect ratio while hidden, `/me`
      predicted time answering 200, the add-time EAN lookup no longer finding an image-only secret puzzle (a player may
      add a duplicate).
- [ ] Prague-formatted dates still in `events.html.twig:68` and the edition/event detail meta descriptions.
- [ ] Deleting a whole event or series does not ask before revealing secret puzzles other events hold (round deletion
      and puzzle removal do - `SecretRevealPreview`).
- [ ] `GetCompetitionEditions` is dead code - remove.
- [ ] `SecretPuzzleHides::isStoredFor()` runs one query per row - fine at today's sizes.
- [ ] The images cache (nginx in front of imgproxy, 365 days) still serves thumbnails requested under a secret
      puzzle's old guessable image name after the backfill moved it - there is no purge endpoint; delete the cache
      files of those keys on the box if they were requested.
- [ ] A puzzle removed from its round while on a manual reveal stays hidden with no end - its adder finds it in the round
      picker again, otherwise an admin clears `puzzle.hide_until` / `hide_image_until` on request.
- [ ] Nothing new is recorded on a secret puzzle before its reveal (`SecretPuzzleAccess::assertWritableBy()`), but
      records made before it became secret stay - a puzzle taken over by "Keep it hidden everywhere" or by the backfill
      may already have times, collection items or listings, and feeds, profiles and the marketplace show its name with
      them. Hide those records (or the puzzle in them) while it is secret, if one ever turns up.
- [ ] The add-time form and the stopwatch tell an organiser up front that a secret puzzle takes no time yet; the
      collection / wishlist / sell-swap / lend buttons on its page still say it only on submit (flash or modal).
- [ ] With the deploy: purge the old guessable image names the backfill prints from the images-cache and Cloudflare
      (commands in the PR's production plan). Later renames (a puzzle becoming secret) are rare - same commands by hand.
- [ ] "Something went wrong" in multiscan for a row whose puzzle became secret meanwhile - say "no longer available".
- [ ] Multiscan answers a code only a hidden puzzle carries with its generic "could not be added / linking failed" (since
      2026-10, no more "already assigned" - that told a secret box has the code). A player may retry in vain; once the
      puzzle is revealed it resolves normally.
- [ ] A guessable picture name a change request's snapshot (`puzzle_change_request.original_image` / `proposed_image`)
      still references is kept when its puzzle becomes secret (warning "Old guessable picture of a secret puzzle kept").
      Resolve by hand: point the snapshot at the puzzle's new image (`UPDATE puzzle_change_request SET original_image =
      <new> WHERE original_image = <old>`), then delete the old object and purge it from the caches (PR #240 step 3b).
- [ ] "Shown by another surface" for turning a non-secret round puzzle secret is read as "another round shows it now"
      (`RoundPuzzleOwnership::sqlShownByAnotherRound()`) - a public catalogue puzzle may still be hidden on the event
      page before its round starts, as when adding it. Revisit if that should be refused too.
- [ ] Not in the internal API: changing a reveal, Reveal now, "Keep it hidden everywhere", attaching a secret puzzle
      (refused - its reveal is chosen on the round's page).
- [ ] The coordinator saw America/Chicago twice at the end of the zone select; the server renders it once and TomSelect
      moves the selected option to the end of the native select - not reproduced in Chrome 2026-10-06, re-check on a phone.

## Large photo uploads (Sentry WEB-D4, 2026-10-06)

A 54 MB phone photo on the add form went over PHP's `post_max_size` (50M): the whole request was dropped and the
player lost the form. Shipped: limit 128M (`web-base-php85/php.ini`), photos up to 60 MB accepted and shrunk by
`ImageOptimizer` (`PhotoUploadLimits`), and `submit_prevention_controller` takes out a photo still above the limit
(with a note) instead of sending a request PHP would drop.

- [ ] Find out why the browser did not shrink the photo (Samsung Internet, Chrome 143 base): `compressImage()` decodes
      the full image into an `<img>` first - a 200 MP shot may be too big to decode on a phone, a HEIC cannot be
      decoded outside Safari. Try `createImageBitmap(file, {resizeWidth, resizeHeight})`, and a beacon when
      compression fails, to see how often it happens.
- [ ] Traefik's default `readTimeout` is 60 s for the whole request, body included (lily's `traefik.yml` sets none):
      a 60 MB photo that the browser could not shrink needs ~8 Mbit/s upload to make it. Raise it on `websecure`
      (shared edge, all apps) or accept that the fallback only works on a fast connection.
- [ ] The other photo forms (Suggest a change, puzzle record, avatar, competition logo) have no submit-time
      compression or guard yet - they only take small files, but the same body-limit loss applies above 128M.

## Events through the internal API (`docs/features/internal-api.md` §Competitions and events)

- [ ] Shared tags: a competition whose tag other competitions/series carry too answers `PUT …/puzzles` with 409. If
      that happens on production (e.g. one "WJPC" tag for several editions), decide: give each competition its own tag
      (and keep the old one for the badge), or let the endpoint fork the tag.
- [ ] Not in the API yet: the competition logo (upload), series and editions (`AddCompetitionSeries`, `AddEdition`),
      rejecting a competition, a round puzzle's "hide until the round starts" setting (new ones are attached
      unhidden), maintainers by player code instead of id.
- [ ] Puzzle search leaves out secret competition puzzles (`hide_until` in the future), like the site - an admin
      entering a future round with a secret puzzle has to know its id.
- [ ] `AddCompetitionHandler` still calls `flush()` itself (older than the flush rule) - drop it once nothing depends
      on the competition being flushed before the e-mail.

## Barcode scanner (`docs/features/barcode-scanner/README.md`)

- [x] Step 0 (2026-10-06): the browser's own detector is back on Android. zbar alone had cost about half of the Android
      scans (add-form lookups 169 → 92 a day, iOS flat). The plan with Steps 1-4 is in the README.
- [ ] Ask Vanja to scan her Ravensburger box again after the 2026-10-06 deploy.
- [ ] A few days after the deploy, re-run the Tempo count of `/puzzle-by-ean-search/` per platform (README §1). Android
      should be back near 169 a day.
- [ ] *The World of Trolls* (`01947b9c-24c6-7359-972a-54f6a6d86402`) stores `045570100330`, most likely a misread of
      Ravensburger `4005556100330`. Check a box or a shop listing, then file a change proposal.

## Codes in the forms (`docs/features/puzzle-names/README.md`, phase 5)

- [x] `myspeedpuzzling:canonicalize-puzzle-codes` on production (2026-10-05): 1,175 EAN + 157 brand-code fields
      written, search keys unchanged, undo file `/root/codes-20261005T084536Z/codes-undo-20261005T084536Z.csv` on the
      box (`\N` = NULL); 304 of the report's 316 puzzles with a fix filed as change proposals (one per puzzle, both
      lists). Jan's call: the technical, certain ones approved by Claude right away (94, + 4 a moderator approved
      first); the other 165 (plain numbers, Amazon codes - Jan: moderators decide -, unknown formats) wait in the
      queue.
- [ ] File the other 12 once their pending merge / change request is decided (the API answered 409):
      `.claude/worktrees/pn-delivery-state/codes-cleanup/file/refile-409.json` (local to Jan's Mac) - re-read each
      puzzle first, the proposal replaces both whole lists.
- [ ] The 126 puzzles the report has no fix for wait for a person with the box (`codes-cleanup/file/nofix.json` next
      to it): 65 barcode lengths with a wrong check digit, 60 brand codes with words in them, 1 comma between digits.
- [x] One release after phase 5: `CodeListType::acceptLegacyFields()` and its five calls removed (2026-10-05).

## Codes help and check-digit aliases (`docs/features/puzzle-names/codes-help-and-check-digit.md`)

- [x] Release runbook on production (2026-10-05): dry run 8,944 keys gain an alias (only added `c:` lines, report
      `/root/search-key-aliases-20261005.csv` on lily), the run 5.5 s, a second dry run 0; "Finding Concentration"
      found by `120020288`, `12 002 028 8` and `12002028`, the barcode lookup still exact. Nightly
      `rebuild-puzzle-search-keys --alert-on-drift` at 05:16 (lily.srv bc00ef5).
- [ ] The "What is it?" help also on "add puzzle to a round" and "Suggest a change" (`puzzle/_code_inputs.html.twig`).
- [ ] A puzzle without a valid EAN gets no alias: `120020288` does not find a stored `12002028` there (an unchecked
      fallback would hit near-miss codes).
- [ ] Global search highlights nothing when an alias matched (`components/GlobalSearch.html.twig`, the shown code
      differs by a digit).

## Puzzle names - catalogue cleanup (`docs/features/puzzle-names/README.md`, phase 4)

- [x] All filed on 2026-10-05 (Jan: everything at once, approve right away what is certain): wave 1 (45 merges,
      54 splits, 3 swaps - in the queue), 401 high-confidence language tags (397 read by hand and approved, 4 spelled
      alike in another language left to moderators), 128 medium/low-confidence ones for moderators (19 had no
      language to propose). Tool, research and logs local to Jan's Mac: `.claude/worktrees/pn-delivery-state/phase4/`.

## Puzzle names on pages and in search (`docs/features/puzzle-names/README.md`, phase 2)

- [ ] 6-8 weeks after phase 2 shipped (2026-10-05, so 2026-11-16 to 2026-11-30): Search Console index coverage per
      language (`/puzzle/…` cs, `/de/puzzle/…`, `/fr/…`, `/es/…`, `/ja/…`) - do the locale copies of puzzles with a
      name in that language leave "crawled - currently not indexed" more than the others? Compare with the numbers
      before the deploy (`docs/features/seo/research-2026-09.md` §4.1, §9).

## EAN codes in the catalogue (2026-10-04)

- [x] "Suggest a change" refuses codes that are not EAN/UPC codes, each one of a comma-separated list
      (`Value\EanList`); codes the puzzle already carries pass, so legacy junk never blocks another change.
      Ravensburger `4005555…`/`4005556…` typed without its two zeros (`45555…`) is refused with the full code suggested.
- [x] Same check on the add-puzzle form, for a new puzzle only, with a Clear button; the photo stays (2026-10-04).
- [x] 7 of the 11 stored `45555…` codes: change proposals filed via the internal API (`puzzle-change-proposal` skill,
      `01a106fe-…`, 2026-10-04) - waiting for review.
- [ ] The other 4 need a person: *Library at St. Florian* (Barb's proposal `019e9cfd-de9e-7176-a416-f46f467ad92a` pending
      since June - fix the EAN while deciding it); *Star wars the man* (pending merge into The Mandalorian and Grogu
      `019ea35e-e36f-7026-be51-c6ce7b24739e` - drop `45555018124` from the merged EAN); *Have dog, will travel #1* (merge
      `01a08232-606a-7187-884c-36dc111b9382` misses a third record `019fc37a-0a6c-722b-b07c-344caf3a7d72` holding the
      full code); *Extinct Giants* (`045556130443` - its full code is on *By the River* `01934f99-de98-71e9-91e4-232499596903`).
- [x] The 12 pending change requests that changed only the EAN, to an invalid code, rejected via the internal API
      ("The proposed EAN is not valid EAN barcode", 2026-10-04).
- [ ] 4 pending ones carry an invalid code but change other fields too - manual review
      (`019df4f1-d64d-7179-b8ef-0e5ab1f77b56`, `019e4530-f7ca-72a3-b365-c2df422784e1`, `019e98f1-dc2a-7225-9842-257ab9cd377e`,
      `019ea2a3-e359-724c-9327-76f86c03c125`).
- [x] Where the `45555…` form comes from: Android's built-in barcode detector misreads `4005555…` as `0045555…`
      (a valid code, stored without leading zeros). Vanja reproduced it on her box, 8 of the 9 players scan on Android.
      The scanner used our zbar on every platform from 2026-10-04 (`docs/features/multiscan/README.md` "Decoder"). It
      went back to the browser's detector on Android on 2026-10-06, see the Barcode scanner section.
      Matilda's Aurore `778649925052` is the same misread of `3770039925052` - not a real code.
- [x] Ask Vanja or Matilda to scan one of those boxes again after the deploy (Android) to confirm the fix. Vanja
      (2026-10-05): with zbar her phone read nothing ("the camera couldn't focus properly").

## Players page (`docs/features/players-page/README.md`)

- [x] Cron row for `myspeedpuzzling:recalculate-community-stats` in `~/www/lily.srv` (`14-59/15`, lily.srv b3ea25c);
      first run by hand right after the deploy: 3.37 s, 11,493 players, 85 scopes, 1,334 moments (2026-10-03).
- [x] Translations of `players.*` into cs, de, es, fr, ja; the dead keys of the old page removed (2026-10-03).
- [ ] Leaderboard "Where you stand": best-time distribution per country with your marker (line for every signed-in
      player, chart for members, like the puzzle page).
- [ ] Hub rework (Jan, 2026-10-03: catch-up + trending + recent activity of all sorts, digests of other sections,
      likes/comments later): "See all" from Hub people widgets into Players, `player_moment` rows as feed items.
- [ ] Player card on leaderboards, the feed and puzzle pages (avatar/name taps; a puzzle leaderboard row tap stays the
      result detail).
- [ ] Measure after a few weeks: Players visitors per day against the 53 baseline (Tempo), Favorite/Compare started
      from the card, the share of new sign-ups with a country.
- [ ] Remove the "New" badges from the menu and footer Players links once the page is not new any more.

## Marketplace at events (`docs/features/marketplace/11-events.md`)

- [ ] Phase 2 (#228): "Going to events" panel on sell/swap lists (own: edit per event; visitors: read-only, links to the
      filtered marketplace) and a bag icon next to sellers in an event's participant list.
- [ ] Phase 3 (#229): notify a buyer when a puzzle from their wishlist is coming to an event they go to; tell a seller how many
      people going have one of their listed puzzles on the wishlist (count only); e-mail 3 days before the event.
- [ ] Links are kept after the event (decision) - nothing reads them yet ("Brought to WJPC 2026" history is an idea).
- [ ] Measure after the spring 2027 season: sellers marking per event vs sellers going, "Ask to bring it" conversations,
      listings sold to a buyer who went to the same event (`sold_swapped_item` + participants).
- [ ] Event pages still format dates with `_event_date_range.html.twig` ("10.-11.10."); the new labels use the
      locale-aware `event_dates()` - unify the event pages one day.
- [ ] Marketplace filter panel: the Sort select is squeezed to ~90 px ("Ne…" for "Newest") on desktop - pre-existing,
      seen during the review (also on `main`).
- [ ] German pre-filled buyer message uses the site's formal "Sie" - between hobbyists "du" may read warmer; Jan to decide.

## Compare line-ups (`docs/features/player-comparison.md`)

- [ ] Guests can't open a shared comparison link (sign-in first). Consider a public read-only view if members share
      links outside the site.
- [x] Caps are not race-proof: two simultaneous adds can exceed a cap by one - `AddComparisonSubject` is
      `SerializedByLock` per owner now.
- [ ] Scatter chart scale follows the longest time; one marathon solve squeezes the rest - consider clipping at p95.
- [ ] "Puzzles they solved that you haven't" (show = all) could link to the wishlist - idea from the review.
- [ ] Measure usage after a few weeks (line-up sizes per kind, views, similar-speed rolls, share links opened).
- [ ] Charts tab at 320 px: the head-to-head grid card pushes the page ~60 px sideways with 7 subjects (measured
      2026-10-03; `.cmp-chart` / `.cmp-h2h-wrap`). Also `.cmp-h2h` is two things: the head-to-head card
      (`_comparison.scss`) and the charts' grid table (`_comparison-charts.scss`) - the card's padding lands on the table.
- [ ] Site topbar at 320 px: the language/feedback row overflows by 15-35 px in de/fr/es/ja on every page (not
      compare-specific; measured on the homepage 2026-10-03).
- [x] Panther suite red (found 2026-10-03, fixed the same day, 62/62 green). Only one test was about the launcher,
      and it was test-only: WebDriver scrolls an element just to the window's edge, under the pill (or the sticky
      header at the top) - people scroll it clear, every page has room for the pill under the footer. Cards are now
      opened through `AbstractPantherTestCase::openCardMenu()` (card in the middle of the window, real click). The
      SystemCollection failure was a **real bug**: "Borrow from player" on a collection page answered 500 after
      saving the borrow (stream rendered without the card) - fixed, `BorrowPuzzleControllerTest`. PuzzlesTest raced
      the debounced Live search (`searchPuzzles()` waits for the whole query's render).

## Player header (#224)

Shipped: [`features/player-header.md`](features/player-header.md).

- [ ] **API vs web (Jan's call):** `/api/v1/me/followers` (`GetPlayerConnections`) still lists private followers the
      viewer may not see, masked but by `#CODE`; the favorites page only counts them (`GetFavoritePlayers::followersOf`).
      Align the API (count only) or accept the difference
- [ ] The favorite toggle is still a GET link (`ToggleFavoritePlayerController`, `rel="nofollow"`, no CSRF) - a POST
      button would be the honest form now that it is a primary action on 12 pages
- [x] Found while screenshotting, not caused by #224: `onboarding.checklist.progress` was overwritten by the free trial
      (`cf819693`) with `%logged%`/`%required%` in en, cs, de - restored, guarded by `HubControllerTest`
- [ ] Found while screenshotting, not caused by #224: the site topbar (`.topbar-text.text-nowrap`) is wider than
      320 px in German and for admins - the page scrolls sideways there

## Transactional e-mails

Shipped: [`features/transactional-emails.md`](features/transactional-emails.md).

- [ ] **Reply-To (Jan's decision):** the footer invites questions, but replies to `robot@mail.` / `notify@notify.`
      go nowhere anybody reads - pick the mailbox (e.g. simona@ or jan@) and set `Reply-To` on the transactional and
      notification e-mails (the newsletter already sets `Reply-To: jan@myspeedpuzzling.com` per campaign)
- [ ] Inky `<spacer size-sm>` never shows anywhere (its `hide-for-large` class inlines `display:none` and the mobile
      rule that would show it is not in `email_document`); spacing relies on paddings - replace them with `size` or a
      plain spacer table when touching a template

## Duplicate results (#221)

Plan: [`features/duplicate-results.md`](features/duplicate-results.md).

- [ ] "Possibly saved twice" marker in the player's results list (`PlayerSolvedPuzzles`) for results with an open
      case - left out of P4 while those templates are being reworked
- [ ] "Your results" backlog (491 planned 2026-10-02, sending since ~16:00, one e-mail a minute around the clock,
      crons live in lily.srv) - after the first day
      watch bounces/complaints, the reaction rate and the folder at Gmail / iCloud / Seznam (admin "Contacts"); lower
      `RESULT_REVIEW_EMAILS_PER_RUN` or raise `RESULT_REVIEW_EMAIL_SPACING_SECONDS` on the box if it goes badly
- [ ] Admin overview: prevented saves (re-sends caught, "saved anyway" after the warning) next to the monthly trend -
      the doc's success measure; no query reads `result_duplicate_prevention` yet (left out of the 2026-10-08 redesign,
      which was presentation only)

## Live activity feed (Hub + Recent activity)

Shipped: [`features/live-activity-feed.md`](features/live-activity-feed.md).

- [ ] A week after deploy: compare `RecentActivity` re-renders in Tempo with the baseline in §1.2 (7,281 polls
      Thu 12–24 UTC, 91 % from tabs open 3+ hours)
- [ ] Report upstream (symfony/ux): a re-render whose `fetch` rejects leaves `Component::backendRequest` set, so the
      component ignores every later render on that page; drop the workaround in `live_refresh_controller.js` once fixed
- [ ] `mercure_hub_controller.js` (unread counts, chat) has no recovery: on iOS 18 the stream can stay "open" but dead
      after resume, a non-200 answer closes it for good, and the open stream keeps signed-in pages out of Chrome's
      back/forward cache - close on `pagehide`, reopen on `pageshow` / visible, reconnect with backoff (§10 of the doc)
- [ ] Before a competition with thousands watching: push rows rendered once per locale through Mercure instead of
      per-viewer refreshes, after a load test through Traefik (§6.1 of the doc)

## Result detail modal + unified ranking rows (#222)

Shipped: [`features/puzzle-result-detail.md`](features/puzzle-result-detail.md),
[`features/puzzle-leaderboard-chart.md`](features/puzzle-leaderboard-chart.md) §"Row layout".

- [ ] Translate the new `puzzle_result.*` and `puzzle_times.leaderboard.*` keys (English only so far) + the `loading`
      spinner label (English only from before)
- [ ] Real-device check after deploy: the phone sheet, iOS swipe-back and the Android back button closing the modal
- [ ] Click through the signed-in edit-time and feedback modals after the `dynamic_modal_controller.js` rewrite
- [ ] Editing a time from the result detail opened on the profile lands on the puzzle page afterwards, not back on the
      profile (edit context `puzzle-detail`)
- [ ] The modal's rank is always the unfiltered leaderboard rank - with a country / first-try filter on the puzzle
      page it can differ from the row's rank
- [ ] After a link out of the modal, the page shows up twice in history (Back from the next page, then once more on
      the same page) - accepted trade-off of pausing Turbo's history, see the hotwire guide
- [ ] Profile pair/team tabs count distinct puzzles while their rows are per puzzle *and* pair - "Pair (1)" can have 2
      rows; decide whether the tab should count rows
- [ ] Older, found on the way: `templates/puzzle/brand_hub.html.twig` uses `column-gap-*`, which Bootstrap 5.2 does
      not have (no-op)

## Leaderboard chart switch (distribution / rankings)

Shipped: [`features/puzzle-leaderboard-chart.md`](features/puzzle-leaderboard-chart.md) §"Chart switch".

- [ ] Click the switch as a member in a real browser (dev is not signed in as one): both ways on London Postcard, zoom +
      reset in the rankings, tooltips in the distribution, no console errors. Both views and a short board were checked
      on screenshots of rendered pages (375 px + 1,280 px, 2026-10-02); the Live switch itself was not clicked yet
- [ ] Rankings on big boards ship 118 KB of chart JSON with every re-render (London Postcard); if members use it a lot,
      send a `firstAttempt` flag array and map colours in JS (~ -40 KB)

## First-try integrity (#217)

Shipped: [`features/first-try-integrity.md`](features/first-try-integrity.md).

- [x] Hub banner for players with first-try conflicts - part of the "Review your results" banner since duplicate
      results P4
- [ ] Remind about unresolved first-try conflicts in the digest e-mail
- [ ] A few weeks after the deploy: how many of the 3,170 duplicate pairs (2026-09-30) got resolved - decide whether
      the rest needs another nudge
- [ ] Object-storage lifecycle rule for `tmp-uploads/` (24 h) as a second safety net next to the
      `myspeedpuzzling:prune-photo-stash` cron, if Hetzner Object Storage supports one

## FrankenPHP worker restarts (found 2026-09-30, session research)

- [ ] The `max_requests 500` guard against memory leaks probably never fires: FrankenPHP 1.12.7 resets its request
      counter every time the Symfony worker script restarts, and that script restarts every 500 requests too (locally,
      both limits at 10: 100 requests → 0 thread restarts; script limit 1000 → 9). If the guard matters, set
      `FRANKENPHP_LOOP_MAX` above `max_requests`. Never pair it with a persistent PDO connection: FrankenPHP does not
      close those on a thread restart (reproduced: 2 threads, 9 restarts, 11 connections left) - the session handler
      deliberately uses the worker's Doctrine connection instead.

## Insights recalculation writes only what changed

Shipped 2026-09-30: [`features/puzzle-intelligence/README.md`](features/puzzle-intelligence/README.md) §"Writes: only what changed".

- [ ] After the deploy: WAL per day on lily and `n_tup_upd` of the eight insights tables, against the 2026-09-30
      research (~13 GB/day, 43 % of all WAL, ~111k row updates per run)
- [ ] `player_rating_snapshot` has no reader, grows by 17.5k rows a day (575 MB on the 2026-09-25 copy) and is now most
      of the recalculation's WAL (2.2 GB on a replayed day): the first run of every day inserts 17.5k rows into four
      indexes of a 2.7M-row table (82-151 MB), and every run updates the snapshots of the players whose rating or skill
      moved. Decide: keep as is, one row per day written once, room for HOT updates (fillfactor), or drop the table
- [ ] `ImprovementRatioCalculator` orders a player's solves of one puzzle by `COALESCE(finished_at, tracked_at),
      tracked_at` only, so equal timestamps come back in plan order and a few "4+" ratios differ between any two runs
      (now: rewritten, before: invisible). `pst.id` as the last tie-breaker (also `buildTransitionsCte()` and
      `PredictionReconstructor`) makes it deterministic, changing those few ratios once
- [ ] `myspeedpuzzling:recalculate-puzzle-intelligence --player=UUID` removes every other player's baselines, skills,
      ratings and improvement ratios (and so most difficulty scores) until the next full run - its cleanups delete what
      the run did not produce, as they did before 2026-09-30. Scope the cleanups to that player, or drop the option

## Database indexes (2026-09-30 review)

Registry and how the numbers were taken: [`database-indexes.md`](database-indexes.md). Shipped: `custom_notification_unread`,
`custom_player_search_trgm`, two unused `puzzle_solving_time` indexes dropped. What an index could not fix radically:

- [ ] After the deploy: `pg_stat_user_indexes.idx_scan` of `custom_notification_unread` and `custom_player_search_trgm`
      is growing, and the `pg_stat_statements` mean of the unread count and the player search dropped
- [ ] `GetRanking::allForPlayer()` (profile ranking) is the web statement with the most total time: 114-128 ms mean, ~13k
      calls a day, 0.3-0.7 s for players with 100+ puzzles on production. A covering `(puzzle_id, player_id,
      seconds_to_solve)` index only gets 1.7-1.9x - the aggregate and the two window functions over every player's
      best time on those puzzles are the cost. Rewrite to "players with a better best time + 1" per puzzle, or cache
- [ ] `MostActiveSoloPlayers` this/last month: 64-80 ms, ~2.4k calls a day. A `finished_at` index only gets 1.6x
      (the joins to player and puzzle and the aggregate remain) - cache it for a few minutes instead
- [ ] Notifications page (`GetNotifications::forPlayer()`): 112 ms mean, 58k buffers a call for players with thousands
      of notifications - every branch reads all of them before `LIMIT 200`; limit inside the branches first
- [ ] `SearchPuzzle::byUserInput()`: 53 ms mean, ~13.5k calls a day. A term of 3+ characters is index-served (0.5-12 ms
      locally); the mean comes from calls without a term (library pages, filters, API: ~110 ms locally - `ILIKE '%%'`
      and the `match_score` CASE with the non-inlinable `immutable_unaccent()` run on all 41k puzzles) and 1-2
      characters (~70 ms). Skip the search conditions when the term is empty; plain `unaccent()` for short terms
      (as `SearchPlayers` does)
- [ ] Two hand-made indexes exist only on production (`custom_puzzlers_gin`, `custom_seconds_to_solve_order_asc`, both
      used): put them into a migration + `tests/bootstrap.php` with the query they serve, or drop them

## Speed check of the autumn SEO round

Measured 2026-09-30: [`features/seo/performance-2026-10.md`](features/seo/performance-2026-10.md).

- [ ] Decide on JIT for web requests: `PGOPTIONS='-c jit=off'` on the web + api containers (lily.srv), or
      `ALTER DATABASE speedpuzzling SET jit = off` - it cost 34 ms of the 83 ms London Postcard pairs query, and a query
      estimated above 500k pays another 150-400 ms (numbers in the doc)
- [ ] After the deploy: Sentry p95 of `GET puzzle_detail` for the biggest boards (London / New York Postcard),
      `GET ladder`, `GET ladder_solo_500_pieces`, `GET sitemap_players`, and the brand hubs for signed-in players
- [ ] Tracker page: take `GetStatistics::globally()` (a sum over all solving times on every request) from the
      homepage's 60 s snapshot (`HomepageStatistics`)

## Prediction history

Design: [`features/puzzle-intelligence/prediction-history.md`](features/puzzle-intelligence/prediction-history.md) (2026-09-30).
First delivery = store + backfill; the UI comes after it.

- [x] Build it: columns + live recording on add/edit + backfill command (2026-09-30)
- [x] Recap page and API `POST /api/v1/me/solving-times` read the stored prediction (fallback to computing it only
      while a back-dated time is still pending)
- [x] Backfill on production 2026-09-30: 393,074 times of 6,977 players in 27 min (first attempt OOM-killed by Sentry
      console tracing - command excluded since 7581129d)
- [x] Cron `34 1,7,13,19 * * *` Prague in lily.srv (c32e756), Sentry monitor `backfill-solving-time-predictions`
- [ ] Calibration check (first numbers after the backfill): personal predictions 74 % inside their range, median
      -0.3 % (well centred); statistical only 32 % inside the p25-p75 range (a calibrated IQR holds ~50 %) and players
      are a median 9.8 % faster than predicted - look into it before the UI shows "faster than expected" on first solves
- [ ] `--recompute-reconstructed` on the backfill command, built together with the calibration fix: after a
      `TimePredictionCalculator::MODEL_VERSION` bump it resets the `reconstructed` rows to NULL (a genuine bulk
      `UPDATE`, commented as such) so the next run rebuilds them with the new model - `live` rows stay, they are
      history. Without it the ~445k reconstructed rows keep model version 1 and only new times get the new model
- [ ] Per-time outcome in the player's history (puzzle page "my times", profile): range, "12 % faster than predicted", inside/outside the range, members-only, hidden for players who opted out of predictions (stored anyway), decide for unboxed/suspicious times
- [ ] Mark `reconstructed` predictions in the UI ("reconstructed from your earlier solves") vs `live`
- [ ] Player-level accuracy: how often inside the range, average beat, trend over time (Insights section)
- [ ] Ideas for later: "beat the prediction" streaks/badges, API fields on result rows (additive: `prediction`, `faster_than_predicted_percent`)

## SEO site-wide links (WS-F2)

Shipped with WS-F2 of [`features/seo/implementation-plan-2026-10.md`](features/seo/implementation-plan-2026-10.md).

- [ ] The footer "Popular searches" are hand-picked, hardcoded links (5 brand × pieces pages, 4 brand hubs, the WJPC
      2022-2026 event slugs, the hardest 1000-piece list) - all checked live on the production copy 2026-09-30.
      Renaming one of those brand or event slugs breaks a link on every guest page; revisit the picks yearly (new WJPC)
- [ ] The "how long" guides are English-only: their links on the pieces hubs, brand × pieces pages, `/puzzle` and in
      the footer say "(in English)" in the other five languages - drop that when the guides get localised (plan, open decisions)

## Sign-in / sign-up UX redesign

Phase 1 shipped 2026-09-29: [`features/auth-ux-redesign.md`](features/auth-ux-redesign.md) §8. Phase 2 (6-digit code) shipped 2026-09-29: §9.

- [x] Phase 2: 6-digit code in the sign-in e-mail + code input on `/login-link/sent`, attempt cap, audit, e-mail subject with the code (§9)
- [ ] Real-device check of the code: iOS Mail / Gmail "one-time-code" autofill from the e-mail subject, paste from the notification, Instagram in-app browser end to end (request -> mail app -> back -> code)
- [ ] After a month: `auth_audit_log` `sign_in_code_used` vs `sign_in_link_used`, and `sign_in_code_failed` by `metadata.code` (lots of `locked_out`/`throttled` from one IP = somebody guessing - then add a `warning` log for clustered lock-outs, spec §6.4)
- [ ] Test the redesigned forms with 1Password, Bitwarden, iCloud Keychain and Chrome on Android (save on register/reset, fill on login) - §6.4
- [ ] Real-device check of the in-app notice: Instagram + Facebook on iOS and Android (UA tokens drift; "Open in Chrome" intent, copy link)
- [ ] Watch `auth_audit_log` sign-in-link use rate before demoting "Email me a sign-in link instead" to a text link (D7)
- [ ] 16px inputs site-wide, then drop `maximum-scale=1` - [#216](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/216)
- [ ] `/welcome?return=` (spec §5.4) - not built; registration still ends on the welcome page

## Difficulty on puzzle lists and thumbnails

Shipped with #214, thumbnails + profile/marketplace filters 2026-10-03 (d8b3183d, b39d8e30). Design: [`features/list-difficulty-and-my-list-filter.md`](features/list-difficulty-and-my-list-filter.md).

- [ ] Turbo-stream re-renders of list items (reserve, move, lend, ...) drop the difficulty icon, solve count and `data-difficulty-tier` until reload - pass `puzzle_insights` for the one puzzle in the stream callers if it ever matters
- [ ] Same for the marketplace card re-rendered by the reserve / unreserve streams (`sell-swap/_mark_reserved_stream`, `_remove_reservation_stream`): no corner until reload - resolve the one puzzle's tier with `ResolveDifficultyTiers` there
- [ ] Round results show no tier for a puzzle without any picture either (the gate is "picture is out", the cheap proxy for "revealed") - expose the hide state from `GetEditionRounds` if that ever matters
- [ ] Sort by difficulty on the profile results and the marketplace (the comparison has it) - not asked for yet
- [ ] Costs were measured on the dev copy only - check the Hub, `/ladder` and the marketplace in Tempo a week after the deploy
- [x] `templates/_most_solved_puzzle.html.twig` deleted - unused since the Hub's Most solved became a component (efc8b2b4)

## Social login hardening

Shipped 2026-09-29; Google + Apple public 2026-09-29, Facebook 2026-09-30 (Meta app published, flag removed). Design: [`features/auth-hardening/README.md`](features/auth-hardening/README.md) §Hardening 2026-09-29.

- [ ] Stripe mails to Apple relay addresses: Stripe customers carry the account e-mail, so receipts to `@privaterelay.appleid.com` come from Stripe's domain and are likely dropped by the relay - Stripe custom e-mail domain + register it with Apple, or accept ([`setup-apple.md`](features/auth-hardening/setup-apple.md) §Open questions)
- [x] Translate the interstitial strings `auth.social.confirm.sign_in_and_connect` / `duplicate_accounts_help` and re-translate `auth.social.confirm.have_account` in cs/de/es/fr/ja (2026-09-29)
- [ ] Deliberately NOT done: Gmail dot/plus normalisation of provider emails when matching accounts (rule 2/3 compare the canonicalised address as-is)
- [ ] True account merge (two accounts, two players) stays a manual admin operation - write the runbook when the first request comes in
- [x] "Continue with Microsoft" (personal accounts, ~13 % of players have a Microsoft mailbox) - code shipped dark 2026-09-30 ([`microsoft-plan.md`](features/auth-hardening/microsoft-plan.md))
- [ ] Microsoft go-live: Jan's console work per [`setup-microsoft.md`](features/auth-hardening/setup-microsoft.md) (prod + `MySpeedPuzzling Local` registrations) → local test with the local values in `.env.local` → prod values to Infisical (`MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_CLIENT_SECRET_EXPIRES_AT`) + deploy = public → verify the publisher domain (`/.well-known/microsoft-identity-association.json`) → smoke test
- [ ] Box: `docker compose pull bot-blocker && docker compose up -d bot-blocker` so bot-blocker `7effa7c` (Microsoft's verifier always passes on `/.well-known/microsoft-identity-association.json`) is live before the publisher-domain verification
- [ ] Microsoft client secret rotation due: _fill in when the prod secret is created (creation + 23 months)_ - runbook in [`setup-microsoft.md`](features/auth-hardening/setup-microsoft.md) §Secret rotation; Sentry warns daily from 30 days before `MICROSOFT_CLIENT_SECRET_EXPIRES_AT`
- [ ] Upgrade path at the first rotation: certificate credential (`private_key_jwt`) instead of a client secret ([`microsoft-plan.md`](features/auth-hardening/microsoft-plan.md) §6)

## Puzzle approvals

Shipped 2026-09-25. Design: [`features/puzzle-approvals.md`](features/puzzle-approvals.md).

- [ ] Tell the adder when their puzzle is approved or merged (notification type + `GetNotifications` branch)
- [ ] Count approvals in `GetModerators` (reads `reviewed_by_id` today; `puzzle_moderation_decision` has everything)
- [ ] A read-only history page over `puzzle_moderation_decision` (who decided what, filter by player)
- [ ] Unapproved brands on production: 57 after the 2026-10-03 cleanup (was 213), 10 of them without a pending puzzle, so the queue never shows them - see [Duplicate brands](#duplicate-brands)

## Duplicate brands

Design + survey: [`features/brand-duplicates.md`](features/brand-duplicates.md). Merge API shipped 2026-10-03.

- [x] Cleanup 2026-10-03 (report: `~/Downloads/msp-brand-duplicates-2026-10-03/cleanup-report-2026-10-03.md`): 2,277 → 2,057 brands, 217 merged, 3 empty deleted, 35 approved; no two brands share a name ignoring case/spacing any more
- [ ] Community review of the 137 puzzle merge requests filed by the cleanup (`/admin/puzzle-merge-requests`)
- [ ] Jan's calls from the report: D-Toys/Roovi display name, Big Ben, generic "Jigsaw puzzle" names, Miniwan, Valo, Zdeko, Canadian Art Prints, Whitman Guild, Boynton, Britto/Scenic; 4 Renoir puzzles to re-brand to MarMa
- [ ] Approve or merge the 10 unapproved brands that have no pending puzzle
- [x] Hardening: one brand resolver for `AddPuzzleHandler` (incl. multiscan quick-add) / `AddPuzzleToCompetitionRoundHandler` - `ManufacturerResolver`, case + spacing only (2026-10-03)
- [ ] Hardening: unique index on the normalised brand name (race-safe insert) - possible now: zero collisions since the 2026-10-03 cleanup
- [x] Hardening: every brand picker lists unapproved brands too (2026-10-03)
- [ ] Hardening: approval queue suggests unapproved twins and refuses "approve" for a same-key approved brand
- [x] Hardening: brand picker's client-side match compares the plain name (exact, else a single prefix match), not `includes()` on the option HTML (2026-10-03)
- [ ] Hardening: no brand may sit unapproved without anyone being asked (queue shows brands without a pending puzzle)
- [x] Internal API: delete an empty brand (`POST /internal-api/manufacturers/{id}/delete`, 2026-10-03)

## Image storage (bucket audit 2026-09-22)

Full scan of the bucket against every DB image column; fixes shipped in lily.srv (imgproxy source limit 30 → 60 MP,
images-cache re-resolves imgproxy on Docker DNS). Lists in `~/Downloads/msp-image-audit-2026-09-22/` on Jan's Mac,
raw scan on the box in `/root/bucket-scan.csv` + `/root/db-image-refs.tsv`.

- [ ] Result share images (`players/<id>/results/<id>.png`, 800×800): 490k objects ≈ 420 GB, one per solving time,
      **never deleted** - `GetResultImage` only redraws a card older than a month *when it is requested*, a card nobody
      opens again stays forever. ~5 % of them (≈ 23k, 141 in a 3,000 sample) still carry the finished photo's EXIF/GPS
      from before 2026-09-30 (new cards are clean), reachable via `/original/<key>` - the key is built from public ids.
      Agreed plan (Jan, 2026-09-30: later): 1) delete all existing cards (bulk DeleteObjects, minutes; each one is
      redrawn cleanly on its next request); 2) store new cards under their own prefix `results/<playerId>/<timeId>.png`
      (+ `-hidden`); 3) bucket lifecycle rule: expire `results/` after 35 days (Hetzner supports it - the API answers
      `NoSuchLifecycleConfiguration`, i.e. none set yet; a prefix filter cannot match the middle segment of today's
      `players/<id>/results/`, hence step 2). No cron needed afterwards.
- [ ] Handlers never delete the previous object when a puzzle image / finished photo / logo is replaced or the time is
      deleted - 6,862 orphans ≈ 16 GB today (`3-orphaned-objects-not-referenced.csv`); delete the old key in the handler
- [ ] One-off: delete the existing orphans after a spot check (avatars: `myspeedpuzzling:storage:delete-orphaned-avatars`,
      #213 - see [`features/account-deletion.md`](features/account-deletion.md); a deleted account's files now go automatically)
- [ ] Optional: downscale the 84 pre-`ImageOptimizer` originals above 30 MP to 2,000 px like today's uploads
      (`2-oversized-originals-over-30mp.csv`); not needed for serving since the limit covers them
- [x] A large imgproxy preset - shipped 2026-09-30: `puzzle_large=rs:fit:1200:1200/eth:0/f:jpg` (lily.srv
      `IMGPROXY_PRESETS` + local `compose.yml`; JPEG pinned so every link previewer takes it and the bytes never
      depend on `Accept`, `eth:0` = the full HEIC instead of its embedded ~320 px thumbnail). Used by the image
      sitemap, the puzzle `og:image` + Product JSON-LD, the event/edition/series JSON-LD `image` and the photo
      links (puzzle page, own finished photos) - no public page links a raw original (`/original/…`) any more;
      admin review pages still do on purpose
- [x] Stored originals with EXIF/GPS - stripped 2026-09-30: `/original/<key>` serves the raw file to anyone who
      knows the key (it is in every thumbnail URL), and 21,658 of 112,548 originals carried a GPS position. Lossless
      strip job (scripts removed afterwards, in git history at `ad0ddbf2`, `tools/image-metadata-strip/`): 66,710 objects stripped
      under the same keys + 308 already clean, every one verified (stored checksum, pixel compare of backup vs
      object, rendering of 3,002 keys through imgproxy). Work dir `/root/msp-exif-2026-09-30/` on the box
- [x] Backups deleted 2026-09-30 after the full verification plus an independent spot check (Jan: "check all photos
      are ok, then delete the backup") - no rollback any more; the per-object audit CSVs went too (camera/date details)
- [x] ~~Cloudflare prefix purge of `img.myspeedpuzzling.com/original/` + `img.myspeedpuzzling.com/puzzle/`~~ - skipped
      (Jan, 2026-09-30): old cached copies expire on their own (1-year TTL, rarely requested files are evicted much
      sooner, and no public page links an original any more); `cdn.*` and every imgproxy render are clean
- [ ] `puzzle_small`/`puzzle_medium` of a HEIC source are drawn from its embedded ~320 px thumbnail
      (`IMGPROXY_ENFORCE_THUMBNAIL=true`) - `puzzle_medium` (`el:1`) upscales it to 400 px; `eth:0` there too?

## Multiscan

Shipped 2026-09-22. Design and plan: [`features/multiscan/README.md`](features/multiscan/README.md).

- [ ] Aggregated lending notification ("Anna lent you 6 puzzles") instead of one per puzzle
- [x] Tray persistence across a reload (`sessionStorage` mirror + `restore()` action) - shipped 2026-09-28 with the required quick-add photo
- [ ] Native apps: a multi-mode scanner in the iOS/Android shells (today the single-shot native scanner is re-opened per code)
- [ ] More actions: mark solved without a time (shared date), list for swap/free, remove from library
- [ ] "Undo last batch" for lend/return
- [ ] Watch the numbers after a few weeks: not-found rate, links created (`puzzle_change_request` rows with `proposed_ean` only), quick-adds

## Getting started / newcomer onboarding

Shipped 2026-09-20 (`1f59d530`). Design, research and rules: [`features/getting-started-guide.md`](features/getting-started-guide.md).
Mirrored in [#212](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/212).

**Not built yet**

- [ ] First-time celebration: after the very first saved time, a small "Nice!" moment (reuse the picker's confetti) with
      links to *My statistics*, *Leaderboard* and *Finish my profile* - the best moment to introduce those features
- [ ] Empty states with one sentence + one button on the owner's own empty profile, collections and wishlist
      (only empty statistics has one today); link to the guide's anchors `#track`, `#library`
- [ ] Welcome e-mail with the same first steps + the guide link (today only the verification mail goes out)
- [ ] Edit profile: basic form (name, photo, country…) to the top, developer cards (tokens, applications) folded at the bottom
- [ ] `membership.full_description` is out of date: no Insights / picker filters, "coming soon" items that shipped

**Verify / decide**

- [ ] One real registration on production end to end: name → welcome screen → Hub card at "1 of 5" → guide, click
      *My statistics* and check the step gets its tick (the `mark-seen` beacon was never clicked in a real browser)
- [ ] Native-speaker read of the es / fr / de / ja texts (`onboarding.*`), Japanese first
- [ ] Should Statistics / Leaderboard ticks also count visits through the menu, not only a click from the guide?
      (costs a check on every view of those pages)
- [ ] Remove the NEW badges on "Getting started" and "Pairs & Teams" when they stop being new (no expiry built in)

**Measure**

- [ ] Compare activation (share of registrations with a solving time within 7 days) before vs after 2026-09-20 -
      SQL in the feature doc. If people stall at the fifth checklist step, drop "Add a favorite puzzler"
