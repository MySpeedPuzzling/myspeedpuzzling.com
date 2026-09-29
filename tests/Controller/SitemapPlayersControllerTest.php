<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SitemapPlayersControllerTest extends WebTestCase
{
    public function testListsOnlyPublicProfilesThatShowAResult(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);

        // Michael is in no pair or team result, so without his own times his profile is empty
        $groupResults = $database->fetchOne(
            "SELECT COUNT(*) FROM puzzle_solving_time
             WHERE team IS NOT NULL
                AND (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))",
            ['playerId' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );
        self::assertSame(0, is_numeric($groupResults) ? (int) $groupResults : null, 'Premise: Michael is in no pair or team result');

        $database->executeStatement(
            'UPDATE puzzle_solving_time SET player_id = :to WHERE player_id = :from',
            ['from' => PlayerFixture::PLAYER_WITH_FAVORITES, 'to' => PlayerFixture::PLAYER_ADMIN],
        );

        $browser->request('GET', '/sitemap-players.xml');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        foreach (['/en/player-profile/', '/profil-hrace/', '/de/spieler-profil/'] as $prefix) {
            self::assertStringContainsString($prefix . PlayerFixture::PLAYER_REGULAR . '</loc>', $content);
        }

        self::assertStringNotContainsString(PlayerFixture::PLAYER_WITH_FAVORITES, $content);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, $content);
    }
}
