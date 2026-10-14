<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\StudioInvite;
use SpeedPuzzling\Web\Exceptions\StudioInviteNotFound;
use SpeedPuzzling\Web\Exceptions\StudioInviteRefused;
use SpeedPuzzling\Web\Message\AnswerStudioInvite;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Repository\StudioInviteRepository;
use SpeedPuzzling\Web\Value\StudioInviteAnswer;
use SpeedPuzzling\Web\Value\StudioInviteStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The participant's answer to an invitation to the studio (contract phase-1b.md §4): "Join" → `joined` (on air once
 * LiveKit sees them in the studio - RecordStudioGuestPresence), "Not now" → `declined`. Only an invitation of the
 * personal link's participant (else not found), only while it waits for the answer: the same answer again is answered
 * again (a double tap), anything else is refused (StudioInviteRefused - `invite_expired`, `invite_not_open`; joining
 * also `video_unavailable` once the round's video was turned off meanwhile).
 *
 * Returns the invitation as it is now.
 */
#[AsMessageHandler]
readonly final class AnswerStudioInviteHandler
{
    public function __construct(
        private StudioInviteRepository $studioInviteRepository,
        private OnlineRoundRepository $onlineRoundRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws StudioInviteNotFound
     * @throws StudioInviteRefused
     */
    public function __invoke(AnswerStudioInvite $message): StudioInvite
    {
        $invite = $this->studioInviteRepository->getForUpdate($message->inviteId);

        if ($invite->participant->id->toString() !== strtolower($message->participantId)) {
            throw new StudioInviteNotFound();
        }

        $now = $this->clock->now();
        $status = $invite->statusAt($now);
        $join = $message->answer === StudioInviteAnswer::Join;

        if ($status === ($join ? StudioInviteStatus::Joined : StudioInviteStatus::Declined)) {
            return $invite;
        }

        if ($status === StudioInviteStatus::Expired) {
            throw StudioInviteRefused::because(StudioInviteRefused::EXPIRED);
        }

        if ($status !== StudioInviteStatus::Invited) {
            throw StudioInviteRefused::because(StudioInviteRefused::NOT_OPEN);
        }

        if ($join === false) {
            $invite->decline($now);

            return $invite;
        }

        if ($this->onlineRoundRepository->ofRound($invite->round->id->toString())?->videoEnabled !== true) {
            throw StudioInviteRefused::because(StudioInviteRefused::VIDEO_UNAVAILABLE);
        }

        $invite->join($now);

        return $invite;
    }
}
