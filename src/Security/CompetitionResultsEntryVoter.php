<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Query\GetCompetitionPermissions;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * May enter results of the competition (subject = competition id) in the live entry: everybody with COMPETITION_EDIT
 * plus the competition's referees (docs/features/competitions-management/live-results.md "Referees"). Grants the live
 * entry pages, the round state and result changes only - every other organiser tool stays on COMPETITION_EDIT.
 * Same one query per request as CompetitionEditVoter (GetCompetitionPermissions).
 *
 * @extends Voter<string, string>
 */
final class CompetitionResultsEntryVoter extends Voter
{
    public const string COMPETITION_RESULTS_ENTRY = 'COMPETITION_RESULTS_ENTRY';

    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly GetCompetitionPermissions $getCompetitionPermissions,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::COMPETITION_RESULTS_ENTRY && is_string($subject);
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

        return $this->getCompetitionPermissions->forPlayer($profile->playerId)->canEnterResults($subject);
    }
}
