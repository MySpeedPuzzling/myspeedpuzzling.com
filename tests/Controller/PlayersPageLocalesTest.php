<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The Players pages render in every locale (docs/features/players-page/README.md) - a plural with a broken interval or
 * a missing placeholder only fails at render time, and an untranslated key shows up as "players.…" in the page.
 */
final class PlayersPageLocalesTest extends WebTestCase
{
    #[DataProvider('provideLocales')]
    public function testThePagesRenderTranslated(string $locale): void
    {
        $browser = self::createClient();
        (self::getContainer()->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());
        $router = self::getContainer()->get(UrlGeneratorInterface::class);

        $urls = [
            $router->generate('players', ['_locale' => $locale]),
            $router->generate('players', ['_locale' => $locale, 'scope' => 'cz']),
            $router->generate('players_directory', ['_locale' => $locale]),
            $router->generate('players_per_country', ['_locale' => $locale, 'countryCode' => 'cz']),
            $router->generate('player_card', ['_locale' => $locale, 'playerId' => PlayerFixture::PLAYER_ADMIN]),
        ];

        foreach ([null, PlayerFixture::PLAYER_WITH_STRIPE] as $viewer) {
            if ($viewer !== null) {
                TestingLogin::asPlayer($browser, $viewer);
            }

            foreach ($urls as $url) {
                $browser->request('GET', $url);
                self::assertResponseIsSuccessful($url);
                self::assertDoesNotMatchRegularExpression('/\bplayers\.[a-z_]+\.[a-z_.]+/', (string) $browser->getResponse()->getContent(), "Untranslated key on {$url}");
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLocales(): iterable
    {
        foreach (['cs', 'en', 'de', 'es', 'fr', 'ja'] as $locale) {
            yield $locale => [$locale];
        }
    }
}
