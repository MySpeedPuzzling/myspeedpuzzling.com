<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Message\EditPuzzle;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class EditPuzzleHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PuzzleRepository $puzzleRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->puzzleRepository = self::getContainer()->get(PuzzleRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAnEditSavesEveryFieldAndIsLoggedWithThePuzzleBeforeAndAfter(): void
    {
        $this->messageBus->dispatch(new EditPuzzle(
            puzzleId: PuzzleFixture::PUZZLE_500_01,
            editorId: PlayerFixture::PLAYER_REGULAR,
            values: new PuzzleRecordValues(
                name: '  Edited Name ',
                alternativeName: 'Alternative Title',
                manufacturerId: ManufacturerFixture::MANUFACTURER_TREFL,
                piecesCount: 1000,
                ean: '4005556123452',
                identificationNumber: '  ',
            ),
            note: ' Checked on the box photo ',
        ));

        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01);
        self::assertSame('Edited Name', $puzzle->name);
        self::assertSame('Alternative Title', $puzzle->alternativeName);
        self::assertSame(ManufacturerFixture::MANUFACTURER_TREFL, $puzzle->manufacturer?->id->toString());
        self::assertSame(1000, $puzzle->piecesCount);
        self::assertSame('4005556123452', $puzzle->ean);
        self::assertNull($puzzle->identificationNumber);

        $decisions = $this->decisions();
        self::assertCount(1, $decisions);
        $decision = $decisions[0];
        self::assertSame(PuzzleModerationAction::PuzzleEdited, $decision->action);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $decision->decidedById->toString());
        self::assertSame('Edited Name', $decision->puzzleName);
        self::assertSame('Checked on the box photo', $decision->note);

        $details = $decision->details;
        self::assertIsArray($details);
        self::assertSame('keep', $details['image']);
        $before = $details['before'];
        $after = $details['after'];
        self::assertIsArray($before);
        self::assertIsArray($after);
        self::assertSame('Puzzle 1', $before['name']);
        self::assertSame('Edited Name', $after['name']);
        self::assertSame(500, $before['piecesCount']);
        self::assertSame(1000, $after['piecesCount']);
        self::assertSame('Trefl', $after['manufacturerName']);
    }

    public function testAnEditThatChangesNothingRecordsNothing(): void
    {
        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01);

        $this->messageBus->dispatch(new EditPuzzle(
            puzzleId: PuzzleFixture::PUZZLE_500_01,
            editorId: PlayerFixture::PLAYER_ADMIN,
            values: new PuzzleRecordValues(
                name: $puzzle->name,
                alternativeName: $puzzle->alternativeName,
                manufacturerId: $puzzle->manufacturer?->id->toString(),
                piecesCount: $puzzle->piecesCount,
                ean: $puzzle->ean,
                identificationNumber: $puzzle->identificationNumber,
            ),
            note: 'Nothing to see',
        ));

        self::assertSame([], $this->decisions());
    }

    public function testInvalidValuesAreRefusedBeforeAnythingChanges(): void
    {
        try {
            $this->messageBus->dispatch(new EditPuzzle(
                puzzleId: PuzzleFixture::PUZZLE_500_01,
                editorId: PlayerFixture::PLAYER_ADMIN,
                values: new PuzzleRecordValues(
                    name: '   ',
                    alternativeName: 'Should not be saved',
                    manufacturerId: ManufacturerFixture::MANUFACTURER_TREFL,
                    piecesCount: 1000,
                    ean: null,
                    identificationNumber: null,
                ),
            ));
            self::fail('A blank name must be refused.');
        } catch (InvalidPuzzleValues) {
        }

        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01);
        self::assertSame('Puzzle 1', $puzzle->name);
        self::assertNull($puzzle->alternativeName);
        self::assertSame(500, $puzzle->piecesCount);
        self::assertSame([], $this->decisions());
    }

    /**
     * @return list<PuzzleModerationDecision>
     */
    private function decisions(): array
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(PuzzleModerationDecision::class)->findBy([
            'puzzleId' => Uuid::fromString(PuzzleFixture::PUZZLE_500_01),
            'action' => PuzzleModerationAction::PuzzleEdited,
        ]);
    }
}
