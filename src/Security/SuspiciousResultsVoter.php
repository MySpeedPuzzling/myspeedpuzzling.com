<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Seeing somebody else's suspicious results (docs/features/suspicious-times.md) - on the result detail, which
 * otherwise shows them to the player or the pair's/team's members only: admins, plus the community moderators.
 * Its own capability, like every moderator-reachable area outside the catalogue (PuzzleModerationVoter).
 *
 * @extends Voter<string, mixed>
 */
final class SuspiciousResultsVoter extends Voter
{
    public const string VIEW_SUSPICIOUS_RESULTS = 'VIEW_SUSPICIOUS_RESULTS';

    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW_SUSPICIOUS_RESULTS;
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
