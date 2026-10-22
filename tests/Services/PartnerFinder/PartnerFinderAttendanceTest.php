<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PartnerFinder;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderAttendance;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PartnerFinderAttendanceTest extends KernelTestCase
{
    private PartnerFinderAttendance $attendance;
    private CompetitionRepository $competitionRepository;
    private PlayerRepository $playerRepository;
    private EntityManagerInterface $entityManager;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->attendance = self::getContainer()->get(PartnerFinderAttendance::class);
        $this->competitionRepository = self::getContainer()->get(CompetitionRepository::class);
        $this->playerRepository = self::getContainer()->get(PlayerRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAPlayerNotGoingIsMadeGoingLikeImGoing(): void
    {
        $joined = $this->attendance->ensureGoing(
            $this->competitionRepository->get(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN),
            $this->playerRepository->get(PlayerFixture::PLAYER_ADMIN),
        );
        $this->entityManager->flush();

        self::assertTrue($joined);
        self::assertSame([['source' => 'self_joined', 'registration_status' => null]], $this->rowsOf(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN, PlayerFixture::PLAYER_ADMIN));

        // Twice is once
        self::assertFalse($this->attendance->ensureGoing(
            $this->competitionRepository->get(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN),
            $this->playerRepository->get(PlayerFixture::PLAYER_ADMIN),
        ));
    }

    public function testAPlayerGoingAlreadyKeepsTheirRow(): void
    {
        self::assertFalse($this->attendance->ensureGoing(
            $this->competitionRepository->get(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN),
            $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR),
        ));
        $this->entityManager->flush();

        self::assertCount(1, $this->rowsOf(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN, PlayerFixture::PLAYER_REGULAR));
    }

    public function testALeftEventIsJoinedAgainOnTheSameRow(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_participant SET deleted_at = NOW() WHERE id = :id',
            ['id' => PartnerFinderFixture::PARTICIPANT_REGULAR],
        );

        self::assertTrue($this->attendance->ensureGoing(
            $this->competitionRepository->get(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN),
            $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR),
        ));
        $this->entityManager->flush();

        /** @var list<string> $ids */
        $ids = $this->database->executeQuery(
            'SELECT id FROM competition_participant WHERE competition_id = :competitionId AND player_id = :playerId AND deleted_at IS NULL',
            ['competitionId' => PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN, 'playerId' => PlayerFixture::PLAYER_REGULAR],
        )->fetchFirstColumn();

        self::assertSame([PartnerFinderFixture::PARTICIPANT_REGULAR], $ids);
    }

    public function testAnEventManagingRegistrationIsLeftAlone(): void
    {
        self::assertFalse($this->attendance->ensureGoing(
            $this->competitionRepository->get(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN),
            $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR),
        ));
        $this->entityManager->flush();

        self::assertSame([], $this->rowsOf(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN, PlayerFixture::PLAYER_REGULAR));
    }

    /**
     * @return list<array{source: string, registration_status: null|string}>
     */
    private function rowsOf(string $competitionId, string $playerId): array
    {
        /** @var list<array{source: string, registration_status: null|string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT source, registration_status FROM competition_participant WHERE competition_id = :competitionId AND player_id = :playerId AND deleted_at IS NULL',
            ['competitionId' => $competitionId, 'playerId' => $playerId],
        )->fetchAllAssociative();

        return $rows;
    }
}
