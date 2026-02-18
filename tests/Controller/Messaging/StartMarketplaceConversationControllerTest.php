<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Messaging;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StartMarketplaceConversationControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/messages/new/offer/' . SellSwapListItemFixture::SELLSWAP_01);

        $this->assertResponseRedirects();
    }

    public function testPageLoadsWithListingContext(): void
    {
        $browser = self::createClient();

        // PLAYER_REGULAR is not the owner of SELLSWAP_01 (owned by PLAYER_WITH_STRIPE)
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/messages/new/offer/' . SellSwapListItemFixture::SELLSWAP_01);

        $this->assertResponseIsSuccessful();
    }

    public function testCannotContactYourself(): void
    {
        $browser = self::createClient();

        // PLAYER_WITH_STRIPE is the owner of SELLSWAP_01
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/messages/new/offer/' . SellSwapListItemFixture::SELLSWAP_01);

        $this->assertResponseRedirects('/en/messages');
    }

    public function testSubmittingCreatesConversation(): void
    {
        $browser = self::createClient();

        // PLAYER_ADMIN is not the owner of SELLSWAP_01
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/messages/new/offer/' . SellSwapListItemFixture::SELLSWAP_01);
        $this->assertResponseIsSuccessful();

        $browser->submitForm('Send message', [
            'message' => 'Hi, I am interested in your puzzle!',
        ]);

        $this->assertResponseRedirects('/en/messages');
    }
}
