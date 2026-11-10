<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Imagick;
use League\Flysystem\Filesystem;
use SpeedPuzzling\Web\Services\GetResultImage;
use SpeedPuzzling\Web\Services\ResultImageCache;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetResultImageTest extends KernelTestCase
{
    // TIME_06: solo time of PLAYER_REGULAR (public profile)
    private const string PHOTO = 'players/018d0000-0000-0000-0000-000000000001/solo-1700000000.jpg';
    private const string CARD = 'players/' . PlayerFixture::PLAYER_REGULAR . '/results/' . PuzzleSolvingTimeFixture::TIME_06 . '.png';
    private const string FIXTURE_PHOTO = __DIR__ . '/../fixtures/result-image/photo.jpg';

    /**
     * The card TIME_06 gets over FIXTURE_PHOTO, as drawn by the code of 2026-11-09 (PNG32). Regenerate it only when the
     * card is meant to look different (and say so in the commit):
     * REGENERATE_RESULT_IMAGE_GOLDEN=1 vendor/bin/phpunit --filter testTheCardShowsWhatWasDrawn tests/Services/GetResultImageTest.php
     */
    private const string GOLDEN = __DIR__ . '/../fixtures/result-image/time06.png';

    /**
     * Normalized root mean square error a card may differ by - room for an ImageMagick / FreeType bump of the image,
     * far below anything a person could see (a wrong text, a moved line or a missing overlay is above 0.01)
     */
    private const float GOLDEN_TOLERANCE = 0.002;

    public function testDrawnCardIsKeptOnLocalDiskAndNotInTheBucket(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $bucket = $container->get(Filesystem::class);
        $cache = $container->get(ResultImageCache::class);

        $photo = file_get_contents(__DIR__ . '/../../public/img/placeholder-puzzle.jpg');
        self::assertIsString($photo);
        $bucket->write(self::PHOTO, $photo);
        $container->get('doctrine.dbal.default_connection')->executeStatement(
            'UPDATE puzzle_solving_time SET finished_puzzle_photo = :p WHERE id = :id',
            ['p' => self::PHOTO, 'id' => PuzzleSolvingTimeFixture::TIME_06],
        );

        $image = $container->get(GetResultImage::class)->forSolvingTime(PuzzleSolvingTimeFixture::TIME_06);

        self::assertFalse($image->withPlaceholder);
        self::assertSame($image->content, $cache->read(self::CARD)?->content);
        self::assertFalse($bucket->fileExists(self::CARD));

        // The next request gets the kept card, even with the photo gone
        $bucket->delete(self::PHOTO);
        self::assertSame($image->content, $container->get(GetResultImage::class)->forSolvingTime(PuzzleSolvingTimeFixture::TIME_06)->content);
    }

    public function testTheCardShowsWhatWasDrawn(): void
    {
        $card = $this->drawTime06OverTheFixturePhoto();

        if (getenv('REGENERATE_RESULT_IMAGE_GOLDEN') === '1') {
            file_put_contents(self::GOLDEN, $card);
        }

        $drawn = new Imagick();
        $drawn->readImageBlob($card);
        $golden = new Imagick(self::GOLDEN);

        self::assertSame([800, 800], [$drawn->getImageWidth(), $drawn->getImageHeight()]);
        // Compared as opaque RGB: what a person sees, not how the file stores it
        $drawn->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $golden->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        [, $difference] = $drawn->compareImages($golden, Imagick::METRIC_ROOTMEANSQUAREDERROR);

        self::assertLessThanOrEqual(self::GOLDEN_TOLERANCE, $difference);
    }

    public function testTheCardIsOpaque(): void
    {
        $drawn = new Imagick();
        $drawn->readImageBlob($this->drawTime06OverTheFixturePhoto());

        // A file without an alpha channel is opaque by definition; with one, its most transparent pixel must be opaque too
        if ($drawn->getImageAlphaChannel()) {
            $mostTransparent = $drawn->getImageChannelRange(Imagick::CHANNEL_ALPHA)['minima'];
            self::assertEqualsWithDelta((float) Imagick::getQuantum(), (float) $mostTransparent, 0.5);
        }
    }

    private function drawTime06OverTheFixturePhoto(): string
    {
        self::bootKernel();
        $container = self::getContainer();

        $photo = file_get_contents(self::FIXTURE_PHOTO);
        self::assertIsString($photo);
        $container->get(Filesystem::class)->write(self::PHOTO, $photo);
        $container->get('doctrine.dbal.default_connection')->executeStatement(
            'UPDATE puzzle_solving_time SET finished_puzzle_photo = :p WHERE id = :id',
            ['p' => self::PHOTO, 'id' => PuzzleSolvingTimeFixture::TIME_06],
        );

        $image = $container->get(GetResultImage::class)->forSolvingTime(PuzzleSolvingTimeFixture::TIME_06);
        self::assertFalse($image->withPlaceholder);

        return $image->content;
    }
}
