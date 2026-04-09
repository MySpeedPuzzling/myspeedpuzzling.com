<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditCompetitionRoundHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
    ) {
    }

    public function __invoke(EditCompetitionRound $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        $round->edit(
            name: $message->name,
            minutesLimit: $message->minutesLimit,
            startsAt: $message->startsAt,
            badgeBackgroundColor: $message->badgeBackgroundColor,
            badgeTextColor: $message->badgeTextColor,
            category: $message->category,
        );
    }
}
