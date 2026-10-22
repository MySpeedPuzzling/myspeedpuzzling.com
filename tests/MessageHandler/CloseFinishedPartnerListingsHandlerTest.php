<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Message\CloseFinishedPartnerListings;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The daily auto-close (docs/features/partner-finder.md D7): the event over, or the player entered in a pair/team of
 * the event - every other listing stays as it is, a closed one keeps its first reason.
 */
final class CloseFinishedPartnerListingsHandlerTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testNothingClosesWhileTheEventIsAheadAndNobodyIsEntered(): void
    {
        self::assertSame(['eventOver' => 0, 'entered' => 0], $this->closeListings());

        self::assertSame([
            PartnerFinderFixture::LISTING_REGULAR_PAIR => null,
            PartnerFinderFixture::LISTING_FAVORITES_TEAM => null,
            PartnerFinderFixture::LISTING_STRIPE_CLOSED => 'withdrawn',
        ], $this->reasons());
    }

    public function testListingsOfAnEventThatIsOverClose(): void
    {
        // The event ended yesterday
        $this->connection->executeStatement(
            "UPDATE competition SET date_from = NOW() - INTERVAL '2 days', date_to = NOW() - INTERVAL '1 day' WHERE id = :id",
            ['id' => PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN],
        );

        self::assertSame(['eventOver' => 2, 'entered' => 0], $this->closeListings());

        self::assertSame([
            PartnerFinderFixture::LISTING_REGULAR_PAIR => 'event_over',
            PartnerFinderFixture::LISTING_FAVORITES_TEAM => 'event_over',
            PartnerFinderFixture::LISTING_STRIPE_CLOSED => 'withdrawn',
        ], $this->reasons());

        // Again: nothing left to do
        self::assertSame(['eventOver' => 0, 'entered' => 0], $this->closeListings());
    }

    public function testAListingOfAPlayerEnteredInAPairOrTeamOfTheEventCloses(): void
    {
        // The organiser put PLAYER_REGULAR into a pair of the Pairs round
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $round = self::getContainer()->get(CompetitionRoundRepository::class)->get(PartnerFinderFixture::ROUND_PAIRS);
        $team = new CompetitionTeam(Uuid::uuid7(), $round, 'Edge Lords');
        $entityManager->persist($team);
        $entityManager->persist(new CompetitionParticipantRound(
            Uuid::uuid7(),
            self::getContainer()->get(CompetitionParticipantRepository::class)->get(PartnerFinderFixture::PARTICIPANT_REGULAR),
            $round,
            $team,
        ));
        $entityManager->flush();

        self::assertSame(['eventOver' => 0, 'entered' => 1], $this->closeListings());

        self::assertSame([
            PartnerFinderFixture::LISTING_REGULAR_PAIR => 'entered',
            PartnerFinderFixture::LISTING_FAVORITES_TEAM => null,
            PartnerFinderFixture::LISTING_STRIPE_CLOSED => 'withdrawn',
        ], $this->reasons());
    }

    /**
     * @return array{eventOver: int, entered: int}
     */
    private function closeListings(): array
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(new CloseFinishedPartnerListings());
        $stamp = $envelope->last(HandledStamp::class);
        self::assertInstanceOf(HandledStamp::class, $stamp);

        /** @var array{eventOver: int, entered: int} */
        return $stamp->getResult();
    }

    /**
     * @return array<string, null|string>
     */
    private function reasons(): array
    {
        /** @var array<string, null|string> */
        return $this->connection->fetchAllKeyValue(
            'SELECT id, closed_reason FROM partner_listing WHERE competition_id = :id ORDER BY id',
            ['id' => PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN],
        );
    }
}
