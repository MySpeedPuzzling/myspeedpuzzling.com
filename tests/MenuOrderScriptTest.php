<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The Menu's client-side rules (docs/features/navigation MENU-02b, MENU-01), run under node by
 * tests/menu-order-harness.mjs:
 * - assets/navigation/menu_order.js: the phone panel moves the group nodes into data-order-phone order and back into
 *   docked-column order when it closes - never CSS order (decision 29); on the real slot of /en/faq the result equals
 *   the `menu` page's server-rendered phone order (MENU-02a: the page shows the panel's rows);
 * - assets/navigation/menu_counts.js: the lazy counts are asked on every open of the panel and at most once per
 *   5 minutes per tab for the docked column (sessionStorage), never from another player's answer;
 * - assets/navigation/menu_links.js: a click on a link to `menu` (any locale) or the ☰ opens the panel on phones; a
 *   click the browser should keep, desktop (until NAV-13) and the native app go to the page.
 */
final class MenuOrderScriptTest extends WebTestCase
{
    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    private const array DOCK = ['home', 'puzzles', 'community', 'you', 'inbox', 'add', 'help', 'about', 'logout'];

    private const array PHONE = ['you', 'inbox', 'community', 'puzzles', 'add', 'help', 'about', 'logout', 'home'];

    /** @var array<string, null|string> page name => viewer */
    private const array PAGES = [
        'guest' => null,
        'player' => PlayerFixture::PLAYER_PRIVATE,
        'member' => PlayerFixture::PLAYER_WITH_STRIPE,
        'admin' => PlayerFixture::PLAYER_ADMIN,
    ];

    public function testNoCaseThrew(): void
    {
        foreach ($this->results() as $case => $facts) {
            self::assertIsArray($facts, $case);
            self::assertArrayNotHasKey('threw', $facts, $case . ': ' . (is_string($facts['threw'] ?? null) ? $facts['threw'] : ''));
        }
    }

    public function testThePanelMovesTheGroupsIntoPhoneOrder(): void
    {
        $facts = $this->case('phone order');

        self::assertSame(self::DOCK, $facts['dockBefore']);
        self::assertSame(self::PHONE, $facts['phone']);
        self::assertGreaterThan(0, $facts['moved']);
        self::assertSame('phone', $facts['marked']);
    }

    public function testClosingPutsTheDockOrderBack(): void
    {
        self::assertSame(['dock' => self::DOCK, 'moved' => 3, 'marked' => 'dock'], $this->case('back to dock order'));
        // Each move reconnects the controllers inside a group: nothing moves when the order is already right
        self::assertSame(['dock' => 0, 'phoneAgain' => 0], $this->case('already in order moves nothing'));
    }

    public function testTheSameNodesMoveNeverACopy(): void
    {
        self::assertSame(['sameNodes' => true, 'rows' => 9, 'menus' => 1], $this->case('the same nodes move, nothing is copied'));
    }

    public function testTiesKeepTheDomOrderAndBrokenValuesGoLast(): void
    {
        // Infinity in JSON is null
        self::assertSame([
            'phone' => ['c', 'f', 'a', 'e', 'b', 'd'],
            'orders' => ['missing' => null, 'unknownSurface' => null],
            'sortedIsPure' => 0,
        ], $this->case('ties keep the DOM order, broken values go last'));
    }

    public function testOnlyGroupsMove(): void
    {
        self::assertSame(['first' => 'nav-search-private', 'phone' => self::PHONE], $this->case('other children of the nav stay'));
        self::assertSame(['unknown' => 0, 'none' => 0, 'dock' => self::DOCK, 'marked' => null], $this->case('unknown surface or no nav does nothing'));
    }

