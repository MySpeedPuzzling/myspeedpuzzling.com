<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DuplicateResults;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\DuplicatePuzzleSignalCandidate;
use SpeedPuzzling\Web\Results\DuplicatePuzzleSignalScore;
use SpeedPuzzling\Web\Services\DuplicateResults\DuplicatePuzzleSignalScoring;
use SpeedPuzzling\Web\Value\DuplicatePuzzleSignalReason as Reason;

/**
 * Cases taken from the signals on a copy of production (docs/features/duplicate-results.md, Layer 4).
 */
final class DuplicatePuzzleSignalScoringTest extends TestCase
{
    public function testSameEanInAListWithLeadingZeros(): void
    {
        // Soft Cans / Lata sobre lata: one EAN, two languages
        $score = $this->score($this->candidate(
            nameA: 'Soft Cans',
            nameB: 'Lata sobre lata',
            eanA: '4005555003540, 08412668184473',
            eanB: '8412668184473',
            nameSimilarity: 0.11,
            sameBrand: true,
            fewerResults: 4,
        ));

        self::assertSame([Reason::SameEan, Reason::SameBrand, Reason::FewResults], $score->reasons);
        self::assertSame(50 + 10 + 15, $score->score);
        self::assertFalse($score->isWeak());
    }

    public function testCatalogueNumberInsideTheOtherEan(): void
    {
        // Lost Treasure (14940) / Versunkener Schatz (4005556149407)
        $score = $this->score($this->candidate(
            nameA: 'Lost Treasure',
            nameB: 'Versunkener Schatz',
            codeA: '14940',
            eanB: '4005556149407',
            fewerResults: 2,
        ));

        self::assertSame([Reason::SameCode, Reason::FewResults], $score->reasons);
        self::assertSame(40 + 15, $score->score);
        self::assertFalse($score->isWeak());
    }

    public function testCatalogueNumbersCompareWithoutPunctuationAcrossColumns(): void
    {
        $score = $this->score($this->candidate(codeA: 'No.094301', codeB: 'NO 094301'));
        self::assertContains(Reason::SameCode, $score->reasons);

        $score = $this->score($this->candidate(eanA: '4005556094301', codeB: '4005556094301'));
        self::assertContains(Reason::SameCode, $score->reasons);
    }

    public function testShortCodesAndDifferentCodesAreNoEvidence(): void
    {
        $score = $this->score($this->candidate(
            eanA: '4005556169863, 4005555003090',
            codeA: '500',
            eanB: '4005556173792',
            codeB: '500',
        ));

        self::assertNotContains(Reason::SameEan, $score->reasons);
        self::assertNotContains(Reason::SameCode, $score->reasons);
    }

    public function testSimilarNameScalesWithTheSimilarity(): void
    {
        // Brand new day (Spiderman) / Spider-Man Brand New Day
        $score = $this->score($this->candidate(
            nameA: 'Brand new day (Spiderman)',
            nameB: 'Spider-Man Brand New Day',
            nameSimilarity: 0.8137,
            sameBrand: true,
            fewerResults: 4,
        ));

        self::assertSame([Reason::SimilarName, Reason::SameBrand, Reason::FewResults], $score->reasons);
        self::assertSame(41 + 10 + 15, $score->score);
        self::assertSame(0.81, $score->nameSimilarity);

        // Below 0.5 the similarity says nothing
        $score = $this->score($this->candidate(nameSimilarity: 0.49, fewerResults: 4));
        self::assertNotContains(Reason::SimilarName, $score->reasons);
    }

    public function testOneNameInsideTheOtherWhenTheSimilarityIsLow(): void
    {
        // Puzzle Moment: Umbrellas / Umbrella
        $score = $this->score($this->candidate(
            nameA: 'Puzzle Moment: Umbrellas',
            nameB: 'Umbrella',
            nameSimilarity: 0.32,
            nameContained: true,
            sameBrand: true,
            fewerResults: 12,
        ));

        self::assertSame([Reason::NameContained, Reason::SameBrand, Reason::FewResults], $score->reasons);
        self::assertSame(30 + 10 + 15, $score->score);

        // A higher similarity wins, the hint is counted once
        $score = $this->score($this->candidate(nameSimilarity: 0.79, nameContained: true, fewerResults: 6));
        self::assertSame([Reason::SimilarName, Reason::FewResults], $score->reasons);
        self::assertSame(40 + 15, $score->score);
    }

    public function testBrandAndRecordHintsAloneAreWeak(): void
    {
        // Rooftop Garden / Matchbox Palette: same brand, one record with 16 results, the newer one added for them
        $score = $this->score($this->candidate(
            sameBrand: true,
            fewerResults: 16,
            daysFromNewerRecordToFirstMatch: 0,
        ));

        self::assertSame([Reason::SameBrand, Reason::FewResults, Reason::AddedAround], $score->reasons);
        self::assertSame(10 + 15 + 5, $score->score);
        self::assertTrue($score->isWeak());
    }

    public function testSeveralRecordHintsTogetherAreStrong(): void
    {
        $score = $this->score($this->candidate(
            sameBrand: true,
            bothApproved: false,
            fewerResults: 1,
        ));

        self::assertSame([Reason::SameBrand, Reason::NotApproved, Reason::FewResults], $score->reasons);
        self::assertSame(10 + 15 + 15, $score->score);
        self::assertFalse($score->isWeak());
    }

