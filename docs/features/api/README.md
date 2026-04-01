# API

## Overview

MySpeedPuzzling exposes a REST API (`/api/v1/`) for third-party integrations and personal use. The API supports two authentication methods:

- **Personal Access Tokens (PAT)** — self-service tokens for accessing your own data
- **OAuth2** — for third-party applications accessing user data with consent

The API is built with API Platform and documented via Swagger UI at `/api/docs`.

## Authentication

### Personal Access Tokens (PAT)

PATs are long-lived tokens for accessing your own data only. Any logged-in user can create multiple named PATs from their profile settings.

- **Format:** `msp_pat_` + 48 hex characters
- **Header:** `Authorization: Token msp_pat_...`
- **Access:** Own data only (`/api/v1/me/*` endpoints)
- **Cannot** access other players' data
- **No scopes** — full read/write access to own data
- **Fair Use Policy** must be accepted before generating
- **Management:** Create, revoke, and view usage in profile settings
- **Audit:** `last_used_at` tracked on every request

### OAuth2

Built on `league/oauth2-server-bundle`. Supports two flows:

**Authorization Code** (user-facing apps) — users authorize third-party apps to access their data with consent. Read and write access per granted scopes.

**Client Credentials** (service-to-service) — read-only access to any non-hidden player's public data. No user context.

### Scopes

| Scope | Description | Auth Code | Client Credentials |
|-------|-------------|-----------|-------------------|
| `profile:read` (default) | View profile info | Yes | Yes |
| `results:read` | View puzzle solving results | Yes | Yes |
| `statistics:read` | View solving statistics | Yes | Yes |
| `collections:read` | View puzzle collections | Yes | Yes |
| `solving-times:write` | Create and edit solving times | Yes | No |
| `collections:write` | Create, edit, delete collections and items | Yes | No |

### Token TTLs

- Access token: 1 hour (stateless JWT)
- Refresh token: 1 month
- Auth code: 10 minutes

## Endpoints

### "Me" Endpoints (PAT + OAuth2 with user context)

| Method | Endpoint | Required |
|--------|----------|----------|
| GET | `/api/v1/me` | PAT or `profile:read` |
| GET | `/api/v1/me/results?type=solo\|duo\|team` | PAT or `results:read` |
| GET | `/api/v1/me/statistics` | PAT or `statistics:read` |
| POST | `/api/v1/me/solving-times` | PAT or `solving-times:write` |
| PUT | `/api/v1/me/solving-times/{timeId}` | PAT or `solving-times:write` |
| GET | `/api/v1/me/collections` | PAT or `collections:read` |
| GET | `/api/v1/me/collections/{id}/items` | PAT or `collections:read` |
| POST | `/api/v1/me/collections` | PAT or `collections:write` (members only) |
| PUT | `/api/v1/me/collections/{id}` | PAT or `collections:write` (members only) |
| DELETE | `/api/v1/me/collections/{id}` | PAT or `collections:write` (members only) |
| POST | `/api/v1/me/collections/{id}/items` | PAT or `collections:write` |
| DELETE | `/api/v1/me/collections/{id}/items/{itemId}` | PAT or `collections:write` |

### Player Endpoints (OAuth2 only)

| Method | Endpoint | Scope |
|--------|----------|-------|
| GET | `/api/v1/players/{id}/results?type=solo\|duo\|team` | `results:read` |
| GET | `/api/v1/players/{id}/statistics` | `statistics:read` |
| GET | `/api/v1/players/{id}/collections` | `collections:read` (public only) |
| GET | `/api/v1/players/{id}/collections/{cid}/items` | `collections:read` (public only) |

### Collection Membership Gating

- **System collection** (`id=default`): All users can list/add/remove items
- **Custom collections**: Only members can create, edit, delete, and manage items
- API returns 403 when non-member attempts members-only collection operation

### Members-Exclusive Data

Puzzle difficulty and player skill tiers are included in responses only if the token owner has active membership. Non-members see `null` for these fields.

### POST `/api/v1/me/solving-times`

```json
{
    "puzzle_id": "uuid",
    "time": "1:23:45",
    "comment": "Optional comment",
    "finished_at": "2025-12-01T14:30:00+00:00",
    "first_attempt": true,
    "unboxed": false,
    "group_players": ["#PLAYER_CODE", "Guest Name"]
}
```

- `time` format: `HH:MM:SS` or `MM:SS`
- `group_players`: player codes prefixed with `#`, or plain names for unregistered players
- Photo uploads not supported via API (use the website)

### Privacy

- `/api/v1/me/*` always returns full data for the token owner
- `/api/v1/players/{id}/*` returns empty/zeroed data for private profiles (not 403)
- Hidden players are never returned in service-to-service queries

### Error Handling

- Missing/invalid/expired token: 401
- Missing scope: 403
- Non-existent player UUID: 404
- Membership required: 403 with message
- Validation error: 422
- Error format: `application/json` and `application/problem+json` (RFC 7807)

## OAuth2 Client Registration

### Self-Service Flow

1. User navigates to `/en/request-api-access` (linked from `/en/for-developers` and profile settings)
2. Fills in: app name, description, purpose, application type (confidential/public), scopes, redirect URIs
3. Accepts Fair Use Policy
4. Admin receives email notification about the new request
5. Admin reviews at `/admin/oauth2-requests` — approve or reject with reason
6. **On approval:** User receives email with a one-time credential claim link (valid 7 days)
7. User clicks link → sees client ID + secret once → saves them securely
8. Credentials are never shown again after claiming

