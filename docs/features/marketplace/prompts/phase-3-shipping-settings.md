# Phase 3: Shipping Settings

Read the feature specification in `docs/features/marketplace/04-shipping-settings.md` and the implementation plan Phase 3 in `docs/features/marketplace/10-implementation-plan.md`.

## Task

Extend the existing `SellSwapListSettings` value object to include shipping countries (array of country codes) and shipping cost info (text). Update the settings form, display, and queries. No database migration is needed — this is stored as JSON on the Player entity.

## Requirements

### 1. Extend SellSwapListSettings Value Object

Update `src/Value/SellSwapListSettings.php` — add two new constructor properties with defaults for backwards compatibility:

```php
readonly final class SellSwapListSettings
{
    public function __construct(
        public null|string $description = null,
        public null|string $currency = null,
        public null|string $customCurrency = null,
        public null|string $shippingInfo = null,
        public null|string $contactInfo = null,
        /** @var string[] */
        public array $shippingCountries = [],
        public null|string $shippingCost = null,
    ) {
    }
}
```

Verify JSON serialization/deserialization works correctly with existing data that lacks the new fields. The defaults (`[]` and `null`) ensure backwards compatibility.

### 2. Update EditSellSwapListSettings Message

Add `shippingCountries` (array) and `shippingCost` (nullable string) to `src/Message/EditSellSwapListSettings.php`.

### 3. Update EditSellSwapListSettingsHandler

In `src/MessageHandler/EditSellSwapListSettingsHandler.php`, include the new fields when constructing the `SellSwapListSettings` value object.

### 4. Update Settings Form

In `src/FormType/EditSellSwapListSettingsFormType.php`, add:

- **`shippingCountries`**: ChoiceType with `multiple => true`, `expanded => true` (checkboxes). Use the `CountryCode` enum (`src/Value/CountryCode.php`) for choices. Group countries by regions for better UX. Common groups to consider: "Central Europe" (CZ, SK, PL, HU, AT), "Western Europe" (DE, FR, NL, BE, etc.), "Southern Europe", "Northern Europe", "North America", "Rest of World".
- **`shippingCost`**: TextType, nullable, with placeholder like "e.g., €5 domestic, €8 EU, €12 worldwide"

### 5. Create Stimulus Controller for Quick-Select

Create `assets/controllers/shipping_countries_controller.js`:
- "Select all" button → checks all checkboxes
- "Deselect all" button → unchecks all
- "EU countries" button → selects EU member state codes
- "Europe" button → selects all European country codes
- Controller targets: the checkbox container and the quick-select buttons

### 6. Update Settings Template

In `templates/sell-swap/edit_settings.html.twig`:
- Add the shipping countries section with checkboxes grouped by region
- Add quick-select buttons wired to the Stimulus controller
- Add the shipping cost text input
- Place these fields logically near the existing `shippingInfo` field

### 7. Update Settings Display Template

In `templates/sell-swap/detail.html.twig`:
- Show the list of shipping countries with country flags (use the existing country flag pattern from the codebase)
- Show shipping cost info text
- Only show these sections if the seller has configured them (non-empty)

### 8. Update Controller

In `src/Controller/SellSwap/EditSellSwapListSettingsController.php`, ensure the new form fields are passed to the message.

### 9. Write Tests

**`tests/MessageHandler/EditSellSwapListSettingsHandlerTest.php`** (create or update):
- Test saving settings with shipping countries and shipping cost
- Test that existing settings without new fields still load correctly (backwards compat)
- Test saving empty shipping countries array

**`tests/Value/SellSwapListSettingsTest.php`** (create):
- Test JSON serialization roundtrip with all fields populated
- Test JSON deserialization of old data (missing new fields) produces correct defaults

### 10. Run Checks

```bash
docker compose exec web composer run phpstan
docker compose exec web composer run cs-fix
docker compose exec web vendor/bin/phpunit --exclude-group panther
docker compose exec web php bin/console doctrine:schema:validate
docker compose exec web php bin/console cache:warmup
```
