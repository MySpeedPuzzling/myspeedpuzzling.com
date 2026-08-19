<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Api;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Security\ApiUser;
use SpeedPuzzling\Web\Security\PatUser;
use SpeedPuzzling\Web\Value\OAuth2Scope;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The single membership / scope gate of the public API: who is behind the
 * token making this request, and what are they entitled to see.
 *
 * A personal access token and an authorization-code token carry a player
 * (ApiUser); a client_credentials token authenticates as the bundle's
 * ClientCredentialsUser and carries none - it is never a member and never
 * sees personal data. Members-exclusive data behaves exactly as on the
 * website (docs/features/api/README.md, Members-Exclusive Data), so every
 * provider asks this service instead of inspecting the token itself.
 *
 * The profile lookup is one query, memoised for the request; FrankenPHP
 * worker mode keeps the instance alive between requests, reset() clears it.
 */
final class ApiTokenOwner implements ResetInterface
{
    private bool $profileLoaded = false;

    private null|PlayerProfile $profile = null;

    public function __construct(
        private readonly Security $security,
        private readonly GetPlayerProfile $getPlayerProfile,
    ) {
    }

    public function reset(): void
    {
        $this->profileLoaded = false;
        $this->profile = null;
    }

    /**
     * The player behind the token, or null for a machine token (client_credentials)
     * and for an anonymous request. Never throws.
     */
    public function profile(): null|PlayerProfile
    {
        if ($this->profileLoaded) {
            return $this->profile;
        }

        $this->profileLoaded = true;
        $user = $this->security->getUser();

        if (!$user instanceof ApiUser) {
            return $this->profile = null;
        }

        try {
            return $this->profile = $this->getPlayerProfile->byId($user->getPlayer()->id->toString());
        } catch (PlayerNotFound) {
            return $this->profile = null;
        }
    }

    /**
     * Active membership of the token owner - the gate for Puzzle Insights
     * (difficulty, predictions, skill tiers). A machine token is never a member.
     */
    public function isMember(): bool
    {
        return $this->profile()?->activeMembership === true;
    }

    /**
     * May the token read the owner's own results? PAT always, OAuth2 with results:read.
     */
    public function canReadResults(): bool
    {
        return $this->security->isGranted(PatUser::ROLE)
            || $this->security->isGranted(OAuth2Scope::ResultsRead->role());
    }

    /**
     * May the token read the owner's own statistics? PAT always, OAuth2 with statistics:read.
     */
    public function canReadStatistics(): bool
    {
        return $this->security->isGranted(PatUser::ROLE)
            || $this->security->isGranted(OAuth2Scope::StatisticsRead->role());
    }
}
