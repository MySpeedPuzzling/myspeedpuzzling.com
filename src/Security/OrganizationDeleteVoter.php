<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Query\GetCompetitionPermissions;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Deleting an organization: its creator and admins, like a series (docs/features/organizations/README.md, P3) - and
 * only while it is empty (DeleteOrganization refuses otherwise).
 *
 * @extends Voter<string, string>
 */
final class OrganizationDeleteVoter extends Voter
{
    public const string ORGANIZATION_DELETE = 'ORGANIZATION_DELETE';

    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly GetCompetitionPermissions $getCompetitionPermissions,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ORGANIZATION_DELETE && is_string($subject);
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

        // Only the organization's creator
        return $this->getCompetitionPermissions->forPlayer($profile->playerId)->canDeleteOrganization($subject);
    }
}
