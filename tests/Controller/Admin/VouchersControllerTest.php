<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VouchersControllerTest extends WebTestCase
{
    public function testVouchersListIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/vouchers');

        $this->assertResponseRedirects('/login?return=/admin/vouchers');
    }

    public function testVouchersAvailableTabNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/vouchers?tab=available');

        $this->assertResponseRedirects('/login?return=/admin/vouchers?tab%3Davailable');
    }

    public function testVouchersUsedTabNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/vouchers?tab=used');

        $this->assertResponseRedirects('/login?return=/admin/vouchers?tab%3Dused');
    }

    public function testVouchersExpiredTabNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/vouchers?tab=expired');

        $this->assertResponseRedirects('/login?return=/admin/vouchers?tab%3Dexpired');
    }
}
