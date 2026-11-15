<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Results\ImportAddRow;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;

/**
 * What the group of an import's rows that wait with an unusual time looks like - shown before "These times are
 * right - add all N", so the player can decide (docs/features/data-import/group-answers.md "Unusual times"): how
 * many are faster and how many slower than expected, how far off (the range of expected ÷ entered), five examples
 * (the fastest, the slowest, three around the middle) with what was expected, and whether most of the file's times
 * wait as unusual - by exact counts over all the import's rows.
 *
 * Worked out now, by the time verification's own judgement (ImportRowTimeCheck) of an even sample of the group
 * (at most SAMPLE rows - each costs several statements): the shares are the sample's, said as such.
 */
readonly final class ImportUnusualTimesSummary
{
    public const int SAMPLE = 200;

    public function __construct(
        private GetImportAddScreen $getImportAddScreen,
        private DataImportRepository $dataImportRepository,
        private ImportRowTimeCheck $timeCheck,
    ) {
    }

    /**
     * @param array{reasons: array<string, int>, timedResults: int, ...} $counts GetImportAddScreen::notAddedByReason()
     *
     * @return array{rows: int, lookedAt: int, faster: int, slower: int, fasterFrom: null|float, fasterTo: null|float, slowerFrom: null|float, slowerTo: null|float, examples: list<array{row: ImportAddRow, direction: string, expected: null|int}>, most: null|string}
     */
    public function of(string $importId, string $playerId, null|string $batchId, array $counts): array
    {
        $rows = $counts['reasons'][ImportRowRefusal::UnusualTime->value] ?? 0;
        $summary = ['rows' => $rows, 'lookedAt' => 0, 'faster' => 0, 'slower' => 0, 'fasterFrom' => null, 'fasterTo' => null, 'slowerFrom' => null, 'slowerTo' => null, 'examples' => [], 'most' => null];

        if ($rows === 0) {
            return $summary;
        }

        $sample = $this->getImportAddScreen->sampleNotAddedFor($importId, $playerId, ImportRowRefusal::UnusualTime->value, $batchId, self::SAMPLE);
        $assessments = $this->timeCheck->unusualAmong($this->dataImportRepository->getOwnedBy($importId, $playerId), $sample);
        $summary['lookedAt'] = count($sample);
        /** @var list<array{id: string, direction: string, factor: null|float, expected: null|int}> $judged */
        $judged = [];

        foreach ($assessments as $rowId => $assessment) {
            $direction = $assessment->direction()->value ?? 'fast';
            // How many times faster (expected ÷ entered) or slower (entered ÷ expected) - null without an expectation
            $factor = $assessment->ratio === null || $assessment->ratio <= 0.0 ? null : ($direction === 'slow' ? 1 / $assessment->ratio : $assessment->ratio);
            $judged[] = ['id' => (string) $rowId, 'direction' => $direction, 'factor' => $factor, 'expected' => $assessment->expectedSeconds];
        }

        foreach (['fast' => 'faster', 'slow' => 'slower'] as $direction => $key) {
            $of = array_values(array_filter($judged, static fn (array $row): bool => $row['direction'] === $direction));
            $factors = array_values(array_filter(array_column($of, 'factor'), is_float(...)));
            $summary[$key] = count($of);
            $summary[$key . 'From'] = $factors === [] ? null : min($factors);
            $summary[$key . 'To'] = $factors === [] ? null : max($factors);
        }

        // The fastest, the slowest, three around the middle - by how the entered time stands to the expected one
        usort($judged, static fn (array $a, array $b): int => self::speed($b) <=> self::speed($a));
        $picked = [];

        if ($judged !== []) {
            $last = count($judged) - 1;
            $middle = intdiv($last, 2);

            foreach ([0, $middle - 1, $middle, $middle + 1, $last] as $index) {
                if ($index >= 0 && $index <= $last) {
                    $picked[$judged[$index]['id']] = $judged[$index];
                }
            }
        }

        $shown = $this->getImportAddScreen->rowsByIds($importId, $playerId, array_keys($picked));

        foreach ($picked as $rowId => $row) {
            if (isset($shown[$rowId])) {
                $summary['examples'][] = ['row' => $shown[$rowId], 'direction' => $row['direction'], 'expected' => $row['expected']];
            }
        }

        // "Most of the times in this file": **exact counts only** - the rows that wait as unusual against all the
        // import's results with a time (GetImportAddScreen::notAddedByReason(), one statement over every row), never
        // an extrapolation of the sample. The sample only chooses the word: faster or slower when four of five
        // of the times looked at are off the same way, else neither
        if ($rows * 2 > $counts['timedResults'] && $counts['timedResults'] > 0) {
            $summary['most'] = match (true) {
                $judged !== [] && $summary['faster'] >= 0.8 * count($judged) => 'faster',
                $judged !== [] && $summary['slower'] >= 0.8 * count($judged) => 'slower',
                default => 'mixed',
            };
        }

        return $summary;
    }

    /**
     * Expected ÷ entered: the larger, the faster the entered time against what was expected.
     *
     * @param array{id: string, direction: string, factor: null|float, expected: null|int} $row
     */
    private static function speed(array $row): float
    {
        $factor = $row['factor'] ?? 1.0;

        return $row['direction'] === 'slow' ? 1 / max($factor, 0.0001) : $factor;
    }
}
