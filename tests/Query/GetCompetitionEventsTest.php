<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

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

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetCompetitionEvents::class);
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
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

    public function testSeriesEditionsExcludedFromUnapprovedList(): void
    {
        $unapproved = $this->query->allUnapproved();

        $ids = array_map(static fn($c) => $c->id, $unapproved);
        self::assertNotContains(CompetitionSeriesFixture::EDITION_EJJ_68, $ids);
        self::assertNotContains(CompetitionSeriesFixture::EDITION_EJJ_69, $ids);
        self::assertNotContains(CompetitionSeriesFixture::EDITION_OFFLINE_1, $ids);
    }
}
