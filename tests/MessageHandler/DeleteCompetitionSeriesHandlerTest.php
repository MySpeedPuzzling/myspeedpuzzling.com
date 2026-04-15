<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;
use SpeedPuzzling\Web\Message\DeleteCompetitionSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class DeleteCompetitionSeriesHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionSeriesRepository $seriesRepository;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->seriesRepository = self::getContainer()->get(CompetitionSeriesRepository::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testDeletesSeriesAndAllItsEditions(): void
    {
        $seriesId = CompetitionSeriesFixture::SERIES_EJJ;

        self::assertGreaterThan(0, $this->countRows(
            'SELECT COUNT(*) FROM competition WHERE series_id = :id',
            ['id' => $seriesId],
        ));
        self::assertGreaterThan(0, $this->countRows(
            'SELECT COUNT(*) FROM competition_round cr
             INNER JOIN competition c ON c.id = cr.competition_id
             WHERE c.series_id = :id',
            ['id' => $seriesId],
        ));

        // Snapshot totals to prove solving times and puzzles survive
        $solvingTimesTotalBefore = $this->countRows('SELECT COUNT(*) FROM puzzle_solving_time');
        $puzzlesTotalBefore = $this->countRows('SELECT COUNT(*) FROM puzzle');

        $this->messageBus->dispatch(new DeleteCompetitionSeries(seriesId: $seriesId));

        $this->expectException(CompetitionSeriesNotFound::class);

        try {
            $this->seriesRepository->get($seriesId);
        } finally {
            self::assertSame(0, $this->countRows(
                'SELECT COUNT(*) FROM competition WHERE series_id = :id',
                ['id' => $seriesId],
            ));
            self::assertSame(0, $this->countRows(
                'SELECT COUNT(*) FROM competition_round cr
                 INNER JOIN competition c ON c.id = cr.competition_id
                 WHERE c.series_id = :id',
                ['id' => $seriesId],
            ));

            self::assertSame(
                $solvingTimesTotalBefore,
                $this->countRows('SELECT COUNT(*) FROM puzzle_solving_time'),
            );
            self::assertSame(
                $puzzlesTotalBefore,
                $this->countRows('SELECT COUNT(*) FROM puzzle'),
            );
        }
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
