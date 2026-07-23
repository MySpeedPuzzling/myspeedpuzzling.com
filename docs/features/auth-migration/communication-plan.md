# Auth0 Migration — User Communication Plan

Companion to [README.md](README.md) (see §Password-manager shock — the UX funnel) and [implementation-plan.md](implementation-plan.md).

## The core message (repeat everywhere)

> **Your email and password stay exactly the same.** Only the sign-in screen changes — it now lives directly on myspeedpuzzling.com instead of redirecting to auth0.com. You will be logged out once and need to sign in again.

The one real user pain: **password managers saved the credential under `speedpuzzling.eu.auth0.com`**, so autofill won't trigger on the new login page. Every message addresses it with the two rescues: *search your password manager for "speedpuzzling" or "auth0"* (the old domain contains "speedpuzzling", so vault search finds it), and *use the email sign-in link if you can't find it*.

**Tone (D16, layered):** short-form surfaces (email, banner, modal) stay simple and benefit-framed. The FAQ carries the honest full story for anyone who wants the real reasons. No corporate fog, no drama.

## Audience & channels

| Segment | Size (2026-07-11) | Channel |
|---|---|---|
| All registered users with an email | ~10k | Announcement email at Stage A — operational/service email (batched via Messenger + rate limiter; listmonk fallback per D12) |
| Active players (90d) | ~3,300 | Same + in-app banner + straggler nudge |
| All visitors | — | Banner + FAQ page + login-page modal & microcopy |

All user-facing copy ships in **all 6 locales** (en, cs, de, es, fr, ja — D17); emails pick the player's `locale`.

## Timeline (staged, compressed — dates per implementation-plan calendar)

| When | What |
|---|---|
| **Stage A day** (~Jul 29–31) | Announcement email to all users; FAQ page live; banner ON ("On {date}…"); native registrations quietly live; socials post |
| Stage B − 1d | Banner switches to "tomorrow" wording |
| **Stage B day** (~Aug 6–12) | Cutover. Login-page modal live; banner switches to "changed" wording (stays ~4 weeks); login microcopy permanent; socials post |
| B + 2w | Straggler nudge email (active-in-6-months minus migrated) |
| B + 3–4w | Transition exit review (Phase 5 criteria); banner removed. Modal + microcopy + sign-in-link stay — dormant players return for months |

If Stage B slips past the export-delay gate, announced dates must say "week of {date}" — never promise a day we can't hold.

## Copy drafts (EN — translate to all locales before Stage A)

### Announcement email (Stage A day)

Subject: **Sign-in is moving to myspeedpuzzling.com — same password, nothing to do today**

> Hi {name},
>
> On **{date}**, signing in to MySpeedPuzzling moves from an external provider (Auth0) to our own site. We've outgrown the external service, and running sign-in ourselves makes it faster, available in your language, and lets us build things like passkeys and two-factor auth later. ([The full story]({faq_url}) if you're curious.)
>
> **What you need to know:**
> - Your **email and password stay exactly the same**. Nothing is reset.
> - On {date} you'll be signed out once. Just sign in again at myspeedpuzzling.com.
> - If your password manager doesn't offer your password on the new page, it's filed under the old sign-in domain — **search your manager for "speedpuzzling" or "auth0"** and you'll find it.
> - Can't find it? Use "**Email me a sign-in link**" on the login page — one click and you're in, no password needed.
>
> Nothing else changes: your times, collections, membership and everything else stay untouched.
>
> Questions? Reply to this email or see the FAQ: {faq_url}

### In-app banner

