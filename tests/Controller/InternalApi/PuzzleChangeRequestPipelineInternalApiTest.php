<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Doctrine\DBAL\Connection;
use League\Flysystem\Filesystem;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\PuzzleOriginalImageArchive;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Tests\TestDouble\ToggleableFailingFilesystemAdapter;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleChangeRequestSource;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/internal-api.md, "Puzzle change requests": the image pipeline files proposals with a picture and its
 * metadata (once per picture and tag, ever; only for the puzzle's picture now; never for a hidden one), lists them with
 * their decisions, decides them with a note, and an approved pipeline picture keeps the original in the archive.
 */
final class PuzzleChangeRequestPipelineInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    private const string PUZZLE = PuzzleFixture::PUZZLE_1000_03;

    private const string OTHER_PUZZLE = PuzzleFixture::PUZZLE_1000_04;

    private const array IMAGES = [
        PuzzleFixture::PUZZLE_1000_03 => 'box-1000-03-018d0003-bbbb0001.jpg',
        PuzzleFixture::PUZZLE_1000_04 => 'box-1000-04-018d0003-bbbb0002.jpg',
        PuzzleFixture::PUZZLE_HIDDEN_IMAGE => '0123456789abcdef0123456789abcdef.jpg',
    ];

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        // One container for the whole test - the in-memory storage keeps what a request wrote
        $this->browser->disableReboot();

        foreach (self::IMAGES as $puzzleId => $image) {
            $this->database()->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => $image, 'id' => $puzzleId]);
            $this->filesystem()->write($image, 'original picture of ' . $puzzleId);
        }
    }

    protected function tearDown(): void
    {
        $this->s3Adapter()->setFailing(false);

        parent::tearDown();
    }

    public function testEveryEndpointNeedsTheToken(): void
    {
        self::callInternalApi($this->browser, 'GET', '/internal-api/puzzle-change-requests', token: null);
        self::assertResponseStatusCodeSame(401);
    }

    public function testFilesAnImageOnlyPipelineProposalStoredLikeTheWebFormsPhoto(): void
    {
        $answer = $this->propose(self::PUZZLE);
        self::assertResponseStatusCodeSame(201);
        self::assertIsString($answer['changeRequestId']);

        $changeRequest = $this->changeRequests()->get($answer['changeRequestId']);
        self::assertSame(PuzzleChangeRequestSource::ImagePipeline, $changeRequest->source);
        self::assertSame(['artwork_crop'], $changeRequest->tags);
        self::assertSame(0.97, $changeRequest->confidence);
        self::assertSame('artwork-crop 1.0.0', $changeRequest->modelVersion);
        self::assertSame(PuzzleImageFingerprint::of(self::IMAGES[self::PUZZLE]), $changeRequest->sourceImageFingerprint);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $changeRequest->reporter->id->toString());
        self::assertSame(self::IMAGES[self::PUZZLE], $changeRequest->originalImage);
        // Nothing else changes - the name stays the puzzle's
        self::assertSame($changeRequest->originalName, $changeRequest->proposedName);

        // The picture went the web form's way: a proposal file with its ratio
        self::assertSame('proposal-' . $answer['changeRequestId'] . '.jpg', $changeRequest->proposedImage);
        self::assertTrue($this->filesystem()->fileExists($changeRequest->proposedImage));
        self::assertNotNull($changeRequest->proposedImageRatio);
        self::assertEqualsWithDelta(4 / 3, $changeRequest->proposedImageRatio, 0.01);
    }

    public function testThePipelineProposesAPictureOncePerTagEvenAfterARejection(): void
    {
        $first = $this->propose(self::PUZZLE);
        self::assertResponseStatusCodeSame(201);
        self::assertIsString($first['changeRequestId']);

        self::callInternalApi($this->browser, 'POST', '/internal-api/puzzle-change-requests/' . $first['changeRequestId'] . '/reject', [
            'rejectionReason' => 'The crop cuts off the edge of the picture.',
        ]);
        self::assertResponseStatusCodeSame(204);

        $again = $this->propose(self::PUZZLE);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('already_proposed', $again['code']);
        self::assertIsString($again['error']);
        self::assertStringContainsString($first['changeRequestId'], $again['error']);

        // Another kind of proposal of the same picture is a new one
        $this->propose(self::PUZZLE, ['tags' => ['artwork_crop_tight']]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testAProposalOfAPictureThePuzzleNoLongerHasIsRefused(): void
    {
        $answer = $this->propose(self::PUZZLE, ['sourceImageFingerprint' => PuzzleImageFingerprint::of('an-older-picture.jpg')]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('image_changed', $answer['code']);
        self::assertCount(0, $this->changeRequestsOf(self::PUZZLE));
    }

    public function testAPuzzleWithAHiddenPictureIsUnknown(): void
    {
        $this->propose(PuzzleFixture::PUZZLE_HIDDEN_IMAGE);
        self::assertResponseStatusCodeSame(404);

        // An image proposal of the internal API itself too
        self::callInternalApi($this->browser, 'POST', '/internal-api/puzzle-change-requests', [
            'puzzleId' => PuzzleFixture::PUZZLE_HIDDEN_IMAGE,
            'image' => self::jpegBase64(),
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->changeRequestsOf(PuzzleFixture::PUZZLE_HIDDEN_IMAGE));
    }

    public function testAnImageTheWebFormWouldRefuseIsRefused(): void
    {
        foreach (['not base64 at all!', base64_encode('plain text, not an image'), base64_encode(self::gif())] as $image) {
            $answer = $this->propose(self::PUZZLE, ['image' => $image]);

            self::assertResponseStatusCodeSame(422);
            self::assertIsArray($answer['errors']);
            self::assertArrayHasKey('image', $answer['errors']);
        }

        self::assertCount(0, $this->changeRequestsOf(self::PUZZLE));
    }

    public function testThePipelinesMetadataIsChecked(): void
    {
        foreach (
            [
            'sourceImageFingerprint' => ['sourceImageFingerprint' => null],
            'tags' => ['tags' => []],
            'confidence' => ['confidence' => 1.5],
            'modelVersion' => ['modelVersion' => str_repeat('v', 101)],
            'source' => ['source' => 'player'],
            ] as $field => $override
        ) {
            $answer = $this->propose(self::PUZZLE, $override);

            self::assertResponseStatusCodeSame(400, $field);
            self::assertIsArray($answer['errors']);
            self::assertArrayHasKey($field, $answer['errors'], $field);
        }
    }

    public function testListsRequestsByFilterPageByPageWithoutHiddenPictures(): void
    {
        $first = $this->propose(self::PUZZLE);
        $second = $this->propose(self::OTHER_PUZZLE, ['tags' => ['artwork_crop', 'rotated'], 'confidence' => 0.5]);
        self::assertIsString($first['changeRequestId']);
        self::assertIsString($second['changeRequestId']);

        $pipeline = $this->listed('source=image_pipeline');
        self::assertSame([$first['changeRequestId'], $second['changeRequestId']], array_keys($pipeline));

        $item = $pipeline[$first['changeRequestId']];
        self::assertSame(self::PUZZLE, $item['puzzleId']);
        self::assertSame('pending', $item['status']);
        self::assertSame('image_pipeline', $item['source']);
        self::assertSame(['artwork_crop'], $item['tags']);
        self::assertSame(0.97, $item['confidence']);
        self::assertSame('artwork-crop 1.0.0', $item['modelVersion']);
        self::assertSame(PuzzleImageFingerprint::of(self::IMAGES[self::PUZZLE]), $item['sourceImageFingerprint']);
        self::assertIsString($item['originalImageUrl']);
        self::assertMatchesRegularExpression('#^https?://.+/original/' . preg_quote(self::IMAGES[self::PUZZLE], '#') . '$#', $item['originalImageUrl']);
        self::assertIsString($item['proposedImageUrl']);
        self::assertStringEndsWith('/original/proposal-' . $first['changeRequestId'] . '.jpg', $item['proposedImageUrl']);
        self::assertNull($item['originalArchiveUrl']);
        self::assertNull($item['decision']);
        self::assertIsString($item['createdAt']);

        self::assertSame([$second['changeRequestId']], array_keys($this->listed('tag=rotated')));
        self::assertSame([], $this->listed('source=image_pipeline&status=rejected'));

        // Every source, the players' ones included (the fixtures)
        $players = $this->listed('source=player');
        self::assertArrayHasKey(PuzzleReportFixture::CHANGE_REQUEST_PENDING, $players);
        self::assertArrayNotHasKey($first['changeRequestId'], $players);
        self::assertArrayHasKey(PuzzleReportFixture::CHANGE_REQUEST_REJECTED, $this->listed('status=rejected'));
        self::assertArrayNotHasKey(PuzzleReportFixture::CHANGE_REQUEST_PENDING, $this->listed('status=approved'));

        // A page at a time, in id order
        $page = self::callInternalApi($this->browser, 'GET', '/internal-api/puzzle-change-requests?source=image_pipeline&limit=1');
        self::assertIsArray($page['changeRequests']);
        self::assertCount(1, $page['changeRequests']);
        self::assertSame($first['changeRequestId'], $page['nextCursor']);
        $next = self::callInternalApi($this->browser, 'GET', '/internal-api/puzzle-change-requests?source=image_pipeline&limit=1&after=' . $first['changeRequestId']);
        self::assertIsArray($next['changeRequests']);
        self::assertIsArray($next['changeRequests'][0]);
        self::assertSame($second['changeRequestId'], $next['changeRequests'][0]['id']);

        // A puzzle whose picture became hidden is never listed
        $this->database()->executeStatement("UPDATE puzzle SET hide_image_until = '2099-12-31' WHERE id = :id", ['id' => self::OTHER_PUZZLE]);
        self::assertSame([$first['changeRequestId']], array_keys($this->listed('source=image_pipeline')));

        self::callInternalApi($this->browser, 'GET', '/internal-api/puzzle-change-requests?status=maybe&limit=0&color=red');
        self::assertResponseStatusCodeSame(400);
    }

    public function testDecisionsKeepTheirNoteAndTheListShowsThem(): void
    {
        $approved = $this->propose(self::PUZZLE);
        $rejected = $this->propose(self::OTHER_PUZZLE);
        self::assertIsString($approved['changeRequestId']);
        self::assertIsString($rejected['changeRequestId']);

        self::callInternalApi($this->browser, 'POST', '/internal-api/puzzle-change-requests/' . $approved['changeRequestId'] . '/approve', [
            'selectedFields' => ['image'],
            'decisionNote' => 'auto: confidence 0.97, band ≥ 0.95, model artwork-crop 1.0.0',
        ]);
        self::assertResponseStatusCodeSame(204);

        self::callInternalApi($this->browser, 'POST', '/internal-api/puzzle-change-requests/' . $rejected['changeRequestId'] . '/reject', [
            'rejectionReason' => 'The crop cuts off the edge of the picture.',
            'decisionNote' => 'auto: confidence 0.31, below the review band',
        ]);
        self::assertResponseStatusCodeSame(204);

        $approval = $this->decisionOf($approved['changeRequestId']);
        self::assertSame('auto: confidence 0.97, band ≥ 0.95, model artwork-crop 1.0.0', $approval->note);
        self::assertSame(MergeDecisionSource::InternalApi, $approval->source);

        // A rejection's note is the reason the player reads - the internal note goes into the details
        $rejection = $this->decisionOf($rejected['changeRequestId']);
        self::assertSame('The crop cuts off the edge of the picture.', $rejection->note);
        self::assertSame(['decisionNote' => 'auto: confidence 0.31, below the review band'], $rejection->details);
        self::assertSame(MergeDecisionSource::InternalApi, $rejection->source);

        $listed = $this->listed('source=image_pipeline&status=all');

        $approvedItem = $listed[$approved['changeRequestId']];
        self::assertSame('approved', $approvedItem['status']);
        self::assertIsArray($approvedItem['decision']);
        self::assertSame('approved', $approvedItem['decision']['outcome']);
        self::assertSame('auto: confidence 0.97, band ≥ 0.95, model artwork-crop 1.0.0', $approvedItem['decision']['note']);
        self::assertSame('internal_api', $approvedItem['decision']['source']);
        self::assertNull($approvedItem['decision']['rejectionReason']);
        self::assertIsArray($approvedItem['decision']['decidedBy']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $approvedItem['decision']['decidedBy']['playerId']);
        self::assertIsString($approvedItem['decision']['decidedAt']);
        // Once approved, the proposed picture is the puzzle's picture now
        $puzzleImage = $this->puzzles()->get(self::PUZZLE)->image;
        self::assertNotNull($puzzleImage);
        self::assertIsString($approvedItem['proposedImageUrl']);
        self::assertStringEndsWith('/original/' . $puzzleImage, $approvedItem['proposedImageUrl']);

        $rejectedItem = $listed[$rejected['changeRequestId']];
        self::assertIsArray($rejectedItem['decision']);
        self::assertSame('rejected', $rejectedItem['decision']['outcome']);
        self::assertSame('auto: confidence 0.31, below the review band', $rejectedItem['decision']['note']);
        self::assertSame('The crop cuts off the edge of the picture.', $rejectedItem['decision']['rejectionReason']);
        // The rejected proposal's file is deleted
        self::assertNull($rejectedItem['proposedImageUrl']);

        foreach (['approve' => ['selectedFields' => []], 'reject' => ['rejectionReason' => 'No.']] as $action => $body) {
            $answer = self::callInternalApi($this->browser, 'POST', '/internal-api/puzzle-change-requests/' . $approved['changeRequestId'] . '/' . $action, $body + [
                'decisionNote' => str_repeat('x', 501),
            ]);
            self::assertResponseStatusCodeSame(400, $action);
            self::assertIsString($answer['error']);
            self::assertStringContainsString('decisionNote', $answer['error']);
        }
    }

    public function testApprovingAPipelinePictureArchivesTheOriginalFirst(): void
    {
        $answer = $this->propose(self::PUZZLE);
        self::assertIsString($answer['changeRequestId']);
        $original = self::IMAGES[self::PUZZLE];

        self::callInternalApi($this->browser, 'POST', '/internal-api/puzzle-change-requests/' . $answer['changeRequestId'] . '/approve', [
            'selectedFields' => ['image'],
        ]);
        self::assertResponseStatusCodeSame(204);

        $archivePath = sprintf('originals/%s/%s.jpg', self::PUZZLE, PuzzleImageFingerprint::of($original));
        self::assertSame($archivePath, PuzzleOriginalImageArchive::pathFor(self::PUZZLE, $original));
        self::assertTrue($this->filesystem()->fileExists($archivePath));
        self::assertSame('original picture of ' . self::PUZZLE, $this->filesystem()->read($archivePath));

        $changeRequest = $this->changeRequests()->get($answer['changeRequestId']);
        self::assertSame($archivePath, $changeRequest->originalArchivePath);
        self::assertSame(PuzzleReportStatus::Approved, $changeRequest->status);

        // The puzzle has the proposed picture under a new name - and the old object is still there
        $puzzle = $this->puzzles()->get(self::PUZZLE);
        self::assertNotSame($original, $puzzle->image);
        self::assertTrue($this->filesystem()->fileExists($original));

        $item = $this->listed('source=image_pipeline&status=approved')[$answer['changeRequestId']];
        self::assertIsString($item['originalArchiveUrl']);
        self::assertStringEndsWith('/original/' . $archivePath, $item['originalArchiveUrl']);
        self::assertSame($archivePath, $this->decisionOf($answer['changeRequestId'])->details['originalArchivePath'] ?? null);
    }

    public function testACropOfAPictureThePuzzleNoLongerHasIsNeverApproved(): void
    {
        $answer = $this->propose(self::PUZZLE);
        self::assertIsString($answer['changeRequestId']);

        // The puzzle got another picture after the pipeline cropped the old one
        $this->database()->executeStatement("UPDATE puzzle SET image = 'box-1000-03-replaced.jpg' WHERE id = :id", ['id' => self::PUZZLE]);

        $refused = self::callInternalApi($this->browser, 'POST', '/internal-api/puzzle-change-requests/' . $answer['changeRequestId'] . '/approve', [
            'selectedFields' => ['image'],
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('image_changed', $refused['code']);

        $this->entityManager()->clear();
        self::assertSame('box-1000-03-replaced.jpg', $this->puzzles()->get(self::PUZZLE)->image);
        $changeRequest = $this->changeRequests()->get($answer['changeRequestId']);
        self::assertSame(PuzzleReportStatus::Pending, $changeRequest->status);
        self::assertNull($changeRequest->originalArchivePath);
        self::assertFalse($this->filesystem()->fileExists(PuzzleOriginalImageArchive::pathFor(self::PUZZLE, 'box-1000-03-replaced.jpg')));

        // Rejecting it stays possible - once
        $reject = ['rejectionReason' => 'The picture changed since.'];
        self::callInternalApi($this->browser, 'POST', '/internal-api/puzzle-change-requests/' . $answer['changeRequestId'] . '/reject', $reject);
        self::assertResponseStatusCodeSame(204);

        $again = self::callInternalApi($this->browser, 'POST', '/internal-api/puzzle-change-requests/' . $answer['changeRequestId'] . '/reject', $reject);
        self::assertResponseStatusCodeSame(409);
        self::assertIsString($again['error']);
        self::assertSame([1], $this->database()->fetchFirstColumn(
            'SELECT COUNT(*) FROM puzzle_moderation_decision WHERE change_request_id = :id',
            ['id' => $answer['changeRequestId']],
        ));
    }

    public function testAFailedArchiveCopyRefusesTheApproval(): void
    {
        $answer = $this->propose(self::PUZZLE);
        self::assertIsString($answer['changeRequestId']);

        $log = new TestHandler();
        self::getContainer()->get('logger')->pushHandler($log);

        $this->s3Adapter()->setFailing(true);
        $refused = self::callInternalApi($this->browser, 'POST', '/internal-api/puzzle-change-requests/' . $answer['changeRequestId'] . '/approve', [
            'selectedFields' => ['image'],
        ]);
        $this->s3Adapter()->setFailing(false);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('original_not_archived', $refused['code']);
        self::assertTrue($log->hasRecordThatContains('could not be copied to the originals archive', Level::Warning));

        $this->entityManager()->clear();
        self::assertSame(self::IMAGES[self::PUZZLE], $this->puzzles()->get(self::PUZZLE)->image);
        $changeRequest = $this->changeRequests()->get($answer['changeRequestId']);
        self::assertSame(PuzzleReportStatus::Pending, $changeRequest->status);
        self::assertNull($changeRequest->originalArchivePath);
    }

    public function testApprovingAPlayersPictureKeepsTheOldObjectWithoutArchiving(): void
    {
        $photo = tempnam(sys_get_temp_dir(), 'player-photo-');
        assert($photo !== false);
        file_put_contents($photo, (string) base64_decode(self::jpegBase64(), true));
        $changeRequestId = Uuid::uuid7()->toString();

        // A player's "Suggest a change" with a photo
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new SubmitPuzzleChangeRequest(
            changeRequestId: $changeRequestId,
            puzzleId: self::OTHER_PUZZLE,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            proposedName: 'Puzzle 1000 04',
            proposedBrand: null,
            proposedPiecesCount: 1000,
            proposedEans: EanList::fromStored(null),
            proposedBrandCodes: BrandCodeList::fromStored(null),
            proposedPhoto: new UploadedFile($photo, 'photo.jpg', 'image/jpeg', null, true),
            originalAlternativeNames: new PuzzleNames(),
            originalNameLanguage: null,
        ));
        $original = self::IMAGES[self::OTHER_PUZZLE];
        self::assertSame(PuzzleChangeRequestSource::Player, $this->changeRequests()->get($changeRequestId)->source);

        self::callInternalApi($this->browser, 'POST', '/internal-api/puzzle-change-requests/' . $changeRequestId . '/approve', [
            'selectedFields' => ['image'],
        ]);
        self::assertResponseStatusCodeSame(204);

        $puzzle = $this->puzzles()->get(self::OTHER_PUZZLE);
        self::assertNotNull($puzzle->image);
        self::assertNotSame($original, $puzzle->image);
        self::assertTrue($this->filesystem()->fileExists($puzzle->image));
        // An image change never deletes the picture it replaces
        self::assertTrue($this->filesystem()->fileExists($original));
        self::assertSame('original picture of ' . self::OTHER_PUZZLE, $this->filesystem()->read($original));
        // Only the image pipeline's pictures are archived
        self::assertNull($this->changeRequests()->get($changeRequestId)->originalArchivePath);
        self::assertFalse($this->filesystem()->fileExists(PuzzleOriginalImageArchive::pathFor(self::OTHER_PUZZLE, $original)));
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function propose(string $puzzleId, array $override = []): array
    {
        $body = $override + [
            'puzzleId' => $puzzleId,
            'source' => 'image_pipeline',
            'image' => self::jpegBase64(),
            'tags' => ['artwork_crop'],
            'confidence' => 0.97,
            'modelVersion' => 'artwork-crop 1.0.0',
            'sourceImageFingerprint' => PuzzleImageFingerprint::of(self::IMAGES[$puzzleId]),
        ];

        return self::callInternalApi($this->browser, 'POST', '/internal-api/puzzle-change-requests', array_filter($body, static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @return array<string, array<string, mixed>> by change request id, in the order listed
     */
    private function listed(string $query): array
    {
        $page = self::callInternalApi($this->browser, 'GET', '/internal-api/puzzle-change-requests?' . $query);
        self::assertResponseIsSuccessful();
        self::assertIsArray($page['changeRequests']);

        $listed = [];

        foreach ($page['changeRequests'] as $item) {
            self::assertIsArray($item);
            $id = $item['id'] ?? null;
            self::assertIsString($id);
            /** @var array<string, mixed> $item */
            $listed[$id] = $item;
        }

        return $listed;
    }

    /**
     * @return list<string>
     */
    private function changeRequestsOf(string $puzzleId): array
    {
        /** @var list<string> */
        return $this->database()->fetchFirstColumn('SELECT id FROM puzzle_change_request WHERE puzzle_id = :id', ['id' => $puzzleId]);
    }

    private function decisionOf(string $changeRequestId): PuzzleModerationDecision
    {
        $decision = $this->entityManager()->getRepository(PuzzleModerationDecision::class)->findOneBy(['changeRequestId' => $changeRequestId]);
        self::assertNotNull($decision);

        return $decision;
    }

    private static function jpegBase64(): string
    {
        $image = imagecreatetruecolor(40, 30);
        assert($image !== false);
        ob_start();
        imagejpeg($image);

        return base64_encode((string) ob_get_clean());
    }

    /**
     * A real image of a type the web form refuses
     */
    private static function gif(): string
    {
        $image = imagecreatetruecolor(4, 3);
        assert($image !== false);
        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function filesystem(): Filesystem
    {
        return self::getContainer()->get(Filesystem::class);
    }

    private function s3Adapter(): ToggleableFailingFilesystemAdapter
    {
        /** @var ToggleableFailingFilesystemAdapter $adapter */
        $adapter = self::getContainer()->get('app.storage.s3_adapter');

        return $adapter;
    }

    private function changeRequests(): PuzzleChangeRequestRepository
    {
        return self::getContainer()->get(PuzzleChangeRequestRepository::class);
    }

    private function puzzles(): PuzzleRepository
    {
        return self::getContainer()->get(PuzzleRepository::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
