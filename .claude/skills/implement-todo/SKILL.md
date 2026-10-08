---
name: implement-todo
description: Take one open item from docs/TODO.md through to production in one session - pick it, research it, brainstorm and refine it with Jan, then implement, ship, verify the deploy and close it out (TODO tick, docs, player follow-up drafts). Only when the user types /implement-todo.
argument-hint: "[item words or section] [-- Jan's instructions]"
disable-model-invocation: true
---

# Implement a TODO item

One item per session, start to finish, in this order:

**pick → claim → research → refine (brainstorm) → Jan's answers → implement → ship → verify → close**

Jan runs `/clear` before each invocation, so this session starts empty - keep it lean. Everything in `CLAUDE.md`
and the memory index applies; this skill only adds the loop around it.

## Arguments

`$ARGUMENTS` = `[selector] [-- instructions]`

- **selector** (before ` -- `): words from the item or its section heading (`bulk move`, `Seating`). Empty = propose.
- **instructions** (after ` -- `): Jan's direction for the brainstorm. They outrank your own preferences - build the
  options around them, don't re-argue them.

Examples: `/implement-todo` · `/implement-todo bulk move` · `/implement-todo bulk move -- checkboxes on the cards, no new page`

## 1. Pick

`docs/TODO.md` is long: start with `grep -n '^## \|^- \[ \]' docs/TODO.md`, then read only the sections you need.

- **Selector given:** find the matching unticked item. Several matches → AskUserQuestion. Ticked or marked
  `(in progress …)` → tell Jan and stop.
