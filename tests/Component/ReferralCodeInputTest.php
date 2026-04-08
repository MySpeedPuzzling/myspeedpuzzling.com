<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class ReferralCodeInputTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testInputIsAlwaysVisible(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_FAVORITES);

        $testComponent = $this->createLiveComponent('ReferralCodeInput', [], $client);
        $testComponent->setRouteLocale('en');

        $rendered = $testComponent->render();
        $html = $rendered->toString();

        $this->assertStringContainsString('data-model="code"', $html);
        $this->assertStringContainsString('ci-heart', $html);
    }

    public function testValidCodeShowsAffiliateName(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_FAVORITES);

        $testComponent = $this->createLiveComponent('ReferralCodeInput', [], $client);
        $testComponent->setRouteLocale('en');

        $testComponent->set('code', 'player1');
        $testComponent->call('validateCode');

        $rendered = $testComponent->render();
        $html = $rendered->toString();

        $this->assertStringContainsString(PlayerFixture::PLAYER_REGULAR_NAME, $html);
        $this->assertStringContainsString('ci-heart', $html);
    }

    public function testInvalidCodeShowsError(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_FAVORITES);

        $testComponent = $this->createLiveComponent('ReferralCodeInput', [], $client);
        $testComponent->setRouteLocale('en');

        $testComponent->set('code', 'INVALIDCODE');
        $testComponent->call('validateCode');

        $rendered = $testComponent->render();
        $html = $rendered->toString();

        $this->assertStringContainsString('invalid-feedback', $html);
    }

    public function testPlayerNotInProgramShowsError(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_FAVORITES);

        $testComponent = $this->createLiveComponent('ReferralCodeInput', [], $client);
        $testComponent->setRouteLocale('en');

        $testComponent->set('code', 'player3');
        $testComponent->call('validateCode');

        $rendered = $testComponent->render();
        $html = $rendered->toString();

        $this->assertStringContainsString('invalid-feedback', $html);
    }

    public function testValidCodeStoresInSession(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_FAVORITES);

        $testComponent = $this->createLiveComponent('ReferralCodeInput', [], $client);
        $testComponent->setRouteLocale('en');

        $testComponent->set('code', 'player1');
        $testComponent->call('validateCode');

        $session = $client->getRequest()->getSession();
        $this->assertSame('player1', $session->get('referral_code'));
    }

    public function testClearCodeRemovesFromSession(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_FAVORITES);

        $testComponent = $this->createLiveComponent('ReferralCodeInput', [], $client);
        $testComponent->setRouteLocale('en');

        $testComponent->set('code', 'player1');
        $testComponent->call('validateCode');

        $testComponent->call('clearCode');

        $session = $client->getRequest()->getSession();
        $this->assertNull($session->get('referral_code'));
    }
}
