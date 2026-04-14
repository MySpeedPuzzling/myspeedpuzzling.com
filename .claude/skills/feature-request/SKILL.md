---
name: feature-request
description: Start implementing a MySpeedPuzzling feature request end-to-end — fetch the request from production, open a GitHub issue, mark it in_progress via the internal API, then plan the work and pause for review before implementing. Use when the user pastes a feature request UUID or says "work on feature request X" / "implement feature request X".
argument-hint: "<feature-request-uuid> [guidance:...] [comment:...] | <uuid> continue"
allowed-tools: Bash, Read, Write, Edit, Glob, Grep, Agent, AskUserQuestion, Skill
---

# Feature Request Workflow

Drives the full loop from a feature request UUID to an open PR. Two phases with a **mandatory human checkpoint** between them:

- **Phase A** (first invocation): fetch → clarify → open GitHub issue → mark `in_progress` → write plan → **STOP** for review.
- **Phase B** (after user replies `continue`): branch → implement → run all checks → commit → push → open PR.

## Invocation

```
/feature-request <uuid>                                           # Phase A, no extras
/feature-request <uuid> guidance: <private dev hints for Claude>  # Phase A + guidance
/feature-request <uuid> comment: <public admin comment>           # Phase A + user-visible comment
/feature-request <uuid> guidance: <...> comment: <...>            # Phase A + both
/feature-request <uuid> continue                                  # Phase B (resume)
continue                                                          # same session resume
```

UUID pattern: 8-4-4-4-12 hex, e.g. `019d222c-adb5-72ac-9eac-c327e95ad1ba`.

### Two distinct optional fields

**Guidance** and **comment** are *different things* — don't conflate them:

| Field | Audience | Where it goes | Example |
|---|---|---|---|
| `guidance:` | Claude only (private) | Feeds planning + implementation. Never leaves the chat. | `guidance: use a string-backed enum, mirror MarketplaceListingCondition` |
| `comment:` | End users (public) | Sent as `adminComment` to `/internal-api/.../mark-in-progress`, shown on the feature request page as an admin note. | `comment: Looking into this, will ship as part of the marketplace v2 rollout` |

Both are optional. Either or both can be provided. Order between them doesn't matter — parse by keyword prefix.

### Parsing `$ARGUMENTS`

The first whitespace-separated token must be the UUID. The rest is free-form with optional `guidance:` and `comment:` markers:

- If you see `guidance:` — everything after it up to `comment:` (if present) or end-of-input is the guidance.
- If you see `comment:` — everything after it up to `guidance:` (if present) or end-of-input is the comment.
- If neither marker is present but there is extra text, treat the whole tail as `guidance:` (backwards-compatible with plain "extra context") and **do not** send it as `adminComment`.
- If `$ARGUMENTS` is exactly `<uuid> continue` (or ends in ` continue`), it's Phase B — guidance/comment markers aren't applicable.

Quotes around values are optional; trim whitespace. Multiline values are fine if the shell permits it.

## Phase detection

1. Check `$ARGUMENTS` — if it contains the literal word `continue` (as a standalone token), or if the user's recent turn says "continue"/"proceed"/"go ahead" and a plan file for this UUID exists → **Phase B**.
2. Otherwise → **Phase A**.

Plan file location: `/Users/janmikes/.claude/plans/feature-request-<uuid>.md`.

---

## Phase A — fetch, clarify, issue, mark, plan, stop

### 1. Fetch & parse the feature request

```sh
UUID="<uuid-from-args>"
curl -sS "https://myspeedpuzzling.com/en/feature-requests/$UUID" -o /tmp/fr.html
```

Extract title and description:

```sh
# Title: content of <title>, strip " - Feature Requests | MySpeedPuzzling" suffix
grep -o '<title>[^<]*</title>' /tmp/fr.html | sed 's/<title>\(.*\) - Feature Requests | MySpeedPuzzling<\/title>/\1/'

# Description: <meta name="description" content="...">
grep -o '<meta name="description" content="[^"]*"' /tmp/fr.html | sed 's/<meta name="description" content="\(.*\)"/\1/'
```

