<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\SellSwap;

use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MarkAsReservedControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/sell-swap/' . SellSwapListItemFixture::SELLSWAP_01 . '/reserve');

        $this->assertResponseRedirects();
    }

    public function testOwnerCanAccessModalForm(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/sell-swap/' . SellSwapListItemFixture::SELLSWAP_01 . '/reserve');

        $this->assertResponseIsSuccessful();
    }

    public function testOwnerCanAccessModalViaTurboFrame(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/sell-swap/' . SellSwapListItemFixture::SELLSWAP_01 . '/reserve', [], [], [
            'HTTP_TURBO_FRAME' => 'modal-frame',
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testNonOwnerGets403(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/sell-swap/' . SellSwapListItemFixture::SELLSWAP_01 . '/reserve');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDirectPostWithReservedForPlayerId(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('POST', '/en/sell-swap/' . SellSwapListItemFixture::SELLSWAP_01 . '/reserve', [
            'reservedForPlayerId' => PlayerFixture::PLAYER_ADMIN,
        ]);

        $this->assertResponseRedirects();

        $repository = self::getContainer()->get(SellSwapListItemRepository::class);
        $item = $repository->get(SellSwapListItemFixture::SELLSWAP_01);
        self::assertTrue($item->reserved);
        self::assertNotNull($item->reservedForPlayerId);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $item->reservedForPlayerId->toString());
    }

    public function testFormSubmissionMarksAsReserved(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/sell-swap/' . SellSwapListItemFixture::SELLSWAP_02 . '/reserve');

        $this->assertResponseIsSuccessful();

        $form = $browser->getCrawler()->selectButton('Confirm Reservation')->form();
        $form['mark_as_reserved_form[reservedForInput]'] = '#admin';
        $browser->submit($form);

        $this->assertResponseRedirects();

        $repository = self::getContainer()->get(SellSwapListItemRepository::class);
        $item = $repository->get(SellSwapListItemFixture::SELLSWAP_02);
        self::assertTrue($item->reserved);
        self::assertNotNull($item->reservedForPlayerId);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $item->reservedForPlayerId->toString());
    }

    public function testFormSubmissionWithEmptyInputMarksAsReservedWithoutPlayer(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/sell-swap/' . SellSwapListItemFixture::SELLSWAP_06 . '/reserve');

        $this->assertResponseIsSuccessful();

        $form = $browser->getCrawler()->selectButton('Confirm Reservation')->form();
        $form['mark_as_reserved_form[reservedForInput]'] = '';
        $browser->submit($form);

        $this->assertResponseRedirects();

        $repository = self::getContainer()->get(SellSwapListItemRepository::class);
        $item = $repository->get(SellSwapListItemFixture::SELLSWAP_06);
        self::assertTrue($item->reserved);
        self::assertNull($item->reservedForPlayerId);
    }
}
