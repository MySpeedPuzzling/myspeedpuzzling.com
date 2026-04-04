<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\GenerateTableLayout;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GenerateTableLayoutHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testGenerateCreatesCorrectStructure(): void
    {
        $roundId = CompetitionRoundFixture::ROUND_CZECH_FINAL;

        $this->messageBus->dispatch(new GenerateTableLayout(
            roundId: $roundId,
            numberOfRows: 2,
            tablesPerRow: 3,
            spotsPerTable: 2,
        ));

        self::assertSame(2, $this->countRows($roundId));
        self::assertSame(6, $this->countTablesForRound($roundId));
        self::assertSame(12, $this->countSpotsForRound($roundId));

        /** @var list<string> $labels */
        $labels = $this->database->fetchFirstColumn(
            'SELECT rt.label FROM round_table rt INNER JOIN table_row tr ON rt.row_id = tr.id WHERE tr.round_id = :roundId ORDER BY tr.position, rt.position',
            ['roundId' => $roundId],
        );
        self::assertSame(['Table 1', 'Table 2', 'Table 3', 'Table 4', 'Table 5', 'Table 6'], $labels);
    }

    public function testRegenerateReplacesExistingLayout(): void
    {
        $roundId = CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION;

        $this->messageBus->dispatch(new GenerateTableLayout(
            roundId: $roundId,
            numberOfRows: 1,
            tablesPerRow: 2,
            spotsPerTable: 3,
        ));

        self::assertSame(1, $this->countRows($roundId));
        self::assertSame(2, $this->countTablesForRound($roundId));
        self::assertSame(6, $this->countSpotsForRound($roundId));
    }

    private function countRows(string $roundId): int
    {
        /** @var int|string|false $result */
        $result = $this->database->fetchOne(
            'SELECT COUNT(*) FROM table_row WHERE round_id = :roundId',
            ['roundId' => $roundId],
        );

        return (int) $result;
    }

    private function countTablesForRound(string $roundId): int
    {
        /** @var int|string|false $result */
        $result = $this->database->fetchOne(
            'SELECT COUNT(*) FROM round_table rt INNER JOIN table_row tr ON rt.row_id = tr.id WHERE tr.round_id = :roundId',
            ['roundId' => $roundId],
        );

        return (int) $result;
    }

    private function countSpotsForRound(string $roundId): int
    {
        /** @var int|string|false $result */
        $result = $this->database->fetchOne(
            'SELECT COUNT(*) FROM table_spot ts INNER JOIN round_table rt ON ts.table_id = rt.id INNER JOIN table_row tr ON rt.row_id = tr.id WHERE tr.round_id = :roundId',
            ['roundId' => $roundId],
        );

        return (int) $result;
    }
}
