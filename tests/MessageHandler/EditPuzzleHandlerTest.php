<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
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
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
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
        self::assertSame([['name' => 'Alternative Title', 'language' => null]], $puzzle->alternativeNames);
        self::assertSame('Alternative Title', $puzzle->alternativeName);
        self::assertSame("\nedited name\nalternative title\n", $puzzle->searchNames);
        self::assertNotNull($puzzle->namesChangedAt);
        self::assertSame(ManufacturerFixture::MANUFACTURER_TREFL, $puzzle->manufacturer?->id->toString());
        self::assertSame(1000, $puzzle->piecesCount);
        self::assertSame('4005556123452', $puzzle->ean);
        self::assertNull($puzzle->identificationNumber);
        self::assertSame("\ne:4005556123452\n", $puzzle->searchCodes);

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
        self::assertSame([], $before['alternativeNames']);
        self::assertSame([['name' => 'Alternative Title', 'language' => null]], $after['alternativeNames']);
        self::assertArrayNotHasKey('alternativeName', $after);
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
                alternativeName: $puzzle->alternativeNames()->legacyAlternativeName(),
                manufacturerId: $puzzle->manufacturer?->id->toString(),
                piecesCount: $puzzle->piecesCount,
                ean: $puzzle->ean,
                identificationNumber: $puzzle->identificationNumber,
            ),
            note: 'Nothing to see',
        ));

        self::assertSame([], $this->decisions());
        self::assertNull($this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01)->namesChangedAt);
    }

    public function testTheSingleAlternativeNameFieldEditsTheCzechNameAndKeepsTheOthers(): void
    {
        $edit = fn (null|string $alternativeName) => $this->messageBus->dispatch(new EditPuzzle(
            puzzleId: PuzzleFixture::PUZZLE_1000_02,
            editorId: PlayerFixture::PLAYER_ADMIN,
            values: new PuzzleRecordValues(
                name: 'Puzzle 7',
                alternativeName: $alternativeName,
                manufacturerId: ManufacturerFixture::MANUFACTURER_TREFL,
                piecesCount: 1000,
                ean: null,
                identificationNumber: null,
            ),
        ));

        // The form shows the Czech name and sends it back unchanged: both names stay, nothing is logged
        $edit(PuzzleFixture::NAME_CS_MAGIC_GARDEN);
        self::assertSame([], $this->decisions(PuzzleFixture::PUZZLE_1000_02));
        self::assertCount(2, $this->puzzleRepository->get(PuzzleFixture::PUZZLE_1000_02)->alternativeNames);

        // Re-spelled: still the Czech name
        $edit('KOUZELNÁ ZAHRADA');
        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_1000_02);
        self::assertSame([
            ['name' => 'KOUZELNÁ ZAHRADA', 'language' => 'cs'],
            ['name' => PuzzleFixture::NAME_DE_MAGIC_GARDEN, 'language' => 'de'],
        ], $puzzle->alternativeNames);

        // Another name: nothing says it is Czech
        $edit('Kouzelná zahrádka');
        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_1000_02);
        self::assertSame([
            ['name' => 'Kouzelná zahrádka', 'language' => null],
            ['name' => PuzzleFixture::NAME_DE_MAGIC_GARDEN, 'language' => 'de'],
        ], $puzzle->alternativeNames);
        self::assertSame('Kouzelná zahrádka', $puzzle->alternativeName);
        self::assertSame("\npuzzle 7\nkouzelna zahradka\nzauberhafter garten\n", $puzzle->searchNames);

        // Emptied: only the name the field showed goes
        $edit('  ');
        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_1000_02);
        self::assertSame([['name' => PuzzleFixture::NAME_DE_MAGIC_GARDEN, 'language' => 'de']], $puzzle->alternativeNames);
        self::assertSame(PuzzleFixture::NAME_DE_MAGIC_GARDEN, $puzzle->alternativeName);
        self::assertSame("\npuzzle 7\nzauberhafter garten\n", $puzzle->searchNames);

        $decisions = $this->decisions(PuzzleFixture::PUZZLE_1000_02);
        self::assertCount(3, $decisions);
        $afterSnapshots = array_map(static fn (PuzzleModerationDecision $decision): mixed => $decision->details['after'] ?? null, $decisions);
        self::assertContains([['name' => PuzzleFixture::NAME_DE_MAGIC_GARDEN, 'language' => 'de']], array_map(
            static fn (mixed $after): mixed => is_array($after) ? $after['alternativeNames'] : null,
            $afterSnapshots,
        ));
    }

    public function testANameIsRemovedFromAPuzzleWithMoreNamesThanAFormMayAdd(): void
    {
        // A merge collects names without a cap
        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_03);
        $puzzle->changeNames('Puzzle 3', null, new PuzzleNames(array_map(
            static fn (int $i): PuzzleName => new PuzzleName('Merged name ' . $i, null),
            range(1, PuzzleNames::FORM_MAX_NAMES + 5),
        )), new DateTimeImmutable());
        $this->entityManager->flush();

        $this->messageBus->dispatch(new EditPuzzle(
            puzzleId: PuzzleFixture::PUZZLE_500_03,
            editorId: PlayerFixture::PLAYER_ADMIN,
            values: new PuzzleRecordValues(
                name: 'Puzzle 3',
                alternativeName: '',
                manufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                piecesCount: 500,
                ean: PuzzleFixture::EAN_PUZZLE_500_03,
                identificationNumber: null,
            ),
        ));

        $names = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_03)->alternativeNames;
        self::assertCount(PuzzleNames::FORM_MAX_NAMES + 4, $names);
        self::assertSame('Merged name 2', $names[0]['name']);
    }

    public function testAnAlternativeNameTooLongIsRefused(): void
    {
        $this->expectException(InvalidPuzzleValues::class);

        $this->messageBus->dispatch(new EditPuzzle(
            puzzleId: PuzzleFixture::PUZZLE_500_01,
            editorId: PlayerFixture::PLAYER_ADMIN,
            values: new PuzzleRecordValues(
                name: 'Puzzle 1',
                alternativeName: str_repeat('ř', PuzzleNames::MAX_NAME_LENGTH + 1),
                manufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                piecesCount: 500,
                ean: null,
                identificationNumber: 'RB-500-001',
            ),
        ));
    }

    public function testANameTooLongIsRefusedBeforeAnythingChanges(): void
    {
        try {
            $this->messageBus->dispatch(new EditPuzzle(
                puzzleId: PuzzleFixture::PUZZLE_500_01,
                editorId: PlayerFixture::PLAYER_ADMIN,
                values: new PuzzleRecordValues(
                    name: str_repeat('a', 256),
                    alternativeName: 'Should not be saved',
                    manufacturerId: ManufacturerFixture::MANUFACTURER_TREFL,
                    piecesCount: 1000,
                    ean: '4005556123452',
                    identificationNumber: null,
                ),
            ));
            self::fail('A name over 255 characters must be refused.');
        } catch (InvalidPuzzleValues) {
        }

        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01);
        self::assertSame('Puzzle 1', $puzzle->name);
        self::assertSame([], $puzzle->alternativeNames);
        self::assertSame(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, $puzzle->manufacturer?->id->toString());
        self::assertNull($puzzle->ean);
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
        self::assertSame([], $puzzle->alternativeNames);
        self::assertSame(500, $puzzle->piecesCount);
        self::assertSame([], $this->decisions());
    }

    /**
     * @return list<PuzzleModerationDecision>
     */
    private function decisions(string $puzzleId = PuzzleFixture::PUZZLE_500_01): array
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(PuzzleModerationDecision::class)->findBy([
            'puzzleId' => Uuid::fromString($puzzleId),
            'action' => PuzzleModerationAction::PuzzleEdited,
        ]);
    }
}
