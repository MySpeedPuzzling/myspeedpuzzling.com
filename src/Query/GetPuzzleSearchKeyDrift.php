<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PuzzleSearchKeyDrift;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleSearchKeys;

/**
 * The dry run of myspeedpuzzling:rebuild-puzzle-search-keys: builds the keys of these puzzles from their names and
 * codes exactly as Puzzle::refreshSearchKeys() does and returns the ones that differ from the stored keys - writing
 * nothing (docs/features/puzzle-names/codes-help-and-check-digit.md, "Rollout and safety").
 */
readonly final class GetPuzzleSearchKeyDrift
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $puzzleIds
     *
     * @return list<PuzzleSearchKeyDrift>
     */
    public function forIds(array $puzzleIds): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT id, name, alternative_names, ean, identification_number, search_names, search_codes
FROM puzzle
WHERE id IN (:ids)
ORDER BY id
SQL;

        /** @var list<array{id: string, name: string, alternative_names: string, ean: null|string, identification_number: null|string, search_names: null|string, search_codes: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($query, ['ids' => $puzzleIds], ['ids' => ArrayParameterType::STRING])
            ->fetchAllAssociative();

        $drift = [];

        foreach ($rows as $row) {
            /** @var array<mixed> $alternativeNames */
            $alternativeNames = json_decode($row['alternative_names'], true, flags: JSON_THROW_ON_ERROR);
            $expectedNames = PuzzleSearchKeys::names($row['name'], PuzzleNames::fromArray($alternativeNames));
            $expectedCodes = PuzzleSearchKeys::codes($row['ean'], $row['identification_number']);

            if ($expectedNames !== $row['search_names'] || $expectedCodes !== $row['search_codes']) {
                $drift[] = new PuzzleSearchKeyDrift(
                    puzzleId: $row['id'],
                    storedNames: $row['search_names'],
                    expectedNames: $expectedNames,
                    storedCodes: $row['search_codes'],
                    expectedCodes: $expectedCodes,
                );
            }
        }

        return $drift;
    }
}
