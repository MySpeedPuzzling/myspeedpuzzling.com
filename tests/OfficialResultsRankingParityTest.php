<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The results desk re-ranks every live update in the browser (assets/official_results_ranking.js) - it must rank and
 * order exactly like the server (OfficialResultsRanking + GetRoundResultEntries), or the desk would show another
 * order than the next page load, the export and the public page.
 */
final class OfficialResultsRankingParityTest extends TestCase
{
    public function testTheBrowserRanksAndOrdersLikeTheServer(): void
    {
        $rounds = [
            // The fixture's Group A: a winner, a tie for second, unfinished, did not start, no result
            [
                ['seconds' => 3600], ['seconds' => 4200], ['seconds' => 4200], ['piecesPlaced' => 850], ['didNotStart' => true], null,
            ],
            // Everything tied, unfinished ties, several without a result
            [
                ['seconds' => 100], ['seconds' => 100], ['seconds' => 100], ['piecesPlaced' => 5], ['piecesPlaced' => 5], ['piecesPlaced' => 900], null, null, ['didNotStart' => true], ['didNotStart' => true],
            ],
            // Nobody has a result yet
            [null, null, null],
            [],
        ];

        // A pseudo-random round of 120 entries with many ties (deterministic)
        $seed = 7;
        $random = [];
        for ($i = 0; $i < 120; $i++) {
            $seed = ($seed * 1103515245 + 12345) % 2147483648;
            $random[] = match ($seed % 6) {
                0 => null,
                1 => ['didNotStart' => true],
                2 => ['piecesPlaced' => 400 + $seed % 5],
                default => ['seconds' => 3000 + $seed % 40],
            };
        }
        $rounds[] = $random;

        $names = ['Anna', 'anna', 'Ben', 'Čeněk', 'Zoë', 'zoe', 'ÁDÁM', 'adam', 'Øyvind', 'Ola', 'Ölf', 'émile', 'Émile', 'Puzzle Sharks', 'puzzle sharks', '#1', '', 'b', 'B', 'Ž'];

        $entrySets = [];
        foreach ($rounds as $roundIndex => $results) {
            $entries = [];
            foreach ($results as $index => $result) {
                $tableNumber = $index % 4 === 0 ? null : ($index * 7) % 13 + 1;
                $entries[] = [
                    'id' => sprintf('018d0020-0000-0000-%04d-%012d', $roundIndex, ($index * 37) % 1000),
                    'displayName' => $names[($index * 3) % count($names)],
                    'tableNumber' => $tableNumber,
                    'result' => $result,
                ];
            }
            $entrySets[] = $entries;
        }

        $expected = array_map(static fn (array $entries): array => self::ranked($entries), $entrySets);

        self::assertSame($expected, $this->runInNode($entrySets));
    }

    /**
     * The server's order: GetRoundResultEntries' own ranking of the entries.
     *
     * @param list<array{id: string, displayName: string, tableNumber: null|int, result: null|array<string, int|bool>}> $entries
     * @return list<array{id: string, rank: null|int}>
     */
    private static function ranked(array $entries): array
    {
        $roundEntries = array_map(static fn (array $entry): RoundResultEntry => new RoundResultEntry(
            ref: RoundEntryRef::participantRound($entry['id']),
            kind: RoundResultEntry::KIND_PERSON,
            roundId: '018d0020-0000-0000-0000-000000000101',
            name: $entry['displayName'],
            participantId: null,
            country: null,
            countries: [],
            members: [],
            playerId: null,
            playerCode: null,
            playerName: null,
            tableNumber: $entry['tableNumber'],
            result: RoundEntryResult::fromWire($entry['result']),
            rank: null,
            qualifiedAt: null,
            resultEnteredAt: null,
            resultEnteredById: null,
            resultEnteredByName: null,
        ), $entries);

        $method = new \ReflectionMethod(GetRoundResultEntries::class, 'ranked');
        /** @var list<RoundResultEntry> $ranked */
        $ranked = $method->invoke(null, $roundEntries);

        return array_map(static fn (RoundResultEntry $entry): array => ['id' => $entry->ref->id, 'rank' => $entry->rank], $ranked);
    }

    /**
     * @param list<list<array<string, mixed>>> $rounds
     * @return list<list<array{id: string, rank: null|int}>>
     */
    private function runInNode(array $rounds): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/official-results-ranking-harness.mjs']);
        $process->setInput((string) json_encode(['rounds' => $rounds], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<list<array{id: string, rank: null|int}>> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