### Credential Management

- Users can view their applications in profile settings ("My Applications" section)
- Approved apps can reset credentials (generates new secret, revokes all tokens, sends new claim link)

### CLI Client Management

```bash
php bin/console myspeedpuzzling:oauth2:create-client "App Name" app-identifier \
    --redirect-uri=https://example.com/callback \
    --grant-type=authorization_code \
    --grant-type=refresh_token \
    --scope=profile:read
```

Add `--public` for public clients (PKCE required, no secret).

```bash
php bin/console myspeedpuzzling:oauth2:list-clients
```

## Audit Trail

- **PAT:** `last_used_at` updated on every authenticated API request (in `PatAuthenticator`)
- **OAuth2:** `last_used_at` on `oauth2_user_consent` updated on API requests (throttled to every 5 minutes, in `ApiTokenUsageSubscriber`)
- Visible in profile settings for both PATs and connected applications

## Security Architecture

Three authenticators on the `api` firewall (`^/api/v1/`):
- `PatAuthenticator` — handles `Bearer msp_pat_*` tokens, creates `PatUser` with `ROLE_PAT`
- OAuth2 authenticator (from bundle) — handles JWT Bearer tokens, creates `OAuth2User` with scope-based roles

Both `PatUser` and `OAuth2User` implement the `ApiUser` interface (`getPlayer(): Player`).

Access control:
- `^/api/v1/me` → `IS_AUTHENTICATED_FULLY` (PAT or OAuth2)
- `^/api/v1/players/.*/results` → `ROLE_OAUTH2_RESULTS:READ`
- `^/api/v1/players/.*/statistics` → `ROLE_OAUTH2_STATISTICS:READ`
- `^/api/v1/players/.*/collections` → `ROLE_OAUTH2_COLLECTIONS:READ`

## Fair Use Policy

Page at `/en/fair-use-policy` — content placeholder (to be filled). Required acceptance for both PAT generation and OAuth2 client registration.

## CORS

Configured in `config/packages/nelmio_cors.php` with `allow_origin: ['*']` globally.

## Deprecated: V0 Legacy API

> **Deprecated** — Do not develop further. Kept for backward compatibility only.

`GET /api/v0/players/{playerId}/results?token=...` — effectively unauthenticated. Lives in `src/Controller/Api/V0/`.

## Internal APIs (not for public use)

### Stopwatch API (`/api/stopwatch/`)

Session-authenticated timer management for the web app's Stimulus controller.

### Mobile Billing (`/api/android/`, `/api/ios/`)

Stub endpoints for in-app purchase verification (not implemented).

## Environment Variables

| Variable | Description |
|----------|-------------|
| `OAUTH2_PRIVATE_KEY` | RSA private key for signing tokens |
| `OAUTH2_PUBLIC_KEY` | RSA public key for verifying tokens |
| `OAUTH2_PASSPHRASE` | Private key passphrase (empty in dev) |
| `OAUTH2_ENCRYPTION_KEY` | Encryption key for refresh tokens |

## Database Tables

| Table | Description |
|-------|-------------|
| `personal_access_token` | PAT storage (hashed tokens, audit trail) |
| `oauth2_client` | Registered OAuth2 clients |
| `oauth2_client_request` | OAuth2 client registration requests (pending/approved/rejected) |
| `oauth2_access_token` | Issued access tokens |
| `oauth2_authorization_code` | Short-lived auth codes (10 min) |
| `oauth2_refresh_token` | Refresh tokens (1 month) |
| `oauth2_user_consent` | User consent per client/scope with `last_used_at` tracking |

## Key Files

| File | Purpose |
|------|---------|
| `src/Security/ApiUser.php` | Shared interface for PAT and OAuth2 users |
| `src/Security/PatUser.php` | PAT user with `ROLE_PAT` |
| `src/Security/PatAuthenticator.php` | PAT token authenticator |
| `src/Security/OAuth2User.php` | OAuth2 user (implements `ApiUser`) |
| `src/Security/OAuth2UserProvider.php` | Loads Player by UUID from JWT |
| `src/Entity/PersonalAccessToken.php` | PAT entity (hashed token, audit fields) |
| `src/Entity/OAuth2/OAuth2ClientRequest.php` | Client registration request entity |
| `src/Entity/OAuth2/OAuth2UserConsent.php` | Consent entity with `lastUsedAt` |
| `src/Api/V1/` | All API Platform resources, providers, and processors |
| `src/Controller/OAuth2/RequestApiAccessController.php` | OAuth2 client registration form |
| `src/Controller/OAuth2/ClaimOAuth2CredentialsController.php` | One-time credential display |
| `src/Controller/Admin/OAuth2ClientRequests*.php` | Admin review pages |
| `src/EventSubscriber/ApiTokenUsageSubscriber.php` | OAuth2 usage tracking |
| `config/packages/security.php` | Firewalls and access control |
| `config/packages/league_oauth2_server.php` | OAuth2 config (scopes, grants, TTLs) |
| `config/packages/api_platform.php` | API Platform config and Swagger |
| `templates/oauth2/request-api-access.html.twig` | Client registration form |
| `templates/oauth2/claim-credentials.html.twig` | One-time credential display |
