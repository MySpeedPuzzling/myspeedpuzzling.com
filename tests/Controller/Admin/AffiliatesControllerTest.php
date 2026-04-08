<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use SpeedPuzzling\Web\Tests\DataFixtures\AffiliateFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AffiliatesControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/affiliates');

        $this->assertResponseRedirects('/login');
    }

    public function testAffiliatesActiveTabNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/affiliates?tab=active');

        $this->assertResponseRedirects('/login');
    }

    public function testAffiliatesSuspendedTabNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/affiliates?tab=suspended');

        $this->assertResponseRedirects('/login');
    }

    public function testAffiliateDetailNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/affiliates/' . AffiliateFixture::AFFILIATE_ACTIVE_ID);

        $this->assertResponseRedirects('/login');
    }

    public function testApproveAffiliateNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/affiliates/' . AffiliateFixture::AFFILIATE_PENDING_ID . '/approve');

        $this->assertResponseRedirects('/login');
    }

    public function testSuspendAffiliateNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/affiliates/' . AffiliateFixture::AFFILIATE_ACTIVE_ID . '/suspend');

        $this->assertResponseRedirects('/login');
    }

    public function testReactivateAffiliateNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/affiliates/' . AffiliateFixture::AFFILIATE_SUSPENDED_ID . '/reactivate');

        $this->assertResponseRedirects('/login');
    }

    public function testMarkPayoutPaidNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/payouts/' . AffiliateFixture::PAYOUT_PENDING_ID . '/mark-paid?affiliateId=' . AffiliateFixture::AFFILIATE_ACTIVE_ID);

        $this->assertResponseRedirects('/login');
    }
}
