# Sandbox — sandbox.myspeedpuzzling.com

A public test copy of MySpeedPuzzling: API developers try the API and OAuth2 there, organisers try how an event is
set up, we try features — on made-up players and results, with no effect on anybody real.

The server side (compose stack, crons, secrets, first deploy and seed) lives in the infra repo:
`lily.srv` → `apps/msp-sandbox/README.md`, decision **D88**. This document is the design and the app's part.

## 1. Jan's calls (2026-11-13)

- Host **`sandbox.myspeedpuzzling.com`**.
- **Anyone may use it**, just for testing.
- **It must never be indexed.** Considered: basic auth with a simple password for everyone, or a Cloudflare challenge.
- **No real data, no payments, no effect on real users.** Made-up players may use addresses like
  `test-user+1@myspeedpuzzling.com`.
- **Everybody is a member.** Stripe: switched off, or the Stripe sandbox — both acceptable.
- **No newsletter, no social login.** Mobile apps are not in use.
- **As few crons as possible.**
- **Sentry: the same project, environment `sandbox`.**
- **No refresh from production.** Instead a cron in which a random player logs a time within the usual range on a
  random puzzle.
- No load testing planned (CrowdSec).

## 2. The one rule

**The sandbox shares nothing writable with production and holds nothing that identifies a person.**

