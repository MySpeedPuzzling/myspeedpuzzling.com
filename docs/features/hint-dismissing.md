# Hint Dismissing

Allows logged-in users to permanently dismiss informational banners (hints) shown on specific pages. Once dismissed, the hint is never shown again for that user.

## How it works

1. **Server-side check**: Each page controller queries `IsHintDismissed` to decide whether to show the hint
2. **Frontend**: The hint banner uses the `dismiss-hint` Stimulus controller. Clicking the close button sends a POST to the `dismiss_hint` route and removes the element from the DOM
3. **Backend**: `DismissHintController` validates the hint type and dispatches a `DismissHint` message
4. **Persistence**: `DismissHintHandler` creates a `DismissedHint` entity (idempotent via unique constraint on player + type)

## Adding a new hint

1. Add a case to `src/Value/HintType.php`
2. In the page controller, query `IsHintDismissed` and pass the result to the template
3. In the template, wrap the banner with the Stimulus controller:

```twig
{% if not hint_dismissed %}
    <div
        class="alert alert-info small mb-3 d-flex align-items-start"
        {{ stimulus_controller('dismiss-hint', {
            url: path('dismiss_hint'),
            type: 'your_hint_type',
        }) }}
    >
        <div class="flex-grow-1">
            Your hint message here
        </div>
        {% if logged_user.profile is not null %}
            <button type="button" class="btn-close ms-2 flex-shrink-0" aria-label="Close" {{ stimulus_action('dismiss-hint', 'dismiss') }}></button>
        {% endif %}
    </div>
{% endif %}
```

## Key files

| File | Purpose |
|------|---------|
| `assets/controllers/dismiss_hint_controller.js` | Stimulus controller — sends POST and removes element |
| `src/Controller/Marketplace/DismissHintController.php` | Route handler (POST, returns 204) |
| `src/Value/HintType.php` | Enum of all hint types |
| `src/Entity/DismissedHint.php` | Entity (unique constraint on player + type) |
| `src/Message/DismissHint.php` | Command message |
| `src/MessageHandler/DismissHintHandler.php` | Persists dismissal (idempotent) |
| `src/Query/IsHintDismissed.php` | Query to check if a hint was dismissed |

## Current hint types

| Type | Page | Alert style |
|------|------|-------------|
| `marketplace_disclaimer` | Marketplace index | `alert-warning` |
| `feature_requests_intro` | Feature requests list | `alert-info` |
