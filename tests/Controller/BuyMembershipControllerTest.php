<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BuyMembershipControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/buy-membership/yearly');

        $this->assertResponseRedirects();
    }

    // Note: Testing logged-in user requires valid Stripe API configuration
    // and would make external API calls. This should be tested with integration
    // tests that mock the Stripe service, not basic functional tests.
}
