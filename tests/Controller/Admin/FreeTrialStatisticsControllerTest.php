<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FreeTrialStatisticsControllerTest extends WebTestCase
{
    public function testAdminSeesHowManyPlayersWereShownEachModal(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO player_modal_impression (id, player_id, modal, displayed_at, seen_at) VALUES
                ('018d0000-0000-0000-0000-0000000000b1', :first, 'free_trial_offer', NOW(), NOW()),
                ('018d0000-0000-0000-0000-0000000000b2', :second, 'free_trial_offer', NOW(), NULL)",
            ['first' => PlayerFixture::PLAYER_REGULAR, 'second' => PlayerFixture::PLAYER_PRIVATE],
        );

        $crawler = $browser->request('GET', '/admin/free-trial');

        $this->assertResponseIsSuccessful();

        $row = $crawler->filter('table')->first()->filter('tbody tr')->first();
        self::assertSame('free_trial_offer', $row->filter('td')->eq(0)->text());
        self::assertSame('2', $row->filter('td')->eq(1)->text());
        self::assertStringContainsString('50 %', $row->filter('td')->eq(2)->text());
    }

    public function testPlayersHaveNoBusinessHere(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/admin/free-trial');

        $this->assertResponseStatusCodeSame(403);
    }
}
