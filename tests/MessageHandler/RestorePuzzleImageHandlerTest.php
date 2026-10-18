<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Exceptions\PuzzleImageNotRestorable;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Message\RestorePuzzleImage;
use SpeedPuzzling\Web\Query\GetPuzzleHistory;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleChangeRequestSource;
use SpeedPuzzling\Web\Value\PuzzleHistoryEntryKind;
use SpeedPuzzling\Web\Value\PuzzleImageChoice;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Restore this picture" (docs/features/puzzle-approvals.md "Restoring a picture"): the picture an approved image change
 * replaced comes back under a new name, copied from the archive or the old object (which stays), logged as a direct
 * edit the history shows as "Picture restored" - and refused, with nothing changed, when it is stored nowhere any more.
 */
final class RestorePuzzleImageHandlerTest extends KernelTestCase
{
    private const string PUZZLE = PuzzleFixture::PUZZLE_1000_03;

    private const string ORIGINAL = 'box-1000-03-018d0003-dddd0001.jpg';

    protected function setUp(): void
    {
        self::bootKernel();

        self::getContainer()->get(Connection::class)->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => self::ORIGINAL, 'id' => self::PUZZLE]);
        $this->filesystem()->write(self::ORIGINAL, 'the original picture');
    }

    public function testThePictureComesBackUnderANewNameAndTheOldObjectStays(): void
    {
        [$changeRequestId, $approvalDecisionId] = $this->approvedPipelineCrop();
        $cropped = $this->puzzle()->image;
        self::assertNotSame(self::ORIGINAL, $cropped);

        $this->restore($approvalDecisionId);

        $restored = $this->puzzle()->image;
        self::assertNotNull($restored);
        self::assertNotSame(self::ORIGINAL, $restored, 'a name is never written twice - the image caches key on it');
        self::assertNotSame($cropped, $restored);
        self::assertSame('the original picture', $this->filesystem()->read($restored));
        // Nothing is deleted: the old object, the crop and the archive copy all stay
        self::assertTrue($this->filesystem()->fileExists(self::ORIGINAL));
        self::assertNotNull($cropped);
        self::assertTrue($this->filesystem()->fileExists($cropped));

        $decision = $this->restoreDecision();
        self::assertSame(PuzzleModerationAction::PuzzleEdited, $decision->action);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $decision->decidedById?->toString());
        self::assertSame(MergeDecisionSource::AdminUi, $decision->source);
        self::assertIsArray($decision->details);
        self::assertSame(PuzzleImageChoice::Restored->value, $decision->details['image']);
        self::assertSame($approvalDecisionId, $decision->details['restoredFromDecisionId']);
        self::assertIsArray($decision->details['before']);
        self::assertSame($cropped, $decision->details['before']['image']);

        // The history shows it as its own line - and it can be undone the same way
        $entries = array_values(array_filter(
            self::getContainer()->get(GetPuzzleHistory::class)->forPuzzle(self::PUZZLE),
            static fn ($entry): bool => $entry->kind === PuzzleHistoryEntryKind::ImageRestored,
        ));
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame($cropped, $entry->previousImage);
        self::assertSame($decision->id->toString(), $entry->decisionId);
        self::assertNotSame('', $changeRequestId);
    }

    public function testTheArchiveCopyIsUsedWhenTheOldObjectIsGone(): void
    {
        [, $approvalDecisionId] = $this->approvedPipelineCrop();
        $this->filesystem()->delete(self::ORIGINAL);

        $this->restore($approvalDecisionId);

        $restored = $this->puzzle()->image;
        self::assertNotNull($restored);
        self::assertSame('the original picture', $this->filesystem()->read($restored));
        $details = $this->restoreDecision()->details;
        self::assertIsArray($details);
        self::assertIsString($details['restoredFrom']);
        self::assertStringStartsWith('originals/', $details['restoredFrom']);
    }

    public function testAPictureStoredNowhereIsRefusedAndNothingChanges(): void
    {
        [$changeRequestId, $approvalDecisionId] = $this->approvedPipelineCrop();
        $archivePath = self::getContainer()->get(EntityManagerInterface::class)->find(PuzzleChangeRequest::class, $changeRequestId)?->originalArchivePath;
        self::assertNotNull($archivePath);
        $this->filesystem()->delete(self::ORIGINAL);
        $this->filesystem()->delete($archivePath);
        $cropped = $this->puzzle()->image;
        $decisions = $this->decisionCount();

        try {
            $this->restore($approvalDecisionId);
            self::fail('A picture stored nowhere cannot come back');
        } catch (PuzzleImageNotRestorable $exception) {
            self::assertSame('restore_picture_gone', $exception->reason);
        }

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame($cropped, $this->puzzle()->image);
        self::assertSame($decisions, $this->decisionCount());
    }

    public function testADecisionThatReplacedNoPictureOfThePuzzleRestoresNothing(): void
    {
        $this->expectException(PuzzleImageNotRestorable::class);

        $this->restore(Uuid::uuid7()->toString());
    }

    /**
     * The image pipeline's crop of the puzzle's picture, approved - the original is archived on the way.
     *
     * @return array{string, string} the change request and its approval decision
     */
    private function approvedPipelineCrop(): array
    {
        $puzzle = $this->puzzle();
        $id = Uuid::uuid7();
        $proposedImage = 'proposal-' . $id->toString() . '.jpg';
        $this->filesystem()->write($proposedImage, 'the cropped picture');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new PuzzleChangeRequest(
            id: $id,
            puzzle: $puzzle,
            reporter: self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_ADMIN),
            submittedAt: new DateTimeImmutable(),
            proposedName: $puzzle->name,
            proposedManufacturer: $puzzle->manufacturer,
            proposedPiecesCount: $puzzle->piecesCount,
            proposedEan: $puzzle->ean,
            proposedIdentificationNumber: $puzzle->identificationNumber,
            proposedImage: $proposedImage,
            proposedImageRatio: 1.25,
            originalName: $puzzle->name,
            originalManufacturerId: $puzzle->manufacturer?->id,
            originalPiecesCount: $puzzle->piecesCount,
            originalEan: $puzzle->ean,
            originalIdentificationNumber: $puzzle->identificationNumber,
            originalImage: $puzzle->image,
            source: PuzzleChangeRequestSource::ImagePipeline,
            tags: ['artwork_crop'],
            confidence: 0.9,
            modelVersion: 'artwork-crop 1.0.0',
            sourceImageFingerprint: PuzzleImageFingerprint::of(self::ORIGINAL),
        ));
        $entityManager->flush();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ApprovePuzzleChangeRequest(
            changeRequestId: $id->toString(),
            puzzleId: self::PUZZLE,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            selectedFields: ['image'],
        ));

        $approval = self::getContainer()->get(EntityManagerInterface::class)->getRepository(PuzzleModerationDecision::class)
            ->findOneBy(['changeRequestId' => $id, 'action' => PuzzleModerationAction::ChangeRequestApproved]);
        self::assertNotNull($approval);

        return [$id->toString(), $approval->id->toString()];
    }

    private function restore(string $decisionId): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new RestorePuzzleImage(
            puzzleId: self::PUZZLE,
            decisionId: $decisionId,
            moderatorId: PlayerFixture::PLAYER_REGULAR,
        ));
    }

    private function restoreDecision(): PuzzleModerationDecision
    {
        // The only direct edit of these tests
        $decision = self::getContainer()->get(EntityManagerInterface::class)->getRepository(PuzzleModerationDecision::class)
            ->findOneBy(['puzzleId' => Uuid::fromString(self::PUZZLE), 'action' => PuzzleModerationAction::PuzzleEdited]);
        self::assertNotNull($decision);

        return $decision;
    }

    private function decisionCount(): int
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(PuzzleModerationDecision::class)
            ->count(['puzzleId' => Uuid::fromString(self::PUZZLE)]);
    }

    private function puzzle(): Puzzle
    {
        return self::getContainer()->get(PuzzleRepository::class)->get(self::PUZZLE);
    }

    private function filesystem(): Filesystem
    {
        return self::getContainer()->get(Filesystem::class);
    }
}
