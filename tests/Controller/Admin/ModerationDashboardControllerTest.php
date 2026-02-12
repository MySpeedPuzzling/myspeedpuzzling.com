<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ModerationDashboardControllerTest extends WebTestCase
{
    public function testDashboardIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/moderation');

        $this->assertResponseRedirects('/login');
    }

    public function testReportDetailIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/moderation/report/00000000-0000-0000-0000-000000000000');

        $this->assertResponseRedirects('/login');
    }

    public function testConversationLogIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/moderation/conversation/00000000-0000-0000-0000-000000000000');

        $this->assertResponseRedirects('/login');
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

        $this->assertResponseRedirects('/login');
    }
}
