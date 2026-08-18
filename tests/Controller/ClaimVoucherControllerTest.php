<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ClaimVoucherControllerTest extends WebTestCase
{
    public function testClaimVoucherPageIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/claim-voucher');

        $this->assertResponseRedirects('/login?return=/en/claim-voucher');
    }

    public function testClaimVoucherPageCzechLocale(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/uplatnit-voucher');

        $this->assertResponseRedirects('/login?return=/uplatnit-voucher');
    }

    public function testClaimVoucherPageGermanLocale(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/de/gutschein-einloesen');

        $this->assertResponseRedirects('/login?return=/de/gutschein-einloesen');
    }
}
