<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\RejectPuzzleChangeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\ExifPhotos;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/upload-image-metadata.md - the summary of an uploaded picture's metadata is stored with the picture on
 * the row that owns it, follows the picture when it moves, and goes with the picture and with its owner.
 */
final class UploadImageMetadataTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testAnAddedPuzzleKeepsTheSummaryOfItsPhotoAndNothingPersonal(): void
    {
        $puzzleId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzle(
            puzzleId: $puzzleId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleName: 'Metadata test puzzle',
            brand: 'Metadata test brand',
            piecesCount: 1000,
            puzzlePhoto: $this->photo(ExifPhotos::iphoneJpeg(64, 48)),
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
        ));

        $json = $this->database->fetchOne('SELECT image_upload_metadata FROM puzzle WHERE id = :id', ['id' => $puzzleId->toString()]);
        self::assertIsString($json);
        $stored = json_decode($json, true);
        self::assertIsArray($stored);

        self::assertTrue($stored['cameraExif']);
        self::assertSame('2026-10-21T14:03:12', $stored['capturedAt']);
        self::assertSame('+02:00', $stored['capturedAtOffset']);
        self::assertSame('phone', $stored['software']);
        self::assertSame('Apple', $stored['make']);
        self::assertSame(64, $stored['width']);
        self::assertSame('JPEG', $stored['format']);

        foreach ([ExifPhotos::SERIAL_NUMBER, ExifPhotos::OWNER, ExifPhotos::USER_COMMENT, ExifPhotos::MODEL, 'GPS'] as $secret) {
            self::assertStringNotContainsStringIgnoringCase($secret, $json);
        }
    }

    public function testAFinishPhotoKeepsItsSummaryWithTheTime(): void
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_1500_02,
            competitionId: null,
            time: '01:00:00',
            comment: null,
            finishedPuzzlesPhoto: $this->photo(ExifPhotos::iphoneJpeg()),
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        $stored = $this->timeMetadata($timeId->toString());
        self::assertNotNull($stored);
        self::assertSame('2026-10-21T14:03:12', $stored['capturedAt']);
        self::assertSame('+02:00', $stored['capturedAtOffset']);
    }

    public function testAnotherPhotoReplacesTheSummaryAndAnEditWithoutOneKeepsIt(): void
    {
        // TIME_01: PLAYER_REGULAR's solo time on PUZZLE_500_01, no photo
        $this->editTime01($this->photo(ExifPhotos::iphoneJpeg()));
        $first = $this->timeMetadata(PuzzleSolvingTimeFixture::TIME_01);
        self::assertNotNull($first);
        self::assertTrue($first['cameraExif']);

        $this->editTime01(null);
        self::assertSame($first, $this->timeMetadata(PuzzleSolvingTimeFixture::TIME_01), 'The same photo, the same summary');

        $this->editTime01($this->photo(ExifPhotos::jpeg(30, 20, null)));
        $replaced = $this->timeMetadata(PuzzleSolvingTimeFixture::TIME_01);
        self::assertNotNull($replaced);
        self::assertFalse($replaced['exif']);
        self::assertNull($replaced['capturedAt'], 'Nothing of the previous photo is left');
        self::assertSame(30, $replaced['width']);
    }

    public function testDeletingThePlayerTakesTheSummariesAlong(): void
    {
        // TIME_01 is PLAYER_REGULAR's solo time, TIME_12 the pair time they own with PLAYER_PRIVATE
        $this->editTime01($this->photo(ExifPhotos::iphoneJpeg()));
        $this->database->executeStatement(
            "UPDATE puzzle_solving_time SET finished_puzzle_photo = 'players/pair-photo.jpg', finished_puzzle_photo_upload_metadata = :metadata WHERE id = :id",
            ['id' => PuzzleSolvingTimeFixture::TIME_12, 'metadata' => $this->database->fetchOne('SELECT finished_puzzle_photo_upload_metadata FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_01])],
        );
        self::assertNotNull($this->timeMetadata(PuzzleSolvingTimeFixture::TIME_12));

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));

        self::assertFalse($this->database->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_01]));

        // The pair time stays with the other member, its photo too - not what the photo's file told about the device
        $pairTime = $this->database->fetchAssociative(
            'SELECT finished_puzzle_photo, finished_puzzle_photo_upload_metadata FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_12],
        );
        self::assertIsArray($pairTime);
        self::assertSame('players/pair-photo.jpg', $pairTime['finished_puzzle_photo']);
        self::assertNull($pairTime['finished_puzzle_photo_upload_metadata']);
    }

    public function testAnApprovedProposalHandsTheSummaryToThePuzzle(): void
    {
        $changeRequestId = $this->proposePhoto(PuzzleFixture::PUZZLE_500_02);
        $proposed = $this->database->fetchOne('SELECT proposed_image_upload_metadata FROM puzzle_change_request WHERE id = :id', ['id' => $changeRequestId]);
        self::assertIsString($proposed);

        $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: PuzzleFixture::PUZZLE_500_02,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            selectedFields: ['image'],
        ));

        $puzzle = self::getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_500_02);
        self::assertNotNull($puzzle->image);
        self::assertStringNotContainsString('proposal-', $puzzle->image);
        self::assertSame('2026-10-21T14:03:12', $puzzle->imageUploadMetadata()?->capturedAt);
        self::assertSame(json_decode($proposed, true), $this->jsonColumn('SELECT image_upload_metadata FROM puzzle WHERE id = :id', PuzzleFixture::PUZZLE_500_02));

        // The proposal's object moved - the request keeps no copy
        self::assertNull($this->jsonColumn('SELECT proposed_image_upload_metadata FROM puzzle_change_request WHERE id = :id', $changeRequestId));
    }

    public function testARejectedProposalLosesItsSummaryWithItsPicture(): void
    {
        $changeRequestId = $this->proposePhoto(PuzzleFixture::PUZZLE_500_03);

        $this->messageBus->dispatch(new RejectPuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: PuzzleFixture::PUZZLE_500_03,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            rejectionReason: 'Not this box',
        ));

        self::assertNull($this->jsonColumn('SELECT proposed_image_upload_metadata FROM puzzle_change_request WHERE id = :id', $changeRequestId));
    }

    private function editTime01(null|UploadedFile $photo): void
    {
        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleSolvingTimeId: PuzzleSolvingTimeFixture::TIME_01,
            competitionId: null,
            time: '00:30:00',
            comment: null,
            groupPlayers: [],
            finishedAt: null,
            finishedPuzzlesPhoto: $photo,
            firstAttempt: false,
            unboxed: false,
        ));
    }

    private function proposePhoto(string $puzzleId): string
    {
        $changeRequestId = Uuid::uuid7()->toString();
        $puzzle = self::getContainer()->get(PuzzleRepository::class)->get($puzzleId);

        $this->messageBus->dispatch(new SubmitPuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: $puzzleId,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            proposedName: $puzzle->name,
            proposedBrand: $puzzle->manufacturer?->id->toString(),
            proposedPiecesCount: $puzzle->piecesCount,
            proposedEans: $puzzle->eans(),
            proposedBrandCodes: $puzzle->brandCodes(),
            proposedPhoto: $this->photo(ExifPhotos::iphoneJpeg()),
            originalAlternativeNames: $puzzle->alternativeNames(),
            originalNameLanguage: $puzzle->nameLanguage,
        ));

        return $changeRequestId;
    }

    /**
     * @return null|array<mixed>
     */
    private function timeMetadata(string $timeId): null|array
    {
        return $this->jsonColumn('SELECT finished_puzzle_photo_upload_metadata FROM puzzle_solving_time WHERE id = :id', $timeId);
    }

    /**
     * @return null|array<mixed>
     */
    private function jsonColumn(string $sql, string $id): null|array
    {
        $json = $this->database->fetchOne($sql, ['id' => $id]);

        if ($json === null) {
            return null;
        }

        self::assertIsString($json);
        $data = json_decode($json, true);
        self::assertIsArray($data);

        return $data;
    }

    private function photo(string $content): UploadedFile
    {
        $path = ExifPhotos::file($content);
        $this->files[] = $path;

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }
}
