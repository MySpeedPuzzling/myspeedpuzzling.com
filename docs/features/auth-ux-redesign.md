# Sign-in / sign-up UX redesign

Status: **phase 1 SHIPPED 2026-09-29** (layout, copy, sent screens, toggle, in-app notice, return URL
through the link, auto sign-in after reset). **Phase 2 SHIPPED 2026-09-29**: the 6-digit code in the
sign-in e-mail next to the link (§4.3/§4.4, D1) - see §9 for what was built and where it differs.
**Method order changed 2026-09-30** (owner): `/login` email first, `/register` "Continue with email"
first with the form opening in place - §10 supersedes the "provider buttons first" parts of §4.1/§4.2.
Mobile first, desktop kept good. See §8 for what shipped and where it differs from this spec.
Scope: `/login`, `/register`, `/login-link` (+ new "check your email" state), `/password-reset`
(+ "check your email"), `/password-reset/{token}`, `/register/social` (both variants), the in-app
browser notice. `/welcome`, `/finish-profile` and `/set-password` are touched only where noted.

Related: [auth-hardening/README.md](auth-hardening/README.md) (linking rules 1–5, anti-enumeration),
[auth-migration/README.md](auth-migration/README.md) §"Password-manager shock — the UX funnel",
[getting-started-guide.md](getting-started-guide.md), [return-url.md](return-url.md).

---

## 1. What is wrong today (verified in the templates, 2026-09-29)

| # | Problem | Where |
|---|---|---|
| P1 | Every auth page wraps the form in `.card.shadow` inside `col-lg-6 col-md-8`. At 360 px that is container padding + card padding = ~24 px lost per side, plus a shadow box that reads as "a widget", not "the page". | all auth templates |
| P2 | "Forgot password?" and "Create an account" are the **last** things on `/login`, below the sign-in-link button, its explainer and (when on) three social buttons. A new visitor who pressed the header's sign-in icon has to scroll past everything to find "create". | `login.html.twig` |
| P3 | "Email me a sign-in link" is a `type=submit` for a hidden form that mirrors the email field. Empty field → lands on `/login-link` with a warning flash "Please enter your email address." — the user is punished for a button we offered. | `login.html.twig`, `SignInLinkController` |
| P4 | Sub-pages' only way back is a plain text link **below** the card ("Back to signing in with a password"): on a phone it is under the keyboard / below the fold, and it reads like body text. | `sign_in_link`, `request_password_reset`, `password_reset_dead_token` |
| P5 | "Link sent" is only a flash above the same form. The field is still there, still focused — people send it again, or think nothing happened. The address it went to is not shown, there is no "wrong address?" and no "open your mail". | `SignInLinkController`, `RequestPasswordResetController` |
| P6 | No show/hide password toggle anywhere. | login, register, reset, set-password |
| P7 | Inputs are `$input-btn-font-size` = 0.9375 rem = **15 px**. iOS Safari zooms any focused input under 16 px; today that zoom is only suppressed by `maximum-scale=1` in the viewport meta, which also disables pinch-zoom on Android (WCAG 1.4.4 problem). | `_variables.scss:261`, `base.html.twig:7` |
| P8 | `autofocus` on `/login` and `/register`: on Android it opens the keyboard on arrival and covers the social buttons; screen readers start mid-form, skipping the heading. | login, register |
| P9 | A sign-in link always lands on `my_profile` — the `?return=` destination is lost (password and social sign-in keep it). | `LoginLinkSuccessHandler` |
| P10 | Sign-in-link request inside Instagram/Facebook's in-app browser: the link in the mail opens in Safari/Chrome, so the person is signed in *there* while the Instagram tab they came from stays signed out. Google sign-in in those webviews fails outright with `403 disallowed_useragent`. Nothing on the page warns about either. | – |
| P11 | Copy is long and repeats itself ("We send the link to the address above. One click and you are in." under a button that says the same). | translations `auth.*` |

---

## 2. Principles (with the evidence behind each)

