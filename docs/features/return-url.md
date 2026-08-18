# Return URLs — one convention, no session

**Status (2026-08-01): DELIVERED.** PR 1 `ReturnUrl` + open-redirect fixes; PR 2 post-login redirects moved off the session; PR 3 the Auth0 fork's `supports()` short-circuit.

Goal: after signing in, registering, or finishing a modal flow, the user lands back where they
started — carried in the URL as `?return=`, never in the session or a cookie. Auth0 is a
fallback-only escape hatch since Stage B, so the session-based machinery it needed can go.

Two things motivate this beyond ergonomics:

1. **It is the direct cause of the sessions-table bloat.** Production `sessions` is **3,434,070
   rows / 1.8 GB** (measured 2026-08-01). Sampling it: **68,450 of 68,524** sampled rows are
   anonymous, holding nothing but `_security.main.target_path` and `auth0:callback_redirect` —
   crawler hits on protected URLs. Daily volume spiked to **1,051,548** rows on 2026-07-28 against
   a ~10–30k/day baseline the week before. There are **332** `IsGranted`/`denyAccessUnlessGranted`
   sites in controllers; every anonymous hit on one writes a session row and a `Set-Cookie`.
2. **The existing `?return=` convention has live open redirects** (below). Extending an
   inconsistently-validated pattern into the *auth* flow is exactly where this class of bug turns
   dangerous — post-login redirect to a phishing clone is the textbook attack. Centralising
   validation is a precondition, not a nice-to-have.

---

## Current state

### Auth return path — three mechanisms, all stateful

| Mechanism | Where | Notes |
|---|---|---|
| `_security.main.target_path` | Symfony `ExceptionListener::setTargetPath()` → session | Written on **every** anonymous hit to a protected page. The bulk of the session bloat. |
| `auth0:callback_redirect` | `Auth0\Symfony\Security\Authenticator::onAuthenticationFailure()` → session | Written for protected pages, and for public pages when a session already exists. `LoginFormAuthenticator::onAuthenticationSuccess()` reads it as migration-window glue. |
| `auth0_redirect_target` | `Auth0EntryPoint::REDIRECT_COOKIE` → cookie | Client-writable, so `LoginFormAuthenticator` **already deliberately refuses to honor it** (open redirect — see the comment at `LoginFormAuthenticator.php:129`). Effectively dead weight. |

### Breadcrumb return path — an established convention, three inconsistent patterns

The site already uses `?return=` (URL) + `?return_title=` (label), rendered by
`templates/_return_back_button.html.twig`. `base.html.twig:11` strips both from canonical and
hreflang URLs via `cleanQuery` — good precedent to keep.

| Pattern | Example | Safety |
|---|---|---|
| **Enum context** — no URL from the client at all | `EditTimeController` + `EditTimeReturnContext` | Safest. Preferred wherever the destination set is closed. |
| **Validated relative path** | `RemovePuzzleFromCollectionController::isValidReturnUrl()`, plus inline copies in `Wishlist\RemovePuzzleFromWishListController:126` and `SellSwap\RemovePuzzleFromSellSwapListController:129` | OK, but duplicated three times and only checks `/` and `//`. |
| **Unvalidated** | see below | **Open redirect.** |

### Security findings — FIXED in PR 1

All three were live open redirects when this was written. `SpeedPuzzling\Web\Value\ReturnUrl`
now guards every consumer.

1. **`Rating/RateTransactionController`** — `$returnUrl = $request->query->getString('return')` is
   passed straight to `$this->redirect()` at lines **55, 104, 116**. Line 55 fires on a plain
   **GET** when `canRate()` is false, so it is a GET-triggered open redirect: send a signed-in user
   `…/rate/<id>?return=https://evil.com` and they are bounced off-site.
2. **`SellSwap/EditSellSwapListSettingsController`** — unvalidated `$this->redirect($returnUrl)` at
   line **85**, and `$cancelUrl` at **91–92** renders an off-site link.