- Stage A → B−1d: > 🔑 On **{date}** sign-in moves to myspeedpuzzling.com. Same email and password. [What's changing?]({faq_url})
- B−1d: > 🔑 **Tomorrow** sign-in moves to myspeedpuzzling.com. You'll be signed out once — same email and password. [Details]({faq_url})
- Stage B → B+4w: > 🔑 Sign-in has a new home on myspeedpuzzling.com — same email and password as before. Trouble signing in? [Read this]({faq_url})

### Login-page modal (Stage B, one-time per browser, localStorage-dismissed)

Title: **Sign-in has moved home**

> Signing in now happens right here on myspeedpuzzling.com — no more redirect to auth0.com.
>
> - **Same email, same password.** Nothing was reset.
> - **Password manager not offering it?** It saved your password under our old sign-in domain. Search it for "**speedpuzzling**" or "**auth0**" — it's there.
> - **Can't find it?** Use **Email me a sign-in link** below — one click and you're in. You can set a fresh password afterwards.
>
> [Got it] · [Why did this change?]({faq_url})

### Login-page microcopy (permanent)

> **New sign-in screen, same account.** Use the same email and password as before — or get a sign-in link by email.

### Login-failure helper (appears after a failed password attempt)

> Password not working? If a password manager saved it, search the manager for "**speedpuzzling**" or "**auth0**" — the entry is filed under our old sign-in domain. Or skip the password entirely:
> **[Email me a sign-in link →]** (pre-filled with the typed email)

### Post-magic-link password prompt (one-time, skippable)

Title: **Set a fresh password?**

> You're signed in! If you set a new password now, your password manager will save it under **myspeedpuzzling.com** — so next time it autofills like it should.
> [New password field — with "Suggest strong password"] [Save password]
> [Not now — my current password keeps working]

### Straggler nudge (B+2w, active-in-last-6-months minus migrated)

Subject: **Reminder: sign in once with your existing password**

> Hi {name},
>
> Two weeks ago MySpeedPuzzling sign-in moved to our own site. You haven't signed in since, so a quick heads-up: your **email and password still work** — sign in once at myspeedpuzzling.com and you're done. If the password won't come to hand, use "Email me a sign-in link". That's it!

### FAQ page (live from Stage A)

- **Why is the login changing?** *(the honest, layered story — D16)* MySpeedPuzzling launched on Auth0, an external sign-in service. It was the right call early on — it let us build puzzle features instead of building authentication. But we've outgrown it: the piece connecting Auth0 to our framework is no longer well maintained, we kept hitting bugs in it, and the fixes we contributed weren't accepted — so we've been maintaining our own patched copy just to keep sign-in working. That slows down everything else we want to build. Moving sign-in onto myspeedpuzzling.com gives us full control: faster sign-in, every page in your language, and it clears the way for passkeys and two-factor authentication.
- **Do I need a new password?** No. Same email, same password. Your password was never visible to us and wasn't reset — the securely hashed form was transferred directly.
- **My password manager doesn't offer my password anymore.** It saved it under the old sign-in domain (speedpuzzling.eu.auth0.com). Search your manager for "speedpuzzling" or "auth0", then let it update the entry the first time you sign in on the new page.
- **I can't find my password at all.** Use "Email me a sign-in link" to get in instantly — then set a fresh password in the prompt that follows (or in your profile settings).
- **Is my password safe during this change?** Yes. Passwords were and remain stored only as strong one-way hashes; we transferred those hashes securely and your actual password is never visible to us — before, during, or after.
- **Why was I logged out?** The switch to the new system required ending all old sessions, once.
- **I registered recently and never saw Auth0.** Then nothing changes for you at all — your account was already created on the new system.
- **I have two accounts / another sign-in problem.** Contact us at {support_email} — include your player code if you know it.

## Support playbook (during transition)

1. "Password doesn't work" → confirm exact email (old/secondary addresses), point to the sign-in link, then reset.
2. "No reset / sign-in-link email arrives" → check spam; verify address exists in `user_account` (admin lookup); resend; watch for seznam.cz deliverability pattern (escalate to D12 if clustered).
3. "Password leaked" error (rare, from the trickle fallback) → user must reset; explain their old password appeared in a public breach database.
4. Registered during the window (Stage A → B) and can't sign in on the Auth0 form → they're a native account; send them a sign-in link / point to the native login (gone as an issue after Stage B).
5. Changed password in the window (via the old profile "Password" card or Auth0's forgot-password) → their export hash is stale; the invisible fallback handles it at first login — if it doesn't, sign-in link + set password.
6. Duplicate accounts (the 7 known pairs + any surfacing) → identify the live account (the one in the export), manually merge/point the user to it.
7. Log every login-related ticket with a tag — feeds the Phase 5 exit review.
