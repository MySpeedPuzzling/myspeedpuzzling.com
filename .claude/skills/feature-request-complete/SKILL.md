---
name: feature-request-complete
description: Mark a MySpeedPuzzling feature request as completed after its PR has been merged — calls the internal API with the PR URL as githubUrl. Use when the user says "mark feature request X complete", "the PR for feature request X is merged", or provides a feature-request UUID plus a merged PR URL.
argument-hint: "<feature-request-uuid> <pr-url-or-number> [optional admin comment]"
allowed-tools: Bash, Read
---

# Feature Request — Mark Completed

Tiny skill. Runs after a human has reviewed and merged the PR opened by `/feature-request`. Flips the feature request status to `completed` on production and attaches the PR URL.

## Invocation

```
/feature-request-complete <uuid> <pr-url>
/feature-request-complete <uuid> 123                                          # PR number (expanded to full URL)
/feature-request-complete <uuid> <pr-url> "Shipped in v2.4, see release notes" # with admin comment
```

UUID: 8-4-4-4-12 hex. PR: either a full `https://github.com/MySpeedPuzzling/myspeedpuzzling.com/pull/<n>` URL, or just the number `<n>` (expand it).

## Steps

### 1. Validate & normalise inputs

```sh
UUID="$1"
PR_ARG="$2"
COMMENT="${3:-}"

# Validate UUID
echo "$UUID" | grep -Eq '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' \
  || { echo "Bad UUID: $UUID"; exit 1; }

# Expand bare PR number to full URL
case "$PR_ARG" in
  http*) PR_URL="$PR_ARG" ;;
  *)     PR_URL="https://github.com/MySpeedPuzzling/myspeedpuzzling.com/pull/$PR_ARG" ;;
esac
```

If either is malformed, stop and tell the user.

### 2. Verify the PR is actually merged (safety check)

`gh pr view` does NOT expose a boolean `merged` field — use `state` (`"MERGED"` when merged, `"OPEN"` / `"CLOSED"` otherwise) or `mergedAt` (ISO timestamp vs `null`):

```sh
STATE=$(gh pr view "$PR_URL" --json state --jq '.state')
```

- `MERGED` → proceed.
- `OPEN` / `CLOSED` → warn the user that the PR isn't merged yet and ask if they want to continue anyway (use `AskUserQuestion` or just stop and surface the state).
- Command fails (PR not found, wrong repo, no gh auth) → stop, surface the error.

### 3. Call the internal API

```sh
TOKEN=$(grep '^INTERNAL_API_TOKEN=' .env.local | cut -d= -f2)
[ -n "$TOKEN" ] || { echo "INTERNAL_API_TOKEN missing from .env.local"; exit 1; }

if [ -n "$COMMENT" ]; then
  BODY=$(printf '{"githubUrl": "%s", "adminComment": "%s"}' "$PR_URL" "$COMMENT")
else
  BODY=$(printf '{"githubUrl": "%s"}' "$PR_URL")
fi

STATUS=$(curl -sS -o /tmp/frc-resp.txt -w '%{http_code}' \
  -X POST "https://myspeedpuzzling.com/internal-api/feature-requests/$UUID/mark-completed" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d "$BODY")

case "$STATUS" in
  204) echo "Marked completed. PR: $PR_URL" ;;
  401) echo "Unauthorized — check INTERNAL_API_TOKEN on production"; exit 1 ;;
  404) echo "Feature request $UUID not found on production"; exit 1 ;;
  *)   echo "Unexpected HTTP $STATUS"; cat /tmp/frc-resp.txt; exit 1 ;;
esac
```

### 4. Confirm to the user

Report back: feature request URL + new status + PR URL. Also update the plan file if present — append a note that the request is shipped:

```sh
PLAN="/Users/janmikes/.claude/plans/feature-request-$UUID.md"
if [ -f "$PLAN" ]; then
  printf '\n- Status: completed on %s (PR merged)\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> "$PLAN"
fi
```

## Rules

- **Never** mark completed unless step 2 confirms the PR is merged (or the user explicitly overrides).
- **Never** log / print / commit the `INTERNAL_API_TOKEN`.
- Use production URL — this skill has no local/dev mode. Completion is a production-state transition.
- Do **not** close the GitHub issue manually — the PR body should have `Closes #<n>`, which GitHub handles automatically on merge.
- If the user invokes this skill without a PR URL, ask for it. Don't invent one.
