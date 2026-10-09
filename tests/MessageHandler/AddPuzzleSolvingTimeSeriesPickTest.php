<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;
use SpeedPuzzling\Web\Exceptions\SolvingTimeAlreadySaved;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Saving a time with a series pick (docs/features/events-page/high-frequency-series.md H4): precedence round >
 * competition > series, the edition found by the one rule before persist, then its round.
 */
final class AddPuzzleSolvingTimeSeriesPickTest extends KernelTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR_USER_ID;

    private SeriesEditionScenario $scenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scenario = new SeriesEditionScenario(self::getContainer());
    }

    /**
     * H12 2: matched by the puzzle - the edition and its round.
     */
    public function testScenario2ASeriesPickIsMatchedWithItsRound(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $edition = $this->scenario->edition($series, 'Jam No. 153', '2026-03-02');
        $round = $this->scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);

        $time = $this->scenario->addTime(self::PLAYER, $puzzle, '2026-03-03', seriesId: $series);

        self::assertSame(
            ['competition_id' => $edition, 'competition_series_id' => $series, 'series_edition_match' => 'puzzle', 'competition_round_id' => $round],
            $this->scenario->link($time),
        );
    }

    /**
     * Series-level is a normal state: nothing matched, the series kept.
     */
    public function testASeriesPickNothingMatchesIsSeriesLevel(): void
    {
        $series = $this->scenario->series();
        $this->scenario->edition($series, 'Jam No. 153', '2026-03-02');

        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-04-20', seriesId: $series);

        self::assertSame(
            ['competition_id' => null, 'competition_series_id' => $series, 'series_edition_match' => null, 'competition_round_id' => null],
            $this->scenario->link($time),
        );
    }

    /**
     * H12 1: a one-time event is linked exactly as before - explicit, its round derived.
     */
    public function testScenario1AOneTimeEventIsLinkedAsBefore(): void
    {
        $time = $this->scenario->addTime(self::PLAYER, PuzzleFixture::PUZZLE_500_01, '2026-03-02', competitionId: CompetitionFixture::COMPETITION_WJPC_2024);

        self::assertSame(
            ['competition_id' => CompetitionFixture::COMPETITION_WJPC_2024, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION],
            $this->scenario->link($time),
        );
    }

    /**
     * An explicitly picked edition stays explicit - also when a series is sent along (precedence competition > series).
     */
    public function testAnExplicitEditionWinsOverTheSeries(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $explicit = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $matching = $this->scenario->edition($series, 'Jam No. 2', '2026-03-20');
        $this->scenario->round($matching, RoundCategory::Solo, '2026-03-20 19:00', puzzleIds: [$puzzle]);

        $time = $this->scenario->addTime(self::PLAYER, $puzzle, '2026-03-20', seriesId: $series, competitionId: $explicit);

        self::assertSame(
            ['competition_id' => $explicit, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => null],
            $this->scenario->link($time),
        );
    }

    /**
     * Precedence round > competition > series: the API's round id links its competition explicitly.
     */
    public function testARoundWinsOverTheSeries(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $edition = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $round = $this->scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $timeId = Uuid::uuid7();

        $this->scenario->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: self::PLAYER,
            puzzleId: $puzzle,
            competitionId: null,
            time: '01:05:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: new DateTimeImmutable('2026-03-02'),
            firstAttempt: false,
            unboxed: false,
            roundId: $round,
            seriesId: $series,
        ));

        self::assertSame(
            ['competition_id' => $edition, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => $round],
            $this->scenario->link($timeId->toString()),
        );
    }

    /**
     * A series that is not publicly visible (any more) - saved without the link, never silently.
     */
    public function testANotPubliclyVisibleSeriesSavesWithoutALinkAndWarns(): void
    {
        $pending = $this->scenario->series('Harbor Puzzle Club Nights', public: false);
        $logs = $this->captureLogs();

        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $pending);

        self::assertSame(
            ['competition_id' => null, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => null],
            $this->scenario->link($time),
        );
        $warning = self::seriesWarning($logs);
        self::assertSame($pending, $warning->context['seriesId']);
        self::assertArrayNotHasKey('exception', $warning->context);
    }

    public function testAnUnknownSeriesSavesWithoutALinkAndWarnsWithTheException(): void
    {
        $logs = $this->captureLogs();
        $unknown = Uuid::uuid7()->toString();

        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $unknown);

        self::assertNull($this->scenario->link($time)['competition_series_id']);
        self::assertInstanceOf(CompetitionSeriesNotFound::class, self::seriesWarning($logs)->context['exception'] ?? null);
    }

    /**
     * H12 14: the 10-second twin net compares the series pick, not the edition it was matched to: the same save sent
     * again is the saved result; the same data without the series is another result.
     */
    public function testTheTwinNetComparesTheSeriesPick(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $first = $this->scenario->addTime(self::PLAYER, $puzzle, '2026-03-02', seriesId: $series);

        $refusal = null;

        try {
            $this->scenario->addTime(self::PLAYER, $puzzle, '2026-03-02', seriesId: $series);
        } catch (HandlerFailedException $exception) {
            $refusal = $exception->getPrevious();
        }

        self::assertInstanceOf(SolvingTimeAlreadySaved::class, $refusal);
        self::assertSame($first, $refusal->timeId);

        // Without the series it is not the same save
        $plain = $this->scenario->addTime(self::PLAYER, $puzzle, '2026-03-02');
        self::assertNotSame($first, $plain);
        self::assertNull($this->scenario->link($plain)['competition_series_id']);
    }

    private function captureLogs(): TestHandler
    {
        $handler = new TestHandler();
        self::getContainer()->get('logger')->pushHandler($handler);

        return $handler;
    }

    private static function seriesWarning(TestHandler $logs): LogRecord
    {
        $warnings = array_values(array_filter(
            $logs->getRecords(),
            static fn (LogRecord $record): bool => $record->level === Level::Warning && str_starts_with($record->message, 'Solving time saved without series'),
        ));

        self::assertCount(1, $warnings);

        return $warnings[0];
    }
}
