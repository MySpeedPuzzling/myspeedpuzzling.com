<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The events page lists no people - only counts ("41 going"). So it needs no blocklist or private-profile canary; a
 * later phase that shows people ("3 of your favourite puzzlers are going") must join BlocklistCanaryTest and
 * PrivateProfileCanaryTest instead of this test.
 */
final class EventsPagePrivacyTest extends WebTestCase
{
    public function testThePageShowsNoPlayerNameOrCode(): void
    {
        $browser = self::createClient();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        /** @var list<array{name: null|string, code: string}> $players */
        $players = $connection->fetchAllAssociative('SELECT name, code FROM player');
        self::assertNotEmpty($players);

        foreach ([null, PlayerFixture::PLAYER_REGULAR] as $viewer) {
            if ($viewer !== null) {
                TestingLogin::asPlayer($browser, $viewer);
            }

            $crawler = $browser->request('GET', '/en/events');
            self::assertResponseIsSuccessful();
            $main = $crawler->filter('main .ev-page')->html();

            foreach ($players as $player) {
                if ($player['name'] !== null && mb_strlen($player['name']) >= 4) {
                    self::assertStringNotContainsString($player['name'], $main, sprintf('player name "%s" on the events page', $player['name']));
                }

                self::assertDoesNotMatchRegularExpression('/#' . preg_quote(strtoupper($player['code']), '/') . '\b/', $main);
            }
        }
    }
}