Own database, Redis, Mercure, bucket `myspeedpuzzling-sandbox` (in production's Hetzner project - Jan's call; its key
could reach production's bucket and backups, the app only ever uses `S3_BUCKET`), own secrets (Infisical
`myspeedpuzzling-sandbox`; only `SENTRY_DSN` is production's), own OAuth2 keys (a sandbox token is worthless on production and the other way round). Its
e-mail is real but marked and kept apart from production (§6). The one thing it reads from production is the **public** image host for catalogue
pictures — what any browser gets.

## 3. Search engines: a welcome page with an Enter button

**Since 2026-11-13 (Jan's call, replaces the published basicAuth password `sandbox`/`sandbox`):** until a visitor
pressed **Enter**, every page answers the sandbox's welcome page (`templates/sandbox/gate.html.twig`, picture
`public/img/sandbox/sandbox-gate.webp`) with **401** + `noindex` + `no-store`: what the sandbox is, that nothing done
there touches production, that its e-mails are real with `[Sandbox]` in the subject. Enter is a **POST**
(`/sandbox/enter`, `SandboxEnterController`): it sets the cookie `msp_sandbox_entered` (1 year, HttpOnly, Lax) and
continues to the page asked for (`ReturnUrl::tryFrom()`, else `/`). Crawlers follow links, they do not submit forms,
so none of them ever gets a real page. The check (`SandboxGateSubscriber`) runs before routing - an unknown URL is the
welcome page too, never a 404 page of the site. Why not the password: links in e-mails (sign-in, password reset,
OAuth2 claim, an invitation) landed on the browser's password prompt; `https://user:pass@host` links are a phishing
pattern spam filters punish and browsers treat differently.

Even if a bot pressed the button, nothing would be indexed: every answer carries `X-Robots-Tag: noindex, nofollow,
noarchive, nosnippet, noimageindex` (Traefik on every router **and** the app's `SandboxNoIndexSubscriber`), every page
a `<meta name="robots" content="noindex…">` (`base.html.twig`), and `robots.txt` says `Disallow: /` (site and images).

Never behind the welcome page (`SandboxGate::isOpenPath()`, the same list as lily's open router): `/api/`,
`/oauth2/token`, `/internal-api/`, `/-/` (health, telemetry), `robots.txt`, `/sandbox/enter`; static files never reach
the app. They are JSON or files, carry `noindex` and nothing links to them from outside. `/api/docs` (Swagger UI) is
HTML there: noindex + robots.txt + no inbound links.

**Why not a Cloudflare challenge:** every API client (curl, a server-side OAuth2 token exchange) fails a challenge,
the challenge needs the host proxied through Cloudflare (today the `*.myspeedpuzzling.com` wildcard is DNS-only), and
it depends on Cloudflare's bot scoring instead of a rule that holds by construction.

The host will show up in Certificate Transparency logs (every Let's Encrypt certificate is public) — bots will
knock and get the welcome page with a 401.

## 4. Data

### What comes from production: the public catalogue — once

`myspeedpuzzling:sandbox:export-catalogue` (read-only, run on production) prints brands, puzzles, competition tags,
picture tags, public organizations, series, events, their rounds and round puzzles — what anybody can read on
myspeedpuzzling.com. `myspeedpuzzling:sandbox:import-catalogue` loads it on the sandbox. One class decides both:
`Services\Sandbox\SandboxCatalogue` (`COLUMNS` = the only columns that travel).

Never exported: any column naming a player (`added_by`, `approved_by`, `rejected_by`, …), rejection notes, picture
upload metadata, unapproved puzzles, still-secret puzzles (`hide_until` / `hide_image_until` in the future — also as
round puzzles), non-public events, and every table of people, results, collections, registrations, messages.
Real API developers want real puzzles: a real EAN scanned at home finds its puzzle on the sandbox too.

### What is made up: the people and their history

- **`test-user+1..200@myspeedpuzzling.com`** — names, countries and languages from `SandboxPeople` (invented
  combinations), registration dates spread over three years, every one a member, every one public, all with the
  password **`sandbox`** (published: take over any of them to test with a history). Plus
  **`test-user+free@myspeedpuzzling.com`** — the one free account (an ended grant).
- **A history** of ~25,000 results (`SandboxResultPlanner::history()`): a few heavy players, many occasional ones,
  mostly the piece count the player prefers, a first try on most first solves, repeats faster, ~8 % pairs and ~3 %
  teams, times from `SandboxTimeModel` (base by piece count × the player's speed × the puzzle's difficulty — both
  derived from their ids, so a player stays fast and a puzzle hard in every later result too — × noise).
- **Every result is logged through `AddPuzzleSolvingTime`**, the add-time form's own message, so statistics,
  rankings, XP, first tries, predictions, notifications and feeds follow exactly as for a person. The seed sets the
  app's clock to the moment the made-up player saves each result (`Clock::set()`, the `clock` service follows the
  global clock), so tracked dates look like a history that happened.
- **The activity cron** `myspeedpuzzling:sandbox:simulate-activity` (every 10 minutes, 2 results): a random made-up
  player (weighted by how active they are) logs a time within their usual range on a random catalogue puzzle — Jan's
  idea instead of refreshing from production.

**Why not a pseudonymised copy of production** (the first proposal): production's leaderboards are public, so a
pseudonymised player whose times match a real player's times on the same puzzles is that player again — renaming
does not anonymise a history. Jan's call is "no real data"; made-up histories need no scrubbing rules, no checks
and no table policy to keep current.

**Refusals:** the sandbox-only messages (`CreateSandboxPlayers`, `ImportSandboxCatalogue`, `GrantSandboxMembership`)
and commands call `SandboxMode::assertOn()` first: on production (`SANDBOX=0`) they refuse before writing anything
(`SandboxOnlyMessagesTest`). The export is the only one that runs on production, and it only reads.

## 5. Membership, payments

- **Everybody is a member through data**, not a code switch: a `membership.granted_until` far in the future
  (`SandboxMembership::grantedUntil()`, 2099-12-31) — the same mechanism as an admin grant or a free trial, so every
  members-only check (PHP, Twig and the many SQL queries) works unchanged. The seed grants it to the made-up players;
  `SandboxMembershipOnLoginSubscriber` → `GrantSandboxMembership` grants it on every sign-in on the sandbox (right
  after a registration too) to a player who has **no membership row** — the free account keeps its ended one.
- **Stripe:** a dummy key (`sk_test_sandbox_not_connected`): the client builds, nothing calls Stripe unless somebody
  presses Buy on the free account (an error, in Sentry under `sandbox`). With a **test-mode** key + webhook secret
  in Infisical the checkout works against the Stripe sandbox instead — lily's `deploy.sh` refuses a live key.

## 6. E-mail, newsletter, outside services

- **Real e-mail through production's SES account** (Jan 2026-11-13: organisers and API developers use the sandbox for
  real - sign-in links, password resets, OAuth2 claim links must arrive). `MAILER_DSN` = production's SES API DSN in
  the sandbox's Infisical project. `SandboxMailSubscriber` (sandbox only) guards every message:
  - mail to the made-up players (`test-user+…@myspeedpuzzling.com`, `SandboxPeople::isMadeUpEmail()`) is never sent;
  - the subject starts with `[Sandbox] `;
  - every sender moves to the sandbox's own SES identity `sandbox.myspeedpuzzling.com` (`notify@notify.…` →
    `notify@sandbox.…`, `robot@mail.…` → `robot@sandbox.…`; verified domain with Easy DKIM, `SandboxMail`);
  - every message carries the SES tag `sandbox=1`.
  Production's SES event handler ignores every event of a sandbox message (`SesEvent::isFromSandbox()`: the sender
  domain or the tag), so a bounce or complaint about a sandbox mail never suppresses an address or switches off
  anybody's e-mails on production. The sandbox itself receives no SES events (`SES_EVENTS_TOPIC_ARNS` empty), so it
  keeps no bounce list of its own - fine at its volume. No VERP (`BOUNCE_EMAIL_DOMAIN` empty).
