<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportPuzzle;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Value\SuspicionAssessment;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SingleTimeSuspicionCheck;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SolveMoment;

/**
 * The time verification's own judgement of a row's time, before the row is added (README "Time verification",
 * docs/features/suspicious-time-review.md "Catch it while typing"): the classifier the scan and the add form use
 * (SingleTimeSuspicionCheck), for the importing player, the decided puzzle, the row's time, kind and solve day. An
 * unusual time - fast or slow - is the row's problem and needs the player's "it is right" before it is added;
 * nothing is marked here and no case is opened: the scan judges the added result like any other.
 *
 * Fails open like the form's check: no judgement (no data, a failed read) lets the row through.
 */
readonly final class ImportRowTimeCheck
{
    public function __construct(
        private SingleTimeSuspicionCheck $singleTimeSuspicionCheck,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param string $day Y-m-d, the solve day
     *
     * @return null|SuspicionAssessment only a raised one: the time is unusual for this player and puzzle
     */
    public function unusual(string $playerId, string $puzzleId, int $seconds, PuzzlingType $type, int $puzzlersCount, string $day): null|SuspicionAssessment
    {
        $noon = new DateTimeImmutable($day . ' 12:00:00', new DateTimeZone('UTC'));

        $assessment = $this->singleTimeSuspicionCheck->forEntry(
            playerId: $playerId,
            puzzleId: $puzzleId,
            seconds: $seconds,
            puzzlingType: $type,
            puzzlersCount: $puzzlersCount,
            excludeTimeId: null,
            // An imported result is "logged" on its solve day (README D5)
            moment: SolveMoment::of($noon, $noon),
            // The lookup of other editions costs 20-40 ms a row and adds nothing to whether the time is asked about
            withOtherEditions: false,
        );

        return $assessment !== null && $assessment->isRaised() ? $assessment : null;
    }

    /**
     * For the screens, before "Add" (6b-2 - no UI here): which of the rows carry a time the player will be asked
     * about, with what the verification expects. Only rows of the player's own import whose puzzle is decided as a
     * catalogue puzzle and that have a time and an exact day; at most 200 rows a call.
     *
     * @param list<string> $rowIds
     *
     * @return array<string, SuspicionAssessment> by row id
     */
    public function unusualAmong(DataImport $import, array $rowIds): array
    {
        $rowIds = array_slice(array_values(array_unique($rowIds)), 0, 200);

        if ($rowIds === []) {
            return [];
        }

        /** @var list<DataImportRow> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(DataImportRow::class, 'r')
            ->where('r.import = :import')
            ->andWhere('r.id IN (:ids)')
            ->andWhere('r.kind IN (:kinds)')
            ->setParameter('import', $import)
            ->setParameter('ids', $rowIds)
            ->setParameter('kinds', [DataImportRowKind::Result->value, DataImportRowKind::Both->value])
            ->getQuery()
            ->getResult();

        $unusual = [];

        foreach ($rows as $row) {
            $parsed = $row->parsed ?? [];
            $importPuzzle = $row->importPuzzleId === null ? null : $this->entityManager->find(DataImportPuzzle::class, $row->importPuzzleId);
            $type = is_string($parsed['type'] ?? null) ? PuzzlingType::tryFrom($parsed['type']) : null;
            $seconds = $parsed['seconds'] ?? null;
            $day = $parsed['date'] ?? null;

            if (
                $importPuzzle === null
                || $importPuzzle->choice !== ImportPuzzleChoice::Catalogue
                || $importPuzzle->puzzle === null
                || $type === null
                || is_int($seconds) === false
                || $seconds <= 0
                || ($parsed['datePrecision'] ?? null) !== 'day'
                || is_string($day) === false
                || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1
            ) {
                continue;
            }

            $people = is_array($parsed['people'] ?? null) ? count($parsed['people']) : 0;
            $assessment = $this->unusual($import->player->id->toString(), $importPuzzle->puzzle->id->toString(), $seconds, $type, $people + 1, $day);

            if ($assessment !== null) {
                $unusual[$row->id->toString()] = $assessment;
            }
        }

        return $unusual;
    }
}
