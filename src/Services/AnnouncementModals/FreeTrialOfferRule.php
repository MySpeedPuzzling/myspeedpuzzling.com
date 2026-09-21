<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnnouncementModals;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Value\AnnouncementModal;

/**
 * "Try membership free for 10 days" (docs/features/free-trial/README.md): for players who can start the
 * trial right now - never had a membership, account old enough, enough puzzles logged. The modal has one
 * button and it must work; whoever is not there yet keeps their one impression for the day they are.
 */
readonly final class FreeTrialOfferRule implements AnnouncementModalRule
{
    public function modal(): AnnouncementModal
    {
        return AnnouncementModal::FreeTrialOffer;
    }

    public function isEligible(PlayerProfile $viewer, DateTimeImmutable $now): bool
    {
        return $viewer->canStartFreeTrial();
    }
}
