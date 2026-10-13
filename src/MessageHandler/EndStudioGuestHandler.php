<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\StudioInviteNotFound;
use SpeedPuzzling\Web\Message\EndStudioGuest;
use SpeedPuzzling\Web\Repository\StudioInviteRepository;
use SpeedPuzzling\Web\Results\EndedStudioGuest;
use SpeedPuzzling\Web\Value\StudioInviteStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The host's "Thank you" (contract phase-1b.md §4): an invitation on air ends (`ended` + `onAirTo`), one still waiting
 * for its answer is withdrawn (`ended`), one that ran out is written as expired; an invitation that is over already
 * stays as it is (a second click, another host). Returns whether the guest was on air - then the controller takes
 * their publishing away in the studio once this is committed.
 */
#[AsMessageHandler]
readonly final class EndStudioGuestHandler
{
    public function __construct(
        private StudioInviteRepository $studioInviteRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws StudioInviteNotFound
     */
    public function __invoke(EndStudioGuest $message): EndedStudioGuest
    {
        $invite = $this->studioInviteRepository->getForUpdate($message->inviteId);
        $now = $this->clock->now();
        $status = $invite->statusAt($now);

        if ($status === StudioInviteStatus::Expired) {
            $invite->expire();
        } else {
            $invite->end($now);
        }

        return new EndedStudioGuest($invite, wasOnAir: $status === StudioInviteStatus::Joined);
    }
}
