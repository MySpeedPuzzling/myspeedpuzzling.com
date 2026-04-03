# Feature Flags

This file documents all active feature flags in the codebase — where they are, what feature they gate, and when they can be removed.

## Competition Table Layout (admin-only)

- **Feature:** Table layout management for competition rounds
- **Flag:** `is_granted('ADMIN_ACCESS')` check in template
- **Gated files:**
  - `templates/manage_competition_rounds.html.twig` — Tables button visibility
- **Remove when:** Table layout feature is ready for all competition organizers

## Competition Stopwatch (admin-only)

- **Feature:** Stopwatch/timewatch for competition rounds
- **Flag:** `is_granted('ADMIN_ACCESS')` check in template
- **Gated files:**
  - `templates/manage_competition_rounds.html.twig` — Stopwatch button visibility
- **Remove when:** Stopwatch feature is ready for all competition organizers
