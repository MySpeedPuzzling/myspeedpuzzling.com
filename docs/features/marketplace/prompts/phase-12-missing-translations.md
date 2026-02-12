# Phase 12: Fix Missing Marketplace Translations

## Context
After implementing the marketplace feature, some translation keys are missing from `translations/messages.en.yml` and some strings are hardcoded in PHP instead of using the Symfony translator. All user-facing strings must go through the translation system — no hardcoded English text (admin-only pages are the only exception).

## Reference
- Feature docs: `docs/features/marketplace/`
- Translation file: `translations/messages.en.yml`

---

## Task 1: Add Missing Translation Keys

The following translation keys are **used in templates and form types but missing** from `translations/messages.en.yml`. Add them:

| Key | English Value | Used In |
|-----|--------------|---------|
| `marketplace.all_countries` | `All countries` | `templates/components/MarketplaceListing.html.twig` (placeholder for "Ships to" filter) |
| `sell_swap_list.settings.shipping_countries` | `Countries you ship to` | `src/FormType/EditSellSwapListSettingsFormType.php` (label) |
| `sell_swap_list.settings.shipping_cost` | `Shipping cost info` | `src/FormType/EditSellSwapListSettingsFormType.php` (label) |
| `sell_swap_list.settings.shipping_cost_placeholder` | `e.g. Free shipping over 50 EUR, flat rate 5 EUR` | `src/FormType/EditSellSwapListSettingsFormType.php` (placeholder) |

Place them in the correct alphabetical/grouped section within `messages.en.yml` — follow the existing convention for `marketplace.*` and `sell_swap_list.*` keys.

## Task 2: Translate Hardcoded Region Group Names

In `src/FormType/EditSellSwapListSettingsFormType.php`, the shipping countries are grouped by region with **hardcoded English group names**:

```php
$groups = [
    'Central Europe' => $centralEurope,
    'Western Europe' => $westernEurope,
    'Southern Europe' => $southernEurope,
    'Northern Europe' => $northernEurope,
    'Eastern Europe' => $easternEurope,
    'North America' => $northAmerica,
];
// ...
$groups['Rest of World'] = $restOfWorld;
```

These region names are displayed to users in the shipping countries form. Fix this by:

1. Adding translation keys to `messages.en.yml`:
   - `sell_swap_list.settings.region.central_europe`: `Central Europe`
   - `sell_swap_list.settings.region.western_europe`: `Western Europe`
   - `sell_swap_list.settings.region.southern_europe`: `Southern Europe`
   - `sell_swap_list.settings.region.northern_europe`: `Northern Europe`
   - `sell_swap_list.settings.region.eastern_europe`: `Eastern Europe`
   - `sell_swap_list.settings.region.north_america`: `North America`
   - `sell_swap_list.settings.region.rest_of_world`: `Rest of World`

2. Injecting the `TranslatorInterface` into the form type and using it to translate the group names. Since Symfony form `choice_group_by` or grouped `choices` use the array key as the group label and Symfony does NOT automatically translate group labels, you need to use translated strings as the array keys:

```php
use Symfony\Contracts\Translation\TranslatorInterface;

// In constructor or buildForm:
$groups = [
    $this->translator->trans('sell_swap_list.settings.region.central_europe') => $centralEurope,
    $this->translator->trans('sell_swap_list.settings.region.western_europe') => $westernEurope,
    // ... etc.
];
```

## Task 3: Comprehensive Scan

After fixing the above, do a thorough scan for any other untranslated strings in the marketplace feature:

1. **Grep all marketplace/messaging/rating templates** (`templates/marketplace/`, `templates/messaging/`, `templates/rating/`, `templates/components/Marketplace*`) for any text that is NOT wrapped in `|trans` or `{% trans %}` — excluding HTML attributes like `class`, `id`, `data-*`, `href`, `type`, etc.

2. **Grep all marketplace-related PHP files** (`src/FormType/*SellSwap*`, `src/FormType/*Marketplace*`, `src/FormType/*Messaging*`, `src/FormType/*Rating*`, `src/Controller/Marketplace/`, `src/Controller/Messaging/`, `src/Controller/Rating/`, `src/Component/Marketplace*`) for hardcoded strings in:
   - Flash messages (`addFlash`)
   - Form labels, placeholders, help text, empty messages
   - Exception messages shown to users (not internal exceptions)
   - `choice` type option labels

3. **Verify all translation keys used in templates actually exist** in `messages.en.yml` — grep for `|trans` in marketplace templates, extract the keys, and check each one exists in the YAML file.

Fix anything you find.

## Quality Checklist

- [ ] All 4 missing translation keys added to `messages.en.yml`
- [ ] All 7 region group names use translated strings
- [ ] `TranslatorInterface` properly injected in `EditSellSwapListSettingsFormType`
- [ ] Comprehensive scan found no additional untranslated strings
- [ ] All translation keys in templates have corresponding entries in `messages.en.yml`
- [ ] Run: `docker compose exec web composer run phpstan`
- [ ] Run: `docker compose exec web composer run cs-fix`
- [ ] Run: `docker compose exec web vendor/bin/phpunit --exclude-group panther`
- [ ] Run: `docker compose exec web php bin/console cache:warmup`
