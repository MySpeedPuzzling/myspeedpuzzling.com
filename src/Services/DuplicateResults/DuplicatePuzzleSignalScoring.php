<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DuplicateResults;

use SpeedPuzzling\Web\Results\DuplicatePuzzleSignalCandidate;
use SpeedPuzzling\Web\Results\DuplicatePuzzleSignalScore;
use SpeedPuzzling\Web\Value\DuplicatePuzzleSignalReason;

/**
 * The same time on two puzzles is only the trigger of a catalogue signal (docs/features/duplicate-results.md,
 * Layer 4): on a copy of production most pairs are the puzzles of one multi-puzzle box, an advent calendar or an
 * event, where one time went onto every puzzle. What tells one puzzle entered twice apart is everything else:
 *
 *   same EAN                                          +50
 *   same catalogue number (or one inside the EAN)     +40   (only without a shared EAN)
 *   similar name                                      +50 × similarity, from 0.5 up
 *   one name inside the other                         +30   (when that is more than the similarity gives)
 *   same brand                                        +10
 *   one record not approved                           +15
 *   one record has at most 20 results                 +15
 *   the newer record added ≤ 3 days before the first matching result   +5
 *   both records added within 75 s (the form sent twice)               +10
 *
 * Strong = at least 35 points and no counter-hint. Brand, results and dates alone stay below 35: it takes a shared
 * code, a similar name, or several record hints together. Counter-hints make a signal weak whatever its points:
 *   - both records have more than 20 results and the names are not alike (similarity under 0.7) - two established
 *     puzzles; with a shared EAN that is the box's EAN, shared by the puzzles inside;
 *   - parts of one set: the names differ only in a number ("Advent Calendar 7" - "Advent Calendar 18") or share a
 *     series and differ in the title ("Exit Puzzle: Garage" - "Exit Puzzle: Attic").
 * Weak signals are listed only on request.
 *
 * Pure: everything travels in the candidate (GetDuplicatePuzzleSignalCandidates).
 */
final class DuplicatePuzzleSignalScoring
{
    public const int STRONG_MIN_SCORE = 35;

    public const int SAME_EAN_POINTS = 50;
    public const int SAME_CODE_POINTS = 40;
    public const int SIMILAR_NAME_MAX_POINTS = 50;
    public const float SIMILAR_NAME_MIN = 0.5;
    public const int NAME_CONTAINED_POINTS = 30;
    // "Rose" inside "Primrose" says nothing - the shorter name needs at least this many characters
    public const int NAME_CONTAINED_MIN_LENGTH = 5;
    public const int SAME_BRAND_POINTS = 10;
    public const int NOT_APPROVED_POINTS = 15;
    public const int FEW_RESULTS_POINTS = 15;
    public const int FEW_RESULTS_MAX = 20;
    public const int ADDED_AROUND_POINTS = 5;
    public const int ADDED_AROUND_MAX_DAYS = 3;
    public const int ADDED_TOGETHER_POINTS = 10;
    // Measured: forms sent twice land 7-66 s apart, the puzzles of one box are typed in 40 s - 30 min.
    // 0 s does not count - the launch import gave its puzzles one shared timestamp
    public const int ADDED_TOGETHER_MAX_SECONDS = 75;
    // Counter-hint "many results each": both records above FEW_RESULTS_MAX, names below this similarity
    public const float ESTABLISHED_NAME_SIMILARITY_MAX = 0.7;

    // Shorter codes ("500", "1") are too common to mean anything
    private const int CODE_MIN_LENGTH = 5;