- Newsletter off: empty `LISTMONK_*` — `ListmonkClient::isEnabled()` is false and every caller checks it.
- Off by empty settings, set to `""` explicitly in lily's compose (the image's own `.env` has real defaults for some):
  WJPF (their side stores pairings permanently), social login (no button, no route — `SocialLoginSettings`), Google
  Analytics, Turnstile, LiveKit, the CV worker, the puzzle-puzzle.cz login.

## 7. Sentry

`config/packages/sentry.php` takes the environment from `SENTRY_ENVIRONMENT`, falling back to the kernel environment.
The sandbox sets `sandbox`; production sets nothing and keeps sending `prod` (its old `SENTRY_ENVIRONMENT=production`
lines were removed in the same lily change — the app ignored them until now, and honouring them would have renamed
production's environment under every alert and saved search). The sandbox's crons never use `sentry-cli monitors`
(a check-in under production's slugs would hide production's own misses).

## 8. Environment

What lily's `apps/msp-sandbox/compose.yaml` sets, against production:

| Variable | Sandbox | Production |
|---|---|---|
| `SANDBOX` / `SANDBOX_URL` | `1` / `https://sandbox.myspeedpuzzling.com` | unset (`0`) / set once the sandbox is announced |
| `APP_URL` | `https://sandbox.myspeedpuzzling.com/` | `https://myspeedpuzzling.com/` |
| `SENTRY_ENVIRONMENT` | `sandbox` | unset → `prod` |
| `SENTRY_TRACES/PROFILES_SAMPLE_RATE` | `0` | 0.1 / 0.25 |
| `MAILER_DSN` | production's SES (Infisical), guarded by `SandboxMailSubscriber` | SES (Infisical) |
| `S3_BUCKET`, keys | `myspeedpuzzling-sandbox`, own key pair | `myspeedpuzzling` |
| `UPLOADS_BASE_URL`, `NGINX_PROXY_BASE_URL` | `https://sandbox-img.myspeedpuzzling.com` | `https://img.myspeedpuzzling.com` |
| `PREDICTION_SHOWN_MODEL` | `v7` (answers like production) | `v7` |
| `PREDICTION_CANDIDATE_ROWS`, `PREDICTION_GROUP_ROWS` | `0` | `1` |
| `LISTMONK_*`, `WJPF_*`, social login, `GA_TRACKING`, `TURNSTILE_*`, `SES_EVENTS_TOPIC_ARNS`, `LIVEKIT_*`, `ONLINE_CV_URL`, `PUZZLE_PUZZLE_*` | `""` | set |
| `KARMA_INFINITE_PLAYER_IDS`, `INTERNAL_API_REVIEWER_PLAYER_ID`, `STRIPE_PLAN_SWITCH_PORTAL_CONFIGURATION` | `""` (production ids mean nothing there) | set |
| `STRIPE_API_KEY`, `STRIPE_WEBHOOK_SECRET` | dummy, or a test-mode pair | live |
| `APP_SECRET`, `MERCURE_JWT_SECRET`, `OAUTH2_*`, `POSTGRES_PASSWORD` | own, minted for the sandbox | own |
| `SENTRY_DSN` | production's value | — |

Everything else comes from the image's defaults, like on production.

## 9. Crons

17 instead of production's 48 (lily `apps/msp-sandbox/cron.d/msp-sandbox`, each line says why the rest is not
there): the activity simulation; hourly instead of every 15 minutes the Insights batch, round results, community
stats, XP bonuses, the shown model's evidence; the API usage flush as often as production (the page API developers
look at); daily badges, karma, the prediction fits, insights, the predictions backfill, search keys, two prunes.
All behind a marker file (`/var/lib/msp-sandbox/cron.on`), created only after the first seed.

## 10. What else differs or could break

- **One web container** (8 workers, capped at 2 CPUs) serves pages and API — a hammered sandbox slows itself, not
  production. CrowdSec bans are box-wide: an IP banned on the sandbox is banned on production too.
- **Photos** uploaded on the sandbox go to its own bucket (needs its CORS rule —
  `myspeedpuzzling:online-media:cors --apply` on the sandbox); catalogue pictures come from production's public image
  host through the sandbox's image cache.
- **Admins are SQL-only** there too: sign up, then `UPDATE player SET is_admin = true …` (lily README). Events created
  by testers wait for approval like on production — an admin approves them, or the tester tests in the unapproved
  state.
