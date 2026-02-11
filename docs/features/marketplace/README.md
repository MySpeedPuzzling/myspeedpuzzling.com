# Marketplace Feature

## Overview

A fully-featured puzzle marketplace where users can browse, search, and filter all active sell/swap listings in one centralized place. Includes a direct messaging system with first-contact approval, seller/buyer ratings, shipping settings, and real-time notifications.

## Documentation Structure

- [01-marketplace.md](01-marketplace.md) - Marketplace browsing, search, filtering, and UI
- [02-messaging.md](02-messaging.md) - Chat/messaging system with first-contact approval
- [03-ratings.md](03-ratings.md) - Transaction ratings and reviews
- [04-shipping-settings.md](04-shipping-settings.md) - Seller shipping configuration
- [05-reserved-status.md](05-reserved-status.md) - Reserved listing status
- [06-email-notifications.md](06-email-notifications.md) - Unread message email notifications (cron)
- [07-admin-moderation.md](07-admin-moderation.md) - Admin moderation dashboard
- [08-infrastructure.md](08-infrastructure.md) - Mercure separation, Docker changes (DONE)
- [09-puzzle-detail-integration.md](09-puzzle-detail-integration.md) - Puzzle detail page and list integration
- [10-implementation-plan.md](10-implementation-plan.md) - Step-by-step implementation guide

## Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Marketplace access | Browse free, list/chat requires membership | Maximizes exposure, incentivizes membership |
| Currency | Per-seller currency (existing pattern) | Simple, no conversion complexity |
| Chat safety | Full moderation suite | Accept/deny, block, report, admin dashboard |
| Transaction tracking | Connect + mark completed + mutual ratings | Builds trust and marketplace credibility |
| Shipping | Country-level selection | Clean and simple, covers 99% of use cases |
| Reserved status | Manual only | Full seller control, no complexity |
| Ratings | Both rate each other + optional text review | Builds trust on both sides |
| Sorting | Newest, price, search relevance | Covers essential use cases |
| Offer counts | Cached with event-driven invalidation | Performance-friendly with correct data |
| General messaging | Available to all, with opt-out setting | Users control contactability outside marketplace |
| Admin tools | Full suite (warn, mute, ban, remove listings, view logs) | Maximum community safety |
| Email notifications | Count + per-user overview, no duplicates | Informative without being spammy |
