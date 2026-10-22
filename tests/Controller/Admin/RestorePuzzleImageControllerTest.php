<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Message\GrantModeratorRole;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\PuzzleChangeRequestSource;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Restore this picture" on a puzzle's history page (docs/features/puzzle-approvals.md "Restoring a picture").
 */
final class RestorePuzzleImageControllerTest extends WebTestCase
{
    private const string PUZZLE = PuzzleFixture::PUZZLE_1000_03;

    private const string ORIGINAL = 'box-1000-03-018d0003-eeee0001.jpg';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        // One container for the whole test - the in-memory storage keeps what a request wrote
        $this->browser->disableReboot();

        self::getContainer()->get(Connection::class)->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => self::ORIGINAL, 'id' => self::PUZZLE]);
        $this->filesystem()->write(self::ORIGINAL, 'the original picture');
        $this->approvedPipelineCrop();
    }

    public function testTheHistoryOffersTheReplacedPictureAndAModeratorRestoresIt(): void
    {
        $this->signInModerator();
        $cropped = $this->puzzles()->get(self::PUZZLE)->image;

        $crawler = $this->browser->request('GET', '/admin/puzzles/' . self::PUZZLE . '/history');
        self::assertResponseIsSuccessful();
        // Next to the one image change - the picture the approval replaced
        self::assertSelectorCount(1, '[data-role="restore-picture"]');

        $this->browser->submit($crawler->filter('[data-role="restore-picture"] form')->form());

        self::assertResponseRedirects('/admin/puzzles/' . self::PUZZLE . '/history', 303);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'The picture is back');
        self::assertSelectorTextContains('article h3', 'Picture restored');

        $restored = $this->puzzles()->get(self::PUZZLE)->image;
        self::assertNotNull($restored);
        self::assertNotSame($cropped, $restored);
        self::assertNotSame(self::ORIGINAL, $restored);
        self::assertSame('the original picture', $this->filesystem()->read($restored));
        // The restore line itself can be undone - and the approval's picture is no longer the current one either
        self::assertSelectorCount(2, '[data-role="restore-picture"]');
    }

    public function testAPlayerCannotRestore(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_PRIVATE);

        $this->browser->request('POST', '/admin/puzzles/' . self::PUZZLE . '/history/' . Uuid::uuid7()->toString() . '/restore-image');

        self::assertResponseStatusCodeSame(403);
    }

    public function testWithoutAValidTokenNothingHappens(): void
    {
        $this->signInModerator();
        $cropped = $this->puzzles()->get(self::PUZZLE)->image;

        $this->browser->request('POST', '/admin/puzzles/' . self::PUZZLE . '/history/' . Uuid::uuid7()->toString() . '/restore-image', ['_token' => 'nope']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($cropped, $this->puzzles()->get(self::PUZZLE)->image);
    }

    private function signInModerator(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
    }

    private function approvedPipelineCrop(): void
    {
        $puzzle = $this->puzzles()->get(self::PUZZLE);
        $id = Uuid::uuid7();
        $proposedImage = 'proposal-' . $id->toString() . '.jpg';
        $this->filesystem()->write($proposedImage, 'the cropped picture');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new PuzzleChangeRequest(
            id: $id,
            puzzle: $puzzle,
            reporter: self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_ADMIN),
            submittedAt: TestClock::now(),
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
    }

    private function filesystem(): Filesystem
    {
        return self::getContainer()->get(Filesystem::class);
    }

    private function puzzles(): PuzzleRepository
    {
        return self::getContainer()->get(PuzzleRepository::class);
    }
}
