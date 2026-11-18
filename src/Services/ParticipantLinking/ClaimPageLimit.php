<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantLinking;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The limit of the personal claim link's page and its buttons (docs/features/competitions-management/
 * participant-linking.md §6.5): per player when signed in (`participant_claim_page_player`) - a venue's shared Wi-Fi at
 * check-in must not stop a hall of people opening their slips at once - and per client IP for guests
 * (`participant_claim_page`, generous for the same reason). 128-bit tokens are not guessed either way; the limit keeps a
 * script from asking at all.
 */
final readonly class ClaimPageLimit
{
    public function __construct(
        private RateLimiterFactoryInterface $participantClaimPageLimiter,
        private RateLimiterFactoryInterface $participantClaimPagePlayerLimiter,
    ) {
    }

    public function accepts(Request $request, null|string $playerId): bool
    {
        $limiter = $playerId !== null
            ? $this->participantClaimPagePlayerLimiter->create(strtolower($playerId))
            : $this->participantClaimPageLimiter->create($request->getClientIp() ?? 'unknown');

        return $limiter->consume()->isAccepted();
    }
}