3. **`templates/_return_back_button.html.twig`** — emits `href="{{ returnUrl }}"` straight from the
   query string, so any page including the partial can be given an off-site "Back" button. Lower
   severity (needs a click) but the same class.

---

## Design

### D1 — Reuse `return` / `return_title`

Do not invent `returnUrl` as a second name. `return` is already the site convention, already
stripped from canonical URLs, already understood by the back-button partial. One name everywhere.

### D2 — One validator, one place

New `SpeedPuzzling\Web\Value\ReturnUrl` (or a small `ReturnUrlValidator` service) replacing the
three inline copies. Accept **relative paths only**:

- non-empty, and no control characters or newlines (header-injection guard);
- starts with exactly one `/`;
- rejects `//` (scheme-relative) **and** `/\` and any `\` (browsers normalise backslashes to
  slashes, so `/\evil.com` escapes a naive `//` check — none of the three current copies catch it);
- rejects anything that still parses with a scheme or host after `rawurldecode()`;
- invalid → `null`, and every caller falls back to its own default. Never throw at the user.

Expose it as a Twig function so `_return_back_button.html.twig` renders only validated values, and
keep preferring the **enum-context** pattern (`EditTimeReturnContext`) wherever the destinations
are a closed set — no client-supplied URL beats validating one.

### D3 — Getting `return` onto the login page without a session — AS BUILT

