<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\InvalidTableLabels;
use SpeedPuzzling\Web\Exceptions\TableLabelsRefused;
use SpeedPuzzling\Web\Message\SetRoundTableLabels;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\TableLabels;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * table-labels-and-plates.md "Writes": the same list (or none) for every round given, all or nothing - a round another
 * organiser changed since the editor opened, or one where somebody sits at a number the new list does not name, refuses
 * everything. Entries keep their numbers. Answers the ids of the rounds whose list changed.
 */
#[AsMessageHandler]
readonly final class SetRoundTableLabelsHandler
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private CompetitionParticipantRoundRepository $participantRoundRepository,
        private CompetitionTeamRepository $teamRepository,
    ) {
    }

    /**
     * @return list<string>
     * @throws CompetitionRoundNotFound
     * @throws InvalidTableLabels
     * @throws TableLabelsRefused
     */
    public function __invoke(SetRoundTableLabels $message): array
    {
        $labels = $message->labels === null ? null : TableLabels::fromList($message->labels);

        if ($message->roundIds === []) {
            throw new TableLabelsRefused([['round' => null, 'reason' => 'no_rounds']]);
        }

        /** @var array<string, CompetitionRound> $rounds */
        $rounds = [];

        foreach ($message->roundIds as $roundId) {
            $round = $this->roundRepository->get($roundId);

            if ($round->competition->id->toString() !== strtolower($message->competitionId)) {
                throw new CompetitionRoundNotFound();
            }

            $rounds[$round->id->toString()] = $round;
        }

        $problems = [];

        foreach ($rounds as $roundId => $round) {
            $expected = self::expectedFor($message->expected, $roundId);

            if ($expected !== $round->tableLabels) {
                $problems[] = ['round' => $roundId, 'reason' => 'changed_meanwhile', 'current' => $round->tableLabels];

                continue;
            }

            $highest = $labels === null ? 0 : $this->highestTable($round);

            if ($labels !== null && $highest > $labels->count()) {
                $problems[] = ['round' => $roundId, 'reason' => 'too_few', 'highest' => $highest];
            }
        }

        if ($problems !== []) {
            throw new TableLabelsRefused($problems);
        }

        $changed = [];

        foreach ($rounds as $roundId => $round) {
            if ($round->tableLabels !== $labels?->labels) {
                $round->changeTableLabels($labels);
                $changed[] = $roundId;
            }
        }

        return $changed;
    }

    /**
     * @param array<string, null|list<string>> $expected
     * @return null|list<string>
     */
    private static function expectedFor(array $expected, string $roundId): null|array
    {
        foreach ($expected as $id => $list) {
            if (strtolower($id) === $roundId) {
                return $list === [] ? null : $list;
            }
        }

        return null;
    }

    private function highestTable(CompetitionRound $round): int
    {
        $entries = $round->category === RoundCategory::Solo
            ? array_filter(
                $this->participantRoundRepository->findByRound($round),
                static fn (CompetitionParticipantRound $entry): bool => $entry->participant->isDeleted() === false,
            )
            : $this->teamRepository->findByRound($round);

        $highest = 0;

        foreach ($entries as $entry) {
            /** @var CompetitionParticipantRound|CompetitionTeam $entry */
            $highest = max($highest, $entry->tableNumber ?? 0);
        }

        return $highest;
    }
}
