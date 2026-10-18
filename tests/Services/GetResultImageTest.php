<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

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
        self::assertSame($image->content, $cache->read(self::CARD));
        self::assertFalse($bucket->fileExists(self::CARD));

        // The next request gets the kept card, even with the photo gone
        $bucket->delete(self::PHOTO);
        self::assertSame($image->content, $container->get(GetResultImage::class)->forSolvingTime(PuzzleSolvingTimeFixture::TIME_06)->content);
    }
}
