# Announcement modals

Modals the site opens by itself - "try membership for free", a future "this is new". Every player gets
each of them **at most once, ever**, never two of them close together, and never in the middle of
something. Built 2026-09-21 together with the [free trial](free-trial/README.md), its first user.

Replaces the single `player.modal_displayed` boolean (one modal at a time, no memory of who saw the
previous one, no targeting, marked as "displayed" by any HTML response - even with the modal disabled).

## How it works

| Piece | Role |
|-------|------|
| `Value/AnnouncementModal` | enum, one case per modal; case order = priority; `template()` → `templates/modals/announcements/_{value}.html.twig`. The template **stays in the code for good** - rules decide when it shows, never an edit of `base.html.twig` |
| `Services/AnnouncementModals/AnnouncementModalRule` | one tagged service per modal: "is this viewer the audience right now?" - answered from the `PlayerProfile` it is handed, **without querying** (it runs on every full page view of every signed-in player) |
| `Services/AnnouncementModals/ResolveAnnouncementModal` | picks at most one modal per page view, then claims it. `ResetInterface` (worker mode) |
| `player_modal_impression` | `player_id`, `modal`, `displayed_at`, `seen_at`; **unique (player_id, modal)**, cascades with the player |
| Twig `announcement_modal()` | called once, in `base.html.twig`. Calling it *claims* the modal - never call it anywhere else |

### Order of checks (cheapest first)

1. main request, not an error sub-request; `GET`; no `Turbo-Frame` header, not XHR (a fragment would claim the modal and show nothing)
2. no `?return=` (a step of a flow), route not on `QUIET_ROUTES` (membership, checkout, voucher, sign-in/registration/password/account, edit profile, add/edit time, stopwatch), not admin / API
3. web only - native apps sell membership through their stores
4. signed in
5. no announcement modal shown to this player in the last `COOLDOWN_DAYS` (14)
6. first enum case with no impression yet whose rule says yes
7. **claim**

A page that fails 1-3 does not use the modal up - it waits for the next page that can take it.

Ordinary page views cost **no query**: impressions ride on the viewer's own profile
(`GetPlayerProfile::byUserId()` → `PlayerProfile::$modalImpressions`, one correlated subselect, index-only).

### Never twice

The impression is claimed **before** the modal is rendered (`ClaimAnnouncementModalImpressionHandler`):

```sql
INSERT INTO player_modal_impression (...) VALUES (...) ON CONFLICT (player_id, modal) DO NOTHING
```

Only the request whose insert wrote a row renders the modal. Two tabs at once, a retried request - they
meet on the unique index and one wins. The price: a page rendered but never looked at still uses the
impression. That is the right side to err on; a browser-side "I showed it" report cannot give the
guarantee, because every lost report would mean a second display.

### Counting

`seen_at` is filled by the browser (`autoshow-modal` Stimulus controller → `POST /-/announcement-modal-seen`
on `shown.bs.modal`). It can only confirm a claimed impression and decides nothing.

- **Admin → "Free trial & modals"** (`/admin/free-trial`): per modal - players it was displayed to, how many
  really opened, last 24 h / 7 days, first / last.
- SQL: `SELECT modal, count(*) AS displayed, count(seen_at) AS seen FROM player_modal_impression GROUP BY 1;`

`displayed` is a number of *players*, since nobody gets a modal twice.

## Adding a modal

1. Case in `AnnouncementModal` (position = priority).
2. `templates/modals/announcements/_{value}.html.twig` - copy `_free_trial_offer.html.twig`: keep
   `id="announcement-modal"`, `data-controller="autoshow-modal"` and the two `data-autoshow-modal-*` values.
3. A class implementing `AnnouncementModalRule` (autoconfigured). If it needs data that is not on
   `PlayerProfile`, add it to `GetPlayerProfile::byUserId()` - do not query from the rule.
4. Texts in all six locales.
5. Tests: the rule, and a case in `tests/Controller/AnnouncementModalTest.php`.

Panther note: fixture players are registered "now", so the free trial offer (account 7 days old) never opens in browser tests. A
modal without an age gate would - and would intercept clicks; give such a rule a way to stay out of tests.

## Leftover

`player.modal_displayed` is still mapped (deprecated, unused). The blue-green deploy keeps the previous
build - which selects it - serving while the new one migrates, so the column is dropped one release later.
