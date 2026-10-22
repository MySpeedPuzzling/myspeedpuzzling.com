# Live-event strip (removed - the recipe for the next one)

A thin full-width link at the very top of every web page while a big event is live: "Now live: WJPC 2026 – Add & see
results", with a pulsing white dot, linking to the event page.

- **Built** 2026-09-17 for the World Jigsaw Puzzle Championship 2026 (Valladolid): `bf8727c73` (strip + English),
  `a00af5c54` (the other 5 locales, the end date).
- **Shown** from that deploy until **2026-09-21 00:00 Europe/Prague** (the day after the event's last day).
- **Removed** in PR #284 ("Time and the clock", 2026-10-23): expired, so dead code - and its condition read the clock in
  a template, which that PR made go through `now()`. The markup, styles and texts below are the whole of it, so a
  future strip is a copy, not a reinvention.

## Where it sat and how it was gated

- `templates/base.html.twig`, inside `{% block header %}{% if is_web() %}` (web only - not in the apps), as the **first
  child of `<header class="sticky-top shadow-sm" data-controller="header-height">`**, right before
  `<div class="topbar topbar-light">`. Being inside the sticky header, it scrolls with it and takes its own space;
  `header_height_controller.js` publishes the header's real height as `--header-height`, so everything offset by it
  (sticky bars, toasts, the compact bar, the events page) moved down by the strip's height without any other change.
- **Date window:** `now() < date('2026-09-21T00:00:00+02:00')` - shown until the end of the event's last day in the
  event's zone. The offset is written out because Twig parses a date string in PHP's default zone (UTC); without it the
  strip would have stayed until 02:00 Prague time. Read the time with `now()` (the app's clock -
  `docs/features/time-and-clock/README.md`), never `date()`/`'now'|date` (the guard `SystemClockCoverageTest` refuses
  those in templates). There was no start date: the strip shipped with the deploy on the event's first day.
- **Not on the page it links to:** hidden when `currentRoute == 'event_detail'` and the route's `slug` is the event's
  slug (`currentRouteParams` is set in `base.html.twig`).
- **Variables:** `live_event_slug` (Twig `set`, the event's slug), the event's display name passed as `%event%`
  (hard-coded `WJPC 2026` - a brand name, the same in every locale).
- **Link:** `path('event_detail', {slug: live_event_slug})` = `/{_locale}/events/world-jigsaw-puzzle-championship-2026`.

## The removed Twig

```twig
            {# Live-event strip: first child of the sticky header, so it takes its own space
               and --header-height follows it. Gone once the event's last day is over -
               the offset is explicit because Twig parses date strings in UTC. Never shown
               on the event page it links to. #}
            {% set live_event_slug = 'world-jigsaw-puzzle-championship-2026' %}
            {% if now() < date('2026-09-21T00:00:00+02:00') and not (currentRoute == 'event_detail' and currentRouteParams.slug is defined and currentRouteParams.slug == live_event_slug) %}
                <a class="competition-panel-info" href="{{ path('event_detail', {slug: live_event_slug}) }}">
                    <span class="live-dot live-dot-light" aria-hidden="true"></span>{{ 'live_event_strip'|trans({'%event%': 'WJPC 2026'}) }}
                </a>
            {% endif %}
```

## The removed SCSS (`assets/styles/app.scss`)

```scss
// Live-event strip at the top of the sticky header - the whole strip is one link
.competition-panel-info {
    display: block;
    background: $primary;
    color: #fff;
    font-size: 12px;
    font-weight: 500;
    line-height: 1.5;
    padding: 4px 15px;
    text-align: center;

    &:hover,
    &:focus {
        color: #fff;
        background: darken($primary, 6%);
        text-decoration: underline;
    }
}
```

The dot is **not** removed: `.live-dot` (red, the events page's "Live") and its white variant `.live-dot-light` stay in
`app.scss` - `.live-dot-light` is also used by the online round watch page (`templates/online_rounds/watch.html.twig`).
A static dot under `prefers-reduced-motion`.

## The removed translation key (`translations/messages.<locale>.yml`, top level)

| Locale | `live_event_strip` |
|---|---|
| en | `Now live: %event% – Add & see results` |
| cs | `Živě: %event% – přidejte čas a sledujte výsledky` |
| de | `Jetzt live: %event% – Zeit eintragen & Ergebnisse` |
| es | `En directo: %event% – Añade y consulta resultados` |
| fr | `En direct : %event% – Ajouter & voir les résultats` |
| ja | `開催中：%event% – 記録を追加・結果をチェック` |

Each was measured in Rubik 12px/500 at ≤ 324 px with the dot, so it stays one line on a 360 px phone. Keep that
budget for a new text, in all 6 locales.

## Bringing one back

1. Put the Twig block back at the same place in `base.html.twig` with the new event's slug, name and end moment
   (`now() < date('<day after the last day>T00:00:00<event's offset>')`, `now()` - never `date()`).
2. Put `.competition-panel-info` back in `app.scss` (next to `.live-dot`), and the `live_event_strip` key in all 6
   locales (the texts above take `%event%`, so they can be reused as they are).
3. No cleanup commit is needed after the event - the date hides it - but remove the block in a later release so it does
   not stay as dead code (that is what happened to this one).
4. For a strip that should not need a deploy (several events a year), drive it from data instead - e.g. an event flag
   read by one query on the profile/base row - not from a constant in the template.
