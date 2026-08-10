<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\RejectCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GetCompetitionEventsTest extends KernelTestCase
{
    private GetCompetitionEvents $query;
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetCompetitionEvents::class);
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testUnapprovedListContainsUnapprovedCompetition(): void
    {
        $unapproved = $this->query->allUnapproved();

        $ids = array_map(static fn($c) => $c->id, $unapproved);
        self::assertContains(CompetitionFixture::COMPETITION_UNAPPROVED, $ids);
    }

    public function testRejectedCompetitionExcludedFromUnapprovedList(): void
    {
        $this->messageBus->dispatch(new RejectCompetition(
            competitionId: CompetitionFixture::COMPETITION_UNAPPROVED,
            rejectedByPlayerId: PlayerFixture::PLAYER_ADMIN,
            reason: 'Not a real event',
        ));

        $unapproved = $this->query->allUnapproved();

        $ids = array_map(static fn($c) => $c->id, $unapproved);
        self::assertNotContains(CompetitionFixture::COMPETITION_UNAPPROVED, $ids);
    }

    public function testSeriesEditionsExcludedFromUpcomingAndPast(): void
    {
        $upcoming = $this->query->allUpcoming();
        $past = $this->query->allPast();

        $upcomingIds = array_map(static fn($c) => $c->id, $upcoming);
        $pastIds = array_map(static fn($c) => $c->id, $past);

        self::assertNotContains(CompetitionSeriesFixture::EDITION_EJJ_68, $pastIds);
        self::assertNotContains(CompetitionSeriesFixture::EDITION_EJJ_69, $upcomingIds);
    }

    public function testSearchCountryFilterIsCaseInsensitive(): void
    {
        // Legacy/imported rows carry uppercase ISO codes ('CH'), while the UI
        // filter submits lowercase enum names ('ch') — both must match.
        $this->database->executeStatement(
            <<<SQL
            INSERT INTO competition (id, name, location, location_country_code, date_from, date_to, is_online, approved_at)
            VALUES ('018d0004-0000-0000-0000-00000000ffff', 'Swiss Uppercase Championship', 'Zurich', 'CH', NOW() + INTERVAL '20 days', NOW() + INTERVAL '21 days', false, NOW())
            SQL,
        );

        $results = $this->query->search(country: 'ch');

        $ids = array_map(static fn($c) => $c->id, $results);
        self::assertContains('018d0004-0000-0000-0000-00000000ffff', $ids);
    }

    public function testSearchCountryFilterMatchesLowercaseRowsWithUppercaseInput(): void
    {
        // WJPC 2024 fixture is stored with lowercase 'cz'.
        $results = $this->query->search(country: 'CZ');

        $ids = array_map(static fn($c) => $c->id, $results);
        self::assertContains(CompetitionFixture::COMPETITION_WJPC_2024, $ids);
    }

    public function testSeriesEditionsExcludedFromUnapprovedList(): void
    {
        $unapproved = $this->query->allUnapproved();

        $ids = array_map(static fn($c) => $c->id, $unapproved);
        self::assertNotContains(CompetitionSeriesFixture::EDITION_EJJ_68, $ids);
        self::assertNotContains(CompetitionSeriesFixture::EDITION_EJJ_69, $ids);
        self::assertNotContains(CompetitionSeriesFixture::EDITION_OFFLINE_1, $ids);
    }
}
