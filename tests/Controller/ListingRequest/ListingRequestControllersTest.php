<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ListingRequest;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\ListingRequest\ListingRequestEmailsUnsubscribeUrl;
use SpeedPuzzling\Web\Tests\ListingRequestSeeding;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sell/swap requests over the web (docs/features/marketplace/12-listing-requests.md): a buyer ticks listings on a
 * seller's list or the marketplace, the sheet groups them by seller, the seller answers on the request page, only the
 * two sides see it.
 */
final class ListingRequestControllersTest extends WebTestCase
{
    use ListingRequestSeeding;

    private const string SAME_ORIGIN = 'http://localhost';

    private KernelBrowser $browser;
    private string $buyer;
    private string $seller;
    private string $otherSeller;
    private string $lighthouse;
    private string $garden;
    private string $meadow;

    protected function setUp(): void
    {
        $this->browser = self::createClient();

        $this->buyer = $this->seedPlayer('Jana', country: 'cz', userId: 'test|listing-buyer');
        $this->seller = $this->seedPlayer('Anna', userId: 'test|listing-seller');
        $this->otherSeller = $this->seedPlayer('Petr', userId: 'test|listing-other-seller');
        $this->lighthouse = $this->seedListing($this->seller, $this->seedPuzzle(1000, 'Lighthouse at Dusk'), shipsTo: ['cz']);
        $this->garden = $this->seedListing($this->seller, $this->seedPuzzle(500, 'Night Garden'), shipsTo: ['cz']);
        $this->meadow = $this->seedListing($this->otherSeller, $this->seedPuzzle(500, 'Meadow'));
    }

    public function testAVisitorCanTickListingsOnTheSellersListButTheSellerCannotTickTheirOwn(): void
    {
        TestingLogin::asPlayer($this->browser, $this->buyer);
        $crawler = $this->browser->request('GET', '/en/sell-swap-list/' . $this->seller);

        $this->assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-testid="listing-select"]'));
        self::assertCount(1, $crawler->filter('[data-testid="listing-selection-bar"]'));

