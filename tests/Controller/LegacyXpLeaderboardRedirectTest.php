<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Each progression board has its own URL (ProgressLeaderboardController); the old one-page-with-?tab= URLs answer with
 * a permanent redirect to the board they showed, keeping their filters.
 */
final class LegacyXpLeaderboardRedirectTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideOldUrls(): iterable
    {
        yield 'level, no tab' => ['/en/players/xp-leaderboard', '/en/ladder/level'];
        yield 'level' => ['/en/players/xp-leaderboard?tab=xp&country=cz', '/en/ladder/level?country=cz'];
        yield 'achievement points' => ['/en/players/xp-leaderboard?tab=achievement-points', '/en/ladder/achievement-points'];
        yield 'karma with its filters' => ['/en/players/xp-leaderboard?tab=karma&kind=moderation&period=year', '/en/ladder/karma?kind=moderation&period=year'];
        yield 'unknown tab' => ['/en/players/xp-leaderboard?tab=nonsense', '/en/ladder/level'];
        yield 'czech' => ['/hraci/xp-zebricek?tab=karma', '/zebricek/karma'];
    }

    #[DataProvider('provideOldUrls')]
    public function testOldUrlRedirectsPermanentlyToItsBoard(string $oldUrl, string $newUrl): void
    {
        $browser = self::createClient();

        $browser->request('GET', $oldUrl);

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects($newUrl);
    }

    public function testEachBoardIsItsOwnPageWithItsOwnTitle(): void
    {
        $browser = self::createClient();

        $titles = [];
        foreach (['/en/ladder/level', '/en/ladder/karma'] as $url) {
            $crawler = $browser->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSame($url, parse_url((string) $crawler->filter('link[rel="canonical"]')->attr('href'), PHP_URL_PATH));
            $titles[] = $crawler->filter('title')->text();
        }

        self::assertNotSame($titles[0], $titles[1]);
    }
}
