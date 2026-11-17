<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * May open the competition toolkit's settings and editors (subject = competition id,
 * docs/features/competitions-management/toolkit/): exactly COMPETITION_EDIT - every organiser of the event (and admins).
 * Kept as its own attribute so the toolkit's settings stay findable in templates and controllers.
 *
 * @extends Voter<string, string>
 */
final class CompetitionToolkitVoter extends Voter
{
    public const string COMPETITION_TOOLKIT_MANAGE = 'COMPETITION_TOOLKIT_MANAGE';

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::COMPETITION_TOOLKIT_MANAGE && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, null|Vote $vote = null): bool
    {
        return $this->accessDecisionManager->decide($token, [CompetitionEditVoter::COMPETITION_EDIT], $subject);
    }
}
