<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetDuplicateCandidates;
use SpeedPuzzling\Web\Results\DuplicateCandidate;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetDuplicateCandidatesTest extends KernelTestCase
{
    /**
     * @var array<string, DuplicateCandidate> keyed by person + older copy
     */
    private array $candidates = [];

    protected function setUp(): void
    {
        self::bootKernel();

        foreach (self::getContainer()->get(GetDuplicateCandidates::class)->all() as $candidate) {
            $this->candidates[$candidate->personId . ' ' . $candidate->older->timeId] = $candidate;
        }
    }

    public function testFindsEveryPairOfEveryPersonAndNothingElse(): void
    {
        $twins = DuplicateResultsFixture::PLAYER_TWINS;

        self::assertEqualsCanonicalizing([
            "{$twins} " . DuplicateResultsFixture::TIME_CERTAIN_A,
            "{$twins} " . DuplicateResultsFixture::TIME_STRONG_A,
            "{$twins} " . DuplicateResultsFixture::TIME_TEAMMATE_A,
            "{$twins} " . DuplicateResultsFixture::TIME_PRACTICE_A,
            DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE . ' ' . DuplicateResultsFixture::TIME_TEAMMATE_A,
        ], array_keys($this->candidates));
    }

    public function testResentFormCarriesWhatTheClassifierNeeds(): void
    {
        $candidate = $this->candidates[DuplicateResultsFixture::PLAYER_TWINS . ' ' . DuplicateResultsFixture::TIME_CERTAIN_A];

        self::assertSame(DuplicateResultsFixture::TIME_CERTAIN_B, $candidate->newer->timeId);
        self::assertSame(1111, $candidate->secondsToSolve);
        self::assertSame('Twins Puzzle', $candidate->puzzleName);
        self::assertTrue($candidate->sameTracker());
        self::assertTrue($candidate->sameDay());
        self::assertSame(7, $candidate->gapSeconds());
        self::assertSame([], $candidate->differences());
        self::assertFalse($candidate->practiceSession);
        self::assertFalse($candidate->savedInBetween);
        self::assertSame([['id' => DuplicateResultsFixture::PLAYER_TWINS, 'name' => 'Dana Twin', 'code' => 'twins1']], $candidate->older->people);
    }

    public function testPracticeSessionIsAnotherSolveThatDay(): void
    {
        $candidate = $this->candidates[DuplicateResultsFixture::PLAYER_TWINS . ' ' . DuplicateResultsFixture::TIME_PRACTICE_A];

        self::assertTrue($candidate->practiceSession);
        self::assertFalse($candidate->savedInBetween);
    }

    public function testTeammateCopyNamesBothMembersAndChecksNothingInBetween(): void
    {
        $candidate = $this->candidates[DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE . ' ' . DuplicateResultsFixture::TIME_TEAMMATE_A];

        self::assertFalse($candidate->sameTracker());
        self::assertSame($candidate->older->teamId, $candidate->newer->teamId);
        self::assertNull($candidate->savedInBetween);
        self::assertSame(
            [DuplicateResultsFixture::PLAYER_TWINS, DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE],
            array_column($candidate->older->people, 'id'),
        );
        self::assertSame([DuplicateResultsFixture::PLAYER_TWINS], array_column($candidate->otherPeople(), 'id'));
    }

    public function testScopedToPeopleOnOnePuzzle(): void
    {
        $query = self::getContainer()->get(GetDuplicateCandidates::class);

        $teammates = $query->ofPeopleOnPuzzle(DuplicateResultsFixture::PUZZLE_TWINS, [DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE]);
        self::assertSame(
            [DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE . ' ' . DuplicateResultsFixture::TIME_TEAMMATE_A],
            array_map(static fn (DuplicateCandidate $candidate): string => $candidate->personId . ' ' . $candidate->older->timeId, $teammates),
        );

        self::assertCount(4, $query->ofPeopleOnPuzzle(DuplicateResultsFixture::PUZZLE_TWINS, [DuplicateResultsFixture::PLAYER_TWINS]));
        self::assertCount(5, $query->ofPeopleOnPuzzle(DuplicateResultsFixture::PUZZLE_TWINS, [DuplicateResultsFixture::PLAYER_TWINS, DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE]));
        self::assertSame([], $query->ofPeopleOnPuzzle(DuplicateResultsFixture::PUZZLE_TWINS, []));
    }
}
