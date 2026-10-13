<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Services\OnlineRounds\OnlineRoundsFeature;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * May set up and run online timing of the competition (subject = competition id, docs/features/online-competitions/):
 * an organiser of the event (COMPETITION_EDIT) for whom the feature is on (OnlineRoundsFeature - admins always,
 * organisers while ONLINE_ROUNDS_FOR_ORGANISERS is on).
 *
 * @extends Voter<string, string>
 */
final class OnlineRoundsVoter extends Voter
{
    public const string ONLINE_ROUNDS_MANAGE = 'ONLINE_ROUNDS_MANAGE';

    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly OnlineRoundsFeature $onlineRoundsFeature,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ONLINE_ROUNDS_MANAGE && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, null|Vote $vote = null): bool
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null || $this->onlineRoundsFeature->isAvailableTo($profile->playerId) === false) {
            return false;
        }

        return $this->accessDecisionManager->decide($token, [CompetitionEditVoter::COMPETITION_EDIT], $subject);
    }
}
