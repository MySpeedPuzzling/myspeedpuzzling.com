<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\SellSwap;

use SpeedPuzzling\Web\Message\MarkPuzzleAsSoldOrSwapped;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class MarkAsSoldSwappedControllerTest extends WebTestCase
{
    /**
     * Sentry WEB-CM: the last listing sold from the owner's sell/swap list answered 500 (the empty state rendered the
     * "someone else's list" branch without a player) although the sale was already saved.
     */
    public function testSellingTheLastListingFromTheListShowsTheOwnersEmptyState(): void
    {
        $browser = self::createClient();
        $container = self::getContainer();

        $listings = $container->get(GetSellSwapListItems::class)->byPlayerId(PlayerFixture::PLAYER_ADMIN);
        self::assertGreaterThan(1, count($listings));
        $last = array_pop($listings);

        $messageBus = $container->get(MessageBusInterface::class);
        foreach ($listings as $listing) {
            $messageBus->dispatch(new MarkPuzzleAsSoldOrSwapped(
                sellSwapListItemId: $listing->sellSwapListItemId,
                playerId: PlayerFixture::PLAYER_ADMIN,
                buyerInput: null,
            ));
        }

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/sell-swap/' . $last->sellSwapListItemId . '/mark-sold?context=list');
        $this->assertResponseIsSuccessful();

        $form = $browser->getCrawler()->selectButton('Confirm')->form();
        $browser->submit($form, [], [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
        ]);

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('target="sellswap-list-container"', $content);
        self::assertStringContainsString('Your sell/swap list is empty', $content);
        self::assertSame(0, $container->get(GetSellSwapListItems::class)->countByPlayerId(PlayerFixture::PLAYER_ADMIN));
    }

    public function testFullPageFormSubmitRedirectsToTheOwnersList(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // Without the modal frame the form renders on its own page, with a Cancel link to the owner's list
        $browser->request('GET', '/en/sell-swap/' . SellSwapListItemFixture::SELLSWAP_01 . '/mark-sold');
        $this->assertResponseIsSuccessful();

        $form = $browser->getCrawler()->selectButton('Confirm')->form();
        $browser->submit($form);

        $this->assertResponseRedirects('/en/sell-swap-list/' . PlayerFixture::PLAYER_WITH_STRIPE);
    }

    public function testMarkSoldToPlayerWithoutRefererRedirectsToTheOwnersList(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $url = '/en/sell-swap/' . SellSwapListItemFixture::SELLSWAP_01 . '/mark-sold-to-player/' . PlayerFixture::PLAYER_ADMIN;
        $browser->request('POST', $url);
        $this->assertResponseRedirects('/en/sell-swap-list/' . PlayerFixture::PLAYER_WITH_STRIPE);

        // Already sold: the warning path redirects to the same list
        $browser->request('POST', $url);
        $this->assertResponseRedirects('/en/sell-swap-list/' . PlayerFixture::PLAYER_WITH_STRIPE);
    }

    public function testRemoveReservationWithoutRefererRedirectsToTheOwnersList(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('POST', '/en/sell-swap/' . SellSwapListItemFixture::SELLSWAP_04 . '/unreserve');

        $this->assertResponseRedirects('/en/sell-swap-list/' . PlayerFixture::PLAYER_WITH_STRIPE);
    }
}