- **Hard-coded production addresses** stay where they belong (JSON-LD organization ids, the banner's "Go to
  MySpeedPuzzling" link, internal notification recipients — those mails reach Jan, `[Sandbox] `-prefixed). The For developers page's
  examples now name the installation they are read on.
- **Mobile apps** have production built in — not in use, nothing to do.
- **Cookies** are host-only: production's sign-in does not reach the sandbox.

## 11. Code map

| What | Where |
|---|---|
| The switch | `Services\Sandbox\SandboxMode` (`SANDBOX`), Twig globals `sandbox`, `sandbox_url` |
| Noindex header | `EventSubscriber\SandboxNoIndexSubscriber` |
| Welcome page (Enter) | `Services\Sandbox\SandboxGate`, `EventSubscriber\SandboxGateSubscriber`, `Controller\SandboxEnterController`, `templates/sandbox/gate.html.twig` (`sandbox_gate.*`, 6 locales), picture prompt `docs/design-system/prompts/subjects/sandbox-gate.md` |
| Banner, noindex meta | `templates/base.html.twig` (`sandbox_banner.*` in 6 locales) |
| For developers | `templates/for-developers.html.twig` — examples on `app.request.schemeAndHttpHost`, Sandbox section when `SANDBOX_URL` is set (`for_developers.sandbox_*`, 6 locales) |
| Membership | `EventSubscriber\SandboxMembershipOnLoginSubscriber` → `GrantSandboxMembership`, `Services\Sandbox\SandboxMembership` |
| Catalogue | `Services\Sandbox\SandboxCatalogue`, commands `myspeedpuzzling:sandbox:export-catalogue` / `import-catalogue`, `ImportSandboxCatalogue` |
| People + results | `CreateSandboxPlayers`, `Services\Sandbox\SandboxPeople`, `SandboxTimeModel`, `SandboxResultPlanner`, `Query\GetSandboxSimulationData`, `Value\SandboxPlayer`/`SandboxPuzzle`/`SandboxPlannedResult`, commands `myspeedpuzzling:sandbox:seed` / `simulate-activity` |
| Sentry environment | `config/packages/sentry.php` |
| Deploy | `.github/workflows/test.yml` job `deploy-sandbox` (after a production deploy; a notice until the GitHub secret `DEPLOY_WEBHOOK_SECRET_SANDBOX` exists) |
| Tests | `tests/Services/Sandbox/*`, `SandboxOnlyMessagesTest`, `CreateSandboxPlayersHandlerTest`, `GrantSandboxMembershipHandlerTest`, `SandboxSimulatedResultsTest`, `SandboxNoIndexSubscriberTest`, `SandboxPagesTest`, `SandboxGateTest` |

A new table that should be in the catalogue: add it to `SandboxCatalogue::COLUMNS` with its export query —
**public data only**. A new member feature needs nothing: the sandbox's members are ordinary members.

## 12. Rollout

1. Merge the lily change (delivers the stack and the cron file — the cron does nothing until its marker exists;
   removes production's ignored `SENTRY_ENVIRONMENT`).
2. Merge this change — production deploys it with everything sandbox-only switched off; the `deploy-sandbox` CI job
   prints a notice.
3. One-time setup, first deploy, first seed: lily `apps/msp-sandbox/README.md`.
4. Smoke test (below), then announce: `SANDBOX_URL` on production (For developers page).

## 13. Smoke test

- [ ] `curl -sI https://sandbox.myspeedpuzzling.com/` → 401 (the welcome page, no `WWW-Authenticate`), `X-Robots-Tag: noindex…`
- [ ] After Enter: back on the page asked for; the banner on every page, `<meta name="robots" content="noindex…">`, Sentry
      environment `sandbox` on a deliberate 404-free error (e.g. the free account's Buy with the dummy key)
- [ ] `/robots.txt` and `https://sandbox-img.myspeedpuzzling.com/robots.txt` → `Disallow: /`
- [ ] Register with a password → signed in → a member (the membership page says so); the verification e-mail
      arrives from `…@sandbox.myspeedpuzzling.com`, subject `[Sandbox] …`, DKIM pass
- [ ] A made-up player's notification is not sent (log line "Sandbox: e-mail to made-up players only")
- [ ] Puzzle pages show their box pictures (production's public images through `sandbox-img`)
- [ ] Add a time with a photo → saved, the photo shows (the sandbox bucket + CORS)
- [ ] For developers → create a personal access token → `curl -H "Authorization: Bearer …"
      https://sandbox.myspeedpuzzling.com/api/v1/me` → the sandbox account; the same token on production → 401
- [ ] Register an OAuth2 app, approve it as the sandbox admin, run the authorization code flow end to end (the
      welcome page appears once in the browser)
- [ ] Create an event with rounds as a made-up player; approve it as the admin; it is on `/en/events`
- [ ] `test-user+free@myspeedpuzzling.com` sees the membership offer; every other made-up player is a member
- [ ] Ten minutes later: new results of made-up players on the Hub (the activity cron)
- [ ] Production unchanged: its Sentry events still `prod`, no new e-mails, no sandbox host in production's logs
