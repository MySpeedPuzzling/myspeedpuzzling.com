<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Message\GrantModeratorRole;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The badge of honour on a profile: community moderators and the MySpeedPuzzling team (admins), for everybody to see
 */
final class PlayerRoleBadgeTest extends WebTestCase
{
    public function testModeratorProfileShowsTheBadge(): void
    {
        $browser = self::createClient();
        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.player-head-role', 'Community moderator');
    }

    public function testAdminProfileShowsTheTeam(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_ADMIN);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.player-head-role', 'MySpeedPuzzling team');
    }

    public function testEverybodyElseHasNoBadge(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.player-head-role');
    }
}
