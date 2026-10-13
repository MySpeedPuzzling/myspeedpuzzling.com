<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\RecordStudioGuestPresence;
use SpeedPuzzling\Web\Repository\StudioInviteRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * LiveKit's word on a studio guest (its webhook, contract phase-1b.md §5): the participant's invitation on air in the
 * round (`joined`) gets `onAirFrom` when they appear in the studio - the first time only -, and ends (`ended` +
 * `onAirTo`) when they leave it. Without such an invitation nothing changes: a guest the host thanked already, an
 * unknown round or participant, a webhook delivered twice.
 *
 * Returns whether the participant has an invitation on air (`joined`) - for a join without one the webhook removes
 * them from the studio once this is committed (contract phase-1b.md §8: a studio token alone never puts anybody on
 * air).
 */
#[AsMessageHandler]
readonly final class RecordStudioGuestPresenceHandler
{
    public function __construct(
        private StudioInviteRepository $studioInviteRepository,
    ) {
    }

    public function __invoke(RecordStudioGuestPresence $message): bool
    {
        $invite = $this->studioInviteRepository->joinedOfForUpdate($message->roundId, $message->participantId);

        if ($invite === null) {
            return false;
        }

        if ($message->present) {
            $invite->cameOnAir($message->at);

            return true;
        }

        $invite->end($message->at);

        return true;
    }
}
