<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetUpcomingEventsCount;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Value\CommunityScope;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The fixtures' event dates are relative to the day the test database was built, so every event is moved into the
 * past first and the test dates only the ones it needs.
 */
final class GetUpcomingEventsCountTest extends KernelTestCase
{
    private GetUpcomingEventsCount $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetUpcomingEventsCount::class);
        $this->database = self::getContainer()->get(Connection::class);

        $this->database->executeStatement(
            'UPDATE competition SET date_from = :past, date_to = :past',
            ['past' => (new DateTimeImmutable('-100 days'))->format('Y-m-d H:i:s')],
        );
    }

    public function testPubliclyVisibleEventsStartingAfterTodayPerCountry(): void
    {
        // cz, approved
        $this->startIn(CompetitionFixture::COMPETITION_WJPC_2024, '+10 days');
        // cz, edition of an approved series
        $this->startIn(CompetitionSeriesFixture::EDITION_OFFLINE_1, '+14 days');
        // online without a country, edition of an approved series
        $this->startIn(CompetitionSeriesFixture::EDITION_EJJ_69, '+30 days');
        // at, never approved
        $this->startIn(CompetitionFixture::COMPETITION_UNAPPROVED, '+5 days');
        // edition of a series that is not approved
        $this->startIn(CompetitionSeriesFixture::EDITION_UNAPPROVED_1, '+7 days');
        // today is not upcoming any more - it is on
        $this->startIn(CompetitionFixture::COMPETITION_RECURRING_ONLINE, 'today');
        // cz, approved but rejected afterwards
        $this->startIn(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, '+20 days');
        $this->database->executeStatement(
            'UPDATE competition SET rejected_at = NOW() WHERE id = :id',
            ['id' => CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024],
        );

        self::assertSame(2, $this->query->forScope(CommunityScope::country(CountryCode::cz)));
        self::assertSame(3, $this->query->forScope(CommunityScope::world()));
        self::assertSame(0, $this->query->forScope(CommunityScope::country(CountryCode::at)));
        self::assertSame(0, $this->query->forScope(CommunityScope::country(CountryCode::de)));
    }

    public function testHistoricUppercaseCountryCodesCount(): void
    {
        $this->startIn(CompetitionFixture::COMPETITION_WJPC_2024, '+10 days');
        $this->database->executeStatement(
            "UPDATE competition SET location_country_code = 'CZ' WHERE id = :id",
            ['id' => CompetitionFixture::COMPETITION_WJPC_2024],
        );

        self::assertSame(1, $this->query->forScope(CommunityScope::country(CountryCode::cz)));
    }

    private function startIn(string $competitionId, string $when): void
    {
        $this->database->executeStatement(
            'UPDATE competition SET date_from = :dateFrom, date_to = :dateFrom WHERE id = :id',
            ['dateFrom' => (new DateTimeImmutable($when))->format('Y-m-d H:i:s'), 'id' => $competitionId],
        );
    }
}
