<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class SetCompetitionRoundPuzzlesHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->database = $container->get(Connection::class);
    }

    public function testKeepsRemovesAndAttaches(): void
    {
        $keptRoundPuzzleId = $this->roundPuzzleIdOf(CompetitionRoundFixture::ROUND_WJPC_FINAL, PuzzleFixture::PUZZLE_1000_01);

        $this->messageBus->dispatch(new SetCompetitionRoundPuzzles(
            roundId: CompetitionRoundFixture::ROUND_WJPC_FINAL,
            puzzleIds: [PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_1000_03],
        ));

        self::assertEqualsCanonicalizing(
            [PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_1000_03],
            $this->puzzleIdsOf(CompetitionRoundFixture::ROUND_WJPC_FINAL),
        );
        // A kept puzzle keeps its assignment (and with it its hide setting)
        self::assertSame($keptRoundPuzzleId, $this->roundPuzzleIdOf(CompetitionRoundFixture::ROUND_WJPC_FINAL, PuzzleFixture::PUZZLE_1000_01));
    }

    public function testAPuzzleInAnotherRoundOfTheCategoryIsAccepted(): void
    {
        // PUZZLE_500_01 is in the solo Qualification Round already - the solo Final may share it (shared puzzles, S2)
        $this->messageBus->dispatch(new SetCompetitionRoundPuzzles(
            roundId: CompetitionRoundFixture::ROUND_WJPC_FINAL,
            puzzleIds: [PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_1000_02, PuzzleFixture::PUZZLE_500_01],
        ));

        self::assertEqualsCanonicalizing(
            [PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_1000_02, PuzzleFixture::PUZZLE_500_01],
            $this->puzzleIdsOf(CompetitionRoundFixture::ROUND_WJPC_FINAL),
        );
        self::assertContains(PuzzleFixture::PUZZLE_500_01, $this->puzzleIdsOf(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION));
    }

    /**
     * @return list<string>
     */
    private function puzzleIdsOf(string $roundId): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn('SELECT puzzle_id FROM competition_round_puzzle WHERE round_id = :roundId', ['roundId' => $roundId]);

        return $ids;
    }

    private function roundPuzzleIdOf(string $roundId, string $puzzleId): string
    {
        $id = $this->database->fetchOne(
            'SELECT id FROM competition_round_puzzle WHERE round_id = :roundId AND puzzle_id = :puzzleId',
            ['roundId' => $roundId, 'puzzleId' => $puzzleId],
        );
        self::assertIsString($id);

        return $id;
    }
}
