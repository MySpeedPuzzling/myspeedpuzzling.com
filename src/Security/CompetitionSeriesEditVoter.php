<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Query\IsCompetitionSeriesMaintainer;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, string>
 */
final class CompetitionSeriesEditVoter extends Voter
{
    public const string COMPETITION_SERIES_EDIT = 'COMPETITION_SERIES_EDIT';

    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly IsCompetitionSeriesMaintainer $isCompetitionSeriesMaintainer,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::COMPETITION_SERIES_EDIT && is_string($subject);
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

        return $this->isCompetitionSeriesMaintainer->check($subject, $profile->playerId);
    }
}
