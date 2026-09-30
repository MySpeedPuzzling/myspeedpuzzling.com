<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class PlayerSolvedPuzzlesPhotoLinkTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testOwnFinishedPhotoOpensTheLargeStrippedPresetNeverTheOriginal(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle_solving_time SET finished_puzzle_photo = :photo WHERE player_id = :playerId AND team IS NULL',
            ['photo' => 'players/regular/finished-with-exif.jpg', 'playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        $component = $this->createLiveComponent('PlayerSolvedPuzzles', [
            'playerId' => PlayerFixture::PLAYER_REGULAR,
        ], $client);
        $component->setRouteLocale('en');

        $crawler = $component->render()->crawler();
        $links = $crawler->filter('a.gallery-item')->each(static fn (Crawler $link): null|string => $link->attr('href'));

        // The photo opens the 1200 px preset with the metadata stripped, never the uploaded original,
        // which can still carry EXIF including the GPS position of the player's home
        $largeImage = self::getContainer()->get(ImageThumbnailTwigExtension::class)
            ->thumbnailUrl('players/regular/finished-with-exif.jpg', 'puzzle_large');
        self::assertNotSame([], $links);
        self::assertSame(array_fill(0, count($links), $largeImage), $links);
        self::assertStringNotContainsString('/original/', $crawler->html());
    }
}