`LoginEntryPoint` replaces `Auth0EntryPoint`: it redirects to the `login` route with
`?return=<path+query of the current request>` and sets **no cookie and no session**. It skips
non-safe methods and XHR (the same conditions Symfony's own `setTargetPath()` applies) and refuses
to offer `/login` as a destination, which would loop.

That alone is not enough: `ExceptionListener::startAuthentication()` writes the target path
*before* the entry point runs. The plan was to subclass `ExceptionListener` and override the
`protected setTargetPath()`, but **the built version does something simpler**: that class already
takes a `$stateless` constructor flag whose *entire* use is

```php
if (!$this->stateless) {
    $this->setTargetPath($request);
}
```

so `SessionFreeExceptionListenerPass` just flips that argument to `true`. No subclass, no copied
framework code, and no `@final` violation (PHPStan rejects extending it). The firewall itself stays
stateful — `stateless` is not set in `security.php`, and sessions work normally for signed-in users.

### D4 — Carrying `return` through each sign-in path

| Path | How |
|---|---|
| Password login | Hidden `return` field in the login form, value from `app.request.query.get('return')`; `LoginFormAuthenticator::onAuthenticationSuccess()` reads it from `$request->request` and validates. |
| Registration | **Not carried (decided during build).** Registration ends on the welcome page, which is a deliberate stop for a brand-new user, so there is nothing to return to. |
| Social login | Add `null|string $returnUrl` to `OauthFlowState`. The start route reads `?return=`, stores it in `SocialLoginStateStore`; `SocialLoginAuthenticator::onAuthenticationSuccess()` reads it off the consumed state. **This is the piece that makes dropping the session viable at all** — the cache-backed store already exists precisely because Apple's `form_post` callback arrives without SameSite=Lax cookies. |
| Social registration interstitial (`/register/social`) | **Not carried (decided during build).** Rule 4 means "no matching account" — the visitor is registering, and the profile is the right landing. Would require threading the value through `SocialAccountResolver` into `ParkedSocialRegistration` for little gain. |
| Magic sign-in link | **Not carried (decided during build).** The link arrives by email, usually on another device, so a deep link from the requesting session has little value — and it would add an unsigned query parameter to a security-sensitive signed URL. Lands on the profile. |
| Auth0 fallback (`/login/auth0`) | **Not carried.** It round-trips through Auth0's own callback, which is the one flow that genuinely needs the session key. It is a rarely-used escape hatch that dies in Phase 6 — accept "always lands on my-profile" and document it. |

### D5 — Deletions

- `Auth0EntryPoint` and its `auth0_redirect_target` cookie — gone.
- The `auth0:callback_redirect` branch in `LoginFormAuthenticator::onAuthenticationSuccess()` — gone.
- `TargetPathTrait` usage in `LoginFormAuthenticator` and `SocialLoginAuthenticator` — gone.

### D6 — Claiming the session-table win

Removing `target_path` is only half of it. The Auth0 authenticator's `onAuthenticationFailure()`
still writes `auth0:callback_redirect`, and its `hasSession()` check starts a session lazily. So
either:

- **(i)** short-circuit `Auth0\Symfony\Security\Authenticator::supports()` to `false` when
  `!$request->hasPreviousSession()` — a ~3-line change in the already-forked bundle, which mirrors
  the `hasPreviousSession()` early-out its `authenticate()` already has. Also removes the
  per-request `LoginFailureEvent` noise that forced both the remember-me listener swap and the
  audit subscriber's failure allowlist; **or**
- **(ii)** wait for Phase 6, when the authenticator leaves the firewall.

**Recommendation: (i), as part of this work** — otherwise the headline benefit does not land and
the 1.8 GB keeps growing until September. Measure `sessions` row count before and after.

Once anonymous protected-page hits are session-free and cookie-free they also become
shared-cacheable, and session `cookie_lifetime` can then be *shortened* (remember-me now carries
the 30 days — see `docs/features/auth-migration/`), compounding the win.

---

### Where validation belongs: at consumption

`return` is **validated wherever it is read**, not where it is passed along. Several templates
propagate an incoming `return` into a freshly generated `path(...)` (`puzzle_detail.html.twig:125`,
`puzzle/_dropdown_actions.html.twig:49`, `sell-swap/detail.html.twig`, …). Those produce same-site
URLs with the value nested and encoded as a query parameter, so a hostile value can travel but can
never be acted upon — the next consumer runs it through `ReturnUrl` and falls back.

Deliberately not sanitising at propagation too: it would mean touching a dozen long template
expressions for no additional security, and "every consumer validates" is a single invariant that
is easy to state and easy to check (`grep` for `getString('return')`).

## Testing

- **Validator unit tests**, adversarial: `//evil.com`, `/\evil.com`, `/%5Cevil.com`,
  `https://evil.com`, `javascript:alert(1)`, `%2F%2Fevil.com`, embedded `\r\n`, empty, bare `/`.
- **Functional**: deep-link → login → land back on the deep link; social round-trip preserves it;
  magic link preserves it; an invalid `return` silently falls back to the default.
- **Regression guards**: an anonymous request to a protected page must set **no cookie** and start
  **no session** (this is both the #164 constraint and the session-bloat guard); add it next to
  `AnonymousCacheHeadersSubscriberTest`.
- **Fix + cover the three open redirects** listed above.

## Sequencing

| PR | Content | Why separate |
|---|---|---|
| ~~**1**~~ | ~~`ReturnUrl` validator + fix the three open redirects + replace the three inline copies~~ **DONE** | Security fix, small, independently shippable, no auth risk |
| ~~**2**~~ | ~~`LoginEntryPoint`, exception-listener change, `return` through the sign-in paths, delete the session/cookie mechanisms~~ **DONE** | The actual feature |
| ~~**3**~~ | ~~Auth0 `supports()` short-circuit~~ **DONE** | Touches the vendor fork; easy to roll back on its own |

## What was removed

`Auth0EntryPoint` (and its client-writable `auth0_redirect_target` cookie, which nothing was
willing to honor), `Auth0RedirectSubscriber` and its test, the `auth0:callback_redirect` branch in
`LoginFormAuthenticator`, and `TargetPathTrait` from both the form and social authenticators.

## Gotcha worth remembering

`AbstractLoginFormAuthenticator::supports()` compares `getLoginUrl()` against the request path to
decide whether to intercept the POST at all. Appending `?return=` there makes the comparison fail
and the authenticator silently never runs — the login form just re-renders. The failure redirect
belongs in an `onAuthenticationFailure()` override instead; `getLoginUrl()` must stay bare.

## Still open

Should `return` be restricted to paths that match a known route (`UrlMatcherInterface::match()`)
rather than any same-site path? Stricter, but adds locale-prefix and query-string handling. Strict
relative-path validation has been enough so far.
