<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\WithdrawParticipantLinkRequest;
use SpeedPuzzling\Web\Repository\ParticipantLinkRequestRepository;
use SpeedPuzzling\Web\Services\ParticipantLinking\ParticipantLinks;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class WithdrawParticipantLinkRequestHandler
{
    public function __construct(
        private ParticipantLinkRequestRepository $requestRepository,
        private ParticipantLinks $participantLinks,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(WithdrawParticipantLinkRequest $message): void
    {
        $now = $this->clock->now();

        foreach ($this->requestRepository->pendingOfPlayer($message->competitionId, $message->playerId) as $request) {
            $this->participantLinks->withdraw($request, $now);
        }
    }
}