    /**
     * The real Menu: the slot of /en/faq sorted in the browser shows what the `menu` page renders (MENU-02a parity)
     */
    #[DataProvider('pages')]
    public function testThePanelShowsTheMenuPagesRows(string $page): void
    {
        $facts = $this->case('page ' . $page);
        $expected = $this->results()['expected ' . $page] ?? null;

        self::assertIsArray($expected);
        self::assertGreaterThan(20, count($expected));
        self::assertSame($expected, $facts['phone']);
        self::assertTrue($facts['dockRestored']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        foreach (array_keys(self::PAGES) as $page) {
            yield $page => [$page];
        }
    }

    public function testCachedCountsKeepFiveMinutesAndOnlyForTheirPlayer(): void
    {
        self::assertSame([
            'withinTtl' => ['organised' => 2, 'review' => 1],
            'pastTtl' => null,
            'otherPlayer' => null,
            'maxAgeZero' => null,
            'fromTheFuture' => null,
            'broken' => [null, null, null],
            'ttl' => 300000,
            'key' => 'msp:menu-counts',
        ], $this->case('cachedCounts'));
    }

    public function testCountText(): void
    {
        // 0, 1, 5, 99, 100, 250, -3, 2.7, NaN, Infinity, '3', null, undefined
        self::assertSame(['', '1', '5', '99', '99+', '99+', '', '2', '', '', '', '', ''], $this->case('countText'));
        self::assertSame(['filled' => ['3', ''], 'cleared' => ['', '']], $this->case('applyCounts'));
    }

    public function testTheDockedColumnAsksAtMostOncePerFiveMinutes(): void
    {
        $facts = $this->case('the docked column asks at most once per 5 minutes');

        self::assertSame(1, $facts['fetchesWithinTtl']);
        self::assertSame(['organised' => 2, 'review' => 1], $facts['atTheEdge']);
        self::assertSame(2, $facts['fetchesAfterTtl']);
        self::assertSame(['organised' => 3, 'review' => 0], $facts['after']);
        self::assertSame(['at' => 5300001, 'player' => 'p1', 'data' => ['organised' => 3, 'review' => 0]], $facts['stored']);
        self::assertSame(['url' => '/en/menu-counts.json', 'accept' => 'application/json', 'credentials' => 'same-origin'], $facts['request']);
    }

    public function testThePanelAsksOnEveryOpen(): void
    {
        self::assertSame(['fetches' => 2, 'slots' => ['1', '4']], $this->case('the panel asks on every open'));
    }

    public function testAnotherPlayersAnswerIsNeverUsed(): void
    {
        self::assertSame([
            'fetches' => 1,
            'data' => ['organised' => null, 'review' => 2],
            'slots' => ['', '2'],
            'storedPlayer' => 'p2',
        ], $this->case('another player\'s answer is never used'));
    }

    public function testFailuresNeverBreakTheMenu(): void
    {
        self::assertSame(['data' => null, 'slots' => ['', ''], 'stored' => null], $this->case('a failed answer leaves the slots empty and stores nothing'));
        self::assertSame(['fetches' => 2, 'slots' => ['1', '2']], $this->case('no storage: the numbers still show'));
    }

    public function testMenuLinksMatchTheMenuPageInEveryLocale(): void
    {
        $paths = $this->case('menuPaths');
        self::assertSame(['/menu', '/en/menu', '/de/menue', '/es/menu', '/fr/menu', '/ja/メニュー'], $paths['paths']);
        self::assertSame([[], [], [], ['/en/menu']], $paths['broken']);

        self::assertSame([
            'en' => true,
            'cs' => true,
            'de' => true,
            'es' => true,
            'fr' => true,
            'jaEncoded' => true,
            'jaRaw' => true,
            'absolute' => true,
            'withQuery' => true,
            'withFragment' => true,
            'trailingSlash' => true,
            'relative' => true,
            'otherOrigin' => false,
            'javascript' => false,
            'fragmentOnly' => false,
            'otherPage' => false,
            'prefix' => false,
            'empty' => false,
        ], $this->case('isMenuHref'));
    }

    public function testWhichClicksOpenThePanel(): void
    {
        self::assertSame([
            'phone' => 'panel',
            'desktop' => 'page',
            'nativeApp' => 'page',
            'middleButton' => 'page',
            'modified' => 'page',
            'alreadyTaken' => 'page',
            'newTab' => 'page',
            'download' => 'page',
        ], $this->case('menuLinkAction'));

        $opened = ['opened' => ['to-menu'], 'prevented' => true, 'afterStop' => false];
        $page = ['opened' => [], 'prevented' => false, 'afterStop' => false];

        self::assertSame([
            'phone' => $opened,
            'burger' => ['opened' => ['burger'], 'prevented' => true, 'afterStop' => false],
            'desktop' => $page,
            'nativeApp' => $page,
            'ctrlClick' => $page,
            'newTab' => $page,
            // The panel could not open (no Menu on the page): the link goes to the page
            'cannotOpen' => ['opened' => ['to-menu'], 'prevented' => false, 'afterStop' => false],
            'otherLink' => $page,
            'opener' => 'to-menu',
        ], $this->case('listenForMenuLinks'));
    }

    /**
     * @return array<mixed>
     */
    private function case(string $name): array
    {
        $facts = $this->results()[$name] ?? null;
        self::assertIsArray($facts, $name . ' has no result in tests/menu-order-harness.mjs');
        self::assertArrayNotHasKey('threw', $facts, $name);

        return $facts;
    }

    /**
     * @return array<string, mixed>
     */
    private function results(): array
    {
        if (self::$results !== null) {
            return self::$results;
        }

        $pages = [];
        $expected = [];
        $browser = self::createClient();

        foreach (self::PAGES as $name => $playerId) {
            $browser->restart();

            if ($playerId !== null) {
                TestingLogin::asPlayer($browser, $playerId);
            }

            $slot = $browser->request('GET', '/en/faq')->filter('#menu-slot #site-menu');
            self::assertCount(1, $slot, $name . ': no Menu in the slot of /en/faq');
            $pages[] = ['name' => $name, 'slot' => $slot->outerHtml()];

            $rows = [];

            foreach ($browser->request('GET', '/en/menu')->filter('main #site-menu li[id^="menu-row-"]') as $row) {
                assert($row instanceof DOMElement);
                $rows[] = $row->getAttribute('id');
            }

            $expected['expected ' . $name] = $rows;
        }

        self::ensureKernelShutdown();

        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the module - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/menu-order-harness.mjs']);
        $process->setInput(json_encode(['pages' => $pages], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::$results = [...$decoded, ...$expected];

        return self::$results;
    }
}
