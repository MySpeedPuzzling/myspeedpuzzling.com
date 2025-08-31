<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, mixed>
 */
final class AdminAccessVoter extends Voter
{
    public const string ADMIN_ACCESS = 'ADMIN_ACCESS';

    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ADMIN_ACCESS;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if ($attribute !== self::ADMIN_ACCESS) {
            return false;
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return false;
        }

        return $profile->isAdmin === true;
    }
}
