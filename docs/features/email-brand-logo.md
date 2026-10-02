# Our logo as the sender picture in the inbox

Inboxes can show a brand logo next to the sender instead of initials or a placeholder. That helps
players recognise our e-mails, and it helps them trust the sign-in and password-reset links, because
only mail that provably comes from our domain gets the logo. Decided 2026-10-02: **only the free
routes**, no paid certificate.

Players by mail provider (accounts, 2026-10-02): Gmail 63 %, Microsoft 14 %, other 14 %,
Yahoo/AOL 6 %, iCloud 2 %, Seznam 1 %.

| Route | Cost | Where the logo shows | Status |
|---|---|---|---|
| BIMI without a certificate ("self-asserted") | free | Yahoo/AOL, Fastmail; only for senders they consider established | **live** (below) |
| Apple Branded Mail (Apple Business Connect) | free | Apple's Mail app on iPhone, iPad and Mac | Jan sets it up (steps below) |
| BIMI with a certificate (CMC ~$990/yr, VMC ~$1,350/yr + trademark) | paid | Gmail, Apple Mail via BIMI | not wanted |
| — | — | Outlook/Hotmail supports neither | — |

**Both routes require** DMARC at `quarantine` or `reject`. We publish `p=reject` (100 %) on
`myspeedpuzzling.com` and on the sending subdomains `mail.`, `notify.` and `news.` (`_dmarc` CNAMEs to
sendvery.com).

## BIMI (self-asserted)

**Logo:** `public/bimi/myspeedpuzzling.svg`, served at
https://myspeedpuzzling.com/bimi/myspeedpuzzling.svg (`image/svg+xml`; the bot-blocker lets
non-browser fetchers through to static files).
- It must stay **SVG Tiny PS**: `version="1.2" baseProfile="tiny-ps"`, a `<title>`, a square viewBox,
  a solid background.
- No `<style>`, mask, clip path, filter, image, script, link, `use` or xlink; at most 32 KB (ours is
  27 KB).
- Inboxes crop it to a circle. The square is sized so the farthest pixel sits at 94 % of the radius.
- It was converted from `public/img/speedpuzzling-logo.svg` (a CorelDRAW export):
  - CSS classes inlined as attributes;
  - unused masks, style and metadata dropped;
  - gradient stops borrowed through xlink copied in.
- **If the logo changes:** redo that conversion, keep the file at the same URL, and check the rules
  above.

**DNS** (Cloudflare zone `myspeedpuzzling.com`):
`default._bimi.myspeedpuzzling.com TXT "v=BIMI1; l=https://myspeedpuzzling.com/bimi/myspeedpuzzling.svg; a=;"`.
- An explicit record is needed: the zone's wildcard `*.myspeedpuzzling.com` CNAME otherwise answers
  that name with the apex SPF record.
- The sending subdomains have no record of their own. Receivers fall back to the organisational
  domain, and the wildcard does not match `default._bimi.<subdomain>` because those subdomains exist.

**Verify:**
- `dig +short TXT default._bimi.myspeedpuzzling.com`
- `curl -sI https://myspeedpuzzling.com/bimi/myspeedpuzzling.svg`

## Apple Branded Mail (free; Jan sets it up)

What it needs from us is already in place: DMARC `p=reject`, DKIM (Seznam keys), SPF.

1. Sign in at https://businessconnect.apple.com with the Apple Account that should own the business.
2. Add the business (MySpeedPuzzling: the operating company's legal name, country, address, website
   https://myspeedpuzzling.com). Apple verifies the organisation and may ask for a document or a
   phone check.
   - Unverified: how Apple handles a Czech business. If the flow stops, note where.
3. Open **Branded Mail** → add the domain `myspeedpuzzling.com`.
   - If it asks for every sending domain, add `mail.myspeedpuzzling.com` (sign-in links, results),
     `notify.myspeedpuzzling.com` (digests) and `news.myspeedpuzzling.com` (newsletter).
   - Apple gives a TXT record to prove the domain: send it to Claude, who adds it in Cloudflare.
4. Brand name: `MySpeedPuzzling`. Logo: the square 1024×1024 PNG on white
   (`myspeedpuzzling-logo-1024.png`, rendered from the BIMI SVG; regenerate it from that file if lost).
5. Submit. Review takes up to about a week; afterwards the logo shows in Apple Mail for new e-mails.
