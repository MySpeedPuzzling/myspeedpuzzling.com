<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Query\GetCompetitionPermissions;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, string>
 */
final class CompetitionDeleteVoter extends Voter
{
    public const string COMPETITION_DELETE = 'COMPETITION_DELETE';

    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly GetCompetitionPermissions $getCompetitionPermissions,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::COMPETITION_DELETE && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, null|Vote $vote = null): bool
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return false;
        }

        if ($profile->isAdmin === true) {
            return true;
        }

        // The competition's creator, or the creator of its series
        return $this->getCompetitionPermissions->forPlayer($profile->playerId)->canDeleteCompetition($subject);
    }
}
