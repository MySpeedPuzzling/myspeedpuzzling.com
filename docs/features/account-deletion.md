# Account deletion ("Delete my account")

Self-service, e-mail-confirmed, permanent account deletion. Built 2026-08-18.

## User flow

1. **Edit profile → "Danger zone"** (bottom of `/edit-profile`). Friendly copy,
   two CTAs: *Export my data first* (→ existing `export_puzzler_data` page) and
   *E-mail me the confirmation link* (POST `request_account_deletion`).
2. **Confirmation e-mail** (`emails/account_deletion.html.twig`, player's locale)
   with an *Export my data* button, the *Delete my account* button, and the
   "valid for 60 minutes / ignore if it wasn't you" footer.
3. **Link → last-chance page** `GET /delete-account/{token}` (`confirm_account_deletion`).
   Shows which account is about to go (e-mail + player name/code), **"This is what
   goes with it"** tiles (`GetAccountDeletionSummary`: owned solving times of every
   puzzling type, pieces solved, time spent, distinct puzzles across all
   collections — hidden when all zero), the export CTA again, an *I understand
   this is permanent* checkbox and the red button.
   Anonymous-capable like the password-reset page: the token is the proof, and the
   link is opened wherever the mail is read.
4. **`POST /delete-account/{token}`** (CSRF + checkbox) → if the browser is signed
   in as that very account it is logged out first (so the `logout` audit row still
   belongs to the account and cascades away with it), then `ConfirmAccountDeletion`
   runs `DeletePlayer` in the same transaction → redirect to the goodbye page.
5. **Goodbye page** `GET /account-deleted` (`account_deleted`).

Expired / invalid links get their own outcome page ("nothing has been deleted,
request a new link from your profile settings").

## Why the link does not delete on GET

Mail clients and link scanners (Outlook SafeLinks, Apple Mail Privacy Protection,
Gmail image/link proxies) prefetch URLs found in mail. A GET that deletes would
delete accounts nobody asked to delete. The e-mailed link therefore only opens the
last-chance page; the destructive step is a POST behind CSRF + an explicit
checkbox.

## Token design

Mirrors `ResetPasswordRequest` (split token, D8/D18 rationale in
`docs/features/auth-migration`):

- `account_deletion_request` table (`AccountDeletionRequest` entity): `selector`
  (unique, queryable), `hashed_verifier` (sha256 of the verifier — a DB leak alone
  can never forge a working link), `requested_at`, `expires_at`,
  `user_account_id` **ON DELETE CASCADE**.
- `AccountDeletionToken` value object: 64 hex chars = 32 selector + 32 verifier.
- **Lifetime 60 minutes** (`AccountDeletionRequest::LIFETIME_MINUTES`, single
  source for the expiry the row carries and the e-mail promises).
- A new request **replaces** any older open request for the account (a fresh
  click always yields a fresh, working link — the caller is authenticated, so
  there is no enumeration reason for silent throttling). Mail volume is guarded
  by the `account_deletion_request` rate limiter (3 / 15 min per account).
- Expired rows older than a week are garbage-collected opportunistically on the
  next request (same as password reset).
- Deletion consumes the token implicitly: the account row goes, the request rows
  cascade with it.

## Messages / handlers

| Message | Handler | Does |
|---|---|---|
| `RequestAccountDeletion(userId)` | `RequestAccountDeletionHandler` | finds the `UserAccount` (throws `UserAccountNotFound`), removes older requests, mints + stores the token, records `AuthAuditEventType::AccountDeletionRequested`, **returns the `AccountDeletionToken`** |
| `SendAccountDeletionLink(userId, token, fallbackLocale)` | `SendAccountDeletionLinkHandler` | mails the link (+ export link) in the player's locale; separate handler so the row is committed before any mail goes out |
| `ConfirmAccountDeletion(token)` | `ConfirmAccountDeletionHandler` | `ValidateAccountDeletionToken` → dispatches the existing `DeletePlayer` (nested, same transaction); an account without a player row is removed directly |

`DeletePlayer` / `DeletePlayerHandler` are unchanged and remain the single place
that knows how to anonymise/remove a player's data.

## Console command

`myspeedpuzzling:player:delete <identifier> [--force]` — the identifier may be a
player UUID, a player code, or an e-mail (login e-mail first, profile e-mail as
fallback); resolution lives in `Services\ResolvePlayerByIdentifier`. The command
prints who is about to be deleted and asks for confirmation unless `--force` is
given (non-interactive runs need `--force`).

## Security notes

- Request endpoint: `IS_AUTHENTICATED_FULLY` + session-backed CSRF (`RequestAccountDeletionController::CSRF_TOKEN_ID`) + per-account rate limit.
- Confirm page: `_auth_page` route default (locale negotiated from the browser,
  `no-store`), no session started for anonymous visitors (#164), and its CSRF id
  `confirm_account_deletion` is in `stateless_token_ids` (`config/packages/csrf.php`)
  for the same reason. `Referrer-Policy: same-origin` keeps the token URL off any
  third-party Referer. Deliberately **not** `no-referrer` (which the password-reset
  page uses): per the Fetch spec, `no-referrer` makes the browser send
  `Origin: null` on the page's own same-origin form POST, so the stateless
  same-origin CSRF check is left with `Sec-Fetch-Site` alone — fine on current
  browsers, but a dead button on Safari < 16.4 (there is no double-submit CSRF
  JS in this app). `same-origin` shows the URL only to this server, which issued
  the token anyway.
- The audit trail gets `account_deletion_requested` (visible on the
  recent-activity page); "deleted" cannot be audited per account by definition —
  `DeletePlayerHandler` logs it.

## Files

- Entity/repo: `Entity/AccountDeletionRequest`, `Repository/AccountDeletionRequestRepository`
- Value/exceptions/service: `Value/AccountDeletionToken`, `Exceptions/{InvalidAccountDeletionToken,AccountDeletionTokenExpired}`, `Services/ValidateAccountDeletionToken`
- Query/result: `Query/GetAccountDeletionSummary`, `Results/AccountDeletionSummary`
- Controllers: `RequestAccountDeletionController`, `ConfirmAccountDeletionController`, `AccountDeletedController`
- Templates: `edit-profile.html.twig` (danger zone), `account_deletion_confirm.html.twig`, `account_deletion_dead_link.html.twig`, `account_deleted.html.twig`, `emails/account_deletion.html.twig`
- Translations: `messages.*.yml` (`edit_profile.danger_zone.*`, `account_deletion.*`, `account_activity.event.account_deletion_requested`), `emails.*.yml` (`account_deletion.*`) — all six locales
- Config: `rate_limiter.php` (`account_deletion_request`)
- Tests: `tests/Value/AccountDeletionTokenTest`, `tests/MessageHandler/{Request,Send…Link,Confirm}AccountDeletion…HandlerTest`, `tests/Controller/{Request,Confirm}AccountDeletionControllerTest`, `tests/Services/ResolvePlayerByIdentifierTest`, `tests/Query/GetAccountDeletionSummaryTest`
