<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

final class SubmitPuzzleChangeRequestHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PuzzleChangeRequestRepository $changeRequestRepository;
    private PuzzleRepository $puzzleRepository;
    private Filesystem $filesystem;
    /** @var list<string> */
    private array $filesToCleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->changeRequestRepository = $container->get(PuzzleChangeRequestRepository::class);
        $this->puzzleRepository = $container->get(PuzzleRepository::class);
        $this->filesystem = $container->get(Filesystem::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->filesToCleanup as $path) {
            if ($this->filesystem->fileExists($path)) {
                $this->filesystem->delete($path);
            }
        }

        parent::tearDown();
    }

    public function testSubmittingChangeRequestCreatesEntity(): void
    {
        $changeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(
            new SubmitPuzzleChangeRequest(
                changeRequestId: $changeRequestId,
                puzzleId: PuzzleFixture::PUZZLE_500_01,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                proposedName: 'New Puzzle Name',
                proposedManufacturerId: ManufacturerFixture::MANUFACTURER_TREFL,
                proposedPiecesCount: 600,
                proposedEans: EanList::fromInputs(['01234567890123']),
                proposedBrandCodes: BrandCodeList::fromInputs(['new-001']),
                proposedPhoto: null,
                originalAlternativeNames: new PuzzleNames(),
                originalNameLanguage: null,
            ),
        );

        $changeRequest = $this->changeRequestRepository->get($changeRequestId);

        // Verify the change request was created with correct values
        self::assertSame(PuzzleReportStatus::Pending, $changeRequest->status);
        self::assertSame('New Puzzle Name', $changeRequest->proposedName);
        self::assertSame(600, $changeRequest->proposedPiecesCount);
        self::assertSame('1234567890123', $changeRequest->proposedEan);
        self::assertSame('NEW-001', $changeRequest->proposedIdentificationNumber);
        self::assertNotNull($changeRequest->proposedManufacturer);
        self::assertSame(ManufacturerFixture::MANUFACTURER_TREFL, $changeRequest->proposedManufacturer->id->toString());

        // Verify original values were captured
        self::assertSame('Puzzle 1', $changeRequest->originalName);
        self::assertSame(500, $changeRequest->originalPiecesCount);
        self::assertNull($changeRequest->reviewedAt);
        self::assertNull($changeRequest->reviewedBy);
    }

    public function testSubmittingChangeRequestWithImageUsesProposalPrefix(): void
    {
        $changeRequestId = Uuid::uuid7()->toString();

        // Create a valid JPEG image file using GD
        $imagePath = tempnam(sys_get_temp_dir(), 'puzzle_test_') . '.jpg';
        $image = imagecreatetruecolor(10, 10);
        assert($image !== false);
        imagejpeg($image, $imagePath);

        $uploadedFile = new UploadedFile($imagePath, 'my-puzzle-photo.jpg', 'image/jpeg', null, true);

        $this->messageBus->dispatch(
            new SubmitPuzzleChangeRequest(
                changeRequestId: $changeRequestId,
                puzzleId: PuzzleFixture::PUZZLE_500_01,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                proposedName: 'Puzzle With Image',
                proposedManufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                proposedPiecesCount: 500,
                proposedEans: $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01)->eans(),
                proposedBrandCodes: $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_01)->brandCodes(),
                proposedPhoto: $uploadedFile,
                originalAlternativeNames: new PuzzleNames(),
                originalNameLanguage: null,
            ),
        );

        $changeRequest = $this->changeRequestRepository->get($changeRequestId);

        $expectedPath = "proposal-{$changeRequestId}.jpg";
        $this->filesToCleanup[] = $expectedPath;

        self::assertSame($expectedPath, $changeRequest->proposedImage);
        self::assertTrue($this->filesystem->fileExists($expectedPath));
    }

    public function testSubmittingChangeRequestWithoutManufacturerChange(): void
    {
        $changeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(
            new SubmitPuzzleChangeRequest(
                changeRequestId: $changeRequestId,
                puzzleId: PuzzleFixture::PUZZLE_500_02,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                proposedName: 'Updated Name Only',
                proposedManufacturerId: null,
                proposedPiecesCount: 500,
                proposedEans: $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_02)->eans(),
                proposedBrandCodes: $this->puzzleRepository->get(PuzzleFixture::PUZZLE_500_02)->brandCodes(),
                proposedPhoto: null,
                originalAlternativeNames: new PuzzleNames(),
                originalNameLanguage: null,
            ),
        );

        $changeRequest = $this->changeRequestRepository->get($changeRequestId);

        self::assertSame(PuzzleReportStatus::Pending, $changeRequest->status);
        self::assertSame('Updated Name Only', $changeRequest->proposedName);
        self::assertNull($changeRequest->proposedManufacturer);
        self::assertNull($changeRequest->proposedEan);
        self::assertNull($changeRequest->proposedIdentificationNumber);
    }

    public function testProposedNamesAreKeptAsThePuzzleWouldStoreThemWithTheNamesWhenProposed(): void
    {
        $changeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new SubmitPuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: PuzzleFixture::PUZZLE_1000_02,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            proposedName: 'Magic Garden',
            proposedManufacturerId: null,
            proposedPiecesCount: 1000,
            proposedEans: $this->puzzleRepository->get(PuzzleFixture::PUZZLE_1000_02)->eans(),
            proposedBrandCodes: $this->puzzleRepository->get(PuzzleFixture::PUZZLE_1000_02)->brandCodes(),
            proposedPhoto: null,
            originalAlternativeNames: new PuzzleNames([
                new PuzzleName(PuzzleFixture::NAME_CS_MAGIC_GARDEN, 'cs'),
                new PuzzleName(PuzzleFixture::NAME_DE_MAGIC_GARDEN, 'de'),
            ]),
            originalNameLanguage: null,
            proposedAlternativeNames: new PuzzleNames([
                new PuzzleName('  Puzzle   7 ', null),
                new PuzzleName(PuzzleFixture::NAME_CS_MAGIC_GARDEN, 'CS'),
                // The proposed main title again - dropped like the puzzle drops it
                new PuzzleName('magic garden', 'en'),
            ]),
            proposedNameLanguage: null,
        ));

        $changeRequest = $this->changeRequestRepository->get($changeRequestId);

        self::assertSame([
            ['name' => 'Puzzle 7', 'language' => null],
            ['name' => PuzzleFixture::NAME_CS_MAGIC_GARDEN, 'language' => 'cs'],
        ], $changeRequest->proposedAlternativeNames);
        self::assertNull($changeRequest->proposedNameLanguage);
        self::assertSame([
            ['name' => PuzzleFixture::NAME_CS_MAGIC_GARDEN, 'language' => 'cs'],
            ['name' => PuzzleFixture::NAME_DE_MAGIC_GARDEN, 'language' => 'de'],
        ], $changeRequest->originalAlternativeNames);
        self::assertNull($changeRequest->originalNameLanguage);
    }

    public function testWithoutProposedNamesTheNamesAreNoPartOfTheProposal(): void
    {
        $changeRequestId = Uuid::uuid7()->toString();

        $this->messageBus->dispatch(new SubmitPuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: PuzzleFixture::PUZZLE_1000_02,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            proposedName: 'Puzzle 7',
            proposedManufacturerId: null,
            proposedPiecesCount: 1500,
            proposedEans: $this->puzzleRepository->get(PuzzleFixture::PUZZLE_1000_02)->eans(),
            proposedBrandCodes: $this->puzzleRepository->get(PuzzleFixture::PUZZLE_1000_02)->brandCodes(),
            proposedPhoto: null,
            originalAlternativeNames: new PuzzleNames([
                new PuzzleName(PuzzleFixture::NAME_CS_MAGIC_GARDEN, 'cs'),
                new PuzzleName(PuzzleFixture::NAME_DE_MAGIC_GARDEN, 'de'),
            ]),
            originalNameLanguage: null,
            proposedNameLanguage: 'cs',
        ));

        $changeRequest = $this->changeRequestRepository->get($changeRequestId);

        self::assertNull($changeRequest->proposedAlternativeNames);
        self::assertNull($changeRequest->proposedNameLanguage);
        self::assertCount(2, $changeRequest->originalAlternativeNames ?? []);
        self::assertTrue($changeRequest->proposedNamesDiff()->isEmpty());
    }

    public function testTheOriginalNamesAreTheOnesTheProposalWasMadeAgainst(): void
    {
        $changeRequestId = Uuid::uuid7()->toString();

        // The player saw the Czech name only - the German one came later; the proposal adds a Spanish one
        $this->messageBus->dispatch(new SubmitPuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: PuzzleFixture::PUZZLE_1000_02,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            proposedName: 'Puzzle 7',
            proposedManufacturerId: null,
            proposedPiecesCount: 1000,
            proposedEans: $this->puzzleRepository->get(PuzzleFixture::PUZZLE_1000_02)->eans(),
            proposedBrandCodes: $this->puzzleRepository->get(PuzzleFixture::PUZZLE_1000_02)->brandCodes(),
            proposedPhoto: null,
            originalAlternativeNames: new PuzzleNames([new PuzzleName(PuzzleFixture::NAME_CS_MAGIC_GARDEN, 'cs')]),
            originalNameLanguage: 'it',
            proposedAlternativeNames: new PuzzleNames([
                new PuzzleName(PuzzleFixture::NAME_CS_MAGIC_GARDEN, 'cs'),
                new PuzzleName('Jardín mágico', 'es'),
            ]),
            proposedNameLanguage: 'it',
        ));

        $changeRequest = $this->changeRequestRepository->get($changeRequestId);

        self::assertSame([['name' => PuzzleFixture::NAME_CS_MAGIC_GARDEN, 'language' => 'cs']], $changeRequest->originalAlternativeNames);
        self::assertSame('it', $changeRequest->originalNameLanguage);

        // Applied to the puzzle as it is, the German name stays: nothing proposed removing it
        $diff = $changeRequest->proposedNamesDiff();
        self::assertSame([], $diff->removed);
        self::assertSame([], $diff->changed);
        self::assertEquals([new PuzzleName('Jardín mágico', 'es')], $diff->added);
    }
}
