<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use SpeedPuzzling\Web\Security\SuspiciousResultsVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The current viewer for the navigation (MENU-01), built once per request from what the page already loads: the
 * viewer's profile row (RetrieveLoggedUserProfile), the voters (they read the same row) and the XP gate (a cached
 * switch) - no statement of its own. Reset between requests (FrankenPHP worker mode).
 */
final class NavigationViewerFactory implements ResetInterface
{
    private null|NavigationViewer $viewer = null;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private AuthorizationCheckerInterface $authorizationChecker,
        readonly private XpFeatureGate $xpFeatureGate,
        readonly private ClockInterface $clock,
    ) {
    }

    public function current(): NavigationViewer
    {
        return $this->viewer ??= $this->build();
    }

    public function reset(): void
    {
        $this->viewer = null;
    }

    private function build(): NavigationViewer
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $xpVisible = $this->xpFeatureGate->isVisibleFor($profile);

        if ($profile === null) {
            return NavigationViewer::forGuest($xpVisible);
        }

        return new NavigationViewer(
            profile: $profile,
            member: $profile->activeMembership,
            organiser: $profile->organizesEvents,
            moderator: $this->authorizationChecker->isGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS),
            timeVerifier: $this->authorizationChecker->isGranted(SuspiciousResultsVoter::REVIEW_SUSPICIOUS_TIMES),
            admin: $this->authorizationChecker->isGranted(AdminAccessVoter::ADMIN_ACCESS),
            xpVisible: $xpVisible,
            xpParticipant: $xpVisible && !$profile->experienceSystemOptedOut,
            achievementsShown: $xpVisible && !$profile->achievementsOptedOut,
            karmaHolder: $xpVisible && $profile->karma > 0 && !$profile->karmaOptedOut,
            privateMember: $profile->activeMembership && $profile->isPrivateProfile,
            memberOrReferral: $profile->activeMembership || $profile->isInReferralProgram(),
            trialDaysLeft: $profile->freeTrialEndsAt !== null
                ? max(1, (int) ceil(($profile->freeTrialEndsAt->getTimestamp() - $this->clock->now()->getTimestamp()) / 86400))
                : null,
        );
    }
}
