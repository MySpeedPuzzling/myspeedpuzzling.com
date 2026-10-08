<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\PuzzleChangedMeanwhile;
use SpeedPuzzling\Web\Message\EditPuzzle;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;
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
                nameLanguage: null,
                alternativeNames: new PuzzleNames([new PuzzleName('Alternative Title', null)]),
                manufacturerId: ManufacturerFixture::MANUFACTURER_TREFL,
                piecesCount: 1000,
                eans: EanList::fromStored('4005556123452'),
                brandCodes: BrandCodeList::fromStored('  '),
            ),
            note: ' Checked on the box photo ',
        ));

        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01);
        self::assertSame('Edited Name', $puzzle->name);
        self::assertSame([['name' => 'Alternative Title', 'language' => null]], $puzzle->alternativeNames);
        self::assertSame('Alternative Title', $puzzle->alternativeNames()->legacyAlternativeName());
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
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $decision->decidedById?->toString());
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
                nameLanguage: $puzzle->nameLanguage,
                alternativeNames: $puzzle->alternativeNames(),
                manufacturerId: $puzzle->manufacturer?->id->toString(),
                piecesCount: $puzzle->piecesCount,
                eans: EanList::fromStored($puzzle->ean),
                brandCodes: BrandCodeList::fromStored($puzzle->identificationNumber),
            ),
            note: 'Nothing to see',
        ));

        self::assertSame([], $this->decisions());
        self::assertNull($this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01)->namesChangedAt);
    }

    public function testTheWholeListOfNamesIsSavedAsSentWithTheMainTitlesLanguage(): void
    {
        $this->messageBus->dispatch(new EditPuzzle(
            puzzleId: PuzzleFixture::PUZZLE_1000_02,
            editorId: PlayerFixture::PLAYER_ADMIN,
            values: new PuzzleRecordValues(
                // The Czech box had no English title - its name is the main title now, the old one an other name
                name: PuzzleFixture::NAME_CS_MAGIC_GARDEN,
                nameLanguage: 'cs',
                alternativeNames: new PuzzleNames([
                    new PuzzleName(PuzzleFixture::NAME_DE_MAGIC_GARDEN, 'de'),
                    new PuzzleName('Puzzle 7', 'en'),
                    new PuzzleName('Jardín mágico', 'es'),
                ]),
                manufacturerId: ManufacturerFixture::MANUFACTURER_TREFL,
                piecesCount: 1000,
                eans: EanList::fromStored(null),
                brandCodes: BrandCodeList::fromStored(null),
            ),
        ));

        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_1000_02);
        self::assertSame(PuzzleFixture::NAME_CS_MAGIC_GARDEN, $puzzle->name);
        self::assertSame('cs', $puzzle->nameLanguage);
        self::assertSame([
            ['name' => PuzzleFixture::NAME_DE_MAGIC_GARDEN, 'language' => 'de'],
            ['name' => 'Puzzle 7', 'language' => 'en'],
            ['name' => 'Jardín mágico', 'language' => 'es'],
        ], $puzzle->alternativeNames);
        self::assertSame("\nkouzelna zahrada\nzauberhafter garten\npuzzle 7\njardin magico\n", $puzzle->searchNames);

        $decisions = $this->decisions(PuzzleFixture::PUZZLE_1000_02);
        self::assertCount(1, $decisions);
        $details = $decisions[0]->details;
        self::assertIsArray($details);
        self::assertIsArray($details['before']);
        self::assertIsArray($details['after']);
        self::assertNull($details['before']['nameLanguage']);
        self::assertSame('cs', $details['after']['nameLanguage']);
    }

    public function testASaveOverANewerRecordIsRefusedBeforeAnythingChanges(): void
    {
        $loaded = PuzzleRecordVersion::ofPuzzle($this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01));

        // Somebody else saved in between
        $this->messageBus->dispatch(new EditPuzzle(
            puzzleId: PuzzleFixture::PUZZLE_500_01,
            editorId: PlayerFixture::PLAYER_REGULAR,
            values: $this->puzzle1(piecesCount: 520, recordVersion: $loaded),
        ));
        $this->entityManager->clear();

        try {
            $this->messageBus->dispatch(new EditPuzzle(
                puzzleId: PuzzleFixture::PUZZLE_500_01,
                editorId: PlayerFixture::PLAYER_ADMIN,
                values: $this->puzzle1(name: 'Typed over the old record', recordVersion: $loaded),
            ));
            self::fail('A stale form must be refused.');
        } catch (PuzzleChangedMeanwhile) {
        }

        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01);
        self::assertSame('Puzzle 1', $puzzle->name);
        self::assertSame(520, $puzzle->piecesCount);
        self::assertCount(1, $this->decisions());
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
                nameLanguage: null,
                alternativeNames: new PuzzleNames(array_slice($puzzle->alternativeNames()->all(), 1)),
                manufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                piecesCount: 500,
                eans: EanList::fromStored(PuzzleFixture::EAN_PUZZLE_500_03),
                brandCodes: BrandCodeList::fromStored(null),
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
                nameLanguage: null,
                alternativeNames: new PuzzleNames([new PuzzleName(str_repeat('ř', PuzzleNames::MAX_NAME_LENGTH + 1), 'cs')]),
                manufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                piecesCount: 500,
                eans: EanList::fromStored(null),
                brandCodes: BrandCodeList::fromStored('RB-500-001'),
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
                    nameLanguage: null,
                    alternativeNames: new PuzzleNames([new PuzzleName('Should not be saved', null)]),
                    manufacturerId: ManufacturerFixture::MANUFACTURER_TREFL,
                    piecesCount: 1000,
                    eans: EanList::fromStored('4005556123452'),
                    brandCodes: BrandCodeList::fromStored(null),
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
                    nameLanguage: null,
                    alternativeNames: new PuzzleNames([new PuzzleName('Should not be saved', null)]),
                    manufacturerId: ManufacturerFixture::MANUFACTURER_TREFL,
                    piecesCount: 1000,
                    eans: EanList::fromStored(null),
                    brandCodes: BrandCodeList::fromStored(null),
                ),
            ));
            self::fail('A blank name must be refused.');
        } catch (InvalidPuzzleValues) {
        }

        $puzzle = $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01);
        self::assertSame('Puzzle 1', $puzzle->name);
        self::assertNull($puzzle->alternativeNames()->legacyAlternativeName());
        self::assertSame([], $puzzle->alternativeNames);
        self::assertSame(500, $puzzle->piecesCount);
        self::assertSame([], $this->decisions());
    }

    private function puzzle1(string $name = 'Puzzle 1', int $piecesCount = 500, null|string $recordVersion = null): PuzzleRecordValues
    {
        return new PuzzleRecordValues(
            name: $name,
            nameLanguage: null,
            alternativeNames: new PuzzleNames(),
            manufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            piecesCount: $piecesCount,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored('RB-500-001'),
            recordVersion: $recordVersion,
        );
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