- **No selector:** skip items that are ticked, marked `(in progress …)`, time-gated (a future date, "after a few
  weeks", "measure …"), waiting on a person ("Jan's call", "ask …", "pending … answer"), or manual device QA. Rank
  the rest:
  1. promises to players ("Inbox promises", an MSP follow-up `F…`)
  2. bugs and data correctness
  3. small hardening and UX gaps
  4. performance
  5. ideas

  Prefer what fits one session. Offer the top 3 with AskUserQuestion, your recommendation first, one line each:
  what, why now, rough size.

## 2. Claim

Append ` (in progress YYYY-MM-DD)` to the first line of the item in `docs/TODO.md`. **Do not stage or commit it**:
the uncommitted mark is how parallel sessions in this shared checkout see the item is taken. Remove it if the item is
dropped; ticking it replaces it.

## 3. Research - delegated

Keep file dumps out of this session: send one or two agents in parallel (`Explore` for code, `general-purpose` when
it needs commands), each with a precise brief, and take back conclusions with `file:line` pointers. Read yourself only
the code you are about to change.

- **Code:** where the behaviour lives, the current logic, the linked feature doc, the related tests and fixtures
  (`.claude/fixtures.md`), and `git log -S` for when and why it became this way.
- **Numbers**, when they decide scope (how many rows/players are affected): read-only `SELECT`s on production
  (memory `reference_production_access`). Never write.
- **People**, when the item names a promise (MSP `#…`, follow-up `F…`): `msp-mail followup show F…` and
  `msp-mail thread "<anchor>"` - what they actually asked and what Jan promised.
- The memory index entry of the feature, if there is one.

## 4. Refine and brainstorm

Write the definition in the chat, short:

- **What's wrong or missing today** - with `file:line`.
- **Numbers**, if any.
- **Definition of done** - numbered, observable behaviour, then the rollout list: queries (budget), translations (all
  6 locales), migration (generated, never hand-written), cron row on lily, feature flag (`feature_flags.md`), API
  (additive only), docs.
- **Out of scope** - and that it goes to TODO.
- **Related bugs found on the way** in the same code - offer to include them.
- **Size:**
  - **S** - a few files, no open UX or schema decision
  - **M** - a schema change, a UX choice, or many files
  - **L** - a new surface, several decisions, or unclear

Then ask the real decisions with AskUserQuestion: at most 4, recommended option first. Conventional defaults are
stated, not asked. **Never start implementing before Jan has answered.**

How deep the brainstorm goes, by size - it is always implemented in this session afterwards:

- **S:** one round of questions; the answers are the approval → implement.
- **M:** after the answers, write the plan (files, build sequence, tests) into the feature doc
  (`docs/features/<feature>/`), show a short summary, wait for Jan's OK → implement.
- **L:** brainstorm first - 2-3 approaches with trade-offs and a recommendation, iterate with Jan until it is settled,
  write the design of record into `docs/features/<feature>/`, split it into phases. Implement phase 1 now; the other
  phases go to TODO as their own items.

Already done or obsolete? Check it, tick it (or delete it) with the reason, and offer the next candidate.

## 5. Implement

- Match the surrounding code: naming, comment density, existing helpers.
- Tests next to the existing ones of the feature: component tests (`InteractsWithLiveComponents`), handler, query and
  service tests. Seed through the message bus like the forms do, then adjust flags/dates with SQL when an integrity
  rule is in the way. Assert on ids you created rather than on counts that depend on fixtures.
- Gates, in this order:
  1. `docker compose exec web php bin/console cache:warmup` - after pulling `main`, PHPStan otherwise reports dozens
     of "is not registered in the container" errors
  2. `composer run cs-fix`
  3. `composer run phpstan`
  4. `vendor/bin/paratest --testsuite "Project Test Suite"` - run it in the background
  5. `doctrine:schema:validate` when entities changed
- Every new translation key goes into all 6 locales.

## 6. Ship and verify

1. `git fetch`, then:
   - `git branch --show-current` must be `main`
   - `git log --oneline origin/main..main` must show only your commits - and `TODO: …` commits, which the
     msp-mailer's `todo-add` makes when it could not push; they ride along with yours
   - behind `origin/main` → `git pull --ff-only` and rerun the affected tests
2. Commit only your own paths, written out literally - zsh does not split a `$FILES` variable into words:
   `git add -- a b c && git commit -F - -- a b c`. The message says what and why, and ends with the attribution line.
   Never `git add -A`.
3. Push `main`. Find the CI run by commit (`gh run list --branch main --json databaseId,headSha,status,conclusion`)
   and wait for it with a background until-loop on `gh run view <id> --json status,conclusion` - never a
   foreground `sleep`.
4. On lily (`ssh -o IdentitiesOnly=yes -i ~/.ssh/id_rsa root@lily.srv.thedevs.cz`):
   - Blue-green rollout: wait until the old `myspeedpuzzling-web-*` containers are gone.
   - Confirm the new ones carry the change: `docker exec <container> grep -c <new symbol> /app/src/…`.
   - A migration in the change: check it applied (the web container migrates on boot).
5. Smoke test from inside a web container, never from outside (the bot-blocker):
   `curl -s -H "Host: myspeedpuzzling.com" -H "X-Forwarded-Proto: https" "http://localhost:8080/<path>"`.
   Then `docker logs --since 15m <container>` filtered for errors of the changed code.
6. Signed-in or members-only behaviour cannot be smoke-tested that way - say so in the report instead of claiming it.

## 7. Close

- `docs/TODO.md`: tick the item (`[x]`, date, link to the doc) - that also removes the claim mark. Loose ends found
  on the way go in as new unticked items in the right section. The tick rides in the same commit as the change.
- Feature doc, and the `CLAUDE.md` feature entry when the behaviour changed in a way the next session must know.
- **Player promises** - an item naming `MSP #…` / `follow-up F…` (the msp-mailer adds those with `todo-add` when a
  reply promises a change), only once it is **deployed**: draft the delivery mail with the `msp-mailer` skill, one
  per follow-up the item names:
  1. `msp-mail followup show F…` - still open? (Already done or cancelled → nothing to draft, say so.) It names the
     conversation; `msp-mail thread "<anchor>"` gives its last message ref and what Jan wrote them last.
  2. `msp-mail reply <last message ref> --resolves F… --context "<item, commit, what was verified>"` - plus
     `--again` when Jan mailed them in the last 14 days, with the reason in the context. Short, plain, in their
     language: what they can do now and where to find it, nothing about how it was built. Several items shipped
     for one person → one mail resolving all their F-ids.
  3. The draft id goes into the report; Jan approves and sends.
- Cron rows in `~/www/lily.srv` yourself when the change needs one.
- Memory only for a lesson that is not obvious from the code or the docs.
- **Report to Jan:**
  - what shipped (commit)
  - what was verified on production and what was not
  - drafts to approve (links)
  - the next candidate

  End with: **Next: `/clear`, then `/implement-todo`.**
