<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The player header on the pages that show it (docs/features/player-header.md): who sees which actions, tabs and chips.
 */
final class PlayerHeaderTest extends WebTestCase
{
    public function testSomeoneElsesProfileOffersFavoriteAndMessageAndTheirPublicPages(): void
    {
        $crawler = $this->page(PlayerFixture::PLAYER_ADMIN, '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $actions = $crawler->filter('.player-head .player-head-actions');
        self::assertCount(1, $actions->filter('a.btn-outline-primary[href*="/en/add-player-to-favorites/' . PlayerFixture::PLAYER_WITH_STRIPE . '"]'));
        self::assertCount(1, $actions->filter('a.btn[href="/en/messages/new/' . PlayerFixture::PLAYER_WITH_STRIPE . '"][data-turbo-frame="modal-frame"]'));

        // Favorite, Message, compare, ⋯ - in this order; Favorite can fold to its star next to Message (_player-header.scss)
        $known = ['player-head-favorite', 'player-head-message', 'player-head-compare', 'more-menu'];
        self::assertSame($known, $actions->children()->each(
            static fn (Crawler $child): string => array_values(array_intersect($known, explode(' ', (string) $child->attr('class'))))[0] ?? '?',
        ));
        self::assertSame('Favorite', trim($actions->filter('.player-head-favorite .player-head-favorite-label')->text()));

        // Favorites are personal: not a tab on someone else's pages
        self::assertSame(['Profile', 'Statistics', 'Calendar', 'Library'], $this->tabLabels($crawler));
        self::assertSame('page', $crawler->filter('.player-head-tabs .player-tab.active')->attr('aria-current'));
        self::assertSame('nofollow', $crawler->filter('.player-head-tabs .player-tab[href*="/en/player-statistics/"]')->attr('rel'));

        // The member chip; the tier chip says "not yet" to a member viewer when there is no tier
        self::assertCount(1, $crawler->filter('.player-head .player-chip-member'));
        self::assertStringContainsString('No tier yet', $crawler->filter('.player-head .player-head-chips')->text());

        // The name is the page's one H1
        self::assertCount(1, $crawler->filter('h1'));
        self::assertSame('Sarah Williams', trim($crawler->filter('h1.player-head-name')->text()));
    }

    public function testTheMenuIsInTheHeaderAndInTheCompactBar(): void
    {
        $crawler = $this->page(PlayerFixture::PLAYER_ADMIN, '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertCount(1, $crawler->filter('.player-head .more-menu[data-nosnippet] .dropdown-menu'));
        self::assertCount(1, $crawler->filter('.player-bar .more-menu[data-nosnippet] .dropdown-menu'));
        self::assertNotNull($crawler->filter('.player-bar')->attr('inert'));
        self::assertCount(4, $crawler->filter('.player-bar .player-tab'));
        self::assertCount(1, $crawler->filter('.player-bar .player-tab[href*="/en/player-statistics/"][rel="nofollow"]'));
    }

    public function testAFavoriteIsAFilledButtonThatRemovesIt(): void
    {
        // PLAYER_WITH_FAVORITES has PLAYER_REGULAR in favorites
        $crawler = $this->page(PlayerFixture::PLAYER_WITH_FAVORITES, '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);

        $button = $crawler->filter('.player-head-actions a.btn-primary[href*="/en/remove-player-from-favorites/' . PlayerFixture::PLAYER_REGULAR . '"]');
        self::assertCount(1, $button);
        self::assertSame('Remove from favorites', $button->attr('aria-label'));
        self::assertCount(1, $crawler->filter('.player-head .dropdown-menu a[href*="/en/remove-player-from-favorites/"]'));
    }

    public function testTheFavoriteToggleReturnsToThePageItWasUsedOn(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $library = '/en/puzzle-library/' . PlayerFixture::PLAYER_WITH_STRIPE;
        $browser->request('GET', '/en/add-player-to-favorites/' . PlayerFixture::PLAYER_WITH_STRIPE . '?return=' . rawurlencode($library));

        self::assertResponseRedirects($library);

        // Anything else than a path on this site is ignored
        $browser->request('GET', '/en/remove-player-from-favorites/' . PlayerFixture::PLAYER_WITH_STRIPE . '?return=' . rawurlencode('https://evil.example/'));

        self::assertResponseRedirects('/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);
    }

    public function testOwnProfileOffersShareAndEditAndAllPagesIncludingFavorites(): void
    {
        $crawler = $this->page(PlayerFixture::PLAYER_WITH_STRIPE, '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $actions = $crawler->filter('.player-head .player-head-actions');
        self::assertCount(1, $actions->filter('button.btn-primary[data-controller="share-link"]'));
        self::assertCount(1, $actions->filter('a.btn[href="/en/edit-profile"]'));
        self::assertCount(0, $crawler->filter('[href*="/en/add-player-to-favorites/"]'));
        self::assertCount(0, $crawler->filter('[data-bs-target="#blockPlayerModal"]'));

        self::assertSame(['Profile', 'Statistics', 'Calendar', 'Library', 'Favorites'], $this->tabLabels($crawler));

        $menu = $crawler->filter('.player-head .dropdown-menu');
        self::assertCount(1, $menu->filter('a[href="/en/export-puzzler-data/' . PlayerFixture::PLAYER_WITH_STRIPE . '"]'));
        // Only a private profile gets the allow list in the menu
        self::assertCount(0, $menu->filter('a[href="/en/who-can-see-my-profile"]'));
    }

    public function testOwnPrivateProfileLinksTheAllowListAndHasNoTierChip(): void
    {
        $crawler = $this->page(PlayerFixture::PLAYER_PRIVATE, '/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE);

        self::assertCount(1, $crawler->filter('.player-head-chips a.player-chip[href="/en/who-can-see-my-profile"]'));
        self::assertCount(1, $crawler->filter('.player-head .dropdown-menu a[href="/en/who-can-see-my-profile"]'));
        self::assertCount(0, $crawler->filter('.player-chip-tier'));
        self::assertStringNotContainsString('No tier yet', $crawler->filter('.player-head')->text());
    }

    public function testGuestSeesTheLockedTierAndNoMessage(): void
    {
        $crawler = $this->page(null, '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);

        self::assertCount(1, $crawler->filter('.player-head-chips button.player-chip[data-bs-target="#membersExclusiveModal"]'));
        self::assertCount(1, $crawler->filter('.player-head-actions > a[href*="/en/add-player-to-favorites/"]'));
        self::assertCount(0, $crawler->filter('.player-head-actions a[data-turbo-frame="modal-frame"]'));
        self::assertCount(0, $crawler->filter('[data-bs-target="#blockPlayerModal"]'));
    }

    public function testHiddenPrivateProfileShowsNoNameNoChipsNoTabs(): void
    {
        $crawler = $this->page(null, '/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE);

        self::assertSame('Hidden Puzzler', trim($crawler->filter('h1.player-head-name')->text()));
        self::assertCount(0, $crawler->filter('.player-head-chips'));
        self::assertCount(0, $crawler->filter('.player-tabs'));
        // Nothing to compare: no button, no menu items
        self::assertCount(0, $crawler->filter('.player-head-compare'));
        self::assertCount(0, $crawler->filter('.dropdown-menu [href^="/login?return="]'));
        self::assertStringNotContainsString('comparison', $crawler->filter('.player-head .dropdown-menu')->text());
        self::assertCount(1, $crawler->filter('.player-head-actions[data-compact-bar-target="head"]'));
    }

    public function testCompareAddsSomebodyNotInTheViewersLineUp(): void
    {
        // PLAYER_ADMIN's line-ups are empty
        $crawler = $this->page(PlayerFixture::PLAYER_ADMIN, '/en/puzzle-library/' . PlayerFixture::PLAYER_WITH_STRIPE);

        $form = $crawler->filter('.player-head-actions > form.player-head-compare');
        self::assertCount(1, $form);
        self::assertSame('/en/compare/add', $form->attr('action'));
        self::assertSame('post', $form->attr('method'));
        self::assertSame('p-' . PlayerFixture::PLAYER_WITH_STRIPE, $form->filter('input[name="subject"]')->attr('value'));
        self::assertSame('/en/puzzle-library/' . PlayerFixture::PLAYER_WITH_STRIPE, $form->filter('input[name="return"]')->attr('value'));
        self::assertNotEmpty($form->filter('input[name="_token"]')->attr('value'));

        // An icon only: the label is its name
        $button = $form->filter('button[type="submit"].more-btn');
        self::assertSame('Add to comparison', $button->attr('aria-label'));
        self::assertSame('Add to comparison', $button->attr('title'));
        self::assertSame('', trim($button->text()));

        // The ⋯ menu - in the header and in the compact bar - offers the same, without ids
        foreach (['.player-head', '.player-bar'] as $where) {
            $menu = $crawler->filter($where . ' .dropdown-menu');
            self::assertCount(1, $menu->filter('form[action="/en/compare/add"] button.dropdown-item'), $where);
            self::assertStringContainsString('Add to comparison', $menu->text(), $where);
            self::assertCount(0, $menu->filter('form[action="/en/compare/remove"]'), $where);
            self::assertCount(0, $menu->filter('[id]'), $where);
        }

        // The old 1:1 page is gone from the header
        self::assertCount(0, $crawler->filter('[href*="/compare-with-puzzler/"]'));
    }

    public function testCompareOpensTheComparisonWhenTheyAreInTheLineUp(): void
    {
        // PLAYER_WITH_STRIPE has PLAYER_ADMIN in the Solo line-up
        $crawler = $this->page(PlayerFixture::PLAYER_WITH_STRIPE, '/en/player-profile/' . PlayerFixture::PLAYER_ADMIN);

        self::assertCount(0, $crawler->filter('.player-head-actions form.player-head-compare'));
        $link = $crawler->filter('.player-head-actions > .player-head-compare > a.more-btn.is-active');
        self::assertCount(1, $link);
        self::assertSame('/en/compare?kind=solo', $link->attr('href'));
        self::assertSame('Open comparison', $link->attr('title'));
        self::assertSame('Open comparison', $link->attr('aria-label'));

        $menu = $crawler->filter('.player-head .dropdown-menu');
        self::assertCount(1, $menu->filter('a.dropdown-item[href="/en/compare?kind=solo"]'));
        $remove = $menu->filter('form[action="/en/compare/remove"]');
        self::assertCount(1, $remove);
        self::assertSame('p-' . PlayerFixture::PLAYER_ADMIN, $remove->filter('input[name="subject"]')->attr('value'));
        self::assertStringContainsString('Remove from comparison', $remove->text());
        self::assertCount(0, $menu->filter('form[action="/en/compare/add"]'));
    }

    public function testGuestIsAskedToSignInFirst(): void
    {
        $crawler = $this->page(null, '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);

        // Back to the profile after signing in
        $link = $crawler->filter('.player-head-actions > .player-head-compare > a.more-btn');
        self::assertCount(1, $link);
        self::assertStringStartsWith('/login?return=', (string) $link->attr('href'));
        self::assertStringContainsString(PlayerFixture::PLAYER_REGULAR, rawurldecode((string) $link->attr('href')));
        self::assertSame('Add to comparison', $link->attr('aria-label'));
        self::assertSame($link->attr('href'), $crawler->filter('.player-head .dropdown-menu a[href^="/login?return="]')->attr('href'));

        // No forms for guests - the page stays the same for every guest
        self::assertCount(0, $crawler->filter('form[action^="/en/compare/"]'));
        self::assertCount(0, $crawler->filter('.player-head-message'));
    }

    public function testOwnProfileHasNoCompare(): void
    {
        $crawler = $this->page(PlayerFixture::PLAYER_WITH_STRIPE, '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertCount(0, $crawler->filter('.player-head-compare'));
        self::assertCount(0, $crawler->filter('form[action^="/en/compare/"]'));
        self::assertCount(0, $crawler->filter('.player-head-favorite'));
    }

    public function testPrivateProfileHiddenFromTheViewerHasNoCompare(): void
    {
        $browser = self::createClient();

        // PLAYER_ADMIN is not on PLAYER_PRIVATE's allow list
        $crawler = $this->page(PlayerFixture::PLAYER_ADMIN, '/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE, $browser);

        self::assertCount(0, $crawler->filter('.player-head-compare'));
        self::assertCount(0, $crawler->filter('form[action^="/en/compare/"]'));

        // …while PLAYER_WITH_FAVORITES, who is on it, may compare her
        $crawler = $this->page(PlayerFixture::PLAYER_WITH_FAVORITES, '/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE, $browser);
        self::assertCount(1, $crawler->filter('.player-head-actions > form.player-head-compare'));
    }

    public function testRankingOptOutHidesTheTierChipCompletely(): void
    {
        $browser = self::createClient();
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET ranking_opted_out = true WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        $crawler = $this->page(PlayerFixture::PLAYER_ADMIN, '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE, $browser);
        self::assertStringNotContainsString('No tier yet', $crawler->filter('.player-head')->text());
        self::assertCount(0, $crawler->filter('.player-chip-tier'));

        $crawler = $this->page(null, '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE, $browser);
        self::assertCount(0, $crawler->filter('[data-bs-target="#membersExclusiveModal"].player-chip'));
    }

    public function testMemberViewerSeesTheTier(): void
    {
        $browser = self::createClient();
        $browser->getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO player_skill (id, player_id, pieces_count, skill_score, skill_tier, skill_percentile, confidence, qualifying_puzzles_count, computed_at)
             VALUES (gen_random_uuid(), :player, 500, 0.8, 5, 87.6, 'high', 12, now())",
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        $crawler = $this->page(PlayerFixture::PLAYER_ADMIN, '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE, $browser);

        $chip = $crawler->filter('.player-chip-tier');
        self::assertCount(1, $chip);
        self::assertSame('Expert', trim($chip->text()));
    }

    public function testMessageNeedsOpenDirectMessagesOrAnAcceptedConversation(): void
    {
        $browser = self::createClient();
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET allow_direct_messages = false WHERE id IN (:stripe, :regular)',
            ['stripe' => PlayerFixture::PLAYER_WITH_STRIPE, 'regular' => PlayerFixture::PLAYER_REGULAR],
        );

        // No conversation between them
        $crawler = $this->page(PlayerFixture::PLAYER_ADMIN, '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE, $browser);
        self::assertCount(0, $crawler->filter('.player-header a[data-turbo-frame="modal-frame"]'));

        // PLAYER_REGULAR started an accepted conversation with PLAYER_ADMIN
        $crawler = $this->page(PlayerFixture::PLAYER_ADMIN, '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, $browser);
        self::assertCount(1, $crawler->filter('.player-head-actions > a[data-turbo-frame="modal-frame"]'));
    }

    public function testAListViewShowsLibraryAsItsSection(): void
    {
        $crawler = $this->page(PlayerFixture::PLAYER_ADMIN, '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);

        $current = $crawler->filter('.player-head-tabs .player-tab.active');
        self::assertSame('Library', trim($current->text()));
        self::assertSame('true', $current->attr('aria-current'));
        self::assertCount(0, $crawler->filter('h1.player-head-name'));
    }

    public function testOwnerFormPagesGetTheStripInsteadOfTheHeader(): void
    {
        $crawler = $this->page(PlayerFixture::PLAYER_WITH_STRIPE, '/en/edit-collection/' . CollectionFixture::COLLECTION_PUBLIC);

        self::assertCount(1, $crawler->filter('nav.player-strip'));
        self::assertSame('Library', trim($crawler->filter('.player-strip-section')->text()));
        self::assertCount(0, $crawler->filter('.player-head'));
        self::assertCount(0, $crawler->filter('.player-bar'));
    }

    public function testStatisticsAndTabPagesHaveOneHeading(): void
    {
        $browser = self::createClient();
        $crawler = $this->page(PlayerFixture::PLAYER_ADMIN, '/en/player-statistics/' . PlayerFixture::PLAYER_WITH_STRIPE, $browser);
        self::assertCount(1, $crawler->filter('h1'));
        self::assertSame('Sarah Williams', trim($crawler->filter('h1')->text()));

        foreach (['/en/activity-calendar/', '/en/puzzle-library/'] as $page) {
            $crawler = $this->page(PlayerFixture::PLAYER_ADMIN, $page . PlayerFixture::PLAYER_WITH_STRIPE, $browser);
            self::assertCount(1, $crawler->filter('h1'), $page);
            self::assertCount(0, $crawler->filter('h1.player-head-name'), $page);
        }
    }

    private function page(null|string $viewer, string $url, null|KernelBrowser $browser = null): Crawler
    {
        $browser ??= self::createClient();

        if ($viewer !== null) {
            TestingLogin::asPlayer($browser, $viewer);
        } else {
            $browser->getCookieJar()->clear();
        }

        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @return list<string>
     */
    private function tabLabels(Crawler $crawler): array
    {
        return $crawler->filter('.player-head-tabs .player-tab')->each(
            static fn (Crawler $tab): string => trim($tab->text()),
        );
    }
}
