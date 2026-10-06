<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\AddCompetitionRoundWithPuzzles;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The internal API's round with its puzzles is one transaction: whatever refuses the puzzles - a puzzle that became
 * secret or was deleted after the controller's own check - leaves no round behind.
 */
final class AddCompetitionRoundWithPuzzlesHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testCreatesTheRoundWithItsPuzzles(): void
    {
        $roundId = Uuid::uuid7();
        $this->messageBus->dispatch(new AddCompetitionRoundWithPuzzles($this->round($roundId), [PuzzleFixture::PUZZLE_300]));

        self::assertSame(
            [PuzzleFixture::PUZZLE_300],
            $this->database->fetchFirstColumn('SELECT puzzle_id FROM competition_round_puzzle WHERE round_id = :id', ['id' => $roundId->toString()]),
        );
    }

    public function testAHiddenPuzzleLeavesNoRoundBehind(): void
    {
        // Became secret after the controller checked it (here: before the dispatch)
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $puzzle = $entityManager->find(Puzzle::class, PuzzleFixture::PUZZLE_300);
        self::assertNotNull($puzzle);
        $puzzle->approved = false;
        $puzzle->keepSecretUntil(new DateTimeImmutable('+5 days'), new DateTimeImmutable('+5 days'));
        $entityManager->flush();
        $entityManager->clear();

        $roundId = Uuid::uuid7();

        try {
            $this->messageBus->dispatch(new AddCompetitionRoundWithPuzzles($this->round($roundId), [PuzzleFixture::PUZZLE_300]));
            self::fail('A hidden puzzle is never attached unhidden');
        } catch (PuzzleIsStillSecret) {
        }

        self::assertFalse($this->roundExists($roundId));
    }

    public function testADeletedPuzzleLeavesNoRoundBehind(): void
    {
        $roundId = Uuid::uuid7();

        try {
            $this->messageBus->dispatch(new AddCompetitionRoundWithPuzzles($this->round($roundId), ['018d0003-0000-0000-0000-00000000ffff']));
            self::fail('An unknown puzzle refuses the list');
        } catch (PuzzleNotFound) {
        }

        self::assertFalse($this->roundExists($roundId));
    }

    private function round(UuidInterface $roundId): AddCompetitionRound
    {
        return new AddCompetitionRound(
            roundId: $roundId,
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            name: 'Atomic Round ' . $roundId->toString(),
            minutesLimit: 60,
            startsAt: new DateTimeImmutable('+20 days'),
            timezone: 'Europe/Prague',
            badgeBackgroundColor: null,
            badgeTextColor: null,
        );
    }

    private function roundExists(UuidInterface $roundId): bool
    {
        return $this->database->fetchOne('SELECT 1 FROM competition_round WHERE id = :id', ['id' => $roundId->toString()]) !== false;
    }
}