1. **One column, no card, on phones.** The page *is* the form. Width is the scarcest resource at
   360 px and the card spends it on decoration. Desktop may keep a light panel next to the
   illustration. (web.dev: generous input/tap sizes on mobile, 15 px padding — impossible inside a
   card inside a padded column. <https://web.dev/articles/sign-in-form-best-practices>)
2. **Separate sign-in and create-account forms, cross-linked at the top.** web.dev: "Use a different
   `<form>` for each UI component"; password managers need `current-password` vs `new-password` on
   different forms to decide between *fill* and *save/generate*. Social buttons are already unified
   (rule 1/2 sign in, rule 4 asks) and say "Continue with…" on both pages, so the "which one do I
   press" worry disappears for social users. The known failure of separate pages — people creating
   a second account because they don't remember having one (UIE data quoted by Luke Wroblewski:
   45 % of a retailer's customers had multiple registrations,
   <https://www.smashingmagazine.com/2011/08/new-approaches-to-designing-login-forms/>) — is handled
   by the existing "this email already has an account → sign in / send me a link" error on
   `/register` and the rule-4 interstitial.
3. **Keep email + password on one page (reject identifier-first).** Two-step login "often breaks
   autofill and password managers" and "for most users, login/pass is way faster"; the pattern only
   earns its keep when you must route to enterprise SSO
   (<https://smart-interface-design-patterns.com/articles/2-page-login-pattern/>, Okta's own
   migration guide lists the autofill change and the enumeration probe as the trade-offs:
   <https://developer.okta.com/docs/guides/oie-manage-id-first-signin/main/>). We have no SSO and
   our biggest real pain *is* the password manager (auth-migration UX funnel). Adaptive
   step-2 screens would also reveal who is registered (D8).
4. **Recovery sits next to the thing that fails.** Baymard: put "Forgot password?" directly beside
   or below the password field (<https://baymard.com/blog/simplifying-sign-in>, #6). On the label
   row it is visible before the user fails, and it never gets pushed below social buttons again.
5. **Passwordless is a peer route, not a hidden submit trick.** A real link to its own page,
   carrying the typed address without putting it in the URL.
6. **Email codes beat email links on phones — offer both in one mail.** A link opens in whatever
   browser the mail app picks (iOS Mail / Gmail in-app browser, Safari when you came from Chrome,
   Safari when you came from Instagram), so the session lands in the wrong place; a code binds the
   session to the tab the user types it into
   (<https://www.scalekit.com/blog/otp-vs-magic-links-passwordless-authentication>,
   <https://sesmetric.com/inbox/magic-link-vs-otp>). NN/g: getting an OTP from email is harder than
   from SMS because of the app switch
   (<https://www.nngroup.com/articles/passwordless-accounts/>) — which is exactly why the code must
   be pasteable and short. WCAG 2.2 SC 3.3.8 accepts e-mailed links and codes **as long as the code
   can be pasted** (manual transcription only fails it):
   <https://www.w3.org/WAI/WCAG22/Understanding/accessible-authentication-minimum.html>.
7. **Tell people exactly where the mail went, and let them fix it.** GOV.UK "confirm an email
   address": show the address, explain the next step, allow resend and changing the address
   (<https://design-system.service.gov.uk/patterns/confirm-an-email-address/>).
8. **Never block password managers or paste; label every field; generic errors.** SC 3.3.8
   (autofill + paste), SC 1.3.5 (`autocomplete` tokens), SC 3.3.1 (errors in text), SC 2.5.8
   (targets ≥ 24 × 24 CSS px — we use 44–48 px for anything tappable in the form).
9. **Provider buttons: equal weight, provider branding, "Continue with".** Google: "at least as
   prominently as other third party sign-in options … approximately the same size and similar
   visual weight", allowed CTAs *Sign in / Sign up / Continue with Google*
   (<https://developers.google.com/identity/branding-guidelines>). Apple HIG: no smaller than other
   sign-in buttons, visible without scrolling, titles *Sign in / Sign up / Continue with Apple*
   (<https://developer.apple.com/design/human-interface-guidelines/sign-in-with-apple>). Already
   implemented in `_social_provider_button.html.twig` — keep.
10. **Don't send people into a dead end we can predict.** Google refuses OAuth inside embedded
    webviews (`403 disallowed_useragent`, fully enforced since 2023-07-24:
    <https://developers.googleblog.com/upcoming-security-changes-to-googles-oauth-20-authorization-endpoint-in-embedded-webviews/>).
    Instagram/Facebook visitors must be told *before* they tap, with an escape route
    (<https://truelink-group.com/en/blog/why-google-login-fails-in-line-facebook-in-app-browsers-2026/>).
11. **Remember the method, not the person.** A "Last used" badge on the method this device used last
    (Clerk and WorkOS ship it on by default:
    <https://clerk.com/changelog/2025-09-12-last-used-sign-in>,
    <https://workos.com/changelog/last-used-login-method-in-authkit>) answers "did I sign up with
    Google or with a password?" — the single most common returning-user confusion.

---

## 3. Decisions vs. the coordinator's draft

| Draft said | Decision | Why |
|---|---|---|
| Social buttons on top, "or" divider, then email + password | **Agree** (when ≥1 provider is public). With `SOCIAL_LOGIN_ADMIN_ONLY=1` (today) the page starts with the email form and has no divider. | 63.5 % gmail + rule 2 auto-link = one tap for most returning users *and* no password-manager problem. Apple's HIG requires the Apple button above the fold anyway. |
| Hint "works with your existing account too" | **Agree with changed wording**: *"Already have an account? You'll land in it if it uses the same email."* | The draft's wording is untrue for people whose Google address differs from their MSP address (they hit the rule-4 interstitial). The hint must promise only what rule 2 delivers. Shown on `/login` only, one line, muted, under the provider buttons. |
| `Forgot password?` on the password label row | **Agree.** | Baymard #6. Also frees the bottom of the page. |
| Primary "Sign in" then link "Sign in without password →" on its own page | **Agree on the separate page, change the label**: *"Email me a sign-in code instead"* (a code + link mail, see §4.3). Rendered as a full-width **outline button-looking link** (secondary), not a text link. | "Without password" is a negative description; people scan for what they *will* do. The owner's complaint was that the rescue was a trick submit, not that it was prominent — it was deliberately prominent in the UX funnel (§3), and the Auth0 password-manager shock still exists for dormant players, so it stays a visible button, just an honest one. |
| Carry typed email without putting it in the URL | **Agree** — via `sessionStorage` (see §6.2). No-JS falls back to an empty field. | Keeps email out of access logs, history and `Referer`. Also fix the existing `request_password_reset → sign_in_link_request?email=` link the same way. |
| Bottom row "New here? Create an account" | **Disagree on position**: put it **under the heading** ("New to MySpeedPuzzling? **Create an account**"), mirrored on `/register` ("Already have an account? **Sign in**"). No bottom row. | The header's sign-in icon is what *new* visitors from Instagram press too. At 360 px with three provider buttons, a bottom row is ~2 screens down under the keyboard. The top line costs 24 px and is the first thing a screen reader reads after the h1. |
| No card on mobile | **Agree**, and on desktop use a borderless column too (max 440 px) next to the existing illustration; no shadow card anywhere. | One layout, fewer breakpoints; the illustration already gives the desktop page its "product" feel. |
| "← Back to sign in" above heading on sub-pages | **Agree.** 44 px-tall link with a chevron-left icon, above the h1, text *"Back to sign in"*. Keep no second copy at the bottom. | Top-left is where every mobile OS puts "back"; above the h1 it's never under the keyboard. |
| Identifier-first considered and rejected | **Confirmed rejected** (principle 3). One refinement adopted from the "adaptive single page" idea: *nothing* — we have no SSO routing need, so no email-driven UI changes at all. | Evidence above. |
| (not in draft) | **Add a 6-digit code to the sign-in e-mail, and a code entry on the "check your email" screen.** Owner decision — see §7. | Principle 6 + P10. |
| (not in draft) | **Show/hide password toggle** on every password field. | web.dev; NN/g (masking causes errors, especially on phones). |
| (not in draft) | **16 px inputs on auth pages; drop `maximum-scale=1` site-wide in a separate change.** | P7. |
| (not in draft) | **"Last used" badge** (localStorage, per device). | Principle 11. |
| (not in draft) | **In-app browser notice** + Google button that explains instead of 403-ing. | Principle 10. |
| (not in draft) | **Sign-in link/code keeps `?return=`.** | P9. |

---

## 4. Screens

Conventions for all screens:

- Layout: `.auth-page` = single column, `max-width: 440px`, left-aligned on desktop inside the
  existing `row` with the illustration column (`d-none d-md-block`) to the right; on < 768 px the
  column is the full container width (container padding 16 px each side). **No `.card`.**
- Vertical rhythm: h1 → 4 px → top cross-link line → 24 px → first control. 12 px between stacked
  buttons, 16 px between fields.
- Inputs `form-control-lg`-ish: **font-size 16 px (1rem), height 48 px**. Buttons 48 px tall,
  full width. Text links in the form get `padding-block: 10px` (≥ 44 px effective target).
- Headings: one `h1` per page, sized `h3` visually on mobile (28 px is too big at 360 px),
  `h2` look from `md` up.
- Error summary: an `alert` with `role="alert"` directly under the h1, *before* the fields, plus
  `aria-invalid="true"` + `aria-describedby` on the offending field (Symfony form errors already
  render inline for form types; `/login` errors are page-level by nature).
- Flash messages from `base.html.twig` still render (top of `<main>`), but the new screens don't
  rely on them for primary states — success states are their own screens (§4.3/4.5).
- Every POST that fails re-renders with **422** or redirects (CLAUDE.md Turbo rule). Every success
  redirects (303).
- Copy below is English source for `translations/messages.en.yml`; all `auth.*` keys need all
  **6 locales** (D17). Keys named here are proposals.

### 4.1 Sign in — `/login`

Mobile 360 px, social live, nothing typed yet:

```
┌──────────────────────────────────────┐
│ [site header — unchanged]            │
├──────────────────────────────────────┤
│ Sign in                              │  h1
│ New to MySpeedPuzzling?              │
│ Create an account                    │  link, bold
│                                      │
│ ┌──────────────────────────────────┐ │
│ │ G  Continue with Google  Last used│ │  48px, badge right
│ └──────────────────────────────────┘ │
│ ┌──────────────────────────────────┐ │
│ │   Continue with Apple            │ │  48px
│ └──────────────────────────────────┘ │
│ ┌──────────────────────────────────┐ │
│ │ f  Continue with Facebook        │ │  (when enabled)
│ └──────────────────────────────────┘ │
│   Also for Instagram users (Meta)    │  fs-xs muted, centered
│ Already have an account? You'll land │
│ in it if it uses the same email.     │  fs-sm muted
│                                      │
│ ─────────────── or ───────────────   │
│                                      │
│ Email                                │
│ ┌──────────────────────────────────┐ │
│ │                                  │ │  16px text, 48px
│ └──────────────────────────────────┘ │
│ Password            Forgot password? │  label row
│ ┌─────────────────────────────┬────┐ │
│ │                             │ 👁  │ │  toggle = 48×48 button
│ └─────────────────────────────┴────┘ │
│ ┌──────────────────────────────────┐ │
│ │            Sign in               │ │  btn-primary
│ └──────────────────────────────────┘ │
│ ┌──────────────────────────────────┐ │
│ │ ✉  Email me a sign-in code instead│ │  btn-outline-secondary (link)
│ └──────────────────────────────────┘ │
│                                      │
└──────────────────────────────────────┘
```

With social off (today's admin-only stage) the block from "Continue with Google" down to the "or"
divider is simply absent.

Desktop (≥ 992 px):

```
┌───────────────────────────────────────────────────────────────────┐
│ [header]                                                          │
│                                                                   │
│  Sign in                                     ┌─────────────────┐  │
│  New to MySpeedPuzzling? Create an account   │                 │  │
│                                              │   puzzlie-1.png │  │
│  [ G  Continue with Google      Last used ]  │   (unchanged)   │  │
│  [    Continue with Apple                 ]  │                 │  │
│  Already have an account? You'll land in it  └─────────────────┘  │
│  if it uses the same email.                                       │
│  ─────────────────── or ───────────────────                       │
│  Email                                                            │
│  [                                        ]                       │
│  Password                  Forgot password?                       │
│  [                                    | 👁 ]                      │
│  [               Sign in                  ]                       │
│  [ ✉  Email me a sign-in code instead     ]                       │
│   ← column max-width 440px                                        │
└───────────────────────────────────────────────────────────────────┘
```

**Order rationale at 360 × 640 (≈ 560 px visible under browser chrome)**: header 101 + h1 block 70
+ 2 provider buttons 108 + hint 40 + divider 40 = 359 → email field visible, password + Sign in at
the fold. With Facebook (3 buttons + hint) the Sign in button falls below the fold; acceptable
because password users arrive via autofill (the manager's bar sits above the keyboard, and tapping
Email scrolls the form into view). Apple's "no scrolling to see our button" is still met.

**Fields**

| Field | Attributes |
|---|---|
| Email | `type="email" name="email" id="login-email" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="next" required` + `value="{{ last_email }}"`. **No `autofocus`** (P8); exception: after a failed attempt focus goes to the password field (see states). |
| Password | `type="password" name="password" id="login-password" autocomplete="current-password" enterkeyhint="go" required`. Never `maxlength`, never block paste. |
| Show/hide | `<button type="button" class="password-toggle" aria-controls="login-password" aria-pressed="false">` with visually-hidden text *"Show password"* / *"Hide password"*; 48 × 48 inside the input group; switches `type` between `password`/`text`; resets to `password` on submit (so managers see a password field when saving). |
| "Forgot password?" | `<a href="/password-reset">` in the label row, right-aligned, `fs-sm`, 44 px tall hit area. It stays in the label row, i.e. *before* the input in DOM order (visual order = DOM order, WCAG 2.4.3); the `<label for="login-password">` is unaffected. |

**States**

- *Submitting*: Turbo disables the submitter; add `data-turbo-submits-with="Signing in…"` and a
  spinner. Provider buttons (`data-turbo="false"` links): on click add `.is-loading` + `aria-busy`,
  and remove it on `pageshow` (bfcache back from the provider).
- *Failed (any reason — wrong password, unknown email, throttled by the authenticator)*: the page
  re-renders (current mechanism) with the email kept and **focus on the password field**, and an
  alert under the h1:
  > **That email and password don't match.**
  > If your password manager saved it, search it for "speedpuzzling" — older entries may be filed
  > under a different web address.
  > [ ✉ Email me a sign-in code ]   ← same honest link as below, pre-filled via sessionStorage

  The authenticator's own translated messages stay (security domain), the helper replaces
  `_login_failure_helper.html.twig` (shorter copy, same "identical for every reason" rule).
- *Throttled* (authenticator rate limiter): same alert, first line replaced by
  *"Too many attempts. Wait a few minutes, or get a sign-in code by email."* — generic, doesn't
  reveal existence.
- *Social failure* (`SocialLoginFailed` messages): alert under h1, unchanged copy.
- *`?social=expired`*: warning under h1, unchanged copy.

**Copy (EN)**

| Key | Text |
|---|---|
| `auth.login.title` | Sign in |
| `auth.login.new_here` | New to MySpeedPuzzling? %link%Create an account%/link% |
| `auth.login.email` | Email |
| `auth.login.password` | Password |
| `auth.password_reset.link` | Forgot password? |
| `auth.login.submit` | Sign in |
| `auth.login.submitting` | Signing in… |
| `auth.login.code_instead` | Email me a sign-in code instead |
| `auth.social.existing_account_hint` | Already have an account? You'll land in it if it uses the same email. |
| `auth.social.facebook_hint` | Also for Instagram users (Meta account) |
| `auth.social.last_used` | Last used |
| `auth.password.show` / `auth.password.hide` | Show password / Hide password |
| `auth.failure_helper.title` | That email and password don't match. |
| `auth.failure_helper.vault_tip` | If your password manager saved it, search it for "speedpuzzling" — older entries may be filed under a different web address. |

**Remove from `/login`**: the card; the hidden `#sign-in-link-form` + `sign_in_link_controller.js`
mirroring; the explainer "We send the link to the address above. One click and you are in."; the
bottom "Forgot password? · Create an account" row; the `bi-box-arrow-in-right` icon on the primary
button (noise); `autofocus`.

### 4.2 Create account — `/register`

```
┌──────────────────────────────────────┐
│ Create an account                    │  h1
│ Already have an account? Sign in     │
│                                      │
│ [ G  Continue with Google          ] │
│ [    Continue with Apple           ] │
│ [ f  Continue with Facebook        ] │
│   Also for Instagram users (Meta)    │
│ ─────────────── or ───────────────   │
│ Email                                │
│ [                                  ] │
│ Password                             │
│ [                             | 👁 ] │
│ At least 8 characters.               │  help, aria-describedby
│ Your name (optional)                 │
│ [                                  ] │
│ Shown next to your times. You can    │
│ change it any time.                  │
│ [          Create account          ] │  btn-primary
│ By creating an account you agree to  │
│ the Terms of service and Privacy     │
│ policy.                              │  fs-xs muted, links
└──────────────────────────────────────┘
```

- **Social first on `/register` too** — this is where it pays most (no password to invent). The
  "existing account" hint is *not* shown here.
- **Field order: email → password → name (optional).** Required first; the optional field last is
  easy to skip. Password managers pair the text field *immediately before* the password field as
  the username, and that stays the email in this order. (Getting-started doc keeps the name; only
  its position changes — owner may veto.)
- Fields:
  - Email: `type=email autocomplete="username"` (web.dev pairs `username` with `new-password` so
    the manager stores the email as the login; keep), `autocapitalize="none" autocorrect="off"
    spellcheck="false" enterkeyhint="next"`, no autofocus.
  - Password: `autocomplete="new-password" enterkeyhint="next"` + toggle + `minlength="8"` (lets
    the browser validate, and hints generators). Rule shown **before** typing ("At least 8
    characters."), not only after an error (Baymard #4). No strength meter, no composition rules
    (NIST 800-63B). Keep the existing "suggest strong password" hook
    (`data-password-suggestion-target`) as is.
  - Name: `autocomplete="nickname" enterkeyhint="done"`, label carries "(optional)" in the label
    text itself (not only in help), help "Shown next to your times. You can change it any time."
- Terms: one line under the button, no checkbox (a registration consent is a contract acceptance,
  not GDPR consent; a checkbox adds a tap and an error state for no legal gain — **owner to
  confirm with the privacy policy wording**).
- States: Symfony inline errors + 422 (already, via `'form' => $form`). *Email taken*: inline on
  the email field: *"This email already has an account. %link%Sign in%/link% or %link2%get a
  sign-in code%/link2%."* (links, not prose telling users to find a button). *Rate-limited*:
  alert under h1 "Too many sign-ups from this network. Try again in an hour." (redirect as today).
- Success: unchanged → `/welcome` (verification mail sent in the background; welcome screen already
  says so).
- **Remove**: card, `bi-person-plus` icon, the intro sentence "One account for your times,
  collections and everything else…" (the h1 says it; keep only if owner wants marketing copy — then
  one line, muted), bottom "Already have an account? Sign in" (moved to top).

### 4.3 Sign-in code request — `/login-link` (GET form)

```
┌──────────────────────────────────────┐
│ ‹ Back to sign in                    │  44px link, above h1
│ Get a sign-in code                   │  h1
│ We'll email you a code and a link.   │
│ Either one signs you in — no         │
│ password needed.                     │
│ Email                                │
│ [ jane@example.com                 ] │  prefilled from sessionStorage
│ [          Email me a code         ] │  btn-primary
└──────────────────────────────────────┘
```

- Email: `type=email autocomplete="username" … enterkeyhint="send"`, `autofocus` **only if empty**
  (single-field page; the user came here to type exactly this).
- POST → on success **303 to `/login-link/sent`** (new GET screen, §4.4). The address is handed
  over in a flash-bag entry (session already exists on this path because of flashes today — no
  new anonymous-session regression; see §6.4). Empty/invalid email → re-render with **422** and an
  inline field error "Enter your email address." (no redirect-with-warning loop as today).
- Rate-limited → 422, alert under h1: *"You've asked for several codes in a row. Wait a few minutes
  — the last one we sent still works."*
- Without the code feature (if owner says no, §7 D1) the same screen reads "Get a sign-in link" /
  "Email me a sign-in link" and §4.4 drops the code input.

### 4.4 Check your email — `/login-link/sent` (new)

```
┌──────────────────────────────────────┐
│ ‹ Back to sign in                    │
│ Check your email                     │  h1
│ We sent a 6-digit code to            │
│ jane@gmail.com                       │  bold
│ Wrong address? Change it             │  link → /login-link (prefilled)
│                                      │
│ Code                                 │
│ [ _ _ _ _ _ _                      ] │  one input, not 6 boxes
│ [             Sign in              ] │
│                                      │
│ Or tap the link in the email. It     │
│ works once, for 30 minutes.          │  fs-sm muted
│ [   Open Gmail  ↗ ]                  │  only for known webmail domains
│                                      │
│ Nothing arrived? Check spam, or      │
│ [ Send a new code ] (in 0:45)        │  disabled + countdown, then enabled
└──────────────────────────────────────┘
```

- If the flash is gone (reload, direct visit) → 303 to `/login-link`.
- Code input: `type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}"
  maxlength="6"` (accept pasted "123 456" — strip whitespace server-side), `enterkeyhint="go"`,
  **no `autofocus`** (2026-09-30: on iOS focusing it on load popped the iCloud Passwords "fill
  username" sheet; `sign_in_code_controller.js` focuses it for fine pointers only), id
  `one-time-code`, button "Verify code", POST `/verify-code` - nothing on the form says
  login/sign-in, so WebKit does not treat it as a login form; label "Code". **One input, not six boxes**: six boxes break paste,
  autofill and screen readers (SC 3.3.8 requires paste). Auto-submit when 6 digits are pasted/filled
  is fine; typing must not auto-submit mid-correction — submit on the 6th digit only when the
  previous value was shorter than 5 (i.e. paste/autofill).
- Wrong code → 422, inline "That code doesn't work. Check the latest email — each new code replaces
  the old one." After 5 wrong codes for a request the code dies (link too); message "Too many tries.
  Send yourself a new code." (Limit in the handler, not only the IP limiter.)
- "Open Gmail" button: shown only for `gmail.com`/`googlemail.com` → `https://mail.google.com/`,
  `outlook.com/hotmail.*/live.*` → `https://outlook.live.com/mail/`, `yahoo.*` →
  `https://mail.yahoo.com/`, Seznam → `https://email.seznam.cz/` (iCloud dropped 2026-09-30: those
  users read mail in the iPhone Mail app, icloud.com/mail is no use to them); covers
  ~84 % of our users. On phones these universal links open the mail app when installed. No button
  for other domains (we'd guess wrong). `target="_blank" rel="noopener"`.
- Resend: POSTs the flashed address again (hidden field) to `/login-link`; the button is disabled
  for 45 s by a small Stimulus countdown (server limit stays 3 / 15 min per address). A resend
  lands back here with "We sent a new code. The old one no longer works."
- Copy of the e-mail (emails domain): subject *"123 456 is your MySpeedPuzzling sign-in code"*
  (code in the subject = visible in the notification, no app switch needed on most phones), body:
  big code, then button "Sign in", then "Both work once and expire in 30 minutes." and the
  existing "Did not request this?" line.
- Anti-enumeration: identical screen and timing for unknown addresses (no mail is sent; a code
  entered for them just "doesn't work").

### 4.5 Forgot password — `/password-reset` → check your email

Request screen:

```
┌──────────────────────────────────────┐
│ ‹ Back to sign in                    │
│ Reset your password                  │
│ Enter your email and we'll send you  │
│ a link to choose a new password.     │
│ Email                                │
│ [                                  ] │
│ [       Email me a reset link      ] │  btn-primary
│                                      │
│ Just want to get in? Get a sign-in   │
│ code instead                         │  link → /login-link (sessionStorage)
└──────────────────────────────────────┘
```

On success **303 → `/password-reset/sent`** (new), same structure as §4.4 minus the code input:

```
│ ‹ Back to sign in                    │
│ Check your email                     │
│ We sent a reset link to              │
│ jane@gmail.com                       │
│ Wrong address? Change it             │
│ The link works once, for 60 minutes. │
│ [   Open Gmail  ↗ ]                  │
│ Nothing arrived? Check spam, or      │
│ [ Send a new link ] (in 0:45)        │
```

(Reset stays link-only: the reset page is a destination with a form, and opening it in another
browser is harmless — nobody gets signed in by it.)

Remove: the card, the "or" + outline sign-in-link button (demoted to the text line above — one
primary action per page), the `?email=` URL hand-off.

### 4.6 Choose a new password — `/password-reset/{token}`

```
│ Choose a new password                │
│ Your password manager will save it   │
│ for myspeedpuzzling.com.             │
│ New password                         │
│ [                             | 👁 ] │
│ At least 8 characters.               │
│ [        Save and sign in          ] │
```

- Include a hidden-but-present `<input type="email" autocomplete="username" value="{{ email }}"
  hidden readonly>` **inside the form** so managers store the new password under the right account
  (web.dev pattern; today the manager has no username to attach it to). This needs the token's
  account email — handler already loads it; pass it to the template (no enumeration: the holder
  of a valid token owns the mailbox).
- **Sign the user in after the reset** (change from today, where they are sent back to `/login`
  to type the password they just chose). Owner decision — see §7 D4. If rejected, keep the button
  label "Save new password" and redirect to `/login` with the email prefilled.
- Dead token page: keep, add "‹ Back to sign in" above h1, primary button "Email me a new link".

### 4.7 Social sign-up interstitial — `/register/social`

Normal variant (provider email not known to us):

```
│ One more step                        │  h1
│ You're signing in with Google as     │
│ jane@gmail.com. There's no           │
│ MySpeedPuzzling account with this    │
│ email yet.                           │
│ [        Create my account         ] │  primary
│                                      │
│ Already have an account under a      │
│ different email?                     │
│ [  Sign in to it and connect Google ] │ outline
│ This keeps your times in one place.  │  fs-sm muted
```

Apple "Hide My Email" variant — keep today's inverted priority, shorten:

```
│ Have you used MySpeedPuzzling before?│  h1
│ Apple is hiding your email, so we    │
│ can't find your account.             │
│ [   Yes — sign in and connect Apple ] │ primary
│ [   No — I'm new, create my account ] │ outline
│ Our emails reach you through Apple's │
│ forwarding address.                  │  fs-sm muted
```

- Remove the card; keep the FAQ duplicate-accounts line at the bottom.
- Copy must be translated to all 6 locales (today the normal variant is EN-only).

### 4.8 In-app browser notice (Instagram / Facebook / Messenger / Threads / TikTok / LINE)

Detection: server-side UA match (`Instagram`, `FBAN`, `FBAV`, `FB_IAB`, `Threads`, `Line/`,
`BytedanceWebview`, `musical_ly`) in a small `InAppBrowser` Twig function. **No false positives on
real browsers** — do *not* match the generic Android `; wv)` token (it also hits legitimate apps
that use Custom Tabs fallbacks). The auth pages are `no-store`, so server-side detection is
cache-safe.

Where: `/login` and `/register`, above the provider buttons:

```
│ ┌──────────────────────────────────┐ │
│ │ ⓘ You're in Instagram's browser  │ │  alert-info, compact
│ │ Google sign-in doesn't work here.│ │
│ │ [ Open in Chrome ]               │ │  Android only (intent: URL)
│ │ iPhone: tap ••• then "Open in    │ │  iOS only
│ │ external browser".               │ │
│ │ [ Copy link ]                    │ │  both
│ └──────────────────────────────────┘ │
```

- Android: `intent://{host}{path}#Intent;scheme=https;package=com.android.chrome;end` behind a real
  tap. iOS: no reliable programmatic escape (Meta drops `x-safari-https` and recent Instagram builds
  filter it even on tap: <https://plugwith.me/blog/what-escapes-instagram-in-app-browser-in-2026/>)
  → instruction + copy-link (Clipboard API, fallback select-text).
- The **Google button stays visible but, in a detected webview, its `href` is replaced** by an
  anchor to the notice (and the notice gets a subtle highlight/focus) instead of starting OAuth →
  no `403 disallowed_useragent` dead end. Apple (web flow works in webviews, the user types Apple
  ID credentials) and Facebook (works in Meta's own webview) are left alone. Email + password and
  the **code** work normally — which is the main reason for §4.4's code: in the webview the link
  would sign in Safari/Chrome, the code signs in the tab the visitor is actually in.
- Copy: `auth.in_app.title` "You're in %app%'s browser", `auth.in_app.google` "Google sign-in
  doesn't work here.", `auth.in_app.open_android` "Open in Chrome", `auth.in_app.ios_hint` "Tap •••
  (top right), then “Open in external browser”.", `auth.in_app.copy` "Copy link" / "Link copied".

---

## 5. Cross-cutting details

### 5.1 "Last used" badge

- On click of a provider button → `localStorage['msp.lastSignIn'] = 'google'|'apple'|'facebook'`;
  on submit of the password form → `'password'`; on submit of the code form → `'code'`. (Clerk
  stores on choice, not on success — same here: no server involvement, no cookie, no effect on the
  anonymous-cache/session constraints.)
- Render: a small `badge` "Last used" inside the matching provider button (right side), or above
  the Email label ("Last used") for password; nothing for code. Pure client-side Stimulus
  (`last-sign-in` controller), progressive — no layout shift: the badge sits inside an existing
  button, absolutely positioned, so no reflow.
- Privacy: reveals only *which method* this device used, never the address. Cleared by "Sign out
  everywhere"? Not needed. Wrapped in try/catch (private mode).
- Do **not** reorder buttons based on it (muscle memory, provider parity rules).

### 5.2 Keyboard & mobile behaviour

- `enterkeyhint`: email `next`, password `go` (login) / `next` (register), name `done`, single-field
  pages `send`, code `go`.
- No sticky/fixed bottom button: iOS moves fixed elements unpredictably with the keyboard and it
  hides the field being typed into. The form is short enough to scroll naturally.
- `scroll-margin-top: calc(var(--header-height) + 16px)` on inputs so focus/scroll-into-view never
  hides a field under the sticky header.
- Bottom padding `max(24px, env(safe-area-inset-bottom))` on `.auth-page` (viewport already uses
  `viewport-fit=cover`).
- 16 px inputs (P7). Separately (own ticket, site-wide): remove `maximum-scale=1` from the viewport
  meta once all inputs are ≥ 16 px, restoring pinch-zoom (WCAG 1.4.4).
- Dark mode: the site has no dark theme; out of scope. Provider buttons already follow brand light
  variants.

### 5.3 Accessibility checklist (WCAG 2.2 AA)

- One h1; back link before it is a normal `<a>` (not a button), first in tab order after skip-link.
- Every input has a visible `<label>`; "(optional)" inside the label; help text via
  `aria-describedby`.
- Error alert `role="alert"` + focus moved to the first invalid field on 422 renders
  (`autofocus` attribute server-side on that field, the only place autofocus is used besides
  single-field pages).
- Password toggle: real `<button type="button">`, `aria-pressed`, label updates; toggling never
  moves focus.
- Targets: all interactive elements ≥ 44 px tall (2.5.8 needs 24).
- 3.3.8: paste allowed everywhere, `autocomplete` tokens everywhere, code input pasteable, no
  CAPTCHA.
- 3.3.7 Redundant entry: email carried between auth pages (sessionStorage) — no retyping.
- Contrast: muted hint text must still be ≥ 4.5:1 (`$gray-600` #7d879c, used for muted text, is 3.6:1 on white
  — use `$gray-700` for the hint lines).

### 5.4 Return URLs, remember-me, sessions

- `?return=` must survive: `/login` → social (already), → password (already), → code/link (**new**:
  store the validated return in the sign-in-link request — e.g. as a signed parameter in the link
  and in the code request row — and redirect there on success; `LoginLinkSuccessHandler` falls back
  to `my_profile`; the legacy set-password prompt still wins when it applies), → "Create an account"
  (**new**: the top cross-link keeps `?return=`, `RegisterController` redirects to `/welcome?return=`
  and the welcome screen's "Skip, take me in" goes there instead of the Hub).
- Remember-me stays always on, no checkbox (Baymard #7: ≥ 14 days). No shared-computer line on the
  form (adds noise for 99 %); add a one-line FAQ entry "Signed in on a shared computer? Sign out when
  you're done — you stay signed in for 30 days otherwise." Owner may want it on the page — §7 D6.

---

## 6. Implementation notes

### 6.1 Files that change

| File | Change |
|---|---|
| `templates/login.html.twig` | Rewrite per §4.1; drop hidden `#sign-in-link-form`. |
| `templates/register.html.twig` | §4.2; top cross-link; terms line. |
| `templates/sign_in_link.html.twig` | §4.3; back link on top; no card. |
| `templates/sign_in_link_sent.html.twig` (**new**) | §4.4. |
| `templates/request_password_reset.html.twig` | §4.5. |
| `templates/password_reset_sent.html.twig` (**new**) | §4.5 sent state. |
| `templates/password_reset.html.twig`, `password_reset_dead_token.html.twig`, `set_password_after_sign_in_link.html.twig` | no card, toggle, hidden username field, back link. |
| `templates/social_register_confirm.html.twig`, `_social_register_relay.html.twig` | §4.7. |
| `templates/_social_login_buttons.html.twig` | Divider moves *below* the buttons ("or" after social); hint line (login only, param); in-app notice include; last-used hooks. |
| `templates/_login_failure_helper.html.twig` | Shorter copy, link instead of `form=` submit. |
| `templates/_auth_back_link.html.twig`, `_password_input.html.twig`, `_in_app_browser_notice.html.twig` (**new partials**) | Shared pieces so the pages can't drift (same principle as `_social_provider_button`). |
| `templates/sign_in_link_check.html.twig` | no card; keep the self-submitting form untouched. |
| `src/Controller/SignInLinkController.php` | 422 on empty/invalid; 303 → `sign_in_link_sent` with the address in a flash; carry `return`. |
| `src/Controller/SignInLinkSentController.php` (**new**, GET `/login-link/sent`) + `SignInCodeController` (**new**, POST `/verify-code`, was `/login-link/code` until 2026-09-30) | Screen + code check. |
| `src/Controller/RequestPasswordResetController.php` + `PasswordResetSentController.php` (**new**) | Same pattern. |
| `src/Controller/PasswordResetController.php` | Pass account email for the hidden username field; optional auto sign-in (§7 D4). |
| `src/Security/LoginLinkSuccessHandler.php` | Honour validated return URL. |
| Sign-in code backend (**new**, if D1 = yes) | `RequestSignInLink` handler also mints a 6-digit code (store hash + request id + attempts + expiry = link expiry, single-use, invalidated by a newer request and by link use); a `SignInCodeAuthenticator` on `main` (or `Security::login()` from the handler-backed controller with `RememberMeBadge`, like `RegisterController`), audit events `sign_in_code_used` / `login_failure` with reason; per-request attempt cap 5 + IP limiter. Mail template `emails/sign_in_link` gets the code + subject. |
| `src/Twig/…` | `in_app_browser()` function (UA → app name or null). |
| `assets/controllers/password_toggle_controller.js`, `auth_email_handoff_controller.js`, `last_sign_in_controller.js`, `resend_countdown_controller.js`, `copy_link_controller.js` (**new**) | Small, dependency-free. Texts via `data-*` attributes (translations rule). |
| `assets/controllers/sign_in_link_controller.js` | Delete. |
| `assets/styles/components/_auth.scss` (**new**) | `.auth-page`, 16 px/48 px inputs, toggle, badge, safe-area. |
| `translations/messages.{en,cs,de,es,fr,ja}.yml`, `emails.*.yml` | New/changed `auth.*` keys, all 6 locales. |
| `docs/features/auth-hardening/README.md`, `auth-migration/README.md` (UX funnel §3/§4) | Point to this doc; funnel §3 now = "code instead" button, §4 = helper link. |
| Tests | Update `SocialProviderButtonsTest`, `RememberMeTest`, login-failure-helper tests, sign-in-link controller tests (422 instead of redirect-with-flash); new tests for sent screens (flash missing → redirect), code auth (valid, wrong ×5, expired, superseded, used link kills code), return URL through link/code, in-app UA detection (true/false positives list). |

### 6.2 Email hand-off without URLs

`auth-email-handoff` Stimulus controller on every auth page: on `input` of an `autocomplete=username`
field write `sessionStorage['msp.authEmail']`; on connect, fill an *empty* email field from it.
sessionStorage is per-tab and dies with the tab — right lifetime, never sent to the server, never
in logs. Server-side values (`last_email`, the flashed address) win over it.

### 6.3 Routes

New: `GET /login-link/sent` (`sign_in_link_sent`), `POST /verify-code` (`sign_in_code`),
`GET /password-reset/sent` (`password_reset_sent`). All with the `_auth_page` default
(locale negotiation + `no-store`). **Route order**: `/password-reset/sent` must be declared before
`/password-reset/{token}` or get a requirement that excludes it (`sent` matches
`[0-9a-zA-Z]{1,128}`!) — the cleaner fix is a priority on the static route.

### 6.4 Risks

- **Password managers**: keep one `<form>` per task, `username` + `current-password` together on
  `/login`, visible labels, no JS-injected fields, toggle resets `type=password` before submit.
  Test 1Password, Bitwarden, iCloud Keychain, Chrome GPM on Android after the change — the Auth0
  funnel depends on the manager recognising the page.
- **Turbo**: every POST answers 303 or 422 (never 200). Code form: 422 on wrong code. Provider
  buttons stay `data-turbo="false"`. The sign-in-link check page keeps `data-turbo="false"`.
- **Anonymous cache / sessions (#164)**: GET `/login`, `/register`, `/login-link`, `/password-reset`
  must stay session-free (last-used + hand-off are client-side for that reason). The "sent" screens
  use a flash → a session on a *POST-then-redirect* path, which already happens today with the
  success flash. Auth pages are `no-store` already (`NativeAuthPageSubscriber`), so server-side UA
  detection is safe.
- **Enumeration (D8)**: sent screens identical for unknown addresses; code screen accepts a code
  "for" any address and simply fails; login failure copy identical for every reason.
- **Code brute force**: 6 digits = 10⁶; 5 attempts per issued code + 3 codes / 15 min / address →
  ≤ 1 in 66 000 per 15 min window per address; plus the IP limiter. Acceptable for this threat
  model; log `warning` on attempt-cap hits only when they cluster (else `info`).
- **Mail link scanners**: code in the same mail is unaffected by scanners (nothing to prefetch).
  Using the link must invalidate the code and vice versa (single-use per request).
- **Translations**: new copy needs 6 locales before release (auth UI rule D17).
- **In-app detection false positives** would hide Google from real browsers — keep the UA list
  explicit and tested; the Google button is only *re-targeted*, never removed.

### 6.5 Out of scope

Passkeys (deferred by owner — the layout leaves room: a "Sign in with a passkey" button would sit
with the provider buttons and `autocomplete="username webauthn"` goes on the email field);
Microsoft sign-in (planned separately — the provider stack takes a fourth button; at 4 providers
consider a 2-column grid of logo+name buttons on ≥ 400 px only); dark mode; account merging;
the site-wide viewport-meta change (own ticket, see §5.2); header redesign.

---

## 7. Owner decisions needed

| # | Question | Recommendation |
|---|---|---|
| D1 | Add a 6-digit **code** to the sign-in e-mail + code entry screen (backend work: code storage, authenticator, audit, limits)? | **Yes.** It fixes the Instagram/in-app case and the "link opened in another browser" case on phones, and the subject-line code avoids the app switch. Can ship as phase 2 after the layout (phase 1 would then say "sign-in link" everywhere). |
| D2 | Name field on `/register`: keep but move below password (optional), or drop entirely (asked on `/welcome` → finish profile)? | Keep, move last. |
| D3 | Terms: a sentence under the button (no checkbox) OK legally? | Yes, sentence. |
| D4 | After choosing a new password via reset link: **sign in automatically** (today: sent to `/login`)? Current code comment says deliberately not — "proving control of the mailbox resets the password, it does not authenticate the browser". | Sign in — the sign-in link already treats mailbox control as authentication, so the extra step adds friction, not security. |
| D5 | "Last used" badge (localStorage, per device)? | Yes. |
| D6 | "Shared computer? Sign out when done" line on the sign-in page, or FAQ only? | FAQ only. |
| D7 | Keep "Email me a sign-in code instead" as a visible secondary button on `/login` (UX funnel §3), or demote to a text link now that the Auth0 migration is months old? | Keep as a button until the legacy-password tail is gone (check `auth_audit_log` for sign-in-link use rate first). |

---

## 8. Phase 1 - as built (2026-09-29)

Owner decisions applied: D1 code → phase 2; D2 name kept, moved last, optional; D3 **terms
mechanism unchanged** - `/register` had no checkbox and no sentence before, so none was added;
D4 auto sign-in after reset **yes**; D5 last-used badge **yes**; D6 shared computer → FAQ entry
`faq#shared-computer` (6 locales); D7 sign-in link stays a visible secondary button.

| Screen | What shipped |
|---|---|
| Frame | `_auth_layout.html.twig` (borderless column max 440px + illustration from md up, no card anywhere), `assets/styles/_auth.scss` (16px/48px inputs, 48px buttons, 44px text-link targets, `$gray-700` hints, dark alert text, safe-area bottom padding), `_auth_back_link.html.twig` above the h1 of every sub-page. |
| `/login` | h1 → "New to MySpeedPuzzling? Create an account" (keeps `?return=`) → failure alert → in-app notice → provider buttons + existing-account hint → "or" → email/password (label-row "Forgot password?", toggle) → "Email me a sign-in link instead" (plain link, keeps `?return=`). Hidden sign-in-link form + `sign_in_link_controller.js` deleted. No autofocus except the password after a failed attempt. `failure_kind` from `LoginController`: credentials/throttled → one alert with the rescue link (identical for unknown address and wrong password), anything else (social failures, CSRF) → its own message. |
| `/register` | Cross-link on top, social first, email → password (rule shown before typing, `minlength`) → "Your name (optional)" last. Email taken → inline error with *Sign in* / *get a sign-in link* links (`email_taken`). |
| `/login-link` | Back link, one field, `novalidate`; empty/invalid → **422** inline error (`aria-invalid` + `aria-describedby`), rate-limited/failed → 422 alert. Success → **303 `/login-link/sent`**. |
| `/login-link/sent` (new) | `SignInLinkSentController`; address via flash (`Services\CheckEmailFlash`), no flash → 303 back. Shows address, "Wrong address? Change it", next step, "Open Gmail/Outlook/Yahoo/iCloud/Seznam" (`Value\WebmailProvider`), resend POST with a 45 s `resend_countdown` (server limits unchanged). Template `_auth_check_email.html.twig` is **embedded** with a `next_step` block - phase 2's code input goes there. |
| `/password-reset` → `/password-reset/sent` (new) | Same pattern; `PasswordResetSentController` has `priority: 10` (the `{token}` requirement matches "sent"). "Just want to get in? Get a sign-in link instead" replaces the second button. |
| `/password-reset/{token}` | Hidden `autocomplete=username` field with the account e-mail, toggle, "Save and sign in" → `Security::login()` with `LoginFormAuthenticator` + `RememberMeBadge`, 303 to the profile. Audit: `password_reset_completed` + `login_success`. Dead-token page: back link + "Email me a new link". |
| `/register/social` | Both variants restyled and shortened (6 locales). |
| In-app browsers | `Value\InAppBrowser` (Instagram, Threads, Messenger, Facebook, TikTok, LINE - never the bare `; wv)`), Twig `in_app_browser()` / `chrome_intent_url()`. Notice on `/login` + `/register`; Google button re-targeted to `#in-app-browser-notice` (`:target` highlight); "Open in Chrome" intent on Android, "Tap •••" hint on iOS, copy link everywhere. |
| JS | `password_toggle`, `auth_email_handoff` (sessionStorage `msp.authEmail`), `last_sign_in` (localStorage `msp.lastSignIn`, + provider button loading state reset on `pageshow`), `resend_countdown`, `copy_link` - all lazy, texts from data attributes. |
| Return URL | `?return=` → `/login-link` → booked in `login_link_request.return_path` (never an unsigned URL parameter) → `SingleUseLoginLinkHandler` puts it on the request → `LoginLinkSuccessHandler` re-validates via `ReturnUrl`. The legacy set-password prompt still wins. |

**Deviations from the spec, and why**

- Password toggle keeps one accessible name ("Show password") and flips `aria-pressed` - renaming
  a toggle button *and* changing its pressed state announces the state twice (WAI-ARIA APG).
- A repeat "Forgot password?" request now mints another link (the handler used to stay silent
  while one was live) - otherwise "Send a new link" would be a lie. Older links keep working
  until any one is used (so nobody can void a link someone else is about to click); the per
  address/IP rate limiters are unchanged.
- The "Last used" tag sits on the button's top-right corner (outside the label), not inside the
  label row - long translations ("Pokračovat přes Google") would collide with it otherwise. Dark,
  not brand coral (white on #fe696a is < 3:1).
- Seznam added to the webmail buttons (large Czech share).
- `/welcome?return=` (spec §5.4) not built - `docs/features/return-url.md` D4 keeps registration
  ending on the welcome page; only the login ↔ register cross-links carry `?return=`.
- Viewport `maximum-scale=1` is **intentional** (stops iOS focus-zoom on < 16px inputs); left as
  is. Follow-up issue: make every input ≥ 16px site-wide first, then drop it.

---

## 9. Phase 2 - the 6-digit code, as built (2026-09-29)

Why: in Instagram/Facebook in-app browsers a tapped e-mail link opens the phone's own browser, so
the visitor ends up signed in *there*; same for "read the mail on the laptop, sign in on the
phone". A typed code signs in the browser that asked for it.

| Piece | What shipped |
|---|---|
| Mail | `RequestSignInLinkHandler` mints the code (`SignInCodeHasher::generate()`, `random_int`) with the link; subject `"%code% is your MySpeedPuzzling sign-in code"`, big monospace code (one unbroken run of digits - copy, long-press and OS one-time-code autofill get exactly six), then "Or sign in with one tap" + the unchanged link. 6 locales (`emails.*.yml` `sign_in_link.*`). The link, its self-submitting check page and the scanner grace window are untouched. |
| Storage | Same `login_link_request` row (one request = one sign-in): `code_hash` = HMAC-SHA256(`kernel.secret`, row id + code) - the row id is the per-request salt, `code_failed_attempts`, `code_used_at`. Constant-time compare (`hash_equals`). |
| Binding to the browser | `SignInLinkController` picks the request id (`RequestSignInLink::$requestId`) and `SignInCodePending` keeps it in the session (plus the address, expiry = link lifetime) - never in a URL. The session already exists on this path (the flash). Unknown addresses get a pending id no row will ever carry, so screen, countdown and failure look identical (D8). A new request replaces the pending one ("The old one no longer works here" - the older mail's *link* still works). |
| Screen | `/login-link/sent` renders while a sign-in is pending - also after a reload (in-app browsers may reload when the visitor switches to the mail app); first view still reads the flash for the "we sent a new one" line. Code form in the `next_step` block: one input, `inputmode=numeric`, `autocomplete=one-time-code`, `pattern=[0-9]*`, `maxlength=6`, `enterkeyhint=go`, autofocus, 28px monospace with letter-spacing. `sign_in_code_controller.js`: keeps digits of a paste ("123 456" before maxlength cuts it), drops non-digits, submits on the 6th digit (never the same six twice in a row). Works without JS (server strips spaces/dashes/nbsp). |
| Authentication | `SignInCodeAuthenticator` on `main`, `supports()` = exactly `POST /verify-code` (route `sign_in_code`) - it never fails on anything else, so the remember-me cookie of other requests is safe. Order: pending sign-in in this session -> CSRF (stateless id `sign_in_code`) -> six digits -> limiters -> `VerifySignInCode` (handler locks the row `FOR UPDATE`, counts, consumes, *reports* a `SignInCodeCheck` instead of throwing so the attempt counter commits). Success = normal login (session migration, always-on `RememberMeBadge`, `LoginSuccessEvent`), landing shared with the link (`LoginLinkSuccessHandler`: legacy set-password prompt, booked `?return=`, profile), 303. Failure -> `SignInCodeController` re-renders the screen with **422**. |
| Limits | 5 wrong codes per issued code (`LoginLinkRequest::MAX_CODE_ATTEMPTS`), then the **code** dies and the **link keeps working** - guessing digits teaches nothing about the link's signature, and whoever holds the mail should still get in with one tap; killing the link would only punish the person who mistyped. On top: `sign_in_code_email` 10 / 15 min per address (so fresh codes don't buy fresh guesses), `sign_in_code_ip` 30 / 15 min. With 3 requests / 15 min per address that is <= 10 guesses per 10^6 codes per window. |
| One sign-in per request | Link used -> `consumed_at` set -> code says "already been used". Code used -> `consumed_at` + `code_used_at` -> `consumeIfOpen()` refuses the link even inside the 60 s scanner grace window. |
| Messages | Wrong: "That code isn't right. N tries left." (plural forms per locale), 5th: "Too many tries — this code no longer works. Use the link in the email, or request a new code.", limiter: "Too many tries. Wait a few minutes, or use the link in the email.", expired / used alerts, not six digits: "Enter the 6 digits from the email." (costs no try). Nothing pending (other browser, used, locked) -> 303 to `/login-link` with "That sign-in code is no longer valid here." |
| Audit | `sign_in_code_used` (success), `sign_in_code_failed` with `metadata.code` = `SignInCodeOutcome` (`wrong`, `locked_out`, `expired`, `used`, `throttled`, `malformed`, `unknown`...) - never the typed value. Both on the recent-activity page. |
| Copy | "Email me a sign-in code instead" (login), "Get a sign-in code" / "We'll email you a code and a link..." (`/login-link`), "We sent a 6-digit code and a sign-in link to", "Enter the 6-digit code from the email", "Send a new code" - all 6 locales. |

**Deviations from §4.4, and why**

- The code is shown and put in the subject **without** the space ("123456", not "123 456"): an
  unbroken run is what one-time-code autofill and "copy code" chips pick up reliably; the input
  still accepts "123 456".
- Lock-out kills the code only, not the link (spec said "the code dies (link too)") - see Limits.
- Auto-submit on every sixth digit (not only on paste): the form never re-sends the same six digits,
  so correcting one digit is one new attempt, never a loop.
- Same-second requests: a Symfony login link is a pure function of account + expiry second, so two
  requests in one second produced the same link and the second row hit the unique hash (phase 1
  bug, reachable with a double tap). `SingleUseLoginLinkHandler` now shifts the colliding link's
  expiry by a second.

**Double submit fix (2026-09-30, production):** iOS filled the code from Mail and the form went
out twice with the same pre-login session cookie - the first POST signed in (`sign_in_code_used`),
the second found nothing pending (`sign_in_code_failed`/`no_pending_request`), showed "no longer
valid" and its `LoginFailureEvent` cleared the fresh remember-me cookie. Now: (1)
`sign_in_code_controller.js` lets one submission out (cancels later `submit` events before Turbo
sees them, input `readonly`, button disabled, `aria-busy`; unlocked on a failed `turbo:submit-end`);
(2) a success remembers its redirect for 60 s in `sign_in_code_completion_cache`, keyed by a hash
of the session id the request came with (`SignInCodeCompletion`) - that id is destroyed by the
session migration and only this browser holds it. With nothing pending and either that marker or
an authenticated token, `SignInCodeAuthenticator::supports()` steps aside and `SignInCodeController`
answers the same 303 (info log `duplicate_submit`, no failure row). The marker never signs anybody
in. (3) E-mail-only pages (`/login-link`, `/password-reset`) use `autocomplete="email"`, not
`username`, so iOS offers addresses instead of saved passwords; `username` stays on forms with a
password.

**Desktop polish (same release):** `_auth_layout.html.twig` centres the form + illustration as one
group (`.auth-frame`, max 60rem from md up) instead of form hard left / picture hard right at
1280px; mobile unchanged.

---

## 10. Order of the ways in (owner decision 2026-09-30)

**/login - email first.** All ~11.4k existing accounts have a password; social sign-in is new. Order:
heading + "New to MySpeedPuzzling? Create an account" -> in-app browser notice (unchanged, on top)
-> failure alert -> email -> password ("Forgot password?" on the label row, toggle) -> Sign in ->
"Email me a sign-in code instead" -> divider "or continue with" -> provider buttons -> "Same email
as your MySpeedPuzzling account? You'll land right in it." No providers configured -> no divider.
"Last used" keeps working on whichever element it marks.

**/register - equal-weight list, email first, progressive disclosure** (scales to 4-5 providers:
Facebook and Microsoft are coming): heading + "Already have an account? Sign in" -> in-app notice
-> **"Continue with email"** (envelope, neutral white/grey-stroke button like Google's, class
`btn-email-signin`) -> the providers. Tapping it opens the email / password / optional-name form
**right under that button** (`auth_disclosure_controller.js`: shows the panel, hides the button,
focuses the email field, `replaceState` to `?method=email` so a reload keeps it open, scrolls just
enough to show "Create account" without pushing the top of the form under the header); an
"or continue with" divider then leads to the providers below. Without JS the button is a link to
`/register?method=email`, rendered open server-side. The form is also rendered open for a
submitted form (422 errors, "email taken"), after the register redirects (throttled / failed ->
`?method=email`), and when no provider is configured (nothing to choose between). At 360x800 the
open form's "Create account" button is on screen right after the tap.

Tests: `tests/Security/AuthPageMethodOrderTest.php`.

