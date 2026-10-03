<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Messaging;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Ask to bring it" opens the contact form with the request written (docs/features/marketplace/11-events.md) -
 * only for a marketplace event the listing's seller is going to.
 */
final class AskToBringPrefillTest extends WebTestCase
{
    private const string PREFILL = "/^Hi! Could you bring Puzzle 5 to Puzzle Swap Fair \\(.+\\)\\? I'd love to buy it\\.$/u";

    public function testTheMessageIsWrittenForTheBuyer(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        foreach ([[], ['HTTP_TURBO_FRAME' => 'modal-frame']] as $headers) {
            $crawler = $browser->request('GET', $this->url(SellSwapListItemFixture::SELLSWAP_08, MarketplaceEventFixture::COMPETITION_SWAP_FAIR), server: $headers);

            self::assertResponseIsSuccessful();
            self::assertMatchesRegularExpression(self::PREFILL, $crawler->filter('textarea#message')->text());
        }
    }

    public function testNothingIsWrittenUnlessTheSellerGoesToThatMarketplaceEvent(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $cases = [
            'B does not go to Meetup #1' => CompetitionSeriesFixture::EDITION_OFFLINE_1,
            'online edition B goes to' => CompetitionSeriesFixture::EDITION_EJJ_69,
            'not an id' => 'nonsense',
            'no event' => null,
        ];

        foreach ($cases as $case => $event) {
            $crawler = $browser->request('GET', $this->url(SellSwapListItemFixture::SELLSWAP_08, $event));

            self::assertResponseIsSuccessful();
            self::assertSame('', $crawler->filter('textarea#message')->text(), $case);
        }
    }

    public function testAnOpenConversationGetsTheMessageInItsBox(): void
    {
        // PLAYER_WITH_FAVORITES already talks to A about SELLSWAP_01 (CONVERSATION_MARKETPLACE)
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', $this->url(SellSwapListItemFixture::SELLSWAP_01, MarketplaceEventFixture::COMPETITION_SWAP_FAIR));

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('Hi! Could you bring Puzzle 1 to Puzzle Swap Fair (', $crawler->filter('textarea[name="message"]')->text());
    }

    private function url(string $listItemId, null|string $event): string
    {
        return '/en/messages/new/offer/' . $listItemId . ($event !== null ? '?event=' . $event : '');
    }

    private function signedIn(string $playerId): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }
}
