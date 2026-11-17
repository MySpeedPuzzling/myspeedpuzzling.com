<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionToolkitFeature;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * May open the competition toolkit's settings and editors (subject = competition id,
 * docs/features/competitions-management/toolkit/): an organiser of the event (COMPETITION_EDIT) for whom the feature
 * is on (CompetitionToolkitFeature - admins always, organisers while COMPETITION_TOOLKIT_FOR_ORGANISERS is on). Using
 * what was switched on (competitor pages, displays, the start checkbox) is never gated by it.
 *
 * @extends Voter<string, string>
 */
final class CompetitionToolkitVoter extends Voter
{
    public const string COMPETITION_TOOLKIT_MANAGE = 'COMPETITION_TOOLKIT_MANAGE';

    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly CompetitionToolkitFeature $competitionToolkitFeature,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::COMPETITION_TOOLKIT_MANAGE && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, null|Vote $vote = null): bool
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null || $this->competitionToolkitFeature->isAvailableTo($profile->playerId) === false) {
            return false;
        }

        return $this->accessDecisionManager->decide($token, [CompetitionEditVoter::COMPETITION_EDIT], $subject);
    }
}