If either extraction returns empty, stop and tell the user the page couldn't be parsed (likely 404 or layout changed).

Show the user the parsed title + description, plus any **`guidance:`** they passed (if any) — the **`comment:`** is queued for the API call in step 4 and doesn't need to be echoed back.

### 2. Clarify requirements (most important step)

Using `AskUserQuestion`, surface every ambiguity you can think of. Typical categories:

- **Scope** — what counts as done? (UI only? API too? docs?)
- **Data model changes** — new enum values, columns, migrations; what about existing rows?
- **UX details** — ordering of new options in dropdowns, default values, copy/translations
- **Edge cases** — empty states, backwards compat, users with the old value in their profile
- **Testing** — any specific scenarios the user wants covered?

**Do not skip this.** A bad spec costs hours later. If the request is totally clear (rare), state that and ask one confirm question to be sure.

**Do not create the issue or call the API until clarifications are resolved.** If the user says the request is unclear or needs discussion with other people, stop here — don't mark in-progress prematurely.

### 3. Open the GitHub issue

```sh
REPO="MySpeedPuzzling/myspeedpuzzling.com"
FR_URL="https://myspeedpuzzling.com/en/feature-requests/$UUID"

ISSUE_URL=$(gh issue create --repo "$REPO" \
  --title "<feature-request-title>" \
  --body "$(cat <<'EOF'
<2–4 sentence brief problem statement based on the clarified spec>

Linked feature request: $FR_URL
EOF
)")
echo "Issue: $ISSUE_URL"
```

**Rules**:
- **No Claude Code mention.** No `🤖 Generated with`, no `Co-Authored-By` trailer.
- Title can be lightly edited from the feature request title for clarity, but keep it faithful.
- Body: brief problem statement (not a full spec dump) + the feature-request link. Clarifications go into the plan, not the issue.
- Capture `ISSUE_URL` for the next step.

### 4. Mark feature request `in_progress`

Use the `/internal-api` skill conventions. The request body must always include `githubUrl`. If the user provided a `comment:` in `$ARGUMENTS`, include it as `adminComment` (JSON-encode it to handle quotes/newlines safely — use `jq` or Python, not `printf`):

```sh
TOKEN=$(grep '^INTERNAL_API_TOKEN=' .env.local | cut -d= -f2)
[ -n "$TOKEN" ] || { echo "INTERNAL_API_TOKEN missing from .env.local"; exit 1; }

# Build JSON body safely via python (handles quotes, newlines, unicode)
if [ -n "$USER_COMMENT" ]; then
  BODY=$(python3 -c 'import json,sys; print(json.dumps({"githubUrl": sys.argv[1], "adminComment": sys.argv[2]}))' "$ISSUE_URL" "$USER_COMMENT")
else
  BODY=$(python3 -c 'import json,sys; print(json.dumps({"githubUrl": sys.argv[1]}))' "$ISSUE_URL")
fi

STATUS=$(curl -sS -o /tmp/mip-resp.txt -w '%{http_code}' \
  -X POST "https://myspeedpuzzling.com/internal-api/feature-requests/$UUID/mark-in-progress" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d "$BODY")

[ "$STATUS" = "204" ] || { echo "FAILED: HTTP $STATUS"; cat /tmp/mip-resp.txt; exit 1; }
```

`$USER_COMMENT` comes from the `comment:` marker parsed in the invocation section above. `$GUIDANCE` is **not** sent here — it stays in the planning context only.

If non-204, stop and surface the error. Do **not** proceed to planning — the state on production is now inconsistent and needs fixing first.

### 5. Deep plan

Delegate to the existing `feature-dev` agents (they already know how to explore + architect):

- **1–3 `feature-dev:code-explorer` agents in parallel** — each targeting a different slice of the codebase relevant to the change (similar features, domain entities, templates/UI, message handlers, tests).
- **1 `feature-dev:code-architect` agent** (after explorers return) — receive all exploration findings, the **clarified spec + `$GUIDANCE` verbatim**, and project conventions; produce a concrete build sequence. `$GUIDANCE` is a direct instruction from the project owner — prefer it over default choices when they conflict.

