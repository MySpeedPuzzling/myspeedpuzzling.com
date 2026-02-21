# OAuth2 Server

## Overview

MySpeedPuzzling acts as an OAuth2 authorization server, allowing third-party applications to authenticate users and access their data with consent. Built on `league/oauth2-server-bundle`.

## Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/oauth2/authorize` | GET, POST | Authorization screen (requires authenticated user) |
| `/oauth2/token` | POST | Exchange auth code for tokens / refresh tokens |

## Scopes

| Scope | Default | Description |
|-------|---------|-------------|
| `profile:read` | Yes | View profile info (name, avatar, country, city, bio) |
| `results:read` | No | View puzzle solving results |
| `statistics:read` | No | View solving statistics |
| `collections:read` | No | View puzzle collections |

## Grants

| Grant | Enabled | Notes |
|-------|---------|-------|
| `authorization_code` | Yes | Primary flow for user-facing apps |
| `client_credentials` | Yes | Machine-to-machine |
| `refresh_token` | Yes | Refresh expired access tokens |
| `password` | No | Disabled |
| `implicit` | No | Disabled |

## Token TTLs

- Access token: 1 hour
- Refresh token: 1 month
- Auth code: 10 minutes

## Client Types

**Confidential** (default) - has a client secret, no PKCE required.

**Public** (created with `--public`) - no secret, PKCE (S256) required. Used for mobile apps or SPAs where the secret can't be kept safe.

## API Endpoints (Resource Server)

All endpoints require `Authorization: Bearer {access_token}` header.

### `GET /api/v1/me`

Scope: `profile:read`

```json
{
    "id": "uuid",
    "name": "Player Name",
    "code": "player-code",
    "avatar": "https://img.myspeedpuzzling.com/...",
    "country": "CZ",
    "city": "Prague",
    "bio": "Bio text",
    "facebook": "handle",
    "instagram": "handle",
    "is_private": false,
    "has_active_membership": true
}
```

### `GET /api/v1/players/{playerId}/results`

Scope: `results:read`

Query params: `type` = `solo` (default) | `duo` | `team`

```json
{
    "player_id": "uuid",
    "type": "solo",
    "count": 42,
    "results": [
        {
            "time_id": "uuid",
            "puzzle_id": "uuid",
            "puzzle_name": "Puzzle Name",
            "manufacturer_name": "Ravensburger",
            "pieces_count": 1000,
            "time_seconds": 3600,
            "finished_at": "2025-12-01T14:30:00+00:00",
            "first_attempt": true,
            "puzzle_image": "image-path",
            "comment": "Optional comment"
        }
    ]
}
```

### `GET /api/v1/players/{playerId}/statistics`

Scope: `statistics:read`

```json
{
    "player_id": "uuid",
    "solo": {
        "total_seconds": 36000,
        "total_pieces": 15000,
        "solved_puzzles_count": 42
    },
    "duo": { "..." },
    "team": { "..." }
}
```

## Authorization Flow

1. Client redirects user to `/oauth2/authorize?client_id=...&response_type=code&redirect_uri=...&scope=profile:read&state=...`
2. If user is not logged in, `Auth0EntryPoint` saves the OAuth2 URL in a cookie and redirects to Auth0 login
3. After Auth0 login, `Auth0RedirectSubscriber` reads the cookie and redirects back to `/oauth2/authorize`
4. `OAuth2AuthorizationSubscriber` handles consent:
   - If user previously consented to all requested scopes, auto-approves
   - Otherwise shows consent screen (`templates/oauth2/consent.html.twig`)
   - User clicks Authorize or Deny
   - Consent is persisted in `oauth2_user_consent` table
5. User is redirected back to client's `redirect_uri` with `?code=...&state=...`
6. Client exchanges code for tokens via `POST /oauth2/token`

## Security Architecture

Two separate firewalls in `config/packages/security.php`:

- **`main`** (`^/`) - Auth0 authentication for web users (session-based)
- **`api`** (`^/api/v1/`) - OAuth2 Bearer token authentication (stateless)

Scope-to-role mapping (automatic by bundle):
- `profile:read` -> `ROLE_OAUTH2_PROFILE:READ`
- `results:read` -> `ROLE_OAUTH2_RESULTS:READ`
- `statistics:read` -> `ROLE_OAUTH2_STATISTICS:READ`

## Client Management

### Create a client

```bash
php bin/console myspeedpuzzling:oauth2:create-client "App Name" app-identifier \
    --redirect-uri=https://example.com/callback \
    --grant-type=authorization_code \
    --grant-type=refresh_token \
    --scope=profile:read
```

Add `--public` for public clients (PKCE required, no secret).

### List clients

```bash
php bin/console myspeedpuzzling:oauth2:list-clients
```

## Key Files

| File | Purpose |
|------|---------|
| `config/packages/league_oauth2_server.php` | Bundle config (scopes, grants, TTLs, keys) |
| `config/packages/security.php` | Firewalls and access control rules |
| `config/routes.php` | Route registration (authorize + token) |
| `src/Controller/OAuth2/AuthorizationController.php` | Custom authorize endpoint (requires auth) |
| `src/EventSubscriber/OAuth2AuthorizationSubscriber.php` | Consent logic |
| `src/Security/OAuth2User.php` | User wrapper for OAuth2 context |
| `src/Security/OAuth2UserProvider.php` | Loads Player by UUID from token |
| `src/Security/Auth0EntryPoint.php` | Redirects unauthenticated users to Auth0 |
| `src/EventSubscriber/Auth0RedirectSubscriber.php` | Redirects back after Auth0 login |
| `src/Entity/OAuth2/OAuth2UserConsent.php` | Consent persistence entity |
| `templates/oauth2/consent.html.twig` | Consent screen template |
| `src/Controller/Api/V1/GetCurrentUserController.php` | `/api/v1/me` |
| `src/Controller/Api/V1/GetPlayerResultsController.php` | `/api/v1/players/{id}/results` |
| `src/Controller/Api/V1/GetPlayerStatisticsController.php` | `/api/v1/players/{id}/statistics` |
| `src/ConsoleCommands/OAuth2CreateClientConsoleCommand.php` | Create client CLI |
| `src/ConsoleCommands/OAuth2ListClientsConsoleCommand.php` | List clients CLI |

## Environment Variables

| Variable | Description |
|----------|-------------|
| `OAUTH2_PRIVATE_KEY` | RSA private key for signing tokens |
| `OAUTH2_PUBLIC_KEY` | RSA public key for verifying tokens |
| `OAUTH2_PASSPHRASE` | Private key passphrase (empty in dev) |
| `OAUTH2_ENCRYPTION_KEY` | Encryption key for refresh tokens |

## Database Tables

All prefixed with `oauth2_`:

| Table | Description |
|-------|-------------|
| `oauth2_client` | Registered OAuth2 clients |
| `oauth2_access_token` | Issued access tokens |
| `oauth2_authorization_code` | Short-lived auth codes (10 min) |
| `oauth2_refresh_token` | Refresh tokens (1 month) |
| `oauth2_user_consent` | Custom - tracks user consent per client/scope |
