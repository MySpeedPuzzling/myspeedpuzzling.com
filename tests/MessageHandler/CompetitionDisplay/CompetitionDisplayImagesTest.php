<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\CompetitionDisplay;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddCompetitionDisplay;
use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Message\DeleteCompetitionDisplay;
use SpeedPuzzling\Web\Message\DeleteCompetitionDisplayImages;
use SpeedPuzzling\Web\Message\DeleteCompetitionSeries;
use SpeedPuzzling\Web\Message\DuplicateCompetitionDisplay;
use SpeedPuzzling\Web\Message\SaveCompetitionDisplayDesign;
use SpeedPuzzling\Web\MessageHandler\DeleteCompetitionDisplayImagesHandler;
use SpeedPuzzling\Web\Query\GetCompetitionDisplays;
use SpeedPuzzling\Web\Query\GetStoredFileReferences;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayDesignSanitizer;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayImages;
use SpeedPuzzling\Web\Services\UploaderHelper;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\DisplayKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Display pictures in storage (competition toolkit D7, D-displays-overlays.md §3.2, §10): every place a design holds a
 * picture counts as a reference, a duplicate shares its source's files, and a picture is deleted only once no display
 * shows it any more - after a save that dropped it, a deleted display, a deleted event or series.
 */
final class CompetitionDisplayImagesTest extends KernelTestCase
{
    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;

