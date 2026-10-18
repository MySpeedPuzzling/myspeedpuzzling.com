<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\FormType\EditProfileFormType;
use SpeedPuzzling\Web\Query\IsHintDismissed;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\HintType;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * "Complete your profile" on the player's own profile (docs/features/player-timezone.md): the details that are still
 * missing, each a link straight to its field on the edit-profile page. Dismissed for good through the hint system
 * (HintType::ProfileCompletion).
 *
 * One ask at a time - the profile page renders it only where the newcomer "Getting started" card is not shown, and it
 * stays away while "Add your country" (Players:CountryNudge, which has its own inline form) is still asking. A country
 * the player waved away is not asked for again here.
 *
 * Costs no query while nothing is missing (the owner's profile row is already loaded), one otherwise.
 */
#[AsTwigComponent]
final class ProfileCompletion
{
    /**
     * @var list<array{key: string, anchor: string}>
     */
    public array $items = [];

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private IsHintDismissed $isHintDismissed,
    ) {
    }

    public function mount(): void
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return;
        }

        $missing = [];

        if ($profile->timezone === null) {
            $missing[] = ['key' => 'timezone', 'anchor' => EditProfileFormType::TIMEZONE_ANCHOR];
        }

        if ($missing === []) {
            return;
        }

        $dismissed = $this->isHintDismissed->dismissedAmong($profile->playerId, HintType::ProfileCompletion, HintType::PlayersCountryNudge);

        if (in_array(HintType::ProfileCompletion, $dismissed, true)) {
            return;
        }

        // "Add your country" is still asking (the same reading as CountryNudge::isShown()) - one banner at a time
        if (CountryCode::fromCode($profile->country) === null && !in_array(HintType::PlayersCountryNudge, $dismissed, true)) {
            return;
        }

        $this->items = $missing;
    }
}
