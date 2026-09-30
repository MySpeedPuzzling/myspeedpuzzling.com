<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\ModerationActionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ModerationDashboardControllerTest extends WebTestCase
{
    public function testDashboardIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/moderation');

        $this->assertResponseRedirects('/login?return=/admin/moderation');
    }

    public function testReportDetailIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/moderation/report/00000000-0000-0000-0000-000000000000');

        $this->assertResponseRedirects('/login?return=/admin/moderation/report/00000000-0000-0000-0000-000000000000');
    }

    public function testConversationLogIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/moderation/conversation/00000000-0000-0000-0000-000000000000');

        $this->assertResponseRedirects('/login?return=/admin/moderation/conversation/00000000-0000-0000-0000-000000000000');
    }

    public function testResolveReportIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/moderation/report/00000000-0000-0000-0000-000000000000/resolve');

        $this->assertResponseRedirects('/login');
    }

    public function testWarnUserIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/moderation/warn/00000000-0000-0000-0000-000000000000');

        $this->assertResponseRedirects('/login');
    }

    public function testMuteUserIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/moderation/mute/00000000-0000-0000-0000-000000000000');

        $this->assertResponseRedirects('/login');
    }

    public function testHistoryIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/moderation/history/00000000-0000-0000-0000-000000000000');

        $this->assertResponseRedirects('/login?return=/admin/moderation/history/00000000-0000-0000-0000-000000000000');
    }

    public function testHistoryShowsActionsOfADeletedAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        // What the ON DELETE SET NULL leaves behind once the acting admin deleted their account
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE moderation_action SET admin_id = NULL WHERE id = :id',
            ['id' => ModerationActionFixture::ACTION_WARNING],
        );

        $crawler = $browser->request('GET', '/admin/moderation/history/' . PlayerFixture::PLAYER_REGULAR);

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('First warning for inappropriate behavior', $crawler->filter('table')->text());
        self::assertStringContainsString('Deleted user', $crawler->filter('table')->text());
    }
}
