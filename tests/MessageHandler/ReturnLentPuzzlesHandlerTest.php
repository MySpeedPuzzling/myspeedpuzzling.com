<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\MultiscanBatchRejected;
use SpeedPuzzling\Web\Message\ReturnLentPuzzles;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class ReturnLentPuzzlesHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetUserPuzzleStatuses $statuses;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->statuses = self::getContainer()->get(GetUserPuzzleStatuses::class);
    }

    public function testOwnerClosesLendsToDifferentPeopleAndHolderGivesBackInOneBatch(): void
    {
        // PLAYER_WITH_STRIPE owns LENT_01 (PUZZLE_2000 → PLAYER_REGULAR) and LENT_02 (PUZZLE_1500_01 → "Jane Doe"),
        // and holds LENT_05 (PUZZLE_1500_02, owned by PLAYER_REGULAR)
        $this->messageBus->dispatch(new ReturnLentPuzzles(
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleIds: [PuzzleFixture::PUZZLE_2000, PuzzleFixture::PUZZLE_1500_01, PuzzleFixture::PUZZLE_1500_02],
        ));

        $this->statuses->reset();
        $statuses = $this->statuses->byPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertArrayNotHasKey(PuzzleFixture::PUZZLE_2000, $statuses->lentPuzzleIds);
        self::assertArrayNotHasKey(PuzzleFixture::PUZZLE_1500_01, $statuses->lentPuzzleIds);
        self::assertArrayNotHasKey(PuzzleFixture::PUZZLE_1500_02, $statuses->borrowedPuzzleIds);
    }

    public function testPuzzleWithoutAnOpenLendRejectsTheWholeBatch(): void
    {
        try {
            $this->messageBus->dispatch(new ReturnLentPuzzles(
                actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
                puzzleIds: [PuzzleFixture::PUZZLE_500_03, PuzzleFixture::PUZZLE_6000],
            ));
            self::fail('Expected MultiscanBatchRejected');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(MultiscanBatchRejected::class, $previous);
            self::assertSame(PuzzleFixture::PUZZLE_6000, $previous->puzzleId);
            self::assertSame('not_lent', $previous->reason);
        }

        $this->statuses->reset();
        $statuses = $this->statuses->byPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertArrayHasKey(PuzzleFixture::PUZZLE_500_03, $statuses->lentPuzzleIds, 'LENT_04 untouched');
    }
}
