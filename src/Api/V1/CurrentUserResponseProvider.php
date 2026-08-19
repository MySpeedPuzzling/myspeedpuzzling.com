<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Services\Api\ApiTokenOwner;
use SpeedPuzzling\Web\Services\Api\ProfileInsightsResponseFactory;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * GET /api/v1/me - the token owner's own profile, with the insight blocks the
 * owner's profile page shows (MSP Rating, skill tiers, badges) under the same
 * gates: rating and skill are hidden when the owner opted out of rankings,
 * skill is additionally members-only.
 *
 * @implements ProviderInterface<CurrentUserResponse>
 */
final readonly class CurrentUserResponseProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private ApiTokenOwner $tokenOwner,
        private ProfileInsightsResponseFactory $profileInsights,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CurrentUserResponse
    {
        // access_control admits only tokens with a player behind them (PAT, auth-code),
        // so the owner is always there; the profile is loaded once and memoised.
        $profile = $this->tokenOwner->profile() ?? throw new PlayerNotFound();

        // Email is private. Personal Access Tokens grant full access to the owner's own
        // data, while third-party OAuth2 clients must be explicitly granted the
        // "email:read" scope (role ROLE_OAUTH2_EMAIL:READ) — otherwise email stays null.
        $canReadEmail = $this->security->isGranted('ROLE_PAT')
            || $this->security->isGranted('ROLE_OAUTH2_EMAIL:READ');

        // The profile page hides both blocks for a player who opted out of rankings;
        // the skill tiers are Puzzle Insights and members-only on top of that.
        $showsRanking = $profile->rankingOptedOut === false;

        return new CurrentUserResponse(
            id: $profile->playerId,
            name: $profile->playerName,
            email: $canReadEmail ? $profile->email : null,
            code: $profile->code,
            avatar: $profile->avatar,
            country: $profile->country,
            city: $profile->city,
            bio: $profile->bio,
            facebook: $profile->facebook,
            instagram: $profile->instagram,
            is_private: $profile->isPrivate,
            has_active_membership: $profile->activeMembership,
            // GetPlayerProfile coalesces a missing/expired membership to the 1970 epoch
            // (GREATEST over COALESCEd columns), so the date is only meaningful - and
            // only exposed - while the membership is active.
            membership_ends_at: $profile->activeMembership ? $profile->membershipEndsAt?->format('c') : null,
            time_predictions_opted_out: $profile->timePredictionsOptedOut,
            ranking_opted_out: $profile->rankingOptedOut,
            streak_opted_out: $profile->streakOptedOut,
            rating: $showsRanking ? $this->profileInsights->rating($profile->playerId) : null,
            skill: $showsRanking && $this->tokenOwner->isMember() ? $this->profileInsights->skill($profile->playerId) : null,
            badges: $this->profileInsights->badges($profile->playerId),
        );
    }
}
