<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Players;

use Collator;
use SpeedPuzzling\Web\Query\IsHintDismissed;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\HintType;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * "Add your country" (docs/features/players-page/README.md, stream S7): signed-in players without a country are
 * invisible to every country feature. Shown on the Players page and on the player's own profile until the player
 * adds a country or dismisses it for good (HintType::PlayersCountryNudge). Costs no query for guests and players with
 * a country (the profile is already loaded), one for the rest.
 */
#[AsTwigComponent]
final class CountryNudge
{
    /**
     * The 422 answer of SetMyCountryController: the form is shown again with a line about the refused value, whatever
     * the profile or the dismissal say - the player has just submitted it.
     */
    public bool $invalid = false;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private IsHintDismissed $isHintDismissed,
    ) {
    }

    public function isShown(): bool
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return false;
        }

        if ($this->invalid === true) {
            return true;
        }

        // Same reading as the page's home chip (ViewerCountry): a value that is not a known country is no country
        if (CountryCode::fromCode($profile->country) !== null) {
            return false;
        }

        return ($this->isHintDismissed)($profile->playerId, HintType::PlayersCountryNudge) === false;
    }

    /**
     * Every country, by name (a collator, so Åland Islands sits under A). The browser preselects its guess
     * (players_country_nudge_controller.js).
     *
     * @return list<CountryCode>
     */
    public function getCountries(): array
    {
        $collator = new Collator('en');
        $countries = CountryCode::cases();
        usort($countries, static fn (CountryCode $a, CountryCode $b): int => (int) $collator->compare($a->value, $b->value));

        return $countries;
    }
}
