<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * Where an organiser finds online timing (docs/features/online-competitions/, stream C1): "Online timing" in an online
 * event's ⋯ menu (events/_manage_items.html.twig, to the personal links page with its rounds) and "Online timing" +
 * "Personal links" among the round tools of a round that uses it (official_results/_round_tool_links.html.twig) - only
 * for whom the feature is on, without a statement where nothing is offered, nothing for any other event or round.
 */
final class OnlineTimingMenusTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;
    use QueryCountAssertions;

    private const string FLAG = 'ONLINE_ROUNDS_FOR_ORGANISERS';
    private const string COMPETITION = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;
    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string ROUND_WITHOUT = '018d0050-0000-0000-0000-0000000001d1';
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testTheMenuOfAnOnlineEventLeadsToOnlineTiming(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/event-actions/competition/' . self::COMPETITION . '?return=' . rawurlencode('/en/events'), server: ['HTTP_TURBO_FRAME' => 'event-manage-menu']);

        self::assertResponseIsSuccessful();
        $link = $this->menuLink($crawler, 'Online timing');
        self::assertNotNull($link);
        self::assertSame('/en/online-competitions/' . self::COMPETITION . '/links', parse_url($link, PHP_URL_PATH));
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        self::assertSame('/en/events', $query['return'] ?? null);

        // An in-person event has none
        $crawler = $browser->request('GET', '/en/event-actions/competition/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP, server: ['HTTP_TURBO_FRAME' => 'event-manage-menu']);
        self::assertNull($this->menuLink($crawler, 'Online timing'));
    }

    public function testAnOrganiserSeesItOnlyWhileTheFlagIsOn(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $browser = self::createClient();
        $this->connection($browser)->insert('competition_maintainer', ['competition_id' => self::COMPETITION, 'player_id' => self::ORGANISER]);
        TestingLogin::asPlayer($browser, self::ORGANISER);

        $crawler = $browser->request('GET', '/en/event-actions/competition/' . self::COMPETITION, server: ['HTTP_TURBO_FRAME' => 'event-manage-menu']);
        self::assertResponseIsSuccessful();
        self::assertNull($this->menuLink($crawler, 'Online timing'));

        self::ensureKernelShutdown();
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::ORGANISER);

        $crawler = $browser->request('GET', '/en/event-actions/competition/' . self::COMPETITION, server: ['HTTP_TURBO_FRAME' => 'event-manage-menu']);
        self::assertNotNull($this->menuLink($crawler, 'Online timing'));
    }

    /**
     * "You organize" lists every item's actions inline - the entry rides on the permissions every item asks anyway
     */
    public function testYouOrganizeOffersItWithoutAStatementPerItem(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $browser = self::createClient();
        $this->connection($browser)->insert('competition_maintainer', ['competition_id' => self::COMPETITION, 'player_id' => self::ORGANISER]);
        TestingLogin::asPlayer($browser, self::ORGANISER);
        $browser->request('GET', '/en/you-organize');
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/you-organize');
        $flagOff = $this->queryCount($browser);
        self::assertCount(0, $crawler->filter('a[href^="/en/online-competitions/"]'));

        self::ensureKernelShutdown();
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::ORGANISER);
        $browser->request('GET', '/en/you-organize');
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/you-organize');

        self::assertCount(1, $crawler->filter('a[href^="/en/online-competitions/' . self::COMPETITION . '/links"]'));
        self::assertSame($flagOff, $this->queryCount($browser), implode("\n", $this->executedSql($browser)));
    }

    public function testARoundUsingOnlineTimingGetsItsTools(): void
    {
        self::bootKernel();
        $this->viewer(PlayerFixture::PLAYER_ADMIN);

        $tools = $this->roundTools(self::ROUND, true);

        self::assertSame('/en/online-rounds/' . self::ROUND . '/settings?return=/en/x&return_title=Rounds', $tools['online'] ?? null);
        self::assertSame('/en/online-competitions/' . self::COMPETITION . '/links?return=/en/x&return_title=Rounds', $tools['online-links'] ?? null);
    }

    public function testNoOtherRoundGetsThem(): void
    {
        self::bootKernel();
        $this->viewer(PlayerFixture::PLAYER_ADMIN);
        $this->addRoundWithoutOnlineTiming();

        // A round of the online event that does not use online timing
        self::assertArrayNotHasKey('online', $this->roundTools(self::ROUND_WITHOUT, true));
        // A round of an in-person event: not even asked - no statement
        self::assertSame(0, $this->statementsOf(fn () => $this->roundTools(OfficialResultsFixture::ROUND_GROUP_A, false)));
        self::assertArrayNotHasKey('online', $this->roundTools(OfficialResultsFixture::ROUND_GROUP_A, false));
    }

    public function testAViewerTheFeatureIsOffForGetsNoToolsAndCostsNoStatement(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        self::bootKernel();
        self::getContainer()->get(Connection::class)->insert('competition_maintainer', ['competition_id' => self::COMPETITION, 'player_id' => self::ORGANISER]);
        $this->viewer(self::ORGANISER);

        $this->roundTools(self::ROUND, true);
        self::assertSame(0, $this->statementsOf(fn () => $this->roundTools(self::ROUND, true)));
        self::assertArrayNotHasKey('online', $this->roundTools(self::ROUND, true));
        self::assertArrayNotHasKey('online-links', $this->roundTools(self::ROUND, true));
    }

    public function testTheRoundsOfAnEventAreReadOnceForAllItsRounds(): void
    {
        self::bootKernel();
        $this->viewer(PlayerFixture::PLAYER_ADMIN);
        $this->addRoundWithoutOnlineTiming();
        // Every page has read the viewer's profile already
        self::getContainer()->get(RetrieveLoggedUserProfile::class)->getProfile();

        self::assertSame(1, $this->statementsOf(function (): void {
            $this->roundTools(self::ROUND, true);
            $this->roundTools(self::ROUND_WITHOUT, true);
            $this->roundTools(self::ROUND, true);
        }));
    }

    public function testTheRoundsPageLinksThem(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/manage-event-rounds/' . self::COMPETITION);

        self::assertResponseIsSuccessful();
        $online = $crawler->filter('[data-round-tool="online"]');
        self::assertCount(1, $online);
        self::assertStringStartsWith('/en/online-rounds/' . self::ROUND . '/settings', (string) $online->attr('href'));
        self::assertCount(1, $crawler->filter('[data-round-tool="online-links"]'));
    }

    /**
     * @return array<string, string> tool => href
     */
    private function roundTools(string $roundId, bool $isOnline): array
    {
        $html = self::getContainer()->get(Environment::class)->render('official_results/_round_tool_links.html.twig', [
            'round_id' => $roundId,
            'is_online' => $isOnline,
            'return_url' => '/en/x',
            'return_title' => 'Rounds',
        ]);

        $tools = [];

        foreach ((new Crawler('<div>' . $html . '</div>'))->filter('[data-round-tool]') as $link) {
            \assert($link instanceof \DOMElement);
            $tools[$link->getAttribute('data-round-tool')] = urldecode($link->getAttribute('href'));
        }

        return $tools;
    }

    private function viewer(string $playerId): void
    {
        $request = Request::create('/en/manage-event-rounds/x');
        $request->setLocale('en');
        self::getContainer()->get(RequestStack::class)->push($request);
        TestingViewer::signIn(self::getContainer(), $playerId);
    }

    private function statementsOf(callable $render): int
    {
        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();
        $render();

        $statements = 0;

        foreach ($holder->getData() as $queries) {
            $statements += is_array($queries) ? count($queries) : 0;
        }

        return $statements;
    }

    private function addRoundWithoutOnlineTiming(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO competition_round (id, competition_id, name, minutes_limit, starts_at, slug)
             VALUES (:id, :competition, 'Round Without', 30, NOW() + INTERVAL '25 days', 'round-without')",
            ['id' => self::ROUND_WITHOUT, 'competition' => self::COMPETITION],
        );
    }

    private function menuLink(Crawler $crawler, string $label): null|string
    {
        foreach ($crawler->filter('.ev-actions > li > a.ev-action') as $link) {
            \assert($link instanceof \DOMElement);

            if (trim($link->textContent) === $label) {
                return $link->getAttribute('href');
            }
        }

        return null;
    }

    private function connection(KernelBrowser $browser): Connection
    {
        $connection = $browser->getContainer()->get(Connection::class);

        return $connection;
    }
}
