<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\InvalidTableNumbers;
use SpeedPuzzling\Web\Message\AssignTableNumbers;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AssignTableNumbersHandler
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private CompetitionParticipantRoundRepository $participantRoundRepository,
        private CompetitionTeamRepository $teamRepository,
    ) {
    }

    /**
     * @return list<string> refs of the entries whose number changed
     * @throws CompetitionRoundNotFound
     * @throws InvalidTableNumbers
     */
    public function __invoke(AssignTableNumbers $message): array
    {
        $round = $this->roundRepository->get($message->roundId);

        if ($round->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        /** @var array<string, CompetitionParticipantRound|CompetitionTeam> $entries */
        $entries = [];
        if ($round->category === RoundCategory::Solo) {
            foreach ($this->participantRoundRepository->findByRound($round) as $participantRound) {
                if ($participantRound->participant->isDeleted() === false) {
                    $entries[$participantRound->entryRef()->toString()] = $participantRound;
                }
            }
        } else {
            foreach ($this->teamRepository->findByRound($round) as $team) {
                $entries[$team->entryRef()->toString()] = $team;
            }
        }

        $numbers = array_map(static fn (CompetitionParticipantRound|CompetitionTeam $entry): null|int => $entry->tableNumber, $entries);
        $listed = [];
        $problems = [];

        foreach ($message->assignments as $assignment) {
            $ref = RoundEntryRef::tryFromString($assignment['entry'])?->toString();

            if ($ref === null || !isset($entries[$ref])) {
                $problems[] = ['entry' => $assignment['entry'], 'reason' => 'entry_not_found'];

                continue;
            }

            if (isset($listed[$ref])) {
                $problems[] = ['entry' => $ref, 'reason' => 'duplicate_entry'];

                continue;
            }

            $listed[$ref] = true;
            $number = $assignment['number'];

            if ($number !== null && ($number < 1 || $number > RecordRoundResultsHandler::MAX_TABLE_NUMBER)) {
                $problems[] = ['entry' => $ref, 'reason' => 'invalid_table_number'];

                continue;
            }

            $numbers[$ref] = $number;
        }

        $refsByNumber = [];
        foreach ($numbers as $ref => $number) {
            if ($number !== null) {
                $refsByNumber[$number][] = $ref;
            }
        }

        foreach ($refsByNumber as $refs) {
            if (count($refs) > 1) {
                foreach ($refs as $ref) {
                    if (isset($listed[$ref])) {
                        $problems[] = ['entry' => $ref, 'reason' => 'table_number_taken'];
                    }
                }
            }
        }

        if ($problems !== []) {
            throw new InvalidTableNumbers($problems);
        }

        $changed = [];
        foreach ($numbers as $ref => $number) {
            if ($entries[$ref]->tableNumber !== $number) {
                $entries[$ref]->assignTableNumber($number);
                $changed[] = $ref;
            }
        }

        return $changed;
    }
}
