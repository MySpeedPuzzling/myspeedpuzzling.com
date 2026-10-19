<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Who sees Insights (flag INSIGHTS_PUBLIC, docs/features/feature_flags.md): admins always, every other signed-in player
 * only while the flag is on. The single decision point - the page, its Live Component, the profile tab, the menu row
 * and the "See why" links all ask here (Twig: insights_visible()). Nobody signed in never sees it: the page is always
 * the viewer's own. At the launch (implementation-plan.md §8) the flag check goes; the class may stay as the place
 * that says who gets the page.
 */
readonly final class InsightsFeature
{
    public function __construct(
        private Security $security,
        private bool $insightsPublic,
    ) {
    }

    public function isVisibleTo(null|PlayerProfile $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        return $this->insightsPublic || $this->security->isGranted(AdminAccessVoter::ADMIN_ACCESS);
    }
}