        TestingLogin::asPlayer($this->browser, $this->seller);
        $crawler = $this->browser->request('GET', '/en/sell-swap-list/' . $this->seller);

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="listing-select"]'));
        self::assertCount(1, $crawler->filter('[data-testid="listing-requests-link"]'));
    }

    public function testTheMarketplaceOffersTheSelectionToSignedInPlayersOnly(): void
    {
        $crawler = $this->browser->request('GET', '/en/marketplace');
        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="listing-selection-bar"]'));

        TestingLogin::asPlayer($this->browser, $this->buyer);
        $crawler = $this->browser->request('GET', '/en/marketplace');
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="listing-selection-bar"]'));
        self::assertGreaterThan(0, $crawler->filter('[data-testid="listing-select"]')->count());
    }

    public function testTheSheetAsksEachSellerAndTheSellerAnswersOnTheRequestPage(): void
    {
        TestingLogin::asPlayer($this->browser, $this->buyer);

        $crawler = $this->browser->request('POST', '/en/sell-swap-requests/ask', [
            '_token' => 'csrf-token',
            'listingIds' => [$this->lighthouse, $this->garden, $this->meadow],
        ], server: ['HTTP_ORIGIN' => self::SAME_ORIGIN, 'HTTP_TURBO_FRAME' => 'modal-frame']);
        $this->assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-listing-ask-sheet-target="group"]'));
        self::assertCount(1, $crawler->filter('input[name="listings[' . $this->seller . '][]"][value="' . $this->lighthouse . '"]'));
        $content = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('Ships to you', $content);
        // Two listings of 10 EUR from Anna
        self::assertStringContainsString('20 EUR', $content);

        $this->browser->request('POST', '/en/sell-swap-requests/send', [
            '_token' => 'csrf-token',
            'sendId' => Uuid::uuid7()->toString(),
            'listings' => [$this->seller => [$this->lighthouse, $this->garden], $this->otherSeller => [$this->meadow]],
            'notes' => [$this->seller => 'Ship to Brno?'],
        ], server: ['HTTP_ORIGIN' => self::SAME_ORIGIN, 'HTTP_TURBO_FRAME' => 'modal-frame']);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('2 requests are on their way.', (string) $this->browser->getResponse()->getContent());

        $requestId = $this->requestIdOf($this->buyer, $this->seller);
        $url = '/en/sell-swap-requests/' . $requestId;

        // Nobody else sees the request
        TestingLogin::asPlayer($this->browser, $this->otherSeller);
        $this->browser->request('GET', $url);
        $this->assertResponseStatusCodeSame(404);

        TestingLogin::asPlayer($this->browser, $this->seller);
        $crawler = $this->browser->request('GET', $url);
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="listing-request-answer-form"]'));
        self::assertCount(2, $crawler->filter('[data-testid="listing-request-item"]'));
        self::assertStringContainsString('Ship to Brno?', (string) $this->browser->getResponse()->getContent());

        $itemId = $this->itemIdFor($requestId, $this->lighthouse);
        $this->browser->request('POST', $url, [
            '_token' => 'csrf-token',
            'answers' => [$itemId => 'yes'],
        ], server: ['HTTP_ORIGIN' => self::SAME_ORIGIN]);

        // A yes leads into the chat with the buyer, the listing is reserved for them
        $conversationId = $this->connection()->fetchOne('SELECT id FROM conversation WHERE initiator_id = :seller AND recipient_id = :buyer', ['seller' => $this->seller, 'buyer' => $this->buyer]);
        self::assertIsString($conversationId);
        $this->assertResponseRedirects();
        self::assertStringContainsString($conversationId, (string) $this->browser->getResponse()->headers->get('Location'));
        self::assertSame($this->buyer, $this->connection()->fetchOne('SELECT reserved_for_player_id FROM sell_swap_list_item WHERE id = :id', ['id' => $this->lighthouse]));

        // The request page now offers "Mark as sold to Jana"
        $this->browser->request('GET', $url);
        self::assertStringContainsString('Mark as sold to Jana', (string) $this->browser->getResponse()->getContent());

        $this->browser->request('GET', '/en/sell-swap-requests?tab=received');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('From Jana', (string) $this->browser->getResponse()->getContent());

        TestingLogin::asPlayer($this->browser, $this->buyer);
        $this->browser->request('GET', '/en/sell-swap-requests');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('To Anna', (string) $this->browser->getResponse()->getContent());

        $this->browser->request('GET', $url);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Yes - reserved', (string) $this->browser->getResponse()->getContent());

        // The notifications page renders the answer
        $this->browser->request('GET', '/en/notifications');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('answered your request', (string) $this->browser->getResponse()->getContent());
    }

    public function testABuyerTheSellerBlocksIsNowhereOnTheSellersRequests(): void
    {
        $this->askFor($this->buyer, [$this->seller => [$this->lighthouse]]);
        $requestId = $this->requestIdOf($this->buyer, $this->seller);
        $this->seedBlock($this->seller, $this->buyer);

        TestingLogin::asPlayer($this->browser, $this->seller);
        $this->browser->request('GET', '/en/sell-swap-requests?tab=received');
        $this->assertResponseIsSuccessful();
        self::assertStringNotContainsString('From Jana', (string) $this->browser->getResponse()->getContent());

        $this->browser->request('GET', '/en/sell-swap-requests/' . $requestId);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testTheSignedLinkUnsubscribesOnlyOnPost(): void
    {
        $url = $this->browser->getContainer()->get(ListingRequestEmailsUnsubscribeUrl::class)->forPlayer($this->seller, 'en');

        $this->browser->request('GET', $url);
        $this->assertResponseIsSuccessful();
        self::assertTrue($this->browser->getContainer()->get(PlayerRepository::class)->get($this->seller)->listingRequestEmails);

        $this->browser->request('POST', $url, ['List-Unsubscribe' => 'One-Click']);
        $this->assertResponseIsSuccessful();
        self::assertFalse($this->connection()->fetchOne('SELECT listing_request_emails FROM player WHERE id = :id', ['id' => $this->seller]));

        $this->browser->request('POST', '/en/sell-swap-request-emails/unsubscribe/' . $this->seller);
        $this->assertResponseStatusCodeSame(404);
    }

    private function connection(): Connection
    {
        return $this->browser->getContainer()->get(Connection::class);
    }
}
