<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;

#[AsMessageHandler]
readonly final class EditCompetitionRoundHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private GetCompetitionRounds $getCompetitionRounds,
    ) {
    }

    /**
     * @throws PuzzleAlreadyInCompetitionRoundCategory
     */

    public function __invoke(EditCompetitionRound $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        if ($message->category !== $round->category) {
            $conflictingRound = $this->getCompetitionRounds->roundWithPuzzleInCategory(
                competitionId: $round->competition->id->toString(),
                puzzleIds: array_values(array_map(
                    static fn (CompetitionRoundPuzzle $roundPuzzle): string => $roundPuzzle->puzzle->id->toString(),
                    $round->roundPuzzles->toArray(),
                )),
                category: $message->category,
                exceptRoundId: $round->id->toString(),
            );

            if ($conflictingRound !== null) {
                throw new PuzzleAlreadyInCompetitionRoundCategory($conflictingRound);
            }
        }

        $round->edit(
            name: $message->name,
            minutesLimit: $message->minutesLimit,
            startsAt: $message->startsAt,
            timezone: $message->timezone,
            badgeBackgroundColor: $message->badgeBackgroundColor,
            badgeTextColor: $message->badgeTextColor,
            category: $message->category,
            resultsLink: $message->resultsLink,
        );

        // An automatic reveal follows the round's start - the puzzles it keeps secret on the whole site follow too.
        // Scheduled and manual reveals are the organiser's own and stay where they are.
        foreach ($round->roundPuzzles as $roundPuzzle) {
            $roundPuzzle->syncPuzzleHide();
        }
    }
}
