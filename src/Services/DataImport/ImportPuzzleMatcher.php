<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Query\GetImportPuzzleCandidates;
use SpeedPuzzling\Web\Query\GetImportRowsOnProfile;
use SpeedPuzzling\Web\Repository\DataImportPuzzleRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Services\PuzzleTextSearch;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use SpeedPuzzling\Web\Value\ImportPuzzleDecidedHow;
use SpeedPuzzling\Web\Value\ImportPuzzleTier;

/**
 * Pairs an import's distinct puzzles with the catalogue (docs/features/data-import/pairing.md "The flow").
 *
 * **Set-based:** every step is one statement for the whole import (or for a chunk of the similar-name search),
 * never a statement per puzzle - the number of statements does not depend on the import's size. The rules
 * themselves are ImportPuzzleTierRules (pure); what matches a name or a code is PuzzleTextSearch's.
 *
 * Only puzzles nobody decided are the rules' - a decision of the player (in this import, or remembered from an
 * earlier one) is never replaced. Of the rules' own word only "found" is a decision (`automatic`, changeable).
 */
readonly final class ImportPuzzleMatcher
{
    /** A puzzle's codes that are looked up - a row's cell holds a handful at most */
    private const int MAX_CODES = 5;
    public const int SEARCH_CHUNK = 100;

    public function __construct(
        private DataImportPuzzleRepository $puzzles,
        private DataImportRowRepository $rows,
        private GetImportPuzzleCandidates $candidates,
        private GetImportRowsOnProfile $rowsOnProfile,
        private ImportBrandResolver $brandResolver,
    ) {
    }

    /**
     * The exact steps, cheapest first: rows an earlier import already added, the player's remembered decisions, then
     * for every puzzle still open one lookup of all names, barcodes, brand codes and own ids, and one of the
     * player's own results - classified by ImportPuzzleTierRules and written in one statement.
     */
    public function matchExact(DataImport $import, DateTimeImmutable $now): void
    {
        $playerId = $import->player->id->toString();
        $importId = $import->id->toString();

        $this->rows->markRowsOfEarlierImports($import);
        $this->puzzles->applyRememberedDecisions($import, $now);

        $open = $this->puzzles->forTheRules($import);

        $brandTexts = [];

        foreach ($open as $puzzle) {
            if ($puzzle['brandText'] !== null) {
                $brandTexts[] = $puzzle['brandText'];
            }
        }

        $brands = $this->brandResolver->resolve($brandTexts);

        $wanted = [];

        foreach ($open as $puzzle) {
            $wanted[] = [
                'id' => $puzzle['id'],
                'pieces' => $puzzle['pieces'],
                'manufacturerId' => $puzzle['brandText'] === null ? null : $brands[$puzzle['brandText']] ?? null,
                'ownId' => $puzzle['ownPuzzleId'],
                'names' => self::nameLines($puzzle['name']),
                'eans' => self::codeLines($puzzle['eans'], PuzzleTextSearch::barcodeLine(...)),
                'codes' => self::codeLines($puzzle['brandCodes'], PuzzleTextSearch::brandCodeLine(...)),
            ];
        }

        $hits = [];

        foreach ($this->candidates->exactHits($wanted, $playerId, $now) as $hit) {
            $hits[$hit['importPuzzleId']][] = ['puzzleId' => $hit['puzzleId'], 'how' => $hit['how'], 'pieces' => $hit['pieces'], 'manufacturerId' => $hit['manufacturerId'], 'isPlayers' => $hit['isPlayers'], 'capped' => $hit['capped']];
        }

        $profileHints = [];

        foreach ($this->ownResultHits($importId, $playerId, $now, $profileHints) as $importPuzzleId => $ownHits) {
            $hits[$importPuzzleId] = [...$hits[$importPuzzleId] ?? [], ...$ownHits];
        }

        $nowText = $now->format('Y-m-d H:i:s');
        $results = [];

        foreach ($open as $puzzle) {
            $manufacturerId = $puzzle['brandText'] === null ? null : $brands[$puzzle['brandText']] ?? null;
            $said = ImportPuzzleTierRules::exact($puzzle['pieces'], $manufacturerId, $hits[$puzzle['id']] ?? []);
            // "Found" is decided for the player - unless they once took exactly this pairing back ("not this puzzle",
            // remembered per key): then it stays what the rules say, undecided, with its candidates
            $found = $said['tier'] === ImportPuzzleTier::Found && $said['puzzleId'] !== $puzzle['notThisPuzzleId'] ? $said['puzzleId'] : null;
            // The moment of an automatic decision stays while it names the same puzzle: a page that shows it is not
            // told "somebody decided meanwhile" by every run
            $sameAsBefore = $found !== null && $puzzle['choice'] === ImportPuzzleChoice::Catalogue->value && $puzzle['puzzleId'] === $found;

            $results[] = [
                'id' => $puzzle['id'],
                'manufacturerId' => $manufacturerId,
                'tier' => $said['tier']?->value,
                'candidates' => $said['candidates'],
                'keepSearch' => $said['tier'] === null && $puzzle['searched'],
                'choice' => ($found === null ? ImportPuzzleChoice::Undecided : ImportPuzzleChoice::Catalogue)->value,
                'puzzleId' => $found,
                'decidedAt' => $found === null ? null : ($sameAsBefore ? $puzzle['decidedAt'] ?? $nowText : $nowText),
                'decidedHow' => $found === null ? null : ImportPuzzleDecidedHow::Automatic->value,
            ];
        }

        $this->puzzles->storeRulesResults($import, $results);
        $this->rows->storeProfileHints($import, $profileHints);
    }

    /**
     * The similar-name search for the next chunk of puzzles nothing exact was found for: similar names are "choose",
     * none is "none".
     *
     * @return int how many puzzles the chunk held - 0: nothing waits any more
     */
    public function searchSimilarNames(DataImport $import, DateTimeImmutable $now, int $chunk = self::SEARCH_CHUNK): int
    {
        $waiting = $this->puzzles->waitingForSearch($import, $chunk);

        if ($waiting === []) {
            return 0;
        }

        $wanted = [];

        foreach ($waiting as $puzzle) {
            $folded = PuzzleTextSearch::foldedName($puzzle['name']);

            // Under three characters there is no trigram to compare
            if (mb_strlen($folded) >= 3) {
                $wanted[] = ['id' => $puzzle['id'], 'name' => $folded, 'pieces' => $puzzle['pieces'], 'manufacturerId' => $puzzle['manufacturerId']];
            }
        }

        $similar = [];

        foreach ($this->candidates->similarNames($wanted, ImportPuzzleTierRules::SIMILAR_NAME_THRESHOLD, $import->player->id->toString(), $now) as $found) {
            $similar[$found['importPuzzleId']][] = ['puzzleId' => $found['puzzleId'], 'pieces' => $found['pieces'], 'score' => $found['score']];
        }

        $results = [];

        foreach ($waiting as $puzzle) {
            $said = ImportPuzzleTierRules::similar($puzzle['pieces'], $similar[$puzzle['id']] ?? []);
            $results[] = ['id' => $puzzle['id'], 'tier' => $said['tier']->value, 'candidates' => $said['candidates']];
        }

        $this->puzzles->storeSearchResults($import, $results, $now);

        return count($waiting);
    }

    /**
     * The player's own results as hits, by import puzzle (GetImportRowsOnProfile). A row and a result that meet **one
     * to one** - the same day, time and kind, no other row for the result, no other result for the row - are an
     * `own_result` when the time has real seconds (`45:00` is everybody's time), and the row is marked "already on
     * your profile"; anything looser is `own_result_loose`, a suggestion only.
     *
     * @param array<string, string> $profileHints filled: the result's id by row id
     *
     * @return array<string, list<array{puzzleId: string, how: string, pieces: int, manufacturerId: null|string}>>
     */
    private function ownResultHits(string $importId, string $playerId, DateTimeImmutable $now, array &$profileHints): array
    {
        $found = $this->rowsOnProfile->hits($importId, $playerId, $now);
        $resultsOfRow = [];
        $rowsOfResult = [];

        foreach ($found as $hit) {
            if ($hit['how'] === 'profile' && $hit['sameDay'] && $hit['sameType']) {
                $resultsOfRow[$hit['rowId']][$hit['solvingTimeId']] = true;
                $rowsOfResult[$hit['solvingTimeId']][$hit['rowId']] = true;
            }
        }

        $hits = [];

        foreach ($found as $hit) {
            $how = 'earlier_import';

            if ($hit['how'] === 'profile') {
                $oneToOne = $hit['sameDay'] && $hit['sameType']
                    && count($resultsOfRow[$hit['rowId']] ?? []) === 1
                    && count($rowsOfResult[$hit['solvingTimeId']] ?? []) === 1;
                $how = $oneToOne && $hit['seconds'] % 60 !== 0 ? 'own_result' : 'own_result_loose';

                if ($oneToOne) {
                    $profileHints[$hit['rowId']] = $hit['solvingTimeId'];
                }
            }

            $hits[$hit['importPuzzleId']][] = ['puzzleId' => $hit['puzzleId'], 'how' => $how, 'pieces' => $hit['pieces'], 'manufacturerId' => $hit['manufacturerId']];
        }

        return $hits;
    }

    /**
     * The name as a line of the names key holds it - and, as a lookup of its own, without a "(…)" part: "Ocean
     * (500)" may be the catalogue's "Ocean", but "Paris (Panorama)" is not "Paris", so that one is only ever a
     * suggestion. A name too short or without a letter ("Cat", "2024") is a suggestion too.
     *
     * @return list<array{line: string, how: string}>
     */
    public static function nameLines(string $name): array
    {
        $lines = [];
        $written = PuzzleTextSearch::nameLine($name);
        $withoutBrackets = PuzzleTextSearch::nameLine(trim((string) preg_replace('/\s*\([^()]*\)\s*/u', ' ', $name)));

        if ($written !== '') {
            $lines[] = ['line' => $written, 'how' => self::isTooShortToPair($written) ? 'name_short' : 'name'];
        }

        if ($withoutBrackets !== '' && $withoutBrackets !== $written) {
            $lines[] = ['line' => $withoutBrackets, 'how' => 'name_without_brackets'];
        }

        return $lines;
    }

    private static function isTooShortToPair(string $foldedName): bool
    {
        return mb_strlen($foldedName) < ImportPuzzleTierRules::MIN_NAME_LENGTH || preg_match('/\p{L}/u', $foldedName) !== 1;
    }

    /**
     * @param list<string> $codes
     * @param callable(string): (null|string) $asLine
     *
     * @return list<string> each as a line of the codes key holds it, without its tag
     */
    public static function codeLines(array $codes, callable $asLine): array
    {
        $lines = [];

        foreach (array_slice($codes, 0, self::MAX_CODES) as $code) {
            $line = $asLine($code);

            if ($line !== null && $line !== '') {
                $lines[$line] = true;
            }
        }

        return array_map(strval(...), array_keys($lines));
    }
}