Reference `/Users/janmikes/www/speedpuzzling.cz/CLAUDE.md` for project rules you must follow in the plan:

- CQRS: controllers/console commands dispatch messages, handlers contain logic
- Repositories `persist()` only, never `flush()` (Messenger middleware handles it)
- `ClockInterface` instead of `new \DateTimeImmutable()`
- Single-action `__invoke` controllers
- `Uuid::uuid7()` for new IDs
- New features English-only unless translation is explicitly requested
- Services with instance state implement `ResetInterface` (FrankenPHP worker mode)
- Never write migrations manually — always generate via `docker compose exec web php bin/console make:migration`
- When renaming tables, update raw SQL in `src/Query/`
- Test **services/handlers**, not controllers or console commands directly (per project preference)
- Log exceptions with full object: `'exception' => $e`

Write the plan to `/Users/janmikes/.claude/plans/feature-request-<uuid>.md` with this structure:

```markdown
# Feature Request: <title>

- UUID: <uuid>
- Feature request: https://myspeedpuzzling.com/en/feature-requests/<uuid>
- GitHub issue: <issue-url>
- Status: planned — awaiting user `continue`

## Context & clarified spec
<bulleted clarified requirements, including the Q&A from step 2>

## Approach
<1–2 paragraphs on the architectural choice>

## Files to modify/create
| Path | Change |
|---|---|
| ... | ... |

## Build sequence
1. ...
2. ...

## Tests
- <handler test path>: <scenarios>
- <other test path>: <scenarios>

## Verification
- `docker compose exec web composer run phpstan`
- `docker compose exec web composer run cs-fix`
- `docker compose exec web vendor/bin/phpunit --exclude-group panther`
- `docker compose exec web php bin/console doctrine:schema:validate`
- `docker compose exec web php bin/console cache:warmup`
- Manual: <what to click/test in the browser>

## Branch & PR
- Branch: `feature/<short-slug>`
- PR title: `<title>`
- PR body: `Closes #<n>` + `Linked feature request: <fr-url>` + test plan
```

### 6. STOP

Summarise the plan inline to the user and say exactly:

> Plan written to `/Users/janmikes/.claude/plans/feature-request-<uuid>.md`. Issue `<issue-url>` created and feature request marked `in_progress`. **Reply `continue` (or `/feature-request <uuid> continue`) when you're ready for me to implement.** Edit the plan file first if you want changes.

End your turn. Do **not** start implementing.

---

## Phase B — implement, check, commit, push, PR

Only enter this phase when the user has said `continue` (or equivalent) AND the plan file exists. Otherwise re-enter Phase A.

### 1. Load the plan

```sh
cat /Users/janmikes/.claude/plans/feature-request-<uuid>.md
```

If missing, tell the user and stop — don't guess.

### 2. Branch

Derive a short kebab-case slug from the issue title. Make sure you're on `main` and up-to-date before branching:

```sh
git fetch origin main
git checkout -B feature/<slug> origin/main
```

### 3. Implement

Follow the plan's **Files** and **Build sequence** exactly. Key reminders:

- Keep changes scoped to what the plan says. No opportunistic refactors.
- Tests live alongside the code they verify and target handlers/services, not controllers or commands.
- If you need a migration: `docker compose exec web php bin/console make:migration` — never hand-write one, unless the plan specifically called for a custom index (then see `CLAUDE.md` §Custom Database Indexes).
- If the change touches templates or the service worker: remember the notes in `CLAUDE.md` (docker restart for templates; bump `CACHE_VERSION` for service-worker changes).

### 4. Run all mandatory checks

Run sequentially; fix anything red before continuing:

```sh
docker compose exec web composer run phpstan
docker compose exec web composer run cs-fix
docker compose exec web vendor/bin/phpunit --exclude-group panther
docker compose exec web php bin/console doctrine:schema:validate
docker compose exec web php bin/console cache:warmup
```

### 5. Code-review sweep (required, before commit)

Launch the `feature-dev:code-reviewer` agent on the diff. This is a **hard gate**, not an optional step — every feature-request PR goes through it.

Brief it like a smart colleague, giving it enough context to make judgment calls:

```
Review the uncommitted changes on this branch (`git diff origin/main...HEAD` plus any
unstaged work) against the feature spec and project conventions.