    /**
     * @return iterable<string, array{null|int, bool}>
     */
    public static function addedApart(): iterable
    {
        yield 'form sent twice' => [13, true];
        yield 'a minute later' => [75, true];
        yield 'the next puzzle of a box' => [98, false];
        yield 'launch import (one timestamp)' => [0, false];
        yield 'unknown' => [null, false];
    }

    #[DataProvider('addedApart')]
    public function testAddedTogether(null|int $secondsApart, bool $together): void
    {
        $score = $this->score($this->candidate(fewerResults: 1, addedSecondsApart: $secondsApart));

        self::assertSame($together, in_array(Reason::AddedTogether, $score->reasons, true));
    }

    public function testAddedAroundTheResults(): void
    {
        self::assertContains(Reason::AddedAround, $this->score($this->candidate(daysFromNewerRecordToFirstMatch: 3))->reasons);
        // A back-dated result on a fresh record
        self::assertContains(Reason::AddedAround, $this->score($this->candidate(daysFromNewerRecordToFirstMatch: -2))->reasons);
        self::assertNotContains(Reason::AddedAround, $this->score($this->candidate(daysFromNewerRecordToFirstMatch: 4))->reasons);
    }

    public function testTwoEstablishedPuzzlesAreWeakEvenWithTheBoxEan(): void
    {
        // Exit Puzzle boxes: the puzzles inside share the box's EAN, each has its own results
        $score = $this->score($this->candidate(
            nameA: 'Disney Stamps',
            nameB: 'Disney Toys',
            eanA: '4005556172290',
            eanB: '4005556172290',
            nameSimilarity: 0.37,
            sameBrand: true,
            fewerResults: 206,
            moreResults: 219,
        ));

        self::assertSame([Reason::SameEan, Reason::SameBrand, Reason::ManyResults], $score->reasons);
        self::assertSame(60, $score->score);
        self::assertTrue($score->isWeak());

        // Alike names keep it strong
        $score = $this->score($this->candidate(nameSimilarity: 1.0, sameBrand: true, fewerResults: 346, moreResults: 383));
        self::assertNotContains(Reason::ManyResults, $score->reasons);
        self::assertFalse($score->isWeak());
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function setNames(): iterable
    {
        yield 'advent calendar' => ['Christmas Around the World Advent Calendar 21', 'Christmas Around the World Advent Calendar 17', true];
        yield 'numbered with #' => ['Winnie the Pooh - Lustige Abenteuer #1', 'Winnie the Pooh - Lustige Abenteuer #2', true];
        yield 'series with ":"' => ['Exit Puzzle: Garage', 'Exit Puzzle: Attic', true];
        yield 'series with ":" and " - "' => ['Exit Puzzle: Living Room', 'Exit Puzzle - Kitchen', true];
        yield 'series with "("' => ['National Parks Multipack (Acadia)', 'National Parks Multipack (Joshua Tree)', true];
        yield 'one title inside the other' => ['Disney Frozen: Elsa', 'Disney Frozen: Anna & Elsa', false];
        yield 'same name' => ['Tier Set: Delfinpaar', 'Tier Set: Delfinpaar', false];
        yield 'same number, other words' => ['Formule 1 Monaco', 'Formel 1 Monaco', false];
        yield 'hyphen inside a word' => ['Brand new day (Spiderman)', 'Spider-Man Brand New Day', false];
    }

    #[DataProvider('setNames')]
    public function testPartsOfOneSetAreWeak(string $nameA, string $nameB, bool $parts): void
    {
        $score = $this->score($this->candidate(
            nameA: $nameA,
            nameB: $nameB,
            eanA: '4005556123456',
            eanB: '4005556123456',
            nameSimilarity: 0.9,
            sameBrand: true,
            fewerResults: 2,
        ));

        self::assertSame($parts, in_array(Reason::SetParts, $score->reasons, true));
        self::assertSame($parts, $score->isWeak());
    }

    private function score(DuplicatePuzzleSignalCandidate $candidate): DuplicatePuzzleSignalScore
    {
        return (new DuplicatePuzzleSignalScoring())->score($candidate);
    }

    private function candidate(
        string $nameA = 'Tropicana Sunset',
        string $nameB = 'Farmer’s Table',
        null|string $eanA = null,
        null|string $codeA = null,
        null|string $eanB = null,
        null|string $codeB = null,
        float $nameSimilarity = 0.0,
        bool $nameContained = false,
        bool $sameBrand = false,
        bool $bothApproved = true,
        int $fewerResults = 10,
        int $moreResults = 500,
        null|int $addedSecondsApart = 86400,
        null|int $daysFromNewerRecordToFirstMatch = 300,
    ): DuplicatePuzzleSignalCandidate {
        return new DuplicatePuzzleSignalCandidate(
            puzzleAId: 'a',
            puzzleBId: 'b',
            matchingResults: 2,
            matchingPeople: 1,
            examplePlayerId: 'player',
            exampleSeconds: 3600,
            exampleDay: new DateTimeImmutable('2026-09-01'),
            puzzleAName: $nameA,
            puzzleBName: $nameB,
            puzzleAEan: $eanA,
            puzzleACode: $codeA,
            puzzleBEan: $eanB,
            puzzleBCode: $codeB,
            nameSimilarity: $nameSimilarity,
            nameContained: $nameContained,
            sameBrand: $sameBrand,
            bothApproved: $bothApproved,
            fewerResults: $fewerResults,
            moreResults: $moreResults,
            addedSecondsApart: $addedSecondsApart,
            daysFromNewerRecordToFirstMatch: $daysFromNewerRecordToFirstMatch,
        );
    }
}
