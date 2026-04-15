<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class DeleteCompetitionHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionRepository $competitionRepository;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->competitionRepository = self::getContainer()->get(CompetitionRepository::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testDeletesCompetitionAndAllItsData(): void
    {
        $competitionId = CompetitionFixture::COMPETITION_WJPC_2024;

        self::assertGreaterThan(0, $this->countRows(
            'SELECT COUNT(*) FROM competition_round WHERE competition_id = :id',
            ['id' => $competitionId],
        ));
        self::assertGreaterThan(0, $this->countRows(
            'SELECT COUNT(*) FROM competition_participant WHERE competition_id = :id',
            ['id' => $competitionId],
        ));
        self::assertGreaterThan(0, $this->countRows(
            'SELECT COUNT(*) FROM competition_round_puzzle crp
             INNER JOIN competition_round cr ON cr.id = crp.round_id
             WHERE cr.competition_id = :id',
            ['id' => $competitionId],
        ));

        $solvingTimesLinkedToCompetition = $this->countRows(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE competition_id = :id',
            ['id' => $competitionId],
        );
        $solvingTimesLinkedToRounds = $this->countRows(
            'SELECT COUNT(*) FROM puzzle_solving_time
             WHERE competition_round_id IN (SELECT id FROM competition_round WHERE competition_id = :id)',
            ['id' => $competitionId],
        );
        self::assertGreaterThan(0, $solvingTimesLinkedToCompetition + $solvingTimesLinkedToRounds);

        // Snapshot totals to prove no solving times or puzzles are deleted
        $solvingTimesTotalBefore = $this->countRows('SELECT COUNT(*) FROM puzzle_solving_time');
        $puzzlesTotalBefore = $this->countRows('SELECT COUNT(*) FROM puzzle');

        $this->messageBus->dispatch(new DeleteCompetition(competitionId: $competitionId));

        $this->expectException(CompetitionNotFound::class);

        try {
            $this->competitionRepository->get($competitionId);
        } finally {
            // Competition-owned rows are gone
            self::assertSame(0, $this->countRows(
                'SELECT COUNT(*) FROM competition_round WHERE competition_id = :id',
                ['id' => $competitionId],
            ));
            self::assertSame(0, $this->countRows(
                'SELECT COUNT(*) FROM competition_participant WHERE competition_id = :id',
                ['id' => $competitionId],
            ));

            // Solving times survive — only their competition refs are nulled
            self::assertSame(
                $solvingTimesTotalBefore,
                $this->countRows('SELECT COUNT(*) FROM puzzle_solving_time'),
            );
            self::assertSame(0, $this->countRows(
                'SELECT COUNT(*) FROM puzzle_solving_time WHERE competition_id = :id',
                ['id' => $competitionId],
            ));
            self::assertSame(0, $this->countRows(
                'SELECT COUNT(*) FROM puzzle_solving_time WHERE competition_round_id = :rid',
                ['rid' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION],
            ));

            // Puzzles are never touched
            self::assertSame(
                $puzzlesTotalBefore,
                $this->countRows('SELECT COUNT(*) FROM puzzle'),
            );
        }
    }

    public function testDeletesUnapprovedCompetitionWithoutChildren(): void
    {
        $competitionId = CompetitionFixture::COMPETITION_UNAPPROVED;

        $this->messageBus->dispatch(new DeleteCompetition(competitionId: $competitionId));

        $this->expectException(CompetitionNotFound::class);
        $this->competitionRepository->get($competitionId);
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
