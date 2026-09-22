<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\EanAlreadyAssigned;
use SpeedPuzzling\Web\Exceptions\InvalidEan;
use SpeedPuzzling\Web\Message\LinkEanToPuzzle;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class LinkEanToPuzzleHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PuzzleRepository $puzzles;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->puzzles = self::getContainer()->get(PuzzleRepository::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testPuzzleWithoutCodeGetsItImmediatelyWithAnApprovedAuditRow(): void
    {
        $this->messageBus->dispatch(new LinkEanToPuzzle(PuzzleFixture::PUZZLE_9000, PlayerFixture::PLAYER_WITH_STRIPE, PuzzleFixture::EAN_UNKNOWN));

        self::assertSame(PuzzleFixture::EAN_UNKNOWN, $this->puzzles->get(PuzzleFixture::PUZZLE_9000)->ean);

        $audit = $this->database->fetchAssociative(
            'SELECT status, proposed_ean, original_ean, reporter_id, reviewed_by_id FROM puzzle_change_request WHERE puzzle_id = :id AND proposed_ean = :ean',
            ['id' => PuzzleFixture::PUZZLE_9000, 'ean' => PuzzleFixture::EAN_UNKNOWN],
        );

        self::assertIsArray($audit);
        self::assertSame('approved', $audit['status']);
        self::assertNull($audit['original_ean']);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $audit['reporter_id']);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $audit['reviewed_by_id']);

        // Idempotent: the same link again changes nothing and adds no second row
        $this->messageBus->dispatch(new LinkEanToPuzzle(PuzzleFixture::PUZZLE_9000, PlayerFixture::PLAYER_WITH_STRIPE, PuzzleFixture::EAN_UNKNOWN));
        $count = $this->database->fetchOne('SELECT count(*)::text FROM puzzle_change_request WHERE puzzle_id = :id', ['id' => PuzzleFixture::PUZZLE_9000]);
        self::assertSame('1', $count);
    }

    public function testPuzzleWithADifferentCodeOnlyGetsAPendingProposalOfTheUnion(): void
    {
        // PUZZLE_300 carries EAN_PUZZLE_300; a second edition code is proposed, not written
        $this->messageBus->dispatch(new LinkEanToPuzzle(PuzzleFixture::PUZZLE_300, PlayerFixture::PLAYER_WITH_STRIPE, '4005556777761'));
        $this->messageBus->dispatch(new LinkEanToPuzzle(PuzzleFixture::PUZZLE_300, PlayerFixture::PLAYER_WITH_STRIPE, '4005556777761'));

        self::assertSame(PuzzleFixture::EAN_PUZZLE_300, $this->puzzles->get(PuzzleFixture::PUZZLE_300)->ean);

        $rows = $this->database->fetchAllAssociative(
            'SELECT status, proposed_ean, original_ean FROM puzzle_change_request WHERE puzzle_id = :id',
            ['id' => PuzzleFixture::PUZZLE_300],
        );

        self::assertCount(1, $rows, 'a retry must not queue a second proposal');
        self::assertSame('pending', $rows[0]['status']);
        self::assertSame(PuzzleFixture::EAN_PUZZLE_300 . ', 4005556777761', $rows[0]['proposed_ean']);
        self::assertSame(PuzzleFixture::EAN_PUZZLE_300, $rows[0]['original_ean']);
    }

    public function testCodeOfAnotherPuzzleIsRefusedEvenWhenThatPuzzleIsHidden(): void
    {
        try {
            $this->messageBus->dispatch(new LinkEanToPuzzle(PuzzleFixture::PUZZLE_9000, PlayerFixture::PLAYER_WITH_STRIPE, PuzzleFixture::EAN_PUZZLE_2000));
            self::fail('Expected EanAlreadyAssigned');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(EanAlreadyAssigned::class, $e->getPrevious());
        }

        // Hide the owner of the code: still refused
        $this->database->executeStatement('UPDATE puzzle SET hide_until = :until WHERE id = :id', ['until' => '2999-01-01 00:00:00', 'id' => PuzzleFixture::PUZZLE_2000]);

        try {
            $this->messageBus->dispatch(new LinkEanToPuzzle(PuzzleFixture::PUZZLE_9000, PlayerFixture::PLAYER_WITH_STRIPE, PuzzleFixture::EAN_PUZZLE_2000));
            self::fail('Expected EanAlreadyAssigned');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(EanAlreadyAssigned::class, $e->getPrevious());
        } finally {
            $this->database->executeStatement('UPDATE puzzle SET hide_until = NULL WHERE id = :id', ['id' => PuzzleFixture::PUZZLE_2000]);
        }

        self::assertNull($this->puzzles->get(PuzzleFixture::PUZZLE_9000)->ean);
    }

    public function testInvalidCodeIsRefused(): void
    {
        try {
            $this->messageBus->dispatch(new LinkEanToPuzzle(PuzzleFixture::PUZZLE_9000, PlayerFixture::PLAYER_WITH_STRIPE, '4005556123456'));
            self::fail('Expected InvalidEan');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(InvalidEan::class, $e->getPrevious());
        }
    }
}