Feature request: <uuid> — <title>
GitHub issue: <issue-url>
Plan file: /Users/janmikes/.claude/plans/feature-request-<uuid>.md (read it first)

Focus areas, in order of weight:

1. **Clean architecture** — does the change respect CQRS (controllers/console commands
   dispatch Messenger messages, handlers own the logic)? Are repositories only calling
   persist() (never flush())? Are single-action __invoke controllers used? Is
   ClockInterface used instead of new \DateTimeImmutable? Is Uuid::uuid7() used for new
   IDs? Are stateful services implementing ResetInterface (FrankenPHP worker mode)?

2. **Testability & test coverage** — is the business logic in handlers/services
   directly testable without the HTTP layer or console commands? Are tests actually
   testing the handler/service, NOT the controller or command (project preference)?
   Do the added tests cover the happy path AND the edge cases called out in the plan's
   clarifications? Any untested branches that should have coverage?

3. **Security** — any user input flowing unvalidated into SQL/templates/shell? Any
   authorization gaps (who can call this, is the role/scope right)? Any secrets
   logged or echoed? Any exception paths that leak internal info?

4. **Best practices / project conventions** — CLAUDE.md rules: English-only for new
   features, translations where required, exception logging with full object
   ('exception' => $e), no backwards-compat shims, no error handling for impossible
   cases, minimal comments (only when the WHY is non-obvious). No premature
   abstraction.

Report only issues of real consequence (confidence-based filtering). For each:
file:line, severity (blocker / should-fix / nit), what's wrong, suggested fix.
```

**Handle the feedback**:

- **Blockers & should-fix** — fix them, then re-run the mandatory checks (step 4) again before moving on. If the reviewer contradicts the plan, flag it and ask the user rather than picking a side silently.
- **Nits** — judgement call; fix when trivial, ignore when disagreeing.
- **Clean review** — proceed to commit.

Do not skip this step, even when the diff feels tiny. A one-line change with a security hole still needs a security look.

### 6. Commit

Look at recent commit messages with `git log --oneline -10` to match style. Default pattern (matches existing history):

```
<Concise change> (#<issue-number>)
```

Use a HEREDOC for the body when needed. **No `Co-Authored-By: Claude` trailer, no `🤖 Generated with Claude Code` — the user prefers clean history without Claude attribution in this repo's feature-request flow.**

```sh
git add <specific files>
git commit -m "$(cat <<'EOF'
<subject line>

<optional body>
EOF
)"
```

### 7. Push

```sh
git push -u origin HEAD
```

### 8. Open the PR

```sh
PR_URL=$(gh pr create --base main \
  --title "<issue-title>" \
  --body "$(cat <<EOF
## Summary
- <1–3 bullets>

Closes #<issue-number>

Linked feature request: https://myspeedpuzzling.com/en/feature-requests/$UUID

## Test plan
- [ ] <manual check>
- [ ] <another check>
EOF
)")
echo "PR: $PR_URL"
```

Again — **no Claude Code trailer in the PR body**.

### 9. Update the plan file and stop

Append to the plan file:

```markdown

## Outcome
- Branch: feature/<slug>
- PR: <PR_URL>
- Status: awaiting review — use `/feature-request-complete <uuid> <PR_URL>` after merge.
```

Report the PR URL and branch to the user. End your turn. **Do NOT call `mark-completed`** — that happens after a human merges the PR, via the `/feature-request-complete` skill.

---

## Rules (apply to both phases)

- **Never** commit the `INTERNAL_API_TOKEN` or print it.
- **Never** mention Claude Code in issues, PRs, or commits for this repo's feature-request flow.
- **Never** mark `completed` from this skill — it's a hard boundary.
- If the internal API returns non-204 at any point, stop and tell the user. Don't auto-retry.
- If `gh` auth is missing (`gh auth status` fails), stop and ask the user to run `gh auth login`.
- Use the production URL `https://myspeedpuzzling.com` — never `localhost` — unless the user explicitly asks for a local dry run.
