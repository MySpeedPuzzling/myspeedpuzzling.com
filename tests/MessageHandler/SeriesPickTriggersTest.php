<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Events\PuzzleMergeApproved;
use SpeedPuzzling\Web\Message\AddEditions;
use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Message\DeleteCompetitionSeries;
use SpeedPuzzling\Web\Message\EditCompetition;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Message\MoveEditionToSeries;
use SpeedPuzzling\Web\Message\PublishCompetition;
use SpeedPuzzling\Web\Message\PublishCompetitionSeries;
use SpeedPuzzling\Web\Message\ReconcileRoundResults;
use SpeedPuzzling\Web\Message\RejectCompetitionSeries;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Message\RevealRoundPuzzleNow;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use SpeedPuzzling\Web\Message\UnpublishCompetition;
use SpeedPuzzling\Web\Message\UnpublishCompetitionSeries;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\NewEdition;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Every row of the trigger table (docs/features/events-page/high-frequency-series.md "Where the rule runs and when it
 * reconciles") ends with the series picks reconciled - through the app's own messages, the events their entities
 * record and the sync postFlush handlers.
 */
final class SeriesPickTriggersTest extends KernelTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR_USER_ID;
    private const string OTHER_PLAYER = PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID;
    private const string ADMIN = PlayerFixture::PLAYER_ADMIN;

    private SeriesEditionScenario $scenario;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scenario = new SeriesEditionScenario(self::getContainer());
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAddingAnEditionReconciles(): void
    {
        $series = $this->scenario->series();
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);

        $edition = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');

        self::assertSame([$edition, 'date'], $this->editionOf($time));
    }

    /**
     * "Add several dates" creates them in one flush - one reconcile of the series (P4), every pick matched.
     */
    public function testAddingSeveralEditionsReconcilesOnce(): void
    {
        $series = $this->scenario->series();
        $first = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);
        $third = $this->scenario->addTime(self::OTHER_PLAYER, $this->scenario->puzzle(), '2026-03-16', seriesId: $series);
        $ids = [Uuid::uuid7(), Uuid::uuid7(), Uuid::uuid7()];

        $reconciles = $this->countReconciles(fn () => $this->scenario->dispatch(new AddEditions($series, [
            new NewEdition($ids[0], 'Jam No. 1', new DateTimeImmutable('2026-03-02')),
            new NewEdition($ids[1], 'Jam No. 2', new DateTimeImmutable('2026-03-09')),
            new NewEdition($ids[2], 'Jam No. 3', new DateTimeImmutable('2026-03-16')),
        ])));

        self::assertSame(1, $reconciles);
        self::assertSame([$ids[0]->toString(), 'date'], $this->editionOf($first));
        self::assertSame([$ids[2]->toString(), 'date'], $this->editionOf($third));
    }

    public function testMovingAnEditionReconcilesBothSeries(): void
    {
        $from = $this->scenario->series();
        $to = $this->scenario->series('Moonlit Puzzle Sprint');
        $edition = $this->scenario->edition($from, 'Jam No. 1', '2026-03-02');
        $leaving = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $from);
        $arriving = $this->scenario->addTime(self::OTHER_PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $to);
        self::assertSame([$edition, 'date'], $this->editionOf($leaving));
        self::assertSame([null, null], $this->editionOf($arriving));

        $this->scenario->dispatch(new MoveEditionToSeries($edition, $to, self::ADMIN));

        self::assertSame([null, null], $this->editionOf($leaving));
        self::assertSame($from, $this->scenario->link($leaving)['competition_series_id']);
        self::assertSame([$edition, 'date'], $this->editionOf($arriving));
    }

    /**
     * H12 10: the edition's dates change (the edit form, the internal API PATCH) - its automatic links are re-evaluated.
     */
    public function testChangingAnEditionsDatesReconciles(): void
    {
        $series = $this->scenario->series();
        $edition = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);
        $later = $this->scenario->addTime(self::OTHER_PLAYER, $this->scenario->puzzle(), '2026-03-20', seriesId: $series);
        self::assertSame([$edition, 'date'], $this->editionOf($time));

        $this->editDates($edition, '2026-03-20');

        self::assertSame([null, null], $this->editionOf($time));
        self::assertSame([$edition, 'date'], $this->editionOf($later));
    }

    public function testPublishingAnEditionReconciles(): void
    {
        $series = $this->scenario->series();
        $draft = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02', draft: true);
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);
        self::assertSame([null, null], $this->editionOf($time));

        $this->scenario->dispatch(new PublishCompetition($draft, notifyAdmin: false));

        self::assertSame([$draft, 'date'], $this->editionOf($time));
    }

    /**
     * Back to draft only without linked times (UnpublishBlockers) - so the edition going away here is the one that made
     * a pick ambiguous: the other one of the two days is the match now.
     */
    public function testUnpublishingAnEditionReconciles(): void
    {
        $series = $this->scenario->series();
        $first = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $second = $this->scenario->edition($series, 'Jam No. 2', '2026-03-03');
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);
        self::assertSame([null, null], $this->editionOf($time));

        $this->scenario->dispatch(new UnpublishCompetition($first));

        self::assertSame([$second, 'date'], $this->editionOf($time));
    }

    /**
     * A series published: its editions become candidates. Unpublishing it runs the reconcile too (its picks would
     * refuse the unpublish - so this one has none, the reconcile is counted).
     */
    public function testPublishingAndUnpublishingASeriesReconciles(): void
    {
        $series = $this->scenario->series();
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);
        // A draft now (set in SQL - nothing in the app makes a series with picks a draft)
        $this->database->executeStatement('UPDATE competition_series SET is_draft = true WHERE id = :id', ['id' => $series]);
        $edition = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        self::assertSame([null, null], $this->editionOf($time));

        $this->scenario->dispatch(new PublishCompetitionSeries($series, notifyAdmin: false));
        self::assertSame([$edition, 'date'], $this->editionOf($time));

        $empty = $this->scenario->series('Moonlit Puzzle Sprint');
        $this->scenario->edition($empty, 'Sprint No. 1', '2026-03-02');
        self::assertSame(1, $this->countReconciles(fn () => $this->scenario->dispatch(new UnpublishCompetitionSeries($empty))));
    }

    public function testRejectingASeriesReleasesItsAutomaticLinks(): void
    {
        $series = $this->scenario->series();
        $edition = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);
        self::assertSame([$edition, 'date'], $this->editionOf($time));

        $this->scenario->dispatch(new RejectCompetitionSeries($series, self::ADMIN, 'Not a puzzle event'));

        self::assertSame([null, null], $this->editionOf($time));
        self::assertSame($series, $this->scenario->link($time)['competition_series_id']);
    }

    /**
     * H12 10: deleting an edition - an automatic link becomes series-level and is re-matched; an explicit one loses the
     * event as before.
     */
    public function testDeletingAnEditionRematchesItsAutomaticLinks(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $first = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $repeat = $this->scenario->edition($series, 'Jam No. 9', '2026-03-20');
        $this->scenario->round($first, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $repeatRound = $this->scenario->round($repeat, RoundCategory::Solo, '2026-03-20 19:00', puzzleIds: [$puzzle]);
        $automatic = $this->scenario->addTime(self::PLAYER, $puzzle, '2026-03-03', seriesId: $series);
        $explicit = $this->scenario->addTime(self::OTHER_PLAYER, $puzzle, '2026-03-03', competitionId: $first);
        self::assertSame([$first, 'puzzle'], $this->editionOf($automatic));

        $this->scenario->dispatch(new DeleteCompetition($first));

        self::assertSame([$repeat, 'puzzle'], $this->editionOf($automatic));
        self::assertSame($repeatRound, $this->scenario->link($automatic)['competition_round_id']);
        self::assertSame(
            ['competition_id' => null, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => null],
            $this->scenario->link($explicit),
        );
    }

    /**
     * Deleting a series drops its picks with how they were matched - the results stay, without an event.
     */
    public function testDeletingASeriesDropsItsPicks(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $edition = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $this->scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $automatic = $this->scenario->addTime(self::PLAYER, $puzzle, '2026-03-02', seriesId: $series);
        $seriesLevel = $this->scenario->addTime(self::OTHER_PLAYER, $this->scenario->puzzle(), '2026-05-02', seriesId: $series);

        $this->scenario->dispatch(new DeleteCompetitionSeries($series));

        $none = ['competition_id' => null, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => null];
        self::assertSame($none, $this->scenario->link($automatic));
        self::assertSame($none, $this->scenario->link($seriesLevel));
    }

    public function testCreatingARoundReconciles(): void
    {
        $series = $this->scenario->series();
        $edition = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);
        self::assertSame([$edition, 'date'], $this->editionOf($time));

        // A pairs round: the edition no longer accepts a solo time
        $this->scenario->round($edition, RoundCategory::Duo, '2026-03-02 19:00');

        self::assertSame([null, null], $this->editionOf($time));
    }

    public function testMovingARoundsStartReconciles(): void
    {
        $series = $this->scenario->series();
        $edition = $this->scenario->edition($series, 'Night Owl Jam', null);
        $round = $this->scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00');
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);
        self::assertSame([$edition, 'date'], $this->editionOf($time));

        $this->scenario->dispatch(new EditCompetitionRound(
            roundId: $round,
            competitionId: $edition,
            name: 'Solo',
            minutesLimit: 120,
            startsAt: RoundTimezone::toInstant('2026-04-10 19:00', 'Europe/Berlin'),
            timezone: 'Europe/Berlin',
            badgeBackgroundColor: null,
            badgeTextColor: null,
            category: RoundCategory::Solo,
        ));

        self::assertSame([null, null], $this->editionOf($time));
    }

    public function testDeletingARoundReconciles(): void
    {
        $series = $this->scenario->series();
        $edition = $this->scenario->edition($series, 'Night Owl Jam', null);
        $round = $this->scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00');
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);
        self::assertSame([$edition, 'date'], $this->editionOf($time));

        $this->scenario->dispatch(new DeleteCompetitionRound($round, $edition));

        // Undated and without rounds: no day to match by
        self::assertSame([null, null], $this->editionOf($time));
    }

    public function testAttachingRemovingAndRevealingRoundPuzzlesReconciles(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $edition = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $round = $this->scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00');
        $time = $this->scenario->addTime(self::PLAYER, $puzzle, '2026-04-20', seriesId: $series);
        self::assertSame([null, null], $this->editionOf($time));

        $this->scenario->dispatch(new SetCompetitionRoundPuzzles($round, [$puzzle]));
        self::assertSame([$edition, 'puzzle'], $this->editionOf($time));
        self::assertSame($round, $this->scenario->link($time)['competition_round_id']);

        $this->scenario->dispatch(new RemovePuzzleFromCompetitionRound($this->scenario->roundPuzzleId($round, $puzzle)));
        self::assertSame([null, null], $this->editionOf($time));
        self::assertNull($this->scenario->link($time)['competition_round_id']);

        // Attached secret: no match; revealed now: matched
        $secret = $this->scenario->puzzle('Starry Harbor');
        $secretRound = $this->scenario->round($edition, RoundCategory::Duo, '2026-03-02 20:00', puzzleIds: [$secret], secret: true);
        $pair = $this->scenario->addTime(self::OTHER_PLAYER, $secret, '2026-04-20', seriesId: $series, groupPlayers: ['Rowan Guest']);
        self::assertSame([null, null], $this->editionOf($pair));

        $this->scenario->dispatch(new RevealRoundPuzzleNow($this->scenario->roundPuzzleId($secretRound, $secret)));
        self::assertSame([$edition, 'puzzle'], $this->editionOf($pair));
    }

    /**
     * P28: a puzzle merge reconciles every series - the merged times are on the survivor now.
     */
    public function testAPuzzleMergeReconcilesEverySeries(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $edition = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $round = $this->scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00');
        $time = $this->scenario->addTime(self::PLAYER, $puzzle, '2026-04-20', seriesId: $series);
        // What the merge moved, in SQL: the round has the time's puzzle now
        $this->database->executeStatement(
            'INSERT INTO competition_round_puzzle (id, round_id, puzzle_id, hide_until_round_starts, reveal_mode, hides_everywhere) VALUES (:id, :round, :puzzle, false, :mode, false)',
            ['id' => Uuid::uuid7()->toString(), 'round' => $round, 'puzzle' => $puzzle, 'mode' => 'automatic'],
        );
        self::assertSame([null, null], $this->editionOf($time));

        $this->scenario->dispatch(new PuzzleMergeApproved(Uuid::uuid7(), Uuid::fromString($puzzle), []));

        self::assertSame([$edition, 'puzzle'], $this->editionOf($time));
    }

    /**
     * The 15-minute cron answers every count (the console command prints them).
     */
    public function testReconcileRoundResultsAnswersEveryCount(): void
    {
        $series = $this->scenario->series();
        $edition = $this->scenario->edition($series, 'Jam No. 1', '2026-04-01');
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);
        $this->database->executeStatement("UPDATE competition SET date_from = '2026-03-02', date_to = '2026-03-02' WHERE id = :id", ['id' => $edition]);

        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(new ReconcileRoundResults());

        self::assertSame(
            ['linked' => 1, 'moved' => 0, 'released' => 0, 'roundsLinked' => 0, 'roundsUnlinked' => 0],
            $envelope->last(HandledStamp::class)?->getResult(),
        );
        self::assertSame([$edition, 'date'], $this->editionOf($time));
    }

    private function editDates(string $editionId, string $day): void
    {
        $edition = $this->database->fetchAssociative('SELECT name, is_online FROM competition WHERE id = :id', ['id' => $editionId]);
        self::assertIsArray($edition);
        self::assertIsString($edition['name']);
        $date = new DateTimeImmutable($day);

        $this->scenario->dispatch(new EditCompetition(
            competitionId: $editionId,
            name: $edition['name'],
            shortcut: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: null,
            locationCountryCode: null,
            dateFrom: $date,
            dateTo: $date,
            isOnline: $edition['is_online'] === true,
            logo: null,
            maintainerIds: [],
            eligibility: null,
        ));
    }

    /**
     * How many times the series reconcile ran while $action did
     */
    private function countReconciles(callable $action): int
    {
        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        $action();

        $count = 0;
        /** @var array<string, list<array{sql: string}>> $data */
        $data = $debugDataHolder->getData();

        foreach ($data as $queries) {
            foreach ($queries as $query) {
                if (str_contains($query['sql'], 'UPDATE puzzle_solving_time AS target') && str_contains($query['sql'], 'series_match_edition')) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @return array{?string, ?string} competition_id, series_edition_match
     */
    private function editionOf(string $timeId): array
    {
        $link = $this->scenario->link($timeId);

        return [$link['competition_id'], $link['series_edition_match']];
    }
}
