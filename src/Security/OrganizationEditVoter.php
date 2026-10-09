<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Query\GetCompetitionPermissions;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The organization's team (creator and maintainers) and admins - edit it, manage its team, publish/unpublish it, see it
 * while it is a draft (docs/features/organizations/README.md "Permissions").
 *
 * @extends Voter<string, string>
 */
final class OrganizationEditVoter extends Voter
{
    public const string ORGANIZATION_EDIT = 'ORGANIZATION_EDIT';

    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly GetCompetitionPermissions $getCompetitionPermissions,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ORGANIZATION_EDIT && is_string($subject);
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

        return $this->getCompetitionPermissions->forPlayer($profile->playerId)->canEditOrganization($subject);
    }
}
