<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * REVIEW_SUSPICIOUS_TIMES: the time verification queue /admin/time-verification
 * (docs/features/suspicious-time-review.md, "Moderator queue") - admins, plus the community moderators. Its own
 * capability, like every moderator-reachable area outside the catalogue (PuzzleModerationVoter), with its own
 * access_control rule above ^/admin.
 *
 * @extends Voter<string, mixed>
 */
final class SuspiciousResultsVoter extends Voter
{
    public const string REVIEW_SUSPICIOUS_TIMES = 'REVIEW_SUSPICIOUS_TIMES';

    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::REVIEW_SUSPICIOUS_TIMES;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, null|Vote $vote = null): bool
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return false;
        }

        return $profile->isAdmin === true || $profile->isModerator === true;
    }
}
