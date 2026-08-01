<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Rating;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SoldSwappedItemFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RateTransactionControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/rate-transaction/' . SoldSwappedItemFixture::SOLD_RECENT);

        $this->assertResponseRedirects();
    }

    public function testFormLoadsForEligibleTransaction(): void
    {
        $browser = self::createClient();

        // PLAYER_REGULAR is buyer of SOLD_RECENT
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/rate-transaction/' . SoldSwappedItemFixture::SOLD_RECENT);

        $this->assertResponseIsSuccessful();
    }

    public function testFormRedirectsForAlreadyRatedTransaction(): void
    {
        $browser = self::createClient();

        // PLAYER_ADMIN already rated SOLD_01
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/rate-transaction/' . SoldSwappedItemFixture::SOLD_01);

        $this->assertResponseRedirects();
    }

    /**
     * Regression guard: `?return=` used to be handed straight to redirect(). The
     * "already rated" branch fires on a plain GET, so a crafted link was a
     * one-click open redirect for any signed-in visitor.
     */
    public function testHostileReturnUrlIsIgnoredOnTheCannotRateBranch(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request(
            'GET',
            '/en/rate-transaction/' . SoldSwappedItemFixture::SOLD_01 . '?return=https://evil.example.com/phish',
        );

        $location = (string) $browser->getResponse()->headers->get('Location');

        self::assertResponseRedirects();
        self::assertStringNotContainsString('evil.example.com', $location);
        self::assertStringStartsWith('/', $location);
    }

    public function testSchemeRelativeReturnUrlIsIgnored(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        // Backslash form: browsers normalise it to "//", so it leaves the site
        $browser->request(
            'GET',
            '/en/rate-transaction/' . SoldSwappedItemFixture::SOLD_01 . '?return=' . rawurlencode('/\\evil.example.com'),
        );

        $location = (string) $browser->getResponse()->headers->get('Location');

        self::assertStringNotContainsString('evil.example.com', $location);
    }

    public function testSameSiteReturnUrlIsHonored(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request(
            'GET',
            '/en/rate-transaction/' . SoldSwappedItemFixture::SOLD_01 . '?return=' . rawurlencode('/en/marketplace'),
        );

        self::assertResponseRedirects('/en/marketplace');
    }

    public function testFormSubmissionCreatesRating(): void
    {
        $browser = self::createClient();

        // PLAYER_WITH_STRIPE is seller of SOLD_RECENT, can rate
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/rate-transaction/' . SoldSwappedItemFixture::SOLD_RECENT);

        $this->assertResponseIsSuccessful();

        $browser->submitForm('Submit Rating', [
            'stars' => '4',
            'review_text' => 'Nice transaction!',
        ]);

        $this->assertResponseRedirects();
    }
}
