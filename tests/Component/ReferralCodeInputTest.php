<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Tests\DataFixtures\AffiliateFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class ReferralCodeInputTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testValidCodeShowsAffiliateName(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $testComponent = $this->createLiveComponent('ReferralCodeInput', [], $client);
        $testComponent->setRouteLocale('en');

        $testComponent->set('code', AffiliateFixture::AFFILIATE_ACTIVE_CODE);
        $testComponent->call('validateCode');

        $rendered = $testComponent->render();
        $html = $rendered->toString();

        // Should show the affiliate player's name (PLAYER_REGULAR = "John Doe")
        $this->assertStringContainsString(PlayerFixture::PLAYER_REGULAR_NAME, $html);
        // Should show success state
        $this->assertStringContainsString('alert-success', $html);
    }

    public function testInvalidCodeShowsError(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $testComponent = $this->createLiveComponent('ReferralCodeInput', [], $client);
        $testComponent->setRouteLocale('en');

        // Must expand first, then validate — error is only visible when expanded
        $testComponent->call('expand');
        $testComponent->set('code', 'INVALIDCODE');
        $testComponent->call('validateCode');

        $rendered = $testComponent->render();
        $html = $rendered->toString();

        $this->assertStringContainsString('invalid-feedback', $html);
    }

    public function testPendingAffiliateCodeShowsError(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $testComponent = $this->createLiveComponent('ReferralCodeInput', [], $client);
        $testComponent->setRouteLocale('en');

        $testComponent->call('expand');
        $testComponent->set('code', AffiliateFixture::AFFILIATE_PENDING_CODE);
        $testComponent->call('validateCode');

        $rendered = $testComponent->render();
        $html = $rendered->toString();

        $this->assertStringContainsString('invalid-feedback', $html);
    }

    public function testCaseInsensitiveCodeValidation(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $testComponent = $this->createLiveComponent('ReferralCodeInput', [], $client);
        $testComponent->setRouteLocale('en');

        $testComponent->set('code', strtolower(AffiliateFixture::AFFILIATE_ACTIVE_CODE));
        $testComponent->call('validateCode');

        $rendered = $testComponent->render();
        $html = $rendered->toString();

        $this->assertStringContainsString(PlayerFixture::PLAYER_REGULAR_NAME, $html);
    }

    public function testValidCodeStoresInSession(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $testComponent = $this->createLiveComponent('ReferralCodeInput', [], $client);
        $testComponent->setRouteLocale('en');

        $testComponent->set('code', AffiliateFixture::AFFILIATE_ACTIVE_CODE);
        $testComponent->call('validateCode');

        // Verify session has the referral code
        $session = $client->getRequest()->getSession();
        $this->assertSame(AffiliateFixture::AFFILIATE_ACTIVE_CODE, $session->get('referral_code'));
    }

    public function testClearCodeRemovesFromSession(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $testComponent = $this->createLiveComponent('ReferralCodeInput', [], $client);
        $testComponent->setRouteLocale('en');

        // First set a valid code
        $testComponent->set('code', AffiliateFixture::AFFILIATE_ACTIVE_CODE);
        $testComponent->call('validateCode');

        // Then clear it
        $testComponent->call('clearCode');

        $session = $client->getRequest()->getSession();
        $this->assertNull($session->get('referral_code'));
    }

    public function testExpandShowsInputForm(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $testComponent = $this->createLiveComponent('ReferralCodeInput', [], $client);
        $testComponent->setRouteLocale('en');

        // Initially collapsed - should show expand button
        $rendered = $testComponent->render();
        $this->assertStringContainsString('expand', $rendered->toString());

        // After expand - should show input
        $testComponent->call('expand');
        $rendered = $testComponent->render();
        $this->assertStringContainsString('data-model="code"', $rendered->toString());
    }
}
