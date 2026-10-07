<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseChanged;
use SpeedPuzzling\Web\Message\ConfirmPuzzlePiecesCount;
use SpeedPuzzling\Web\Repository\SuspiciousTimePuzzleConfirmationRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/suspicious-time-review.md, "The piece count is wrong": "Piece count is right" on a puzzle card.
 */
final class ConfirmPuzzlePiecesCountHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testThePiecesCountIsConfirmedAndLogged(): void
    {
        $this->messageBus->dispatch(new ConfirmPuzzlePiecesCount(SuspiciousTimesFixture::PUZZLE_HARBOUR, PlayerFixture::PLAYER_ADMIN, 4000));
        $this->entityManager->clear();

        $confirmation = self::getContainer()->get(SuspiciousTimePuzzleConfirmationRepository::class)->find(SuspiciousTimesFixture::PUZZLE_HARBOUR);
        self::assertNotNull($confirmation);
        self::assertSame(4000, $confirmation->piecesCount);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $confirmation->confirmedById?->toString());

        $decision = $this->database->fetchAssociative(
            'SELECT decision, time_id, decided_by_id FROM suspicious_time_decision WHERE puzzle_id = :puzzleId',
            ['puzzleId' => SuspiciousTimesFixture::PUZZLE_HARBOUR],
        );
        self::assertSame(['decision' => 'pieces_confirmed', 'time_id' => null, 'decided_by_id' => PlayerFixture::PLAYER_ADMIN], $decision);
    }

    public function testAConfirmationOfAnOlderPiecesCountIsRenewed(): void
    {
        $this->messageBus->dispatch(new ConfirmPuzzlePiecesCount(SuspiciousTimesFixture::PUZZLE_HARBOUR, PlayerFixture::PLAYER_ADMIN, 4000));
        $this->database->executeStatement('UPDATE puzzle SET pieces_count = 3000 WHERE id = :puzzleId', ['puzzleId' => SuspiciousTimesFixture::PUZZLE_HARBOUR]);
        $this->entityManager->clear();

        $this->messageBus->dispatch(new ConfirmPuzzlePiecesCount(SuspiciousTimesFixture::PUZZLE_HARBOUR, PlayerFixture::PLAYER_REGULAR, 3000));
        $this->entityManager->clear();

        $confirmation = self::getContainer()->get(SuspiciousTimePuzzleConfirmationRepository::class)->find(SuspiciousTimesFixture::PUZZLE_HARBOUR);
        self::assertNotNull($confirmation);
        self::assertSame(3000, $confirmation->piecesCount);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $confirmation->confirmedById?->toString());
        self::assertSame(2, $this->database->fetchOne('SELECT COUNT(*) FROM suspicious_time_decision WHERE puzzle_id = :puzzleId', ['puzzleId' => SuspiciousTimesFixture::PUZZLE_HARBOUR]));
    }

    public function testAPuzzleACompetitionKeepsSecretIsRefused(): void
    {
        // The fixture puzzles are not approved - hidden until later, Harbour Lights is a competition's secret
        $this->database->executeStatement("UPDATE puzzle SET hide_until = NOW() + INTERVAL '1 day' WHERE id = :puzzleId", ['puzzleId' => SuspiciousTimesFixture::PUZZLE_HARBOUR]);

        try {
            $this->messageBus->dispatch(new ConfirmPuzzlePiecesCount(SuspiciousTimesFixture::PUZZLE_HARBOUR, PlayerFixture::PLAYER_ADMIN, 4000));
            self::fail('The confirmation should have been refused.');
        } catch (PuzzleIsStillSecret) {
        }

        $this->entityManager->clear();
        self::assertNull(self::getContainer()->get(SuspiciousTimePuzzleConfirmationRepository::class)->find(SuspiciousTimesFixture::PUZZLE_HARBOUR));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM suspicious_time_decision WHERE puzzle_id = :puzzleId', ['puzzleId' => SuspiciousTimesFixture::PUZZLE_HARBOUR]));
    }

    public function testAPiecesCountChangedMeanwhileIsRefused(): void
    {
        try {
            $this->messageBus->dispatch(new ConfirmPuzzlePiecesCount(SuspiciousTimesFixture::PUZZLE_HARBOUR, PlayerFixture::PLAYER_ADMIN, 3000));
            self::fail('The confirmation should have been refused.');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(SuspiciousTimeCaseChanged::class, $exception->getPrevious());
        }

        self::assertNull(self::getContainer()->get(SuspiciousTimePuzzleConfirmationRepository::class)->find(SuspiciousTimesFixture::PUZZLE_HARBOUR));
    }
}
