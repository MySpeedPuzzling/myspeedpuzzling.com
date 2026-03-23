<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\FeatureRequest;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FeatureRequestsListControllerTest extends WebTestCase
{
    public function testPageLoadsForAnonymousUser(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/feature-requests');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Feature Requests');
    }

    public function testPageLoadsForAuthenticatedUser(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/feature-requests');

        $this->assertResponseIsSuccessful();
    }

    public function testPageLoadsForMember(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/feature-requests');

        $this->assertResponseIsSuccessful();
    }

    public function testHintBannerIsVisibleForNewUsers(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/feature-requests');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.alert-info');
    }
}