    public function score(DuplicatePuzzleSignalCandidate $candidate): DuplicatePuzzleSignalScore
    {
        $reasons = [];
        $score = 0;

        if (self::shareToken(self::tokens($candidate->puzzleAEan), self::tokens($candidate->puzzleBEan))) {
            $reasons[] = DuplicatePuzzleSignalReason::SameEan;
            $score += self::SAME_EAN_POINTS;
        } elseif (
            self::shareCode(
                self::tokens($candidate->puzzleAEan, $candidate->puzzleACode),
                self::tokens($candidate->puzzleBEan, $candidate->puzzleBCode),
            )
        ) {
            $reasons[] = DuplicatePuzzleSignalReason::SameCode;
            $score += self::SAME_CODE_POINTS;
        }

        // The stronger of the two name hints
        $similarNamePoints = $candidate->nameSimilarity >= self::SIMILAR_NAME_MIN
            ? (int) round(self::SIMILAR_NAME_MAX_POINTS * $candidate->nameSimilarity)
            : 0;
        $containedPoints = $candidate->nameContained ? self::NAME_CONTAINED_POINTS : 0;

        if ($similarNamePoints > 0 && $similarNamePoints >= $containedPoints) {
            $reasons[] = DuplicatePuzzleSignalReason::SimilarName;
            $score += $similarNamePoints;
        } elseif ($containedPoints > 0) {
            $reasons[] = DuplicatePuzzleSignalReason::NameContained;
            $score += $containedPoints;
        }

        if ($candidate->sameBrand) {
            $reasons[] = DuplicatePuzzleSignalReason::SameBrand;
            $score += self::SAME_BRAND_POINTS;
        }

        if (!$candidate->bothApproved) {
            $reasons[] = DuplicatePuzzleSignalReason::NotApproved;
            $score += self::NOT_APPROVED_POINTS;
        }

        if ($candidate->fewerResults <= self::FEW_RESULTS_MAX) {
            $reasons[] = DuplicatePuzzleSignalReason::FewResults;
            $score += self::FEW_RESULTS_POINTS;
        }

        if ($candidate->daysFromNewerRecordToFirstMatch !== null && $candidate->daysFromNewerRecordToFirstMatch <= self::ADDED_AROUND_MAX_DAYS) {
            $reasons[] = DuplicatePuzzleSignalReason::AddedAround;
            $score += self::ADDED_AROUND_POINTS;
        }

        if ($candidate->addedSecondsApart !== null && $candidate->addedSecondsApart > 0 && $candidate->addedSecondsApart <= self::ADDED_TOGETHER_MAX_SECONDS) {
            $reasons[] = DuplicatePuzzleSignalReason::AddedTogether;
            $score += self::ADDED_TOGETHER_POINTS;
        }

        if ($candidate->fewerResults > self::FEW_RESULTS_MAX && $candidate->nameSimilarity < self::ESTABLISHED_NAME_SIMILARITY_MAX) {
            $reasons[] = DuplicatePuzzleSignalReason::ManyResults;
        }

        if (self::partsOfOneSet($candidate->puzzleAName, $candidate->puzzleBName)) {
            $reasons[] = DuplicatePuzzleSignalReason::SetParts;
        }

        return new DuplicatePuzzleSignalScore(
            score: $score,
            reasons: $reasons,
            nameSimilarity: round($candidate->nameSimilarity, 2),
        );
    }

    /**
     * "Advent Calendar 7" - "Advent Calendar 18": the same name but for its number.
     * "Exit Puzzle: Garage" - "Exit Puzzle - Attic": the same series (before the last ":", " - " or "("), another title.
     */
    private static function partsOfOneSet(string $nameA, string $nameB): bool
    {
        $nameA = self::normalisedName($nameA);
        $nameB = self::normalisedName($nameB);

        if ($nameA === $nameB) {
            return false;
        }

        if (preg_replace('/\d+/', '#', $nameA) === preg_replace('/\d+/', '#', $nameB)) {
            return true;
        }

        $partsA = self::seriesAndTitle($nameA);
        $partsB = self::seriesAndTitle($nameB);

        if ($partsA === null || $partsB === null || $partsA[0] !== $partsB[0]) {
            return false;
        }

        // "Disney Frozen: Elsa" - "Disney Frozen: Anna & Elsa" may well be one puzzle
        return !str_contains($partsA[1], $partsB[1]) && !str_contains($partsB[1], $partsA[1]);
    }

    private static function normalisedName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($name)) ?? '');
    }

    /**
     * @return null|array{string, string}
     */
    private static function seriesAndTitle(string $name): null|array
    {
        if (preg_match('/^(.*)(?::|\s[-–—]\s|\()(.*)$/u', $name, $matches) !== 1) {
            return null;
        }

        $series = trim($matches[1]);
        $title = trim($matches[2], ' )');

        if ($series === '' || $title === '') {
            return null;
        }

        return [$series, $title];
    }

    /**
     * The codes of a comma-separated list, comparable: letters and digits only, upper case, no leading zeros
     * (stored EANs drop them, UPC-A has one more).
     *
     * @return list<string>
     */
    private static function tokens(null|string ...$lists): array
    {
        $tokens = [];

        foreach ($lists as $list) {
            foreach (explode(',', $list ?? '') as $part) {
                $token = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $part) ?? '');

                if (ctype_digit($token)) {
                    $token = ltrim($token, '0');
                }

                if (strlen($token) >= self::CODE_MIN_LENGTH) {
                    $tokens[] = $token;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function shareToken(array $a, array $b): bool
    {
        return array_intersect($a, $b) !== [];
    }

    /**
     * The same code anywhere (EAN or catalogue number, either column), or a catalogue number of one record inside an
     * EAN of the other - brands build the EAN from the article number (Ravensburger 14940 → 4005556149407).
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function shareCode(array $a, array $b): bool
    {
        if (self::shareToken($a, $b)) {
            return true;
        }

        foreach ([[$a, $b], [$b, $a]] as [$codes, $eans]) {
            foreach ($codes as $code) {
                if (!ctype_digit($code) || strlen($code) > 8) {
                    continue;
                }

                foreach ($eans as $ean) {
                    if (ctype_digit($ean) && strlen($ean) >= 11 && str_contains($ean, $code)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
