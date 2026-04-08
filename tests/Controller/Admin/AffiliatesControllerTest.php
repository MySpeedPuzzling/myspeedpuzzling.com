<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AffiliatesControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/affiliates');

        $this->assertResponseRedirects('/login');
    }

    public function testActiveTabNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/affiliates?tab=active');

        $this->assertResponseRedirects('/login');
    }

    public function testSuspendedTabNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/affiliates?tab=suspended');

        $this->assertResponseRedirects('/login');
    }

    public function testSuspendNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/affiliates/' . PlayerFixture::PLAYER_REGULAR . '/suspend');

        $this->assertResponseRedirects('/login');
    }

    public function testUnsuspendNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/affiliates/' . PlayerFixture::PLAYER_WITH_STRIPE . '/unsuspend');

        $this->assertResponseRedirects('/login');
    }
}
