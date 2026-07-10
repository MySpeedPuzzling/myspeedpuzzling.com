# Cloudflare Proxying (Orange-Cloud) — Deferred Decision

Status: **deferred** (2026-07). DNS is already on Cloudflare; all traffic currently hits the Hetzner origin (157.90.198.86) directly — no edge involved. This doc collects the benefits and the rollout checklist for when we revisit.

## Why it's worth revisiting

1. **Bot traffic relief (the original motivation).** robots.txt only stops *polite* bots. The heavy scrapers that cause real load (Bytespider, random headless scrapers, unlabeled AI agents) ignore it. Cloudflare enforces at the edge before requests reach FrankenPHP:
   - Bot Fight Mode / Super Bot Fight Mode (free tier): challenges known-bad automation.
   - One-click "Block AI Bots" toggle: enforces against AI scrapers regardless of robots.txt compliance.
   - Rate limiting rules: cap per-IP request rates on expensive endpoints (search, sitemaps).
   - WAF rules: block by user-agent/ASN for the specific offenders we see in access logs.
2. **Static assets served from ~300 edge POPs with zero app changes.** `/build/*` (content-hashed, immutable) and `/fonts/*` already send correct cache headers — flipping the proxy on makes them edge-cached instantly. Origin bandwidth and request count drop substantially (every page view currently pulls assets from Hetzner).
3. **Global performance = SEO.** Measured TTFB from EU is ~200–320 ms; US/Canada/Australia/Japan users (a large share of the audience per GSC: US alone is 129k impressions/3mo, plus Canada, Australia, Japan locales) pay full EU round-trips today. Edge-served assets cut mobile LCP for exactly the markets where rankings need to improve. CWV is a (lightweight) ranking signal and a heavyweight UX/conversion factor.
4. **Free-tier is enough to start.** Nothing above requires a paid plan.

## Risks / things to verify before flipping

| Concern | What to check |
|---|---|
| **Mercure SSE** (`/.well-known/mercure`) | Cloudflare buffers/limits long-lived connections on some plans. Options: keep HTML host proxied but route Mercure via a grey-cloud subdomain (e.g. `mercure.myspeedpuzzling.com`), or verify SSE passes through (Cloudflare supports SSE, but idle timeout ~100s — Mercure reconnects handle this if heartbeats are configured). Test stopwatch/live features end-to-end. |
| **HTML caching** | Do NOT edge-cache HTML initially — responses are `Cache-Control: private` anyway (anonymous sessions). Assets only. |
| **imgproxy host** (`img.myspeedpuzzling.com`) | Also worth proxying (34.5k puzzle photos). Verify the duplicate Cache-Control/Vary headers noted in the image audit don't confuse edge caching; fix headers first. |
| **Real client IPs** | FrankenPHP/Caddy must be configured to trust Cloudflare's IP ranges (`CF-Connecting-IP`) or logs/rate-limits in the app see Cloudflare IPs. |
| **ACME/TLS** | Origin certs: switch to Cloudflare origin certificate or keep Let's Encrypt with DNS-01 (HTTP-01 breaks behind proxy unless configured). |
| **Blue-green deploys** | Deploy flow talks to the origin directly (spare.srv.thedevs.cz) — unaffected, but health checks that hit the public hostname would go through the edge. |

## Rollout checklist (when we do it)

1. Fix imgproxy response headers (duplicate Cache-Control/Vary).
2. Configure Caddy/FrankenPHP to trust CF proxy IPs.
3. Create `mercure.` subdomain (grey-cloud) OR verify SSE through the proxy in staging.
4. Flip orange-cloud for `myspeedpuzzling.com` + `www` + `img.` — start with "Full (strict)" TLS.
5. Enable: Brotli, HTTP/3, Bot Fight Mode, Block AI Bots.
6. Add rate-limit rule for `/sitemap*.xml` and search endpoints.
7. Verify: stopwatch live updates, login flow (Auth0 callbacks), Stripe webhooks (`/webhook` paths must not be challenged — add WAF skip rule), sitemap fetch by Googlebot (GSC → Settings → Crawl stats).
8. Watch GSC Crawl Stats + origin load for a week; then consider edge-caching HTML for anonymous users (requires the anonymous-session fix from the SEO plan Phase 7 first).
