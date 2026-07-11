# Auth0 Migration — User Communication Plan

Companion to [README.md](README.md) and [implementation-plan.md](implementation-plan.md).

## The core message (repeat everywhere)

> **Your email and password stay exactly the same.** Only the login screen changes — it now lives directly on myspeedpuzzling.com instead of redirecting to auth0.com. You will be logged out once and need to sign in again.

The one real user pain: **password managers saved the credential under `speedpuzzling.eu.auth0.com` / `auth0.com`**, so autofill won't trigger on the new login page. Every message must address it with the two rescues: *search your password manager for "auth0" or "speedpuzzling"*, and *use the email sign-in link if you can't find it*.

## Audience & channels

| Segment | Size (2026-07-11) | Channel |
|---|---|---|
| Active players (90d) | ~3,300 | Transactional email + in-app banner |
| Dormant registered players | ~6,650 | Newsletter (listmonk) — lower urgency, they'll hit the new login whenever they return |
| All visitors | — | In-app banner + FAQ page + login-page microcopy |

All copy ships in English and Czech (project i18n rules; players' `locale` field selects email language).

## Timeline

| When | What |
|---|---|
| T-14d | Announcement email to active players; FAQ page live |
| T-7d | In-app banner for logged-in users; reminder in newsletter |
| T-1d | Banner switches to "tomorrow" wording |
| T-0 | Cutover. Banner on login page (stays 4 weeks). Announcement on socials |
| T+4w | Nudge email to active-but-not-yet-migrated players |
| T+6–8w | Transition ends (per Phase 5 exit criteria); banner removed |

## Copy drafts (EN — translate to CS before Phase 3)

### Announcement email (T-14d)

Subject: **We're improving how you sign in to MySpeedPuzzling**

> Hi {name},
>
> On **{date}**, we're moving the MySpeedPuzzling login from an external provider (Auth0) to our own site. This gives us a faster, nicer sign-in experience and full control over your account features.
>
> **What you need to know:**
> - Your **email and password stay exactly the same**.
> - On {date} you'll be signed out once. Just sign in again at myspeedpuzzling.com.
> - If your password manager doesn't autofill, search it for "**auth0**" or "**speedpuzzling**" — your saved password is there, just filed under the old login domain.
> - Can't find it? No problem — use "**Email me a sign-in link**" or "**Forgot password**" on the login page.
>
> Nothing else changes: your times, collections, membership and everything else stay untouched.
>
> Questions? Reply to this email or see the FAQ: {faq_url}

### In-app banner (T-7d → T-0)

> 🔑 On **{date}** we're upgrading our sign-in system. You'll be signed out once — your email and password stay the same. [Learn more]({faq_url})

### Login-page microcopy (T-0, permanent for 4 weeks)

> **New login screen, same account.** Use the same email and password as before. Password manager not autofilling? Search it for "auth0" — or use the sign-in link below.

### Straggler nudge (T+4w, active-in-last-6-months minus migrated)

Subject: **Reminder: sign in once with your existing password**

> Hi {name},
>
> A few weeks ago we moved MySpeedPuzzling sign-in to our own site. You haven't signed in since, so a quick heads-up: your **email and password still work** — sign in once at myspeedpuzzling.com and you're done. If the password won't come to hand, use "Email me a sign-in link". That's it!

### FAQ page (publish T-14d)

- **Why is the login changing?** We previously used an external service (Auth0). Running sign-in ourselves makes it faster, fully translated, and lets us build features like passkeys and two-factor authentication.
- **Do I need a new password?** No. Same email, same password.
- **My password manager doesn't offer my password anymore.** It saved it under the old login domain (auth0.com). Search your manager for "auth0" or "speedpuzzling" and update the entry the first time you sign in.
- **I can't find my password at all.** Use "Email me a sign-in link" to get in instantly, or "Forgot password" to set a new one.
- **Is my password safe during this change?** Yes. Passwords were and remain stored only as strong one-way hashes; we transferred those hashes securely and your password is never visible to us — before, during, or after.
- **Why was I logged out?** The switch to the new system required ending all old sessions, once.
- **I have two accounts / a problem signing in.** Contact us at {support_email} — include your player code if you know it.

## Support playbook (during transition)

1. "Password doesn't work" → confirm exact email (check for old/secondary addresses), point to login link, then reset.
2. "No reset email arrives" → check spam; verify address exists in `user_account` (admin lookup); resend; watch for seznam.cz deliverability pattern (escalate to D12 if clustered).
3. "Password leaked" error (rare, from the trickle fallback) → user must use password reset; explain their old password appeared in a public breach database.
4. Duplicate accounts (the 7 known pairs + any surfacing) → identify the live account (has Auth0 login), manually merge/point user to it.
5. Log every login-related ticket with a tag — feeds the Phase 5 exit review.
