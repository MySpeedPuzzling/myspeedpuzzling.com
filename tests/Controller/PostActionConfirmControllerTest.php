<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Controller\PostActionConfirmController;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\PostAction;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The confirmation view of a one-tap action (`post_action_confirm`, docs/features/navigation GEST-15, decision 99): every
 * PostAction in all 6 locales renders one button posting the action's form to its route (its values, a fresh CSRF token
 * when the route checks one), `noindex, nofollow`; the actions that ask first in the modal frame; anything outside the
 * allow-list or of the wrong shape is a 404; guests sign in first. The actions still work through the view.
 */
final class PostActionConfirmControllerTest extends WebTestCase
{
    private const string LISTING = '018d000b-0000-0000-0000-000000000001';
    private const string LENT = '018d000c-0000-0000-0000-000000000005';
    private const string PLAYER_SUBJECT = 'p-' . PlayerFixture::PLAYER_WITH_FAVORITES;

    /**
     * Each action with values of its shape: [route parameters + fields as query, the route's URL, the posted fields]
     *
     * @return array<string, array{array<string, string>, string, array<string, string>, bool}>
     */
    private static function samples(): array
    {
        return [
            'compare_add' => [['subject' => self::PLAYER_SUBJECT, 'return' => '/en/puzzlers'], '/en/compare/add', ['subject' => self::PLAYER_SUBJECT, 'return' => '/en/puzzlers'], true],
            'compare_remove' => [['subject' => self::PLAYER_SUBJECT], '/en/compare/remove', ['subject' => self::PLAYER_SUBJECT], true],
            'follow' => [['target' => 'series:' . OrganizationFixture::SERIES_LANTERN_NIGHTS], '/en/follow-event', ['target' => 'series:' . OrganizationFixture::SERIES_LANTERN_NIGHTS], true],
            'unfollow' => [['target' => 'series:' . OrganizationFixture::SERIES_LANTERN_NIGHTS, 'return' => '/en/events'], '/en/unfollow-event', ['target' => 'series:' . OrganizationFixture::SERIES_LANTERN_NIGHTS, 'return' => '/en/events'], true],
            'set_country' => [['country' => 'cz'], '/en/puzzlers/my-country', ['country' => 'cz'], true],
            'block_player' => [['playerId' => PlayerFixture::PLAYER_WITH_FAVORITES], '/en/block-user/' . PlayerFixture::PLAYER_WITH_FAVORITES, [], true],
            'publish_competition' => [['competitionId' => OrganizationFixture::COMPETITION_DRAFT_NIGHT], '/en/publish-event/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT, [], true],
            'publish_series' => [['seriesId' => OrganizationFixture::SERIES_QUIET_PINES_DRAFT, 'return' => '/en/events'], '/en/publish-series/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT, ['return' => '/en/events'], true],
            'publish_organization' => [['organizationId' => OrganizationFixture::SERIES_QUIET_PINES_DRAFT], '/en/publish-organization/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT, [], true],
            'wishlist_remove' => [['puzzleId' => PuzzleFixture::PUZZLE_4000, 'context' => 'detail'], '/en/remove-from-wish-list/' . PuzzleFixture::PUZZLE_4000, ['context' => 'detail'], false],
            'collection_remove' => [['puzzleId' => PuzzleFixture::PUZZLE_4000, 'collectionId' => '', 'currentCollectionId' => '', 'context' => 'detail'], '/en/collections/' . PuzzleFixture::PUZZLE_4000 . '/remove', ['collectionId' => '', 'currentCollectionId' => '', 'context' => 'detail'], false],
            'reservation_remove' => [['itemId' => self::LISTING, 'context' => 'marketplace'], '/en/sell-swap/' . self::LISTING . '/unreserve', ['context' => 'marketplace'], false],
            'sell_swap_remove' => [['puzzleId' => PuzzleFixture::PUZZLE_4000, 'context' => 'detail', 'collection_id' => ''], '/en/sell-swap/' . PuzzleFixture::PUZZLE_4000 . '/remove', ['context' => 'detail', 'collection_id' => ''], false],
            'puzzle_return' => [['lentPuzzleId' => self::LENT, 'context' => 'list', 'tab' => 'lent', 'collection_id' => ''], '/en/return-puzzle/' . self::LENT, ['context' => 'list', 'tab' => 'lent', 'collection_id' => ''], false],
        ];
    }

    public function testEveryActionHasASample(): void
    {
        self::assertSame(array_map(static fn (PostAction $action): string => $action->value, PostAction::cases()), array_keys(self::samples()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actions(): iterable
    {
        foreach (array_keys(self::samples()) as $action) {
            yield $action => [$action];
        }
    }

    #[DataProvider('actions')]
    public function testTheViewPostsTheActionsForm(string $action): void
    {
        [$query, $targetUrl, $fields, $hasToken] = self::samples()[$action];
        $postAction = PostAction::from($action);

        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/action/' . $action . '?' . http_build_query($query));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertSame('noindex, nofollow', $crawler->filter('head meta[name="robots"]')->attr('content'));
        self::assertStringNotContainsString('public', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame($this->trans($postAction->titleKey()), trim($crawler->filter('main h1')->text()));

        $form = $crawler->filter('main form.post-action-form');
        self::assertCount(1, $form);
        self::assertSame('post', $form->attr('method'));
        self::assertSame($targetUrl, $form->attr('action'));
        self::assertSame('false', $form->attr('data-turbo'), 'A plain POST: the route answers a redirect, never a stream meant for a list');
        self::assertSame($this->trans($postAction->labelKey()), trim($form->filter('button[type="submit"]')->text()));
        self::assertSame($postAction->isDanger() ? 'btn btn-danger' : 'btn btn-primary', $form->filter('button[type="submit"]')->attr('class'));

        $posted = [];

        foreach ($form->filter('input[type="hidden"]') as $input) {
            self::assertInstanceOf(\DOMElement::class, $input);
            $posted[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        $token = $posted['_token'] ?? null;
        unset($posted['_token']);

        self::assertSame($fields, $posted);
        self::assertSame($hasToken, $token !== null && $token !== '');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function framedActions(): iterable
    {
        foreach (PostAction::cases() as $action) {
            if ($action->confirmsInFrame()) {
                yield $action->value => [$action->value];
            }
        }
    }

    #[DataProvider('framedActions')]
    public function testAnActionThatAsksFirstAnswersTheModalFrame(string $action): void
    {
        [$query, $targetUrl] = self::samples()[$action];

        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/action/' . $action . '?' . http_build_query($query), server: ['HTTP_TURBO_FRAME' => PostActionConfirmController::FRAME]);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#modal-frame'));
        self::assertCount(0, $crawler->filter('header, #bottom-nav'), 'A frame, no page around it');

        $form = $crawler->filter('turbo-frame#modal-frame form.post-action-form');
        self::assertSame($targetUrl, $form->attr('action'));
        self::assertSame('_top', $form->attr('data-turbo-frame'), 'The answer is a page, not the frame');
        self::assertCount(1, $crawler->filter('turbo-frame#modal-frame .modal-body button[data-bs-dismiss="modal"]'), 'Cancel closes the sheet');
        self::assertStringContainsString('Turbo-Frame', (string) $browser->getResponse()->headers->get('Vary'));
    }

    public function testTheBlockConfirmationKeepsItsExplanation(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/action/block_player?playerId=' . PlayerFixture::PLAYER_WITH_FAVORITES, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        self::assertSame($this->trans('blocklist.modal_title'), trim($crawler->filter('.modal-title')->text()));
        self::assertStringContainsString($this->trans('blocklist.modal_text'), $crawler->filter('.modal-body')->text());
        self::assertStringContainsString($this->trans('blocklist.modal_undo'), $crawler->filter('.modal-body')->text());
        self::assertSame('btn btn-danger', $crawler->filter('button[type="submit"]')->attr('class'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function localizedPaths(): iterable
    {
        yield 'cs' => ['/akce/compare_add'];
        yield 'en' => ['/en/action/compare_add'];
        yield 'es' => ['/es/accion/compare_add'];
        yield 'ja' => ['/ja/' . rawurlencode('アクション') . '/compare_add'];
        yield 'fr' => ['/fr/action/compare_add'];
        yield 'de' => ['/de/aktion/compare_add'];
    }

    #[DataProvider('localizedPaths')]
    public function testEveryLocaleHasThePage(string $path): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', $path . '?subject=' . self::PLAYER_SUBJECT);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('main form.post-action-form[method="post"]'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedRequests(): iterable
    {
        yield 'not an action' => ['/en/action/delete_everything'];
        yield 'an id that is no id' => ['/en/action/wishlist_remove?puzzleId=not-a-uuid'];
        yield 'a route parameter missing' => ['/en/action/wishlist_remove?context=detail'];
        yield 'a field of another shape' => ['/en/action/compare_add?subject=' . PlayerFixture::PLAYER_REGULAR];
        yield 'a return to another site' => ['/en/action/compare_add?subject=' . self::PLAYER_SUBJECT . '&return=https://evil.example/'];
        yield 'a context of another shape' => ['/en/action/wishlist_remove?puzzleId=' . PuzzleFixture::PUZZLE_4000 . '&context=<script>'];
    }

    #[DataProvider('refusedRequests')]
    public function testAnythingElseIsNotFound(string $url): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
    }

    public function testFieldsTheActionDoesNotPostAreLeftOut(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/action/wishlist_remove?puzzleId=' . PuzzleFixture::PUZZLE_4000 . '&context=detail&role=admin&back=/en/puzzle');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="role"], input[name="back"]'));
        self::assertSame('/en/puzzle', $crawler->filter('main a.btn-outline-secondary')->attr('href'), 'Cancel goes back to the page the link was on');
    }

    /**
     * SEO: a one-tap action reaches signed-in viewers only (TurboCacheControlOptInTest and the guest pages are
     * unchanged), and every link to the confirmation view says rel="nofollow" - a crawler never follows one
     *
     * @return iterable<string, array{string, string}>
     */
    public static function pagesWithActionLinks(): iterable
    {
        yield 'a puzzle in the wishlist (its ⋯ menu)' => ['/en/puzzle/' . PuzzleFixture::PUZZLE_4000, PlayerFixture::PLAYER_REGULAR];
        yield 'another player\'s profile (Block)' => ['/en/player-profile/' . PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR];
    }

    #[DataProvider('pagesWithActionLinks')]
    public function testEveryActionLinkIsNofollow(string $url, string $player): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $player);
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        $links = $crawler->filter('a[href*="/en/action/"]');
        self::assertGreaterThan(0, $links->count());

        foreach ($links as $link) {
            self::assertInstanceOf(\DOMElement::class, $link);
            self::assertContains('nofollow', explode(' ', $link->getAttribute('rel')), $link->getAttribute('href'));
        }
    }

    public function testGuestsSignInFirst(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/action/compare_add?subject=' . self::PLAYER_SUBJECT);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testRemovingFromTheWishlistWorksThroughTheView(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        self::assertSame(1, $this->wishlistCount($browser, PuzzleFixture::PUZZLE_4000));

        $crawler = $browser->request('GET', '/en/action/wishlist_remove?puzzleId=' . PuzzleFixture::PUZZLE_4000 . '&context=detail');
        $browser->submit($crawler->filter('main form.post-action-form')->form());

        self::assertResponseRedirects();
        self::assertSame(0, $this->wishlistCount($browser, PuzzleFixture::PUZZLE_4000));
    }

    public function testComparingWorksThroughTheView(): void
    {
        $browser = self::createClient();
        // A member: room in the line-up (a free player at the cap is asked to swap - the route's own rule)
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/action/compare_add?subject=p-' . PlayerFixture::PLAYER_REGULAR . '&return=/en/puzzlers');
        $browser->submit($crawler->filter('main form.post-action-form')->form());

        self::assertResponseRedirects('/en/puzzlers');
        self::assertSame(1, $this->rowCount(
            $browser,
            'SELECT COUNT(*) FROM comparison_subject WHERE player_id = :owner AND subject_player_id = :subject',
            ['owner' => PlayerFixture::PLAYER_WITH_STRIPE, 'subject' => PlayerFixture::PLAYER_REGULAR],
        ));
    }

    public function testFollowingWorksThroughTheView(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/action/follow?target=series:' . OrganizationFixture::SERIES_LANTERN_NIGHTS . '&return=/en/events');
        $browser->submit($crawler->filter('main form.post-action-form')->form());

        self::assertResponseRedirects('/en/events');
        self::assertSame(1, $this->rowCount(
            $browser,
            'SELECT COUNT(*) FROM followed_competition WHERE player_id = :player AND series_id = :series',
            ['player' => PlayerFixture::PLAYER_REGULAR, 'series' => OrganizationFixture::SERIES_LANTERN_NIGHTS],
        ));
    }

    public function testSettingTheCountryNamesItAndWorksThroughTheView(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/action/set_country?country=de');
        self::assertStringContainsString('Germany', $crawler->filter('main')->text());

        $browser->submit($crawler->filter('main form.post-action-form')->form());

        self::assertResponseRedirects('/en/puzzlers?scope=de');
        self::assertSame('de', $this->connection($browser)->fetchOne('SELECT country FROM player WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]));
    }

    public function testBlockingWorksThroughTheView(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/action/block_player?playerId=' . PlayerFixture::PLAYER_WITH_FAVORITES, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        $browser->submit($crawler->filter('form.post-action-form')->form());

        self::assertResponseRedirects();
        self::assertSame(1, $this->rowCount(
            $browser,
            'SELECT COUNT(*) FROM user_block WHERE blocker_id = :blocker AND blocked_id = :blocked',
            ['blocker' => PlayerFixture::PLAYER_REGULAR, 'blocked' => PlayerFixture::PLAYER_WITH_FAVORITES],
        ));
    }

    private function wishlistCount(KernelBrowser $browser, string $puzzleId): int
    {
        return $this->rowCount(
            $browser,
            'SELECT COUNT(*) FROM wish_list_item WHERE player_id = :player AND puzzle_id = :puzzle',
            ['player' => PlayerFixture::PLAYER_REGULAR, 'puzzle' => $puzzleId],
        );
    }

    private function connection(KernelBrowser $browser): Connection
    {
        return $browser->getContainer()->get(Connection::class);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function rowCount(KernelBrowser $browser, string $sql, array $parameters): int
    {
        $count = $this->connection($browser)->fetchOne($sql, $parameters);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function trans(string $key): string
    {
        return self::getContainer()->get(TranslatorInterface::class)->trans($key, [], null, 'en');
    }
}
