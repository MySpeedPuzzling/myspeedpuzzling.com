<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\TableNumbersCopyRefused;
use SpeedPuzzling\Web\Message\CopyRoundTableNumbers;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Services\SameTablesCopy;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CopyRoundTableNumbersHandler
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private CompetitionParticipantRoundRepository $participantRoundRepository,
        private CompetitionTeamRepository $teamRepository,
        private SameTablesCopy $sameTablesCopy,
    ) {
    }

    /**
     * @return array<string, list<string>> target round id => refs of the entries whose number changed
     * @throws CompetitionRoundNotFound
     * @throws TableNumbersCopyRefused
     */
    public function __invoke(CopyRoundTableNumbers $message): array
    {
        $source = $this->roundRepository->get($message->sourceRoundId);
        $competitionId = $source->competition->id->toString();

        if ($competitionId !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        // The plan is read again under the event's lock - what the confirm showed is checked against it
        $plan = $this->sameTablesCopy->plan($competitionId, $source->id->toString());
        $targetsById = [];
        foreach ($plan['targets'] as $target) {
            $targetsById[$target->roundId] = $target;
        }

        $problems = [];

        if ($message->targets === []) {
            $problems[] = ['round' => null, 'reason' => 'no_targets'];
        }

        if ($plan['sourceSeated'] === 0) {
            $problems[] = ['round' => null, 'reason' => 'source_without_tables'];
        }

        $chosen = [];
        foreach ($message->targets as $roundId => $hash) {
            $target = $targetsById[strtolower($roundId)] ?? null;

            if ($target === null) {
                $problems[] = ['round' => $roundId, 'reason' => 'round_not_available'];

                continue;
            }

            if (!hash_equals($target->hash, $hash)) {
                $problems[] = ['round' => $target->roundId, 'reason' => 'changed_meanwhile'];

                continue;
            }

            if ($target->highestTable() > RecordRoundResultsHandler::MAX_TABLE_NUMBER) {
                $problems[] = ['round' => $target->roundId, 'reason' => 'invalid_table_number'];

                continue;
            }

            $chosen[] = $target;
        }

        if ($problems !== []) {
            throw new TableNumbersCopyRefused($problems);
        }

        $changed = [];
        foreach ($chosen as $target) {
            $changed[$target->roundId] = [];

            foreach ($this->entries($this->roundRepository->get($target->roundId)) as $ref => $entry) {
                $number = $target->tables[$ref] ?? $entry->tableNumber;

                if ($entry->tableNumber !== $number) {
                    $entry->assignTableNumber($number);
                    $changed[$target->roundId][] = $ref;
                }
            }
        }

        return $changed;
    }

    /**
     * The round's entries as AssignTableNumbersHandler writes them.
     *
     * @return array<string, CompetitionParticipantRound|CompetitionTeam> entry ref => entry
     */
    private function entries(CompetitionRound $round): array
    {
        $entries = [];

        if ($round->category === RoundCategory::Solo) {
            foreach ($this->participantRoundRepository->findByRound($round) as $participantRound) {
                if ($participantRound->participant->isDeleted() === false) {
                    $entries[$participantRound->entryRef()->toString()] = $participantRound;
                }
            }

            return $entries;
        }

        foreach ($this->teamRepository->findByRound($round) as $team) {
            $entries[$team->entryRef()->toString()] = $team;
        }

        return $entries;
    }
}