    private MessageBusInterface $messageBus;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->filesystem = self::getContainer()->get(Filesystem::class);
        $this->resetAsyncTransport();
    }

    public function testANewPathIsWhatTheSanitizerKeepsForTheEvent(): void
    {
        foreach (DisplayImages::EXTENSIONS as $mimeType => $extension) {
            $path = DisplayImages::newPath(strtoupper(self::EVENT), $mimeType);
            self::assertNotNull($path);
            self::assertMatchesRegularExpression('~^competition-displays/' . self::EVENT . '/[0-9a-f-]{36}\.' . $extension . '\z~', $path);
            self::assertTrue(DisplayImages::isDisplayUpload($path));

            $design = $this->sanitizer()->normaliseArray(self::design(background: $path, image: $path, rotator: [$path]), self::EVENT);
            self::assertSame([$path], DisplayDesignSanitizer::imagePaths($design), $mimeType);
        }

        self::assertNull(DisplayImages::newPath(self::EVENT, 'image/svg+xml'));
        self::assertNull(DisplayImages::newPath(self::EVENT, 'text/html'));
        self::assertFalse(DisplayImages::isDisplayUpload('competition-pages/' . self::EVENT . '/' . Uuid::uuid7()->toString() . '.png'));
        self::assertFalse(DisplayImages::isDisplayUpload('avatars/someone.png'));
    }

    public function testTheUrlIsTheOriginalUntilAPresetIsConfigured(): void
    {
        $path = 'competition-displays/' . self::EVENT . '/0193a7b2-1c3d-7e4f-8a9b-0c1d2e3f4a5b.png';
        $uploaderHelper = new UploaderHelper('https://img.example');

        self::assertSame('https://img.example/original/' . $path, new DisplayImages($uploaderHelper, 'https://img.example', '')->url($path));
        self::assertSame('https://img.example/preset:display/plain/' . $path, new DisplayImages($uploaderHelper, 'https://img.example', 'display')->url($path));
        self::assertSame('https://img.example/original/' . $path, new DisplayImages($uploaderHelper, 'https://img.example', 'dis/../play')->url($path), 'No plain preset name = the original');

        // The application's default (DISPLAY_IMAGE_PRESET empty): the original upload
        self::assertStringContainsString('/original/' . $path, self::getContainer()->get(DisplayImages::class)->url($path));
    }

    public function testEveryPictureOfADesignIsAReference(): void
    {
        [$background, $image, $rotatorA, $rotatorB, $unused] = self::paths(5);
        $displayId = $this->add();
        $this->save($displayId, self::design(background: $background, image: $image, rotator: [$rotatorA, $rotatorB]), baseVersion: 1);

        $referenced = self::getContainer()->get(GetStoredFileReferences::class)->referencedAmong([$background, $image, $rotatorA, $rotatorB, $unused]);

        self::assertEqualsCanonicalizing([$background, $image, $rotatorA, $rotatorB], array_keys($referenced));
    }

    public function testASaveDeletesOnlyThePicturesNoDisplayShowsAnyMore(): void
    {
        [$kept, $dropped, $sharedWithOther] = self::paths(3);
        $this->store($kept, $dropped, $sharedWithOther);
        $displayId = $this->add();
        $otherId = $this->add();
        $this->save($displayId, self::design(image: $kept, rotator: [$dropped, $sharedWithOther]), baseVersion: 1);
        $this->save($otherId, self::design(image: $sharedWithOther), baseVersion: 1);
        self::assertSame([], $this->sentDeletions(), 'Nothing dropped yet');

        $this->save($displayId, self::design(image: $kept), baseVersion: 2);

        $deletions = $this->sentDeletions();
        self::assertEquals([new DeleteCompetitionDisplayImages([$dropped, $sharedWithOther])], $deletions);

        $this->handle($deletions[0]);

        self::assertFalse($this->filesystem->fileExists($dropped));
        self::assertTrue($this->filesystem->fileExists($sharedWithOther), 'Another display still shows it');
        self::assertTrue($this->filesystem->fileExists($kept));
    }

    public function testDeletingOneOfTwoDuplicatesKeepsTheSharedFiles(): void
    {
        [$background, $logo] = self::paths(2);
        $this->store($background, $logo);
        $sourceId = $this->add();
        $this->save($sourceId, self::design(background: $background, image: $logo), baseVersion: 1);
        $copyId = Uuid::uuid7();
        $this->messageBus->dispatch(new DuplicateCompetitionDisplay($sourceId, $copyId, self::EVENT, 'Copy', null));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        // The copy points at the very same files - nothing copied
        $copy = self::getContainer()->get(GetCompetitionDisplays::class)->byId($copyId->toString());
        self::assertSame($copyId->toString(), $copy->id);
        self::assertCount(2, self::getContainer()->get(GetStoredFileReferences::class)->referencedAmong([$background, $logo]));

        $this->messageBus->dispatch(new DeleteCompetitionDisplay($sourceId));

        $deletions = $this->sentDeletions();
        self::assertEquals([new DeleteCompetitionDisplayImages([$background, $logo])], $deletions);
        $this->handle($deletions[0]);
        self::assertTrue($this->filesystem->fileExists($background), 'The copy still shows it');
        self::assertTrue($this->filesystem->fileExists($logo), 'The copy still shows it');

        // The last display that shows them goes - so do the files
        $this->resetAsyncTransport();
        $this->messageBus->dispatch(new DeleteCompetitionDisplay($copyId->toString()));

        $deletions = $this->sentDeletions();
        self::assertCount(1, $deletions);
        $this->handle($deletions[0]);
        self::assertFalse($this->filesystem->fileExists($background));
        self::assertFalse($this->filesystem->fileExists($logo));
    }

    public function testDeletingTheEventDeletesItsDisplaysPictures(): void
    {
        [$logo, $sponsor] = self::paths(2);
        $this->store($logo, $sponsor);
        $this->save($this->add(), self::design(image: $logo), baseVersion: 1);
        $this->save($this->add(), self::design(rotator: [$logo, $sponsor]), baseVersion: 1);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->messageBus->dispatch(new DeleteCompetition(self::EVENT));

        $deletions = $this->sentDeletions();
        self::assertCount(1, $deletions);
        self::assertEqualsCanonicalizing([$logo, $sponsor], $deletions[0]->paths);

        $this->handle($deletions[0]);
        self::assertFalse($this->filesystem->fileExists($logo));
        self::assertFalse($this->filesystem->fileExists($sponsor));
    }

    public function testDeletingASeriesDeletesItsEditionsDisplaysPictures(): void
    {
        $edition = CompetitionSeriesFixture::EDITION_EJJ_68;
        $path = DisplayImages::newPath($edition, 'image/webp');
        assert($path !== null);
        $displayId = $this->add($edition);
        $this->save($displayId, self::design(background: $path), baseVersion: 1, competitionId: $edition);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->messageBus->dispatch(new DeleteCompetitionSeries(CompetitionSeriesFixture::SERIES_EJJ));

        self::assertEquals([new DeleteCompetitionDisplayImages([$path])], $this->sentDeletions());
    }

    public function testTheCleanUpNeverDeletesAnythingButAnUnreferencedDisplayUpload(): void
    {
        [$orphan] = self::paths(1);
        $pagePicture = 'competition-pages/' . self::EVENT . '/' . Uuid::uuid7()->toString() . '.png';
        $this->store($orphan, $pagePicture);

        $this->handle(new DeleteCompetitionDisplayImages([$orphan, $pagePicture, 'avatars/x.png']));

        self::assertFalse($this->filesystem->fileExists($orphan));
        self::assertTrue($this->filesystem->fileExists($pagePicture), 'Not a display upload - never deleted here');
    }

    public function testADesignWithoutPicturesSendsNoDeletion(): void
    {
        $displayId = $this->add();
        $this->save($displayId, self::design(), baseVersion: 1);
        $this->messageBus->dispatch(new DeleteCompetitionDisplay($displayId));

        self::assertSame([], $this->sentDeletions());
    }

    /**
     * @param list<string> $rotator
     * @return array<string, mixed>
     */
    private static function design(null|string $background = null, null|string $image = null, array $rotator = []): array
    {
        $elements = [['id' => 'timer001', 'type' => 'timer', 'frame' => ['x' => 0, 'y' => 0, 'w' => 50, 'h' => 20]]];

        if ($image !== null) {
            $elements[] = ['id' => 'image001', 'type' => 'image', 'frame' => ['x' => 0, 'y' => 50, 'w' => 20, 'h' => 20], 'props' => ['source' => 'upload', 'path' => $image]];
        }

        if ($rotator !== []) {
            $elements[] = ['id' => 'rotat001', 'type' => 'rotator', 'frame' => ['x' => 50, 'y' => 50, 'w' => 20, 'h' => 20], 'props' => ['images' => array_map(static fn (string $path): array => ['path' => $path], $rotator)]];
        }

        $design = ['schema' => 1, 'elements' => $elements];

        if ($background !== null) {
            $design['canvas'] = ['background' => ['type' => 'image', 'image' => ['path' => $background]]];
        }

        return $design;
    }

    /**
     * @return list<string>
     */
    private static function paths(int $count): array
    {
        $paths = [];

        for ($i = 0; $i < $count; $i++) {
            $path = DisplayImages::newPath(self::EVENT, 'image/png');
            assert($path !== null);
            $paths[] = $path;
        }

        return $paths;
    }

    private function store(string ...$paths): void
    {
        foreach ($paths as $path) {
            $this->filesystem->write($path, 'picture');
        }
    }

    private function add(string $competitionId = self::EVENT): string
    {
        $displayId = Uuid::uuid7();
        $this->messageBus->dispatch(new AddCompetitionDisplay($displayId, $competitionId, DisplayKind::Screen, 'Hall A', 'en', PlayerFixture::PLAYER_ADMIN));

        return $displayId->toString();
    }

    /**
     * @param array<string, mixed> $design
     */
    private function save(string $displayId, array $design, int $baseVersion, string $competitionId = self::EVENT): void
    {
        // As the controller hands it over: decoded JSON with objects as stdClass
        $this->messageBus->dispatch(new SaveCompetitionDisplayDesign(
            displayId: $displayId,
            design: json_decode(json_encode($design, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR),
            baseVersion: $baseVersion,
        ));

        // Every picture the design was saved with is in it - none refused as another event's
        $stored = self::getContainer()->get(GetCompetitionDisplays::class)->byId($displayId);
        self::assertSame($competitionId, $stored->competitionId);
    }

    private function handle(DeleteCompetitionDisplayImages $message): void
    {
        (self::getContainer()->get(DeleteCompetitionDisplayImagesHandler::class))($message);
    }

    private function sanitizer(): DisplayDesignSanitizer
    {
        return self::getContainer()->get(DisplayDesignSanitizer::class);
    }

    private function resetAsyncTransport(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
    }

    /**
     * @return list<DeleteCompetitionDisplayImages>
     */
    private function sentDeletions(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        /** @var list<DeleteCompetitionDisplayImages> */
        return array_values(array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof DeleteCompetitionDisplayImages,
        ));
    }
}
