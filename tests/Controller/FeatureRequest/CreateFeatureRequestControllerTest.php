<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\FeatureRequest;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CreateFeatureRequestControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirected(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/feature-requests/new');

        $this->assertResponseRedirects();
    }

    public function testNonMemberIsRedirectedWithFlash(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/feature-requests/new');

        $this->assertResponseRedirects();
    }

    public function testMemberCanAccessForm(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/feature-requests/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testMemberCanSubmitForm(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/feature-requests/new');
        $this->assertResponseIsSuccessful();

        $browser->submitForm('Submit Suggestion', [
            'feature_request_form[title]' => 'Test from controller test',
            'feature_request_form[description]' => 'Testing the form submission flow end to end.',
        ]);

        $this->assertResponseRedirects();
        $browser->followRedirect();
        $this->assertResponseIsSuccessful();
    }
}
