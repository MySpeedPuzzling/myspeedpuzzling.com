<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\LiveKit;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Repository\StudioInviteRepository;
use SpeedPuzzling\Web\Value\OnlineAccessContext;

/**
 * The `studio` of a participant's state (`GET /api/compete/{token}/state`, contract phase-1b.md §4): their latest
 * invitation to the studio of the round their page is about - `{inviteId, status}`, the expiry checked as of now
 * (StudioInvite::statusAt()) - or null: no round, the round's live video is off, never invited there. One statement,
 * and none at all while the round's video is off.
 */
final readonly class ParticipantStudioState
{
    public function __construct(
        private StudioInviteRepository $studioInviteRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return null|array{inviteId: string, status: string}
     */
    public function forParticipant(OnlineAccessContext $context): null|array
    {
        if ($context->round === null || $context->onlineRound?->videoEnabled !== true) {
            return null;
        }

        $invite = $this->studioInviteRepository->latestOf($context->round->id->toString(), $context->participant->id->toString());

        if ($invite === null) {
            return null;
        }

        return [
            'inviteId' => $invite->id->toString(),
            'status' => $invite->statusAt($this->clock->now())->value,
        ];
    }
}
