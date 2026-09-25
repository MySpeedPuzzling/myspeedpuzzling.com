<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Reviewing the puzzle catalogue - change requests, merge requests and the
 * approval of newly added puzzles: admins, plus the community moderators an
 * admin appointed (player.moderator_since). It is the only part of /admin a
 * moderator reaches - everything else stays ADMIN_ACCESS.
 *
 * The attribute names the capability, not the role, so another area opened to
 * moderators later gets its own attribute rather than widening this one.
 *
 * @extends Voter<string, mixed>
 */
final class PuzzleModerationVoter extends Voter
{
    public const string PUZZLE_MODERATION_ACCESS = 'PUZZLE_MODERATION_ACCESS';

    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::PUZZLE_MODERATION_ACCESS;
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
