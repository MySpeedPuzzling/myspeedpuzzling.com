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

Public instructions for all of this live at `/en/data-deletion` (`data_deletion`
route, all 6 locales, in the footer + sitemap, linked from the privacy policy) —
it is also the "User data deletion" URL given to Meta for Facebook login. Keep it
in sync when the flow or `DeletePlayerHandler` changes what is deleted.

**The public promise** (privacy policy §16 + `/data-deletion` §3, 2026-09-29): there is
no manual deletion any more — the account is closed by its owner, immediately, and
nothing about the person is kept except Stripe's payment records (payments, invoices,
receipts; accounting/tax law). Shared content stays for the other players, unlinked,
showing the name as plain text. Things that technically still remain and are *not*
spelled out on the public pages (keep this list honest when changing `DeletePlayerHandler`):

- the avatar file in object storage — GitHub issue #213 (bug, the promise stands),
- `auth_audit_log` rows **not** linked to the account (e.g. failed sign-ins typed with
  the address) — pruned after 24 months like everything else in that table,
- `email_audit_log.recipient_email` as a sha256 of the address (one-way, but not
  anonymous for someone who already knows the address),
- `puzzle_moderation_decision` (moderators only): decider id/name/code copied on purpose,
- WJPF keeps our player UUID in their database (their write is permanent),
- database backups (7 days) and Loki logs (30 days), Sentry events (Sentry's retention).

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

`DeletePlayer` / `DeletePlayerHandler` remain the single place that knows how to
anonymise/remove a player's data (files included, see below).

## Moderation records of a deleted admin / moderator

Admins and moderators can delete their own account like anyone else. What they
*did* stays on record, just no longer linked to them - every "who acted" column is
nullable and nulled (the other players' side of the story must not disappear with
the person who moderated it):

| Record | Column | On deletion |
|---|---|---|
| `moderation_action` (warn / mute / ban / listing removed) | `admin_id` | `ON DELETE SET NULL` + nulled by the handler; admin history shows "Deleted user" |
| `conversation_report`, `feature_request_comment_report` | `resolved_by_id` | nulled by the handler; report detail shows "Deleted user" |
| `puzzle_change_request`, `puzzle_merge_request`, `oauth2_client_request` | `reviewed_by_id` | `ON DELETE SET NULL` (the "by …" is simply left out) |
| `puzzle` | `approved_by_id` | `ON DELETE SET NULL` |
| `puzzle_merge_audit` | `performed_by_id` | `ON DELETE SET NULL` |
| `competition`, `competition_series` | `approved_by_player_id`, `rejected_by_player_id` | nulled by the handler |
| `puzzle_moderation_decision` | - | no FK by design: decider id/name/code are a copy and stay |

A player's *own* change requests (`puzzle_change_request.reporter_id`) cascade
with them; the decisions about them live on in `puzzle_moderation_decision`.
Moderator appointments are a column on the player (`moderator_since`) and go with it.

**When adding a column that references `player`:** pick `onDelete: 'CASCADE'`
(the row is the player's own data) or `nullable` + `onDelete: 'SET NULL'` (the row
belongs to someone else / is an audit record). A plain FK blocks every deletion of a
player it points to - that is how `moderation_action.admin_id` made admins
undeletable until 2026-09-30 (`DeletePlayerHandlerTest::testDeletingAnAdminKeepsTheirModerationActionsWithoutThem`).

## Files in object storage (#213)

The privacy policy promises that nothing but Stripe's payment records survives an
account deletion, so the player's files go too.

**How:** `DeletePlayerHandler` reads the paths while the rows still exist (the
avatar, the finished-puzzle photos of the solving times it removes) and dispatches
`DeletePlayerStoredFiles(playerId, paths)` with a `DispatchAfterCurrentBusStamp`.
The stamp holds the message until the *outermost* dispatch has finished - which
includes the `doctrine_transaction` commit, also when `DeletePlayer` runs nested in
`ConfirmAccountDeletion` - and drops it when anything rolls back, so a failed
deletion never loses a file (`DeletePlayerStoredFilesHandlerTest::testRolledBackDeletionRequestsNothing`,
via the test-only `Tests\TestDouble\DeletePlayerThenFail`). The message is routed
`async`, so object storage is never in the way of the deletion itself.

`DeletePlayerStoredFilesHandler`:

- does nothing (warning) while the player row still exists - a second guard against
  a message that did not come from a committed deletion;
- deletes the listed paths **plus every object under `players/<id>/`** that no row
  references (`Query\GetStoredFileReferences` checks every storage-key column):
  result share images (`players/<id>/results/…`, a cache regenerated on demand) and
  finished photos replaced by an earlier edit;
- goes through `FailoverS3Adapter`, so a file still waiting in the upload spool
  (S3 was down when it was uploaded) is dropped from the spool too and the drain
  cron never uploads it later; a delete S3 refuses is queued in the spool for retry;
- never throws: any failure is logged at warning with the exception; a missing
  object counts as deleted.

**Deliberately kept:**

| What | Why |
|---|---|
| Photos of pair/team times handed over to another registered member (`players/<deleted id>/…`) | The result lives on for the other puzzlers; the photo belongs to the shared time. Still referenced, so the sweep skips it. |
| Puzzle box photos the player uploaded when adding a puzzle (`puzzle.image`) | Catalogue data, like the puzzle itself (which stays, with `added_by_user_id` nulled). |
| Change-request images (`proposal-…`) | Moderation/catalogue history, not personal data; an approved one is the puzzle's image. |
| Competition/series logos | Belong to the event, which stays. |

Nothing else is stored per player: sell/swap and marketplace listings, chat
messages and conversations have no uploads.

**imgproxy / images-cache:** only the original is deleted. Resized variants the
`images-cache` nginx already holds stay on its disk until they fall out
(`inactive=30d`, `max_size` in lily.srv's `nginx-imgproxy.conf`) - they are no longer
linked from anywhere once the player row is gone, and browsers/CDN keep their
`immutable` copies anyway. No purge needed.

**Known gap (pre-existing, see `docs/TODO.md` "Image storage"):** `EditProfileHandler`
never deletes the previous avatar when a new one is uploaded, so replaced avatars
pile up. The cleanup command below removes them; the handler fix belongs to the
general "delete the old key when an image is replaced" item.

### One-off cleanup of orphaned avatars

`myspeedpuzzling:storage:delete-orphaned-avatars [--delete]` - lists every object
under `avatars/` that no row references (`Services\Storage\OrphanedAvatarFinder`),
skipping objects younger than 24 h (`EditProfileHandler` uploads before its
transaction commits). **Dry run by default**; `--delete` dispatches
`DeleteOrphanedAvatar` per path, whose handler re-checks the reference right before
deleting. Safe to re-run.

On production (as root on lily, never from a laptop):

```bash
cd /srv/myspeedpuzzling
# 1. dry run - read the list, spot-check a few keys
docker compose run --rm --no-deps messenger-consumer bin/console myspeedpuzzling:storage:delete-orphaned-avatars | tee /root/orphaned-avatars-$(date +%F).txt
# 2. delete
docker compose run --rm --no-deps messenger-consumer bin/console myspeedpuzzling:storage:delete-orphaned-avatars --delete
```

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
- Stored files: `Message/DeletePlayerStoredFiles` + handler, `Message/DeleteOrphanedAvatar` + handler, `Query/GetStoredFileReferences`, `Services/Storage/OrphanedAvatarFinder`, `ConsoleCommands/DeleteOrphanedAvatarsConsoleCommand`; tests `tests/MessageHandler/DeletePlayerStoredFilesHandlerTest`, `tests/Services/Storage/OrphanedAvatarFinderTest`
- Tests: `tests/Value/AccountDeletionTokenTest`, `tests/MessageHandler/{Request,Send…Link,Confirm}AccountDeletion…HandlerTest`, `tests/Controller/{Request,Confirm}AccountDeletionControllerTest`, `tests/Services/ResolvePlayerByIdentifierTest`, `tests/Query/GetAccountDeletionSummaryTest`
