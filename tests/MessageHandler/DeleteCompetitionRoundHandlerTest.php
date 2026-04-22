<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class DeleteCompetitionRoundHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionRoundRepository $competitionRoundRepository;
    private EntityManagerInterface $entityManager;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->competitionRoundRepository = self::getContainer()->get(CompetitionRoundRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testDeletesRoundAndAllItsData(): void
    {
        $roundId = CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION;

        // Seed a team for this round — no fixture exists for competition_team
        $team = new CompetitionTeam(
            id: Uuid::uuid7(),
            round: $this->competitionRoundRepository->get($roundId),
            name: 'Team Alpha',
        );
        $this->entityManager->persist($team);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Preconditions: round has related rows in every table touched by the handler
        self::assertGreaterThan(0, $this->countRows(
            'SELECT COUNT(*) FROM competition_round_puzzle WHERE round_id = :id',
            ['id' => $roundId],
        ));
        self::assertGreaterThan(0, $this->countRows(
            'SELECT COUNT(*) FROM competition_participant_round WHERE round_id = :id',
            ['id' => $roundId],
        ));
        self::assertGreaterThan(0, $this->countRows(
            'SELECT COUNT(*) FROM competition_team WHERE round_id = :id',
            ['id' => $roundId],
        ));
        self::assertGreaterThan(0, $this->countRows(
            'SELECT COUNT(*) FROM table_row WHERE round_id = :id',
            ['id' => $roundId],
        ));
        self::assertGreaterThan(0, $this->countRows(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE competition_round_id = :id',
            ['id' => $roundId],
        ));

        // Snapshots to prove unrelated data is preserved
        $solvingTimesTotalBefore = $this->countRows('SELECT COUNT(*) FROM puzzle_solving_time');
        $puzzlesTotalBefore = $this->countRows('SELECT COUNT(*) FROM puzzle');
        $participantsTotalBefore = $this->countRows(
            'SELECT COUNT(*) FROM competition_participant WHERE competition_id = :id',
            ['id' => CompetitionFixture::COMPETITION_WJPC_2024],
        );
        $otherRoundPuzzlesBefore = $this->countRows(
            'SELECT COUNT(*) FROM competition_round_puzzle WHERE round_id = :id',
            ['id' => CompetitionRoundFixture::ROUND_WJPC_FINAL],
        );
        $otherParticipantRoundsBefore = $this->countRows(
            'SELECT COUNT(*) FROM competition_participant_round WHERE round_id = :id',
            ['id' => CompetitionRoundFixture::ROUND_WJPC_FINAL],
        );

        $this->messageBus->dispatch(new DeleteCompetitionRound(roundId: $roundId));

        // Round itself is gone
        try {
            $this->competitionRoundRepository->get($roundId);
            self::fail('Round should have been deleted');
        } catch (CompetitionRoundNotFound) {
        }

        // Round-owned rows are gone
        self::assertSame(0, $this->countRows(
            'SELECT COUNT(*) FROM competition_round_puzzle WHERE round_id = :id',
            ['id' => $roundId],
        ));
        self::assertSame(0, $this->countRows(
            'SELECT COUNT(*) FROM competition_participant_round WHERE round_id = :id',
            ['id' => $roundId],
        ));
        self::assertSame(0, $this->countRows(
            'SELECT COUNT(*) FROM competition_team WHERE round_id = :id',
            ['id' => $roundId],
        ));
        self::assertSame(0, $this->countRows(
            'SELECT COUNT(*) FROM table_row WHERE round_id = :id',
            ['id' => $roundId],
        ));

        // Solving times survive — only their round refs are nulled
        self::assertSame(
            $solvingTimesTotalBefore,
            $this->countRows('SELECT COUNT(*) FROM puzzle_solving_time'),
        );
        self::assertSame(0, $this->countRows(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE competition_round_id = :id',
            ['id' => $roundId],
        ));

        // Puzzles, participants and the sibling round are untouched
        self::assertSame(
            $puzzlesTotalBefore,
            $this->countRows('SELECT COUNT(*) FROM puzzle'),
        );
        self::assertSame($participantsTotalBefore, $this->countRows(
            'SELECT COUNT(*) FROM competition_participant WHERE competition_id = :id',
            ['id' => CompetitionFixture::COMPETITION_WJPC_2024],
        ));
        self::assertSame($otherRoundPuzzlesBefore, $this->countRows(
            'SELECT COUNT(*) FROM competition_round_puzzle WHERE round_id = :id',
            ['id' => CompetitionRoundFixture::ROUND_WJPC_FINAL],
        ));
        self::assertSame($otherParticipantRoundsBefore, $this->countRows(
            'SELECT COUNT(*) FROM competition_participant_round WHERE round_id = :id',
            ['id' => CompetitionRoundFixture::ROUND_WJPC_FINAL],
        ));
    }

    public function testDeletesEmptyRound(): void
    {
        $roundId = CompetitionRoundFixture::ROUND_CZECH_FINAL;

        $this->messageBus->dispatch(new DeleteCompetitionRound(roundId: $roundId));

        $this->expectException(CompetitionRoundNotFound::class);
        $this->competitionRoundRepository->get($roundId);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countRows(string $sql, array $params = []): int
    {
        /** @var string|int|false $value */
        $value = $this->database->fetchOne($sql, $params);

        return (int) $value;
    }
}
