<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Value\ImportPuzzleTier;

/**
 * The pairing's rules for one distinct puzzle of an import (docs/features/data-import/pairing.md "Tiers") - pure:
 * what the file says about the puzzle and every catalogue puzzle some lookup found for it go in, the tier and the
 * candidates come out. No fuzzy match is ever more than a suggestion (README D10).
 *
 * Every hit is first given a **strength**:
 *
 * | Found by (`how`) | strong | weak |
 * |---|---|---|
 * | `own_id` - the file's own puzzle id (our export) | always | |
 * | `earlier_import` - a row with the same key made a result on it | always | |
 * | `barcode` | the file's piece count agrees | the file has no piece count |
 * | `name` - a whole name of the puzzle, the same piece count | the file's brand is the puzzle's brand | the brand differs or is missing, or the file has no piece count |
 * | `own_result` - one result of the player's meets one row: same day, time, kind | the time has real seconds, the file has a piece count | otherwise |
 * | `own_result_loose` - a result meets a row less exactly | | always |
 * | `brand_code` - only when the file's brand is the puzzle's brand | | always |
 *
 * "Agrees" = equal or one apart (a box says 501, the catalogue 500). **A hit whose piece count does not agree is
 * never strong or weak**: it is a candidate only, shown with the difference, and the puzzle is at most "choose". An
 * `own_result` that another lookup contradicts (a name, a code or an id pointing at another puzzle) is weak.
 *
 * Then: exactly one strong puzzle → **found** (the only tier decided for the player); several strong → **choose**;
 * no strong and exactly one weak → **probably**; several weak, or only candidates with another piece count →
 * **choose**; nothing → null: the similar-name search decides between **choose** and **none** (similar()).
 */
final class ImportPuzzleTierRules
{
    public const int MAX_CANDIDATES = 5;
    /** Trigram similarity of the names from which a catalogue puzzle is offered (README: to be checked on 200 labelled rows) */
    public const float SIMILAR_NAME_THRESHOLD = 0.6;

    private const int STRONG = 0;
    private const int WEAK = 1;
    private const int OTHER_PIECES = 2;

    /** The better the lookup, the earlier - what a candidate says it was found by when several lookups found it */
    private const array HOW_ORDER = ['own_id', 'earlier_import', 'barcode', 'name', 'own_result', 'brand_code', 'own_result_loose'];

    /**
     * @param list<array{puzzleId: string, how: string, pieces: int, manufacturerId: null|string, isPlayers?: bool}> $hits
     *
     * @return array{tier: null|ImportPuzzleTier, puzzleId: null|string, candidates: list<array{puzzleId: string, how: string, score: null|float, piecesDiffer: bool}>}
     *         `puzzleId` only for "found"
     */
    public static function exact(null|int $pieces, null|string $manufacturerId, array $hits): array
    {
        $puzzlesOfOtherLookups = [];

        foreach ($hits as $hit) {
            if ($hit['how'] !== 'own_result' && $hit['how'] !== 'own_result_loose') {
                $puzzlesOfOtherLookups[$hit['puzzleId']] = true;
            }
        }

        /** @var array<string, array{strength: int, how: string, isPlayers: bool}> $puzzles */
        $puzzles = [];

        foreach ($hits as $hit) {
            $agrees = $pieces === null ? null : abs($hit['pieces'] - $pieces) <= 1;
            $sameBrand = $manufacturerId !== null && $hit['manufacturerId'] === $manufacturerId;
            $contradicted = array_diff_key($puzzlesOfOtherLookups, [$hit['puzzleId'] => true]) !== [];

            if ($hit['how'] === 'brand_code' && $sameBrand === false) {
                continue;
            }

            $strength = match (true) {
                $agrees === false => self::OTHER_PIECES,
                $hit['how'] === 'own_id', $hit['how'] === 'earlier_import' => self::STRONG,
                $hit['how'] === 'barcode' => $agrees === true ? self::STRONG : self::WEAK,
                $hit['how'] === 'name' => $agrees === true && $sameBrand ? self::STRONG : self::WEAK,
                $hit['how'] === 'own_result' => $agrees === true && $contradicted === false ? self::STRONG : self::WEAK,
                default => self::WEAK,
            };

            $known = $puzzles[$hit['puzzleId']] ?? null;
            $isPlayers = ($hit['isPlayers'] ?? false) || ($known['isPlayers'] ?? false) || str_starts_with($hit['how'], 'own_result') || $hit['how'] === 'earlier_import';

            if ($known === null || $strength < $known['strength'] || ($strength === $known['strength'] && self::howRank($hit['how']) < self::howRank($known['how']))) {
                $puzzles[$hit['puzzleId']] = ['strength' => $strength, 'how' => $hit['how'], 'isPlayers' => $isPlayers];
            } else {
                $puzzles[$hit['puzzleId']]['isPlayers'] = $isPlayers;
            }
        }

        if ($puzzles === []) {
            return ['tier' => null, 'puzzleId' => null, 'candidates' => []];
        }

        // The strongest first; among equals a puzzle the player solved or owns, then a stable order
        uksort($puzzles, static fn (string $a, string $b): int => [$puzzles[$a]['strength'], $puzzles[$a]['isPlayers'] ? 0 : 1, self::howRank($puzzles[$a]['how']), $a]
            <=> [$puzzles[$b]['strength'], $puzzles[$b]['isPlayers'] ? 0 : 1, self::howRank($puzzles[$b]['how']), $b]);

        $strong = array_keys(array_filter($puzzles, static fn (array $puzzle): bool => $puzzle['strength'] === self::STRONG));
        $weak = array_keys(array_filter($puzzles, static fn (array $puzzle): bool => $puzzle['strength'] === self::WEAK));

        $tier = match (true) {
            count($strong) === 1 => ImportPuzzleTier::Found,
            count($strong) > 1 => ImportPuzzleTier::Choose,
            count($weak) === 1 => ImportPuzzleTier::Probably,
            default => ImportPuzzleTier::Choose,
        };

        $candidates = [];

        foreach (array_slice($puzzles, 0, self::MAX_CANDIDATES, true) as $puzzleId => $puzzle) {
            $candidates[] = ['puzzleId' => (string) $puzzleId, 'how' => $puzzle['how'], 'score' => null, 'piecesDiffer' => $puzzle['strength'] === self::OTHER_PIECES];
        }

        return ['tier' => $tier, 'puzzleId' => $tier === ImportPuzzleTier::Found ? (string) $strong[0] : null, 'candidates' => $candidates];
    }

    /**
     * After the similar-name search: similar names are always "choose" - whatever their score, whatever their piece
     * count - and nothing similar is "none".
     *
     * @param list<array{puzzleId: string, pieces: int, score: float}> $similar the best first
     *
     * @return array{tier: ImportPuzzleTier, candidates: list<array{puzzleId: string, how: string, score: null|float, piecesDiffer: bool}>}
     */
    public static function similar(null|int $pieces, array $similar): array
    {
        $candidates = [];

        foreach (array_slice($similar, 0, self::MAX_CANDIDATES) as $found) {
            $candidates[] = [
                'puzzleId' => $found['puzzleId'],
                'how' => 'similar_name',
                'score' => $found['score'],
                'piecesDiffer' => $pieces !== null && abs($found['pieces'] - $pieces) > 1,
            ];
        }

        return ['tier' => $candidates === [] ? ImportPuzzleTier::None : ImportPuzzleTier::Choose, 'candidates' => $candidates];
    }

    private static function howRank(string $how): int
    {
        $rank = array_search($how, self::HOW_ORDER, true);

        return $rank === false ? count(self::HOW_ORDER) : $rank;
    }
}
