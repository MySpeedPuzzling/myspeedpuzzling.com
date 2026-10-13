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
 */
#[AsMessageHandler]
readonly final class RecordStudioGuestPresenceHandler
{
    public function __construct(
        private StudioInviteRepository $studioInviteRepository,
    ) {
    }

    public function __invoke(RecordStudioGuestPresence $message): void
    {
        $invite = $this->studioInviteRepository->joinedOfForUpdate($message->roundId, $message->participantId);

        if ($invite === null) {
            return;
        }

        if ($message->present) {
            $invite->cameOnAir($message->at);

            return;
        }

        $invite->end($message->at);
    }
}
