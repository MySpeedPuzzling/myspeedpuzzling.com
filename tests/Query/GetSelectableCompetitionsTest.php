<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetSelectableCompetitions;
use SpeedPuzzling\Web\Results\SelectableCompetition;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetSelectableCompetitionsTest extends KernelTestCase
{
    private const string UNDATED_STANDALONE = '018d0004-0000-0000-0000-0000000000a1';
    private const string REJECTED_STANDALONE = '018d0004-0000-0000-0000-0000000000a2';
    private const string UNDATED_EDITION_WITH_ROUND = '018d0005-0000-0000-0000-0000000000a3';
    private const string UNDATED_EDITION_ROUND = '018d0005-0000-0000-0000-0000000000a4';

    private GetSelectableCompetitions $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetSelectableCompetitions::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testApprovedStandaloneCompetitionsAreSelectableRegardlessOfDate(): void
    {
        $byId = $this->byId($this->query->all());

        // upcoming
        self::assertArrayHasKey(CompetitionFixture::COMPETITION_WJPC_2024, $byId);
        self::assertArrayHasKey(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, $byId);
        self::assertSame('upcoming', $byId[CompetitionFixture::COMPETITION_WJPC_2024]->eventStatus);
        self::assertNull($byId[CompetitionFixture::COMPETITION_WJPC_2024]->seriesId);

        // live
        self::assertArrayHasKey(CompetitionFixture::COMPETITION_RECURRING_ONLINE, $byId);
        self::assertSame('live', $byId[CompetitionFixture::COMPETITION_RECURRING_ONLINE]->eventStatus);
    }

    public function testUnapprovedAndRejectedStandaloneCompetitionsAreNotSelectable(): void
    {
        $this->insertStandalone(self::REJECTED_STANDALONE, 'Rejected Standalone', approved: true, rejected: true);

        $byId = $this->byId($this->query->all());

        self::assertArrayNotHasKey(CompetitionFixture::COMPETITION_UNAPPROVED, $byId);
        self::assertArrayNotHasKey(self::REJECTED_STANDALONE, $byId);
        self::assertArrayNotHasKey(CompetitionApiFixture::COMPETITION_API_REJECTED, $byId);
    }

    public function testEditionsOfApprovedSeriesAreSelectableWithSeriesData(): void
    {
        $byId = $this->byId($this->query->all());

        $expectedSeries = [
            CompetitionSeriesFixture::EDITION_EJJ_68 => CompetitionSeriesFixture::SERIES_EJJ,
            CompetitionSeriesFixture::EDITION_EJJ_69 => CompetitionSeriesFixture::SERIES_EJJ,
            CompetitionSeriesFixture::EDITION_OFFLINE_1 => CompetitionSeriesFixture::SERIES_OFFLINE,
            CompetitionSeriesFixture::EDITION_PAST_ONLY_1 => CompetitionSeriesFixture::SERIES_PAST_ONLY,
        ];

        foreach ($expectedSeries as $editionId => $seriesId) {
            self::assertArrayHasKey($editionId, $byId);
            self::assertSame($seriesId, $byId[$editionId]->seriesId);
            self::assertNotNull($byId[$editionId]->seriesName);
        }

        self::assertSame('Euro Jigsaw Jam', $byId[CompetitionSeriesFixture::EDITION_EJJ_68]->seriesName);
        self::assertSame('past', $byId[CompetitionSeriesFixture::EDITION_EJJ_68]->eventStatus);
        self::assertSame('upcoming', $byId[CompetitionSeriesFixture::EDITION_OFFLINE_1]->eventStatus);
        // Location falls back to the series when the edition has none
        self::assertSame('Prague', $byId[CompetitionSeriesFixture::EDITION_OFFLINE_1]->location);

        // Editions are never approved individually - the series approval governs them
        self::assertNull($this->database->fetchOne(
            'SELECT approved_at FROM competition WHERE id = :id',
            ['id' => CompetitionSeriesFixture::EDITION_EJJ_68],
        ));
    }

    public function testEditionOfUnapprovedSeriesIsNotSelectable(): void
    {
        $byId = $this->byId($this->query->all());

        self::assertArrayNotHasKey(CompetitionSeriesFixture::EDITION_UNAPPROVED_1, $byId);
    }

    public function testEditionOfRejectedSeriesIsNotSelectable(): void
    {
        self::assertArrayHasKey(CompetitionSeriesFixture::EDITION_PAST_ONLY_1, $this->byId($this->query->all()));

        $this->database->executeStatement(
            'UPDATE competition_series SET rejected_at = now() WHERE id = :seriesId',
            ['seriesId' => CompetitionSeriesFixture::SERIES_PAST_ONLY],
        );

        self::assertArrayNotHasKey(CompetitionSeriesFixture::EDITION_PAST_ONLY_1, $this->byId($this->query->all()));
    }

    public function testRejectedEditionIsNotSelectableEvenWhenItsSeriesIsApproved(): void
    {
        $this->database->executeStatement(
            'UPDATE competition SET rejected_at = now() WHERE id = :id',
            ['id' => CompetitionSeriesFixture::EDITION_EJJ_69],
        );

        $byId = $this->byId($this->query->all());

        self::assertArrayNotHasKey(CompetitionSeriesFixture::EDITION_EJJ_69, $byId);
        self::assertArrayHasKey(CompetitionSeriesFixture::EDITION_EJJ_68, $byId);
    }

    public function testGlobalOrderIsLiveThenUndatedStandaloneThenPastDescThenUpcomingAsc(): void
    {
        // Pin the live anchor so the assertion does not depend on how old the cached fixture database is
        $this->database->executeStatement(
            "UPDATE competition SET date_from = now(), date_to = now() + INTERVAL '2 days' WHERE id = :id",
            ['id' => CompetitionFixture::COMPETITION_RECURRING_ONLINE],
        );
        $this->insertStandalone(self::UNDATED_STANDALONE, 'Perpetual Online Jam', approved: true, rejected: false);

        $ids = array_map(static fn (SelectableCompetition $c): string => $c->id, $this->query->all());
        $index = array_flip($ids);

        self::assertLessThan($index[self::UNDATED_STANDALONE], $index[CompetitionFixture::COMPETITION_RECURRING_ONLINE]);
        self::assertLessThan($index[CompetitionSeriesFixture::EDITION_EJJ_68], $index[self::UNDATED_STANDALONE]);
        // past: newest first (-30 days before -45 days)
        self::assertLessThan($index[CompetitionSeriesFixture::EDITION_PAST_ONLY_1], $index[CompetitionSeriesFixture::EDITION_EJJ_68]);
        // upcoming after past, soonest first (+14 < +30 < +60 days)
        self::assertLessThan($index[CompetitionSeriesFixture::EDITION_OFFLINE_1], $index[CompetitionSeriesFixture::EDITION_PAST_ONLY_1]);
        self::assertLessThan($index[CompetitionFixture::COMPETITION_WJPC_2024], $index[CompetitionSeriesFixture::EDITION_OFFLINE_1]);
        self::assertLessThan($index[CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024], $index[CompetitionFixture::COMPETITION_WJPC_2024]);
    }

    public function testUndatedEditionWithRoundsIsDatedByItsFirstRound(): void
    {
        $this->database->executeStatement(
            <<<SQL
            INSERT INTO competition (id, name, is_online, series_id)
            VALUES (:id, 'EJJ #67 - undated', true, :seriesId)
            SQL,
            ['id' => self::UNDATED_EDITION_WITH_ROUND, 'seriesId' => CompetitionSeriesFixture::SERIES_EJJ],
        );
        $this->database->executeStatement(
            <<<SQL
            INSERT INTO competition_round (id, competition_id, name, minutes_limit, starts_at, category)
            VALUES (:id, :competitionId, 'EJJ #67', 120, now() - INTERVAL '40 days', 'solo')
            SQL,
            ['id' => self::UNDATED_EDITION_ROUND, 'competitionId' => self::UNDATED_EDITION_WITH_ROUND],
        );

        $all = $this->query->all();
        $byId = $this->byId($all);
        $index = array_flip(array_map(static fn (SelectableCompetition $c): string => $c->id, $all));

        self::assertArrayHasKey(self::UNDATED_EDITION_WITH_ROUND, $byId);
        self::assertNull($byId[self::UNDATED_EDITION_WITH_ROUND]->dateFrom);
        self::assertSame('past', $byId[self::UNDATED_EDITION_WITH_ROUND]->eventStatus);
        // -40 days sits between EJJ #68 (-30 days) and Berlin Puzzle Cup 2026 (-45 days)
        self::assertLessThan($index[self::UNDATED_EDITION_WITH_ROUND], $index[CompetitionSeriesFixture::EDITION_EJJ_68]);
        self::assertLessThan($index[CompetitionSeriesFixture::EDITION_PAST_ONLY_1], $index[self::UNDATED_EDITION_WITH_ROUND]);
    }

    public function testUndatedEditionWithoutRoundsSortsLast(): void
    {
        $this->database->executeStatement(
            <<<SQL
            INSERT INTO competition (id, name, is_online, series_id)
            VALUES (:id, 'EJJ #?? - undated', true, :seriesId)
            SQL,
            ['id' => self::UNDATED_EDITION_WITH_ROUND, 'seriesId' => CompetitionSeriesFixture::SERIES_EJJ],
        );

        $all = $this->query->all();
        $last = end($all);

        self::assertNotFalse($last);
        self::assertSame(self::UNDATED_EDITION_WITH_ROUND, $last->id);
        self::assertSame('undated', $last->eventStatus);
    }

    public function testAlwaysIncludedCompetitionIsReturnedExactlyOnce(): void
    {
        // Not selectable on its own (unapproved) - included for the edit form of a time linked to it
        $ids = array_map(static fn (SelectableCompetition $c): string => $c->id, $this->query->all(CompetitionFixture::COMPETITION_UNAPPROVED));
        self::assertCount(1, array_keys($ids, CompetitionFixture::COMPETITION_UNAPPROVED, true));

        // Already selectable - must not be duplicated
        $ids = array_map(static fn (SelectableCompetition $c): string => $c->id, $this->query->all(CompetitionFixture::COMPETITION_WJPC_2024));
        self::assertCount(1, array_keys($ids, CompetitionFixture::COMPETITION_WJPC_2024, true));
    }

    public function testInvalidAlwaysIncludedIdIsIgnored(): void
    {
        $withInvalid = $this->query->all('not-a-uuid');
        $plain = $this->query->all();

        self::assertCount(count($plain), $withInvalid);
    }

    /**
     * @param list<SelectableCompetition> $competitions
     * @return array<string, SelectableCompetition>
     */
    private function byId(array $competitions): array
    {
        $byId = [];

        foreach ($competitions as $competition) {
            $byId[$competition->id] = $competition;
        }

        return $byId;
    }

    private function insertStandalone(string $id, string $name, bool $approved, bool $rejected): void
    {
        $this->database->executeStatement(
            <<<SQL
            INSERT INTO competition (id, name, location, is_online, approved_at, rejected_at)
            VALUES (:id, :name, 'Online', true, :approvedAt, :rejectedAt)
            SQL,
            [
                'id' => $id,
                'name' => $name,
                'approvedAt' => $approved ? '2026-01-01 00:00:00' : null,
                'rejectedAt' => $rejected ? '2026-01-02 00:00:00' : null,
            ],
        );
    }
}
