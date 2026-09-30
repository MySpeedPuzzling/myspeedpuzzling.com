<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Message\ChangeCollectionDisplayMode;
use SpeedPuzzling\Web\Message\EditFeaturesOptions;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CollectionDisplayMode;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

final class CollectionDetailControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);

        $this->assertResponseIsSuccessful();
    }

    /**
     * The "edit comment" link is the owner's - anybody else only ever got a 404 (other players) or a
     * redirect to /login (guests - Googlebot followed thousands of them).
     */
    public function testOnlyTheOwnerGetsTheCommentEditLink(): void
    {
        $pages = [
            '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC,
            '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE,
        ];
        $items = '[id^="library-collection-"]';
        $editLinks = 'a[href^="/en/edit-collection-item-comment/"]';

        // Guest - crawlers included
        $browser = self::createClient();
        foreach ($pages as $page) {
            $crawler = $browser->request('GET', $page);
            $this->assertResponseIsSuccessful();
            self::assertGreaterThan(0, $crawler->filter($items)->count(), $page);
            self::assertCount(0, $crawler->filter($editLinks), $page);
        }

        // Another player
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        foreach ($pages as $page) {
            $crawler = $browser->request('GET', $page);
            $this->assertResponseIsSuccessful();
            self::assertGreaterThan(0, $crawler->filter($items)->count(), $page);
            self::assertCount(0, $crawler->filter($editLinks), $page);
        }

        // The owner: one per item, nofollow
        self::ensureKernelShutdown();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        foreach ($pages as $page) {
            $crawler = $browser->request('GET', $page);
            $this->assertResponseIsSuccessful();
            $links = $crawler->filter($editLinks);
            self::assertCount($crawler->filter($items)->count(), $links, $page);
            self::assertSame(array_fill(0, $links->count(), 'nofollow'), $links->each(static fn (Crawler $link): null|string => $link->attr('rel')), $page);
        }
    }

    /**
     * The item menu holds the owner's actions on the owner's lists - shown to anybody else it offered
     * them the owner's list states and buttons that did nothing. A signed-in visitor gets a heart for
     * their own wishlist instead; a guest gets neither.
     */
    public function testOnlyTheOwnerGetsTheItemMenuAVisitorTheirOwnWishlistHeart(): void
    {
        $pages = [
            '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC,
            '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE,
        ];
        $items = '[id^="library-collection-"]';
        $menus = '[id^="library-collection-"] .dropdown-toggle';
        $hearts = '[id^="library-collection-"] .puzzle-wishlist-toggle';

        // Guest - crawlers included
        $browser = self::createClient();
        foreach ($pages as $page) {
            $crawler = $browser->request('GET', $page);
            $this->assertResponseIsSuccessful();
            self::assertGreaterThan(0, $crawler->filter($items)->count(), $page);
            self::assertCount(0, $crawler->filter($menus), $page);
            self::assertCount(0, $crawler->filter($hearts), $page);
        }

        // Another player: their own heart on every card, never the owner's menu
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        foreach ($pages as $page) {
            $crawler = $browser->request('GET', $page);
            $this->assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter($menus), $page);
            self::assertCount($crawler->filter($items)->count(), $crawler->filter($hearts), $page);

            $crawler->filter($hearts . ' form')->each(static function (Crawler $form) use ($page): void {
                self::assertSame('wishlist-toggle', $form->filter('input[name="context"]')->attr('value'), $page);
                self::assertMatchesRegularExpression('#^/en/(wishlist/[0-9a-f-]+/add|remove-from-wish-list/[0-9a-f-]+)$#', (string) $form->attr('action'), $page);
            });
        }

        // The owner: the menu on every card, no heart
        self::ensureKernelShutdown();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        foreach ($pages as $page) {
            $crawler = $browser->request('GET', $page);
            $this->assertResponseIsSuccessful();
            self::assertCount($crawler->filter($items)->count(), $crawler->filter($menus), $page);
            self::assertCount(0, $crawler->filter($hearts), $page);
        }
    }

    public function testTheHeartTogglesTheVisitorsOwnWishlist(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $streamHeaders = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];

        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);
        $this->assertResponseIsSuccessful();
        $addForm = $crawler->filter('.puzzle-wishlist-toggle form[action$="/add"]')->first();
        self::assertCount(1, $addForm, 'the visitor wishes for none of the collection yet');
        $heartId = (string) $addForm->ancestors()->filter('.puzzle-wishlist-toggle')->attr('id');
        $puzzleId = substr($heartId, strlen('wishlist-toggle-'));

        // Add: the heart fills, nothing else on the page changes
        $browser->request('POST', (string) $addForm->attr('action'), [
            'context' => 'wishlist-toggle',
            'removeOnCollectionAdd' => '1',
        ], server: $streamHeaders);
        $this->assertResponseIsSuccessful();
        $heart = self::replacedHeart($browser, $heartId);
        self::assertSame('/en/remove-from-wish-list/' . $puzzleId, $heart->filter('form')->attr('action'));
        self::assertSame('true', $heart->filter('button')->attr('aria-pressed'));

        // It is the visitor's wishlist that holds it now
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);
        self::assertCount(1, $crawler->filter('#' . $heartId . ' form[action="/en/remove-from-wish-list/' . $puzzleId . '"]'));

        // Remove: back to the empty heart
        $browser->request('POST', '/en/remove-from-wish-list/' . $puzzleId, ['context' => 'wishlist-toggle'], server: $streamHeaders);
        $this->assertResponseIsSuccessful();
        $heart = self::replacedHeart($browser, $heartId);
        self::assertSame('/en/wishlist/' . $puzzleId . '/add', $heart->filter('form')->attr('action'));
        self::assertSame('false', $heart->filter('button')->attr('aria-pressed'));
    }

    private static function replacedHeart(KernelBrowser $browser, string $heartId): Crawler
    {
        $stream = new Crawler((string) $browser->getResponse()->getContent());
        $replacement = $stream->filter('turbo-stream[action="replace"][target="' . $heartId . '"] template');
        self::assertCount(1, $replacement);

        return new Crawler((string) $replacement->html());
    }

    /**
     * A card the owner changes from the collection page comes back as a Turbo Stream, rendered
     * without the collection - the edit link must survive it.
     */
    public function testCommentEditLinkSurvivesTheOwnersStreamUpdate(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('POST', '/en/wishlist/' . PuzzleFixture::PUZZLE_500_02 . '/add', [
            'context' => 'collection-detail',
            'collection_id' => CollectionFixture::COLLECTION_PUBLIC,
        ], server: [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
        ]);

        $this->assertResponseIsSuccessful();
        $stream = new Crawler((string) $browser->getResponse()->getContent());
        $card = new Crawler((string) $stream->filter('turbo-stream[action="replace"][target="library-collection-' . PuzzleFixture::PUZZLE_500_02 . '"] template')->html());
        $link = $card->filter('a[href^="/en/edit-collection-item-comment/"]');
        self::assertCount(1, $link);
        self::assertSame('nofollow', $link->attr('rel'));
    }

    public function testOwnCollectionPagesLinkToThePuzzlePicker(): void
    {
        $browser = self::createClient();
        $browser->followRedirects(); // picker draw URLs redirect once to carry their seed

        // Guest: no button
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);
        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.puzzle-picker-collection-link'));

        // Member on her own custom collection: the picker with this very collection preselected
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);
        $this->assertResponseIsSuccessful();
        $link = $crawler->filter('.puzzle-picker-collection-link');
        self::assertCount(1, $link);
        self::assertSame('/en/what-to-solve-next?collections%5B0%5D=' . CollectionFixture::COLLECTION_PUBLIC, $link->attr('href'));
        self::assertSame('nofollow', $link->attr('rel'));
        self::assertStringContainsString('Pick from this collection', $link->text());

        // ... and on her system collection: the sentinel id
        $crawler = $browser->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE);
        $this->assertResponseIsSuccessful();
        self::assertSame('/en/what-to-solve-next?collections%5B0%5D=' . rawurlencode(Collection::SYSTEM_ID), $crawler->filter('.puzzle-picker-collection-link')->attr('href'));

        // Somebody else's collection: no button
        $crawler = $browser->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_REGULAR);
        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.puzzle-picker-collection-link'));

        // Non-member on his own collection: specific collections are members-only, so the whole shelf
        self::ensureKernelShutdown();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PRIVATE);
        $this->assertResponseIsSuccessful();
        self::assertSame('/en/what-to-solve-next?source=mine', $crawler->filter('.puzzle-picker-collection-link')->attr('href'));

        // The deep link really lands on a picker restricted to that collection
        self::ensureKernelShutdown();
        $browser = self::createClient();
        $browser->followRedirects(); // picker draw URLs redirect once to carry their seed
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/what-to-solve-next?collections%5B0%5D=' . CollectionFixture::COLLECTION_STRIPE_TREFL);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('of 3 matching puzzles', (string) $browser->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('.puzzle-picker-filter-bar a[title="Remove this filter"]:contains("My Trefl Collection")'));
    }

    public function testDisplayControlIsOfferedToSignedInViewersOnly(): void
    {
        $browser = self::createClient();

        // Guest: no control, no times - and nothing of the new column is read
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);
        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.collection-display'));
        self::assertCount(0, $crawler->filter('.collection-my-times'));
        self::assertCount(0, $crawler->filter('[data-my-fastest-seconds]'));

        // Non-member on somebody else's public collection: the control, "+ predictions" locked
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.collection-display'));
        self::assertStringContainsString('Display', $crawler->filter('.collection-display-toggle')->text());
        self::assertStringNotContainsString('My times', $crawler->filter('.collection-display-toggle')->text(), 'Off by default: the toggle shows no mode');
        self::assertCount(1, $crawler->filter('.collection-display button[type="submit"][value="off"]'));
        self::assertCount(1, $crawler->filter('.collection-display button[type="submit"][value="times"]'));
        self::assertCount(0, $crawler->filter('.collection-display button[type="submit"][value="times_predictions"]'));
        $locked = $crawler->filter('.collection-display .collection-display-locked');
        self::assertCount(1, $locked);
        self::assertSame('#membersExclusiveModal', $locked->attr('data-bs-target'));
        self::assertCount(1, $locked->filter('.ci-locked'));
        self::assertCount(0, $crawler->filter('.collection-my-times'), 'Off by default');
        self::assertSame('/en/collection-display-mode?return=/en/collection/' . CollectionFixture::COLLECTION_PUBLIC, $crawler->filter('.collection-display form')->attr('action'));

        // Member: the third option is a real choice
        self::ensureKernelShutdown();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.collection-display button[type="submit"][value="times_predictions"]'));
        self::assertCount(0, $crawler->filter('.collection-display .collection-display-locked'));
        self::assertCount(0, $crawler->filter('.collection-display .collection-display-opted-out'));

        // Member who opted out of time predictions: the option is disabled, with the notice + settings link
        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new EditFeaturesOptions(
            playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            streakOptedOut: false,
            rankingOptedOut: false,
            timePredictionsOptedOut: true,
        ));
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);
        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.collection-display button[type="submit"][value="times_predictions"]'));
        self::assertCount(0, $crawler->filter('.collection-display .collection-display-locked'));
        self::assertCount(1, $crawler->filter('.collection-display .collection-display-opted-out'));
        self::assertStringContainsString('You have opted out of time predictions', $crawler->filter('.collection-display')->text());
        self::assertCount(1, $crawler->filter('.collection-display a[href="/en/edit-profile"]'));
    }

    public function testMyTimesModeShowsTheViewersOwnResultsNextToEveryItem(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $this->setDisplayMode($browser, PlayerFixture::PLAYER_REGULAR, CollectionDisplayMode::Times);

        // Own private collection "Completed Favorites": PUZZLE_500_01, PUZZLE_500_02
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_FAVORITES);
        $this->assertResponseIsSuccessful();

        self::assertStringContainsString('Display: My times', $crawler->filter('.collection-display-toggle')->text());
        self::assertCount(1, $crawler->filter('.collection-display button[value="times"].active'));
        self::assertCount(2, $crawler->filter('.collection-my-times'));

        // PLAYER_REGULAR on PUZZLE_500_02: 36:40 -> 31:40 -> 28:20 (10 days ago); latest = fastest -> no latest line
        $item = $crawler->filter('#library-collection-' . PuzzleFixture::PUZZLE_500_02);
        self::assertSame('1700', $item->attr('data-my-fastest-seconds'));
        self::assertSame('', $item->attr('data-predicted-seconds'));
        self::assertSame('Solved 3×', $item->filter('.collection-my-times-solved')->text());
        self::assertStringContainsString('fastest 00:28:20', $item->filter('.collection-my-times-fastest')->text());
        self::assertCount(1, $item->filter('.collection-my-times-fastest .bi-star-fill'));
        self::assertCount(0, $item->filter('.collection-my-times-latest'));
        self::assertStringContainsString('last time', $item->filter('.collection-my-times')->text());
        self::assertCount(0, $item->filter('.collection-prediction'), '"My times" alone never shows a prediction');

        // Somebody else's public collection: still MY times (PUZZLE_500_02 is in there too), and
        // "Not solved yet" for what I never touched (PUZZLE_1000_05)
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);
        $this->assertResponseIsSuccessful();
        self::assertSame('Solved 3×', $crawler->filter('#library-collection-' . PuzzleFixture::PUZZLE_500_02 . ' .collection-my-times-solved')->text());
        $unsolved = $crawler->filter('#library-collection-' . PuzzleFixture::PUZZLE_1000_05);
        self::assertCount(1, $unsolved->filter('.collection-my-times-unsolved'));
        self::assertStringContainsString('Not solved yet', $unsolved->filter('.collection-my-times')->text());
        self::assertSame('', $unsolved->attr('data-my-fastest-seconds'));

        // Own system collection: PUZZLE_1000_02 has a slower latest time (3950 -> 4500) and an
        // untimed relax solve, PUZZLE_1000_01 only a duo row (no solo time)
        $crawler = $browser->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_REGULAR);
        $this->assertResponseIsSuccessful();
        $item = $crawler->filter('#library-collection-' . PuzzleFixture::PUZZLE_1000_02);
        self::assertSame('Solved 3×', $item->filter('.collection-my-times-solved')->text());
        self::assertStringContainsString('fastest 01:05:50', $item->filter('.collection-my-times-fastest')->text());
        self::assertStringContainsString('latest 01:15:00', $item->filter('.collection-my-times-latest')->text());
        self::assertSame('3950', $item->attr('data-my-fastest-seconds'));
        $duo = $crawler->filter('#library-collection-' . PuzzleFixture::PUZZLE_1000_01);
        self::assertSame('Solved 1×', $duo->filter('.collection-my-times-solved')->text());
        self::assertCount(0, $duo->filter('.collection-my-times-fastest'));
        self::assertStringContainsString('No solo time yet', $duo->filter('.collection-my-times')->text());
        self::assertSame('', $duo->attr('data-my-fastest-seconds'));

        // Back to Off: nothing rendered, nothing computed
        $this->setDisplayMode($browser, PlayerFixture::PLAYER_REGULAR, CollectionDisplayMode::Off);
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_FAVORITES);
        self::assertCount(0, $crawler->filter('.collection-my-times'));
        self::assertCount(0, $crawler->filter('[data-my-fastest-seconds]'));
    }

    public function testPredictionsPillForAnEligibleMemberOnly(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setDisplayMode($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionDisplayMode::TimesPredictions);

        // Own public collection: PUZZLE_500_01 solved once (2100) -> personal prediction pill
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Display: My times + predictions', $crawler->filter('.collection-display-toggle')->text());
        self::assertCount(1, $crawler->filter('.collection-display button[value="times_predictions"].active'));

        $item = $crawler->filter('#library-collection-' . PuzzleFixture::PUZZLE_500_01);
        self::assertSame('Solved 1×', $item->filter('.collection-my-times-solved')->text());
        self::assertSame('2100', $item->attr('data-my-fastest-seconds'));
        $pill = $item->filter('.collection-prediction');
        self::assertCount(1, $pill);
        self::assertMatchesRegularExpression('/~\d+min/', $pill->text());
        self::assertStringContainsString('Your prediction:', (string) $pill->attr('title'));
        self::assertStringContainsString('Based on your 1 previous solves', (string) $pill->attr('title'));
        self::assertCount(1, $pill->filter('.bi-person-check'), 'personalised marker');
        self::assertMatchesRegularExpression('/^\d+$/', (string) $item->attr('data-predicted-seconds'));

        // A non-member with the same persisted value is served "My times" only
        self::ensureKernelShutdown();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $this->setDisplayMode($browser, PlayerFixture::PLAYER_REGULAR, CollectionDisplayMode::TimesPredictions);
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_FAVORITES);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Display: My times', $crawler->filter('.collection-display-toggle')->text());
        self::assertStringNotContainsString('predictions', $crawler->filter('.collection-display-toggle')->text());
        self::assertCount(2, $crawler->filter('.collection-my-times'));
        self::assertCount(0, $crawler->filter('.collection-prediction'));
        self::assertCount(0, $crawler->filter('[data-predicted-seconds]:not([data-predicted-seconds=""])'));
    }

    private function setDisplayMode(KernelBrowser $browser, string $playerId, CollectionDisplayMode $mode): void
    {
        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new ChangeCollectionDisplayMode($playerId, $mode));
    }
}
