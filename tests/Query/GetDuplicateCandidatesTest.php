<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetDuplicateCandidates;
use SpeedPuzzling\Web\Results\DuplicateCandidate;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\RoundCategory;
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

    /**
     * H12 scenario 14 / P23 (docs/features/events-page/high-frequency-series.md): copies of one series pick are the same
     * event whichever edition each was matched to - also a series-level copy next to a matched one; an explicit pick of
     * the edition is another event than the series pick matched to it.
     */
    public function testSeriesPicksCompareTheirSeriesNotTheEditionFound(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $puzzleId = $scenario->puzzle();
        $seriesId = $scenario->series();
        $editionId = $scenario->edition($seriesId, 'Jam No. 154', '2026-09-10');
        $scenario->round($editionId, RoundCategory::Solo, '2026-09-10 19:00', puzzleIds: [$puzzleId]);

        // Solo: matched to the jam by its puzzle; as a pair: the jam has no pairs round - series-level
        $matched = $this->add($scenario, $puzzleId, seriesId: $seriesId);
        $matchedToo = $this->add($scenario, $puzzleId, seriesId: $seriesId, comment: 'Saved again');
        $seriesLevel = $this->add($scenario, $puzzleId, seriesId: $seriesId, groupPlayers: ['Nora Lantern']);
        $explicit = $this->add($scenario, $puzzleId, competitionId: $editionId, comment: 'Picked the jam');

        self::assertSame($editionId, $scenario->link($matched)['competition_id']);
        self::assertNull($scenario->link($seriesLevel)['competition_id']);
        self::assertSame($seriesId, $scenario->link($seriesLevel)['competition_series_id']);

        $differences = [];
        $candidates = self::getContainer()->get(GetDuplicateCandidates::class)->ofPeopleOnPuzzle($puzzleId, [PlayerFixture::PLAYER_REGULAR]);

        foreach ($candidates as $candidate) {
            $differences[$candidate->older->timeId . ' ' . $candidate->newer->timeId] = $candidate->differences();
        }

        self::assertSame($seriesId, $candidates[0]->older->competitionSeriesId);
        self::assertSame([DuplicateCandidate::DIFFERENCE_COMMENT], $differences[$matched . ' ' . $matchedToo]);
        self::assertSame([DuplicateCandidate::DIFFERENCE_GROUP], $differences[$matched . ' ' . $seriesLevel]);
        self::assertSame([DuplicateCandidate::DIFFERENCE_COMPETITION, DuplicateCandidate::DIFFERENCE_COMMENT], $differences[$matched . ' ' . $explicit]);
    }

    /**
     * @param list<string> $groupPlayers
     */
    private function add(SeriesEditionScenario $scenario, string $puzzleId, null|string $seriesId = null, null|string $competitionId = null, null|string $comment = null, array $groupPlayers = []): string
    {
        $timeId = Uuid::uuid7();

        $scenario->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: $puzzleId,
            competitionId: $competitionId,
            time: '00:50:00',
            comment: $comment,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: new DateTimeImmutable('2026-09-10 20:30:00'),
            firstAttempt: false,
            unboxed: false,
            seriesId: $seriesId,
        ));

        return $timeId->toString();
    }
}
