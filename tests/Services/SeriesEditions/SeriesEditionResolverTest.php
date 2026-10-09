<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SeriesEditions;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddEdition;
use SpeedPuzzling\Web\Message\RejectCompetition;
use SpeedPuzzling\Web\Message\RevealRoundPuzzleNow;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use SpeedPuzzling\Web\Results\SeriesEditionResolution;
use SpeedPuzzling\Web\Services\SeriesEditions\SeriesEditionResolver;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\SeriesEditionMatchKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The matching rule for one time (docs/features/events-page/high-frequency-series.md "The matching rule", scenario
 * matrix H12). Made-up series only; every date is in the past, a secret round puzzle is a manual reveal.
 */
final class SeriesEditionResolverTest extends KernelTestCase
{
    private SeriesEditionScenario $scenario;
    private SeriesEditionResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scenario = new SeriesEditionScenario(self::getContainer());
        $this->resolver = self::getContainer()->get(SeriesEditionResolver::class);
    }

    /**
     * H12 2: editions with dates, rounds and puzzles - the puzzle finds the edition (and the round, on save).
     */
    public function testScenario2ThePuzzleFindsTheEdition(): void
    {
        $series = $this->scenario->series();
        $copper = $this->scenario->puzzle('Copper Lighthouse');
        $starry = $this->scenario->puzzle('Starry Harbor');
        $jam153 = $this->scenario->edition($series, 'Jam No. 153', '2026-03-02');
        $jam154 = $this->scenario->edition($series, 'Jam No. 154', '2026-03-04');
        $this->scenario->round($jam153, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$copper]);
        $this->scenario->round($jam154, RoundCategory::Duo, '2026-03-04 19:00', puzzleIds: [$starry]);

        self::assertMatch($jam153, SeriesEditionMatchKind::Puzzle, $this->preview($series, $copper, '2026-03-03', PuzzlingType::Solo));
        self::assertMatch($jam154, SeriesEditionMatchKind::Puzzle, $this->preview($series, $starry, '2026-03-04', PuzzlingType::Duo));
        // The only edition holding it - however far the solve day is
        self::assertMatch($jam153, SeriesEditionMatchKind::Puzzle, $this->preview($series, $copper, '2026-05-30', PuzzlingType::Solo));
        // The category must be the round's: a pair on the solo puzzle is no puzzle match (and 03-02 accepts no pair)
        self::assertNotIdentified($this->preview($series, $copper, '2026-03-02', PuzzlingType::Duo));
    }

    /**
     * H12 3: editions with dates only - the day finds the edition, one day either side.
     */
    public function testScenario3TheDayFindsTheEdition(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $first = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $second = $this->scenario->edition($series, 'Jam No. 2', '2026-03-09');

        self::assertMatch($first, SeriesEditionMatchKind::Date, $this->preview($series, $puzzle, '2026-03-02', PuzzlingType::Solo));
        self::assertMatch($first, SeriesEditionMatchKind::Date, $this->preview($series, $puzzle, '2026-03-01', PuzzlingType::Solo));
        self::assertMatch($first, SeriesEditionMatchKind::Date, $this->preview($series, $puzzle, '2026-03-03', PuzzlingType::Solo));
        self::assertNotIdentified($this->preview($series, $puzzle, '2026-03-04', PuzzlingType::Solo));
        self::assertMatch($second, SeriesEditionMatchKind::Date, $this->preview($series, null, '2026-03-10', PuzzlingType::Team));
    }

    /**
     * H13: an edition without rounds is matched by its day for every category.
     */
    public function testAnEditionWithoutRoundsIsMatchedByDateForEveryCategory(): void
    {
        $series = $this->scenario->series();
        $edition = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $puzzle = $this->scenario->puzzle();

        foreach (PuzzlingType::cases() as $category) {
            self::assertMatch($edition, SeriesEditionMatchKind::Date, $this->preview($series, $puzzle, '2026-03-02', $category));
        }
    }

    public function testRoundsOfTheEditionDecideWhichCategoryItAccepts(): void
    {
        $series = $this->scenario->series();
        $pairsOnly = $this->scenario->edition($series, 'Pairs Jam', '2026-03-02');
        $this->scenario->round($pairsOnly, RoundCategory::Duo, '2026-03-02 19:00');

        self::assertNotIdentified($this->preview($series, null, '2026-03-02', PuzzlingType::Solo));
        self::assertMatch($pairsOnly, SeriesEditionMatchKind::Date, $this->preview($series, null, '2026-03-02', PuzzlingType::Duo));
    }

    /**
     * H12 4: a secret round puzzle never matches by puzzle before its reveal - the round still accepts its category by
     * date, which says nothing about which edition holds the puzzle. Revealed, it is a puzzle match.
     */
    public function testScenario4AHiddenRoundPuzzleMatchesOnlyByDateUntilItsReveal(): void
    {
        $series = $this->scenario->series();
        $secret = $this->scenario->puzzle('Copper Lighthouse');
        $jam155 = $this->scenario->edition($series, 'Jam No. 155', '2026-03-05');
        $round = $this->scenario->round($jam155, RoundCategory::Solo, '2026-03-05 19:00', puzzleIds: [$secret], secret: true);

        // On its day: the date, never the puzzle
        self::assertMatch($jam155, SeriesEditionMatchKind::Date, $this->preview($series, $secret, '2026-03-05', PuzzlingType::Solo));
        // Far from its day: nothing - the hidden puzzle does not tell
        self::assertNotIdentified($this->preview($series, $secret, '2026-04-20', PuzzlingType::Solo));

        $this->scenario->dispatch(new RevealRoundPuzzleNow($this->scenario->roundPuzzleId($round, $secret)));

        self::assertMatch($jam155, SeriesEditionMatchKind::Puzzle, $this->preview($series, $secret, '2026-03-05', PuzzlingType::Solo));
        self::assertMatch($jam155, SeriesEditionMatchKind::Puzzle, $this->preview($series, $secret, '2026-04-20', PuzzlingType::Solo));
    }

    /**
     * H12 4, P7: a round that keeps only the picture secret counts as hidden too.
     */
    public function testScenario4AnImageOnlySecretIsHiddenToo(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $edition = $this->scenario->edition($series, 'Jam No. 155', '2026-03-05');
        $round = $this->scenario->round($edition, RoundCategory::Solo, '2026-03-05 19:00', puzzleIds: [$puzzle]);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $roundPuzzle = $entityManager->find(CompetitionRoundPuzzle::class, $this->scenario->roundPuzzleId($round, $puzzle));
        self::assertNotNull($roundPuzzle);
        $roundPuzzle->changeReveal(PuzzleHideMode::ImageOnly, RoundPuzzleReveal::Manual, null);
        $entityManager->flush();
        $entityManager->clear();

        self::assertNotIdentified($this->preview($series, $puzzle, '2026-04-20', PuzzlingType::Solo));
        self::assertMatch($edition, SeriesEditionMatchKind::Date, $this->preview($series, $puzzle, '2026-03-05', PuzzlingType::Solo));
    }

    /**
     * H12 4: a puzzle hidden on the whole site (puzzle.hide_until in the future) is not used by rule 1 either.
     */
    public function testScenario4APuzzleHiddenOnTheWholeSiteIsNotUsed(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $edition = $this->scenario->edition($series, 'Jam No. 156', '2026-03-05');
        $this->scenario->round($edition, RoundCategory::Solo, '2026-03-05 19:00', puzzleIds: [$puzzle]);

        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle SET hide_until = NOW() + INTERVAL '30 days' WHERE id = :id",
            ['id' => $puzzle],
        );

        self::assertNotIdentified($this->preview($series, $puzzle, '2026-04-20', PuzzlingType::Solo));
        self::assertMatch($edition, SeriesEditionMatchKind::Date, $this->preview($series, $puzzle, '2026-03-05', PuzzlingType::Solo));
    }

    /**
     * H12 5: undated placeholder editions without rounds are never guessed.
     */
    public function testScenario5UndatedPlaceholdersAreNeverMatched(): void
    {
        $series = $this->scenario->series();
        $this->scenario->edition($series, 'Summer Special', null);
        $this->scenario->edition($series, 'Winter Special', null);
        $puzzle = $this->scenario->puzzle();

        self::assertNotIdentified($this->preview($series, $puzzle, '2026-03-05', PuzzlingType::Solo));
        self::assertNotIdentified($this->preview($series, null, '2026-08-01', PuzzlingType::Team));
    }

    /**
     * H12 6: a series without editions - series-level, nothing to match.
     */
    public function testScenario6ASeriesWithoutEditions(): void
    {
        $series = $this->scenario->series();

        self::assertNotIdentified($this->preview($series, $this->scenario->puzzle(), '2026-03-05', PuzzlingType::Solo));
    }

    /**
     * H12 7: a single undated edition is not guessed either - the form shows the choice.
     */
    public function testScenario7ASingleUndatedEditionIsNotGuessed(): void
    {
        $series = $this->scenario->series();
        $this->scenario->edition($series, 'The Only One', null);

        self::assertNotIdentified($this->preview($series, $this->scenario->puzzle(), '2026-03-05', PuzzlingType::Solo));
    }

    /**
     * H12 8: two editions on the same day and category - series-level; the puzzle decides once it is attached.
     */
    public function testScenario8TwoEditionsOnTheSameDay(): void
    {
        $series = $this->scenario->series();
        $flex = $this->scenario->edition($series, 'Flex Jam', '2026-03-11');
        $live = $this->scenario->edition($series, 'Live Jam', '2026-03-11');
        $this->scenario->round($flex, RoundCategory::Solo, '2026-03-11 10:00');
        $liveRound = $this->scenario->round($live, RoundCategory::Solo, '2026-03-11 19:00');
        $puzzle = $this->scenario->puzzle();

        self::assertNotIdentified($this->preview($series, $puzzle, '2026-03-11', PuzzlingType::Solo));

        $this->scenario->dispatch(new SetCompetitionRoundPuzzles($liveRound, [$puzzle]));

        self::assertMatch($live, SeriesEditionMatchKind::Puzzle, $this->preview($series, $puzzle, '2026-03-11', PuzzlingType::Solo));
        // Another puzzle that day is still not identified
        self::assertNotIdentified($this->preview($series, $this->scenario->puzzle('Starry Harbor'), '2026-03-11', PuzzlingType::Solo));
    }

    /**
     * H12 9: a draft, pending or rejected edition - or an edition of a draft or pending series - is never a match.
     */
    public function testScenario9OnlyPubliclyVisibleEditionsAreCandidates(): void
    {
        $puzzle = $this->scenario->puzzle();

        $series = $this->scenario->series();
        $draftEdition = $this->scenario->edition($series, 'Draft Jam', '2026-03-02', draft: true);
        $this->scenario->round($draftEdition, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $rejectedEdition = $this->scenario->edition($series, 'Rejected Jam', '2026-03-09');
        $this->scenario->dispatch(new RejectCompetition($rejectedEdition, PlayerFixture::PLAYER_ADMIN, 'Not a puzzle event'));

        self::assertNotIdentified($this->preview($series, $puzzle, '2026-03-02', PuzzlingType::Solo));
        self::assertNotIdentified($this->preview($series, $puzzle, '2026-03-09', PuzzlingType::Solo));

        $draftSeries = $this->scenario->series('Moonlit Puzzle Sprint', draft: true);
        $this->scenario->edition($draftSeries, 'Sprint No. 1', '2026-03-02');
        self::assertNotIdentified($this->preview($draftSeries, $puzzle, '2026-03-02', PuzzlingType::Solo));

        $pendingSeries = $this->scenario->series('Harbor Puzzle Club Nights', public: false);
        $this->scenario->edition($pendingSeries, 'Night No. 1', '2026-03-02');
        self::assertNotIdentified($this->preview($pendingSeries, $puzzle, '2026-03-02', PuzzlingType::Solo));
    }

    /**
     * P5: two editions equally near hold the puzzle - not identified, and the date is not tried (it could only pick a
     * third edition that does not hold the puzzle).
     */
    public function testATieOfPuzzleCandidatesIsNotIdentified(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $before = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $after = $this->scenario->edition($series, 'Jam No. 3', '2026-03-06');
        $this->scenario->edition($series, 'Jam No. 2', '2026-03-04');
        $this->scenario->round($before, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $this->scenario->round($after, RoundCategory::Solo, '2026-03-06 19:00', puzzleIds: [$puzzle]);

        self::assertNotIdentified($this->preview($series, $puzzle, '2026-03-04', PuzzlingType::Solo));
    }

    public function testTheNearestOfTwoPuzzleCandidatesWins(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $near = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $far = $this->scenario->edition($series, 'Jam No. 9', '2026-03-30');
        $this->scenario->round($near, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $this->scenario->round($far, RoundCategory::Solo, '2026-03-30 19:00', puzzleIds: [$puzzle]);

        self::assertMatch($near, SeriesEditionMatchKind::Puzzle, $this->preview($series, $puzzle, '2026-03-05', PuzzlingType::Solo));
        self::assertMatch($far, SeriesEditionMatchKind::Puzzle, $this->preview($series, $puzzle, '2026-03-30', PuzzlingType::Solo));
    }

    /**
     * A round's day is its start in its own zone: 23:30 in Toronto is the next day in UTC.
     */
    public function testARoundIsDatedByItsLocalDay(): void
    {
        $series = $this->scenario->series();
        $edition = $this->scenario->edition($series, 'Night Owl Jam', null);
        $this->scenario->round($edition, RoundCategory::Solo, '2026-03-05 23:30', 'America/Toronto');

        self::assertMatch($edition, SeriesEditionMatchKind::Date, $this->preview($series, null, '2026-03-04', PuzzlingType::Solo));
        self::assertMatch($edition, SeriesEditionMatchKind::Date, $this->preview($series, null, '2026-03-06', PuzzlingType::Solo));
        // Dated by the UTC day (03-06) it would match 03-07 too
        self::assertNotIdentified($this->preview($series, null, '2026-03-07', PuzzlingType::Solo));
    }

    /**
     * P6: an edition dated by one field only spans that day.
     */
    public function testAnEditionDatedByItsLastDayOnly(): void
    {
        $series = $this->scenario->series();
        $editionId = Uuid::uuid7();
        $this->scenario->dispatch(new AddEdition(
            competitionId: $editionId,
            seriesId: $series,
            name: 'Jam No. 10',
            dateFrom: null,
            dateTo: new DateTimeImmutable('2026-03-10'),
            registrationLink: null,
            resultsLink: null,
        ));

        self::assertMatch($editionId->toString(), SeriesEditionMatchKind::Date, $this->preview($series, null, '2026-03-11', PuzzlingType::Solo));
        self::assertNotIdentified($this->preview($series, null, '2026-03-12', PuzzlingType::Solo));
    }

    public function testResolvingATimeWithoutASeriesPickIsNotIdentified(): void
    {
        $series = $this->scenario->series();
        $edition = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $timeId = $this->scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, $this->scenario->puzzle(), '2026-03-02', competitionId: $edition);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $time = $entityManager->find(PuzzleSolvingTime::class, $timeId);
        self::assertNotNull($time);

        self::assertNotIdentified($this->resolver->resolve($time));
    }

    public function testAPreviewWithAMalformedSeriesOrPuzzle(): void
    {
        $series = $this->scenario->series();
        $edition = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');

        self::assertNotIdentified($this->resolver->preview('nonsense', null, new DateTimeImmutable('2026-03-02'), PuzzlingType::Solo));
        // A puzzle that is no uuid counts as none - the day still matches
        self::assertMatch($edition, SeriesEditionMatchKind::Date, $this->resolver->preview($series, 'nonsense', new DateTimeImmutable('2026-03-02'), PuzzlingType::Solo));
    }

    private function preview(string $series, null|string $puzzle, string $day, PuzzlingType $category): SeriesEditionResolution
    {
        return $this->resolver->preview($series, $puzzle, new DateTimeImmutable($day), $category);
    }

    private static function assertMatch(string $expectedEditionId, SeriesEditionMatchKind $expectedKind, SeriesEditionResolution $resolution): void
    {
        self::assertSame([$expectedEditionId, $expectedKind], [$resolution->competitionId, $resolution->kind]);
        self::assertTrue($resolution->isIdentified());
    }

    private static function assertNotIdentified(SeriesEditionResolution $resolution): void
    {
        self::assertSame([null, null], [$resolution->competitionId, $resolution->kind]);
        self::assertFalse($resolution->isIdentified());
    }
}
