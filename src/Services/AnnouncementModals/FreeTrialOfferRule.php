<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnnouncementModals;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\FreeTrialSettings;
use SpeedPuzzling\Web\Value\AnnouncementModal;

/**
 * "Try membership free for 10 days" (docs/features/free-trial/README.md): for players who never had a
 * membership of any kind and are past their first day. Somebody who registered minutes ago has other
 * things to discover first - for them the offer waits on the membership page and in the members modal.
 */
readonly final class FreeTrialOfferRule implements AnnouncementModalRule
{
    /** Meant to grow once there are numbers to judge it by - raising it needs no data fix */
    public const int MINIMUM_ACCOUNT_AGE_HOURS = 24;

    public function __construct(
        private FreeTrialSettings $freeTrialSettings,
    ) {
    }

    public function modal(): AnnouncementModal
    {
        return AnnouncementModal::FreeTrialOffer;
    }

    public function isEligible(PlayerProfile $viewer, DateTimeImmutable $now): bool
    {
        if ($this->freeTrialSettings->isEnabled() === false || $viewer->freeTrialAvailable === false) {
            return false;
        }

        if ($viewer->registeredAt === null) {
            return false;
        }

        return $viewer->registeredAt <= $now->modify('-' . self::MINIMUM_ACCOUNT_AGE_HOURS . ' hours');
    }
}
