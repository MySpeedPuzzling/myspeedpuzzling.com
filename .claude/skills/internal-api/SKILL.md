---
name: internal-api
description: Call the MySpeedPuzzling internal admin API (mark feature requests in-progress/completed/declined, future admin endpoints). Use when the user asks to hit `/internal-api/*` directly, transition a feature request status without the full feature-request workflow, or talk about the internal API in general.
argument-hint: "<free-form description of the call, e.g. 'mark feature request <uuid> as declined'>"
allowed-tools: Bash, Read
---

# Internal API

Thin reference for calling the MySpeedPuzzling admin-only internal API. Higher-level skills (`/feature-request`, `/feature-request-complete`) already wrap the common cases — only use this skill directly for ad-hoc calls, one-offs (`mark-declined`), or new endpoints not yet wrapped.

Full spec: `docs/features/internal-api.md` and `docs/features/internal-api.openapi.yaml`.

## Config

| Env | Base URL |
|---|---|
| **Production** (default) | `https://myspeedpuzzling.com` |
| Local dev | `http://localhost:8080` |

**Default to production** unless the user explicitly says "local" / "dev" / "against my laptop". The token in `.env.local` is shared across both envs.

Load the token from the repo's `.env.local`:

```sh
TOKEN=$(grep '^INTERNAL_API_TOKEN=' .env.local | cut -d= -f2)
```

If `$TOKEN` is empty, stop and tell the user — the API is closed-by-default and will return 401.

## Endpoints

All endpoints are `POST`, return `204 No Content` on success, accept optional JSON body with `{ "githubUrl": "...", "adminComment": "..." }` — both fields optional, both strings.

| Path | Purpose |
|---|---|
| `/internal-api/feature-requests/{id}/mark-in-progress` | Transition feature request → `in_progress` |
| `/internal-api/feature-requests/{id}/mark-completed` | Transition feature request → `completed` |
| `/internal-api/feature-requests/{id}/mark-declined` | Transition feature request → `declined` |

## Invocation pattern

```sh
TOKEN=$(grep '^INTERNAL_API_TOKEN=' .env.local | cut -d= -f2)
BASE_URL="https://myspeedpuzzling.com"
UUID="019d222c-adb5-72ac-9eac-c327e95ad1ba"
ACTION="mark-declined"   # or mark-in-progress / mark-completed

STATUS=$(curl -sS -o /tmp/internal-api-resp.txt -w '%{http_code}' \
  -X POST "$BASE_URL/internal-api/feature-requests/$UUID/$ACTION" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"adminComment": "Duplicate of #42"}')

echo "HTTP $STATUS"
cat /tmp/internal-api-resp.txt
```

Body fields are **all optional** — send `-d '{}'` or drop the body+header entirely for a bare status change.

## Response codes

| Status | Meaning | What to do |
|---|---|---|
| `204` | Success | Stop, confirm to user. Body is empty. |
| `400` | Body present but not valid JSON | Check your `-d '...'` payload. |
| `401` | Token missing / wrong / `INTERNAL_API_TOKEN` unset on server | Stop. Don't retry. Tell user to check the env var on the target server. |
| `404` | Unknown UUID | Stop. Verify the UUID on the feature request page. |

## Rules

- **Never** hardcode the token into a file or commit message — always read from `.env.local` at call time.
- **Never** log the token or print it back to the user. `echo $TOKEN` is off-limits.
- **Default to production.** Local is only for testing the internal-api feature itself.
- **Don't** retry on 401/404 — those are permanent.
- The auth header is `Authorization: Bearer $TOKEN` (not `Token`, not `ApiKey`).

## Adding a new endpoint

When a new `/internal-api/*` endpoint ships, update this skill's **Endpoints** table and adjust the `ACTION` in the invocation pattern. No auth changes — the firewall covers the whole prefix.
