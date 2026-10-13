<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\NavClickController;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use SpeedPuzzling\Web\Tests\Navigation\NavChromeAssertions;
use SpeedPuzzling\Web\Tests\ReadsRoundAutomaticReveal;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\StopwatchStatus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The stopwatch page (docs/features/navigation README §4.10, D7; implementation plan ADD-04, decision 44): a running
 * stopwatch has the screen to itself - no top bar, bottom bar, rail, column, Menu, footer or "Something wrong" line -
 * and "Leave" leads out: to the stopwatch's puzzle page when the player may see it, else to Add. Paused or not started,
 * the page keeps the bars and the same way out. Laps are set in the test relative to the clock (risk 17);
 * PLAYER_WITH_STRIPE has no stopwatch in the fixtures.
 */
final class StopwatchPageChromeTest extends WebTestCase
{
    use ReadsRoundAutomaticReveal;

    private const string PLAYER_ID = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string SECRET_NAME = 'Leave Secret';

    // Selectors of the chrome beyond NavChromeAssertions::WEB_CHROME_SELECTORS: the rail, the docked column's slot, the
    // end-of-page line (HELP-04)
    private const array MORE_CHROME = ['.nav-rail', '#menu-slot', '.page-help-line'];

    public function testARunningStopwatchHasTheScreenToItself(): void
    {
        $browser = $this->signedInBrowser();
        $stopwatchId = $this->insert(StopwatchStatus::Running, PuzzleFixture::PUZZLE_500_01, [['-1 hour', '-45 minutes'], ['-10 minutes', null]]);

        $crawler = $browser->request('GET', '/en/stopwatch/' . $stopwatchId);
        self::assertResponseIsSuccessful();

        NavChromeAssertions::assertNoWebChrome($crawler, 'running stopwatch');
        $this->assertNoMoreChrome($crawler);
        // body.no-nav-chrome: no room for the tab bar, the desktop column's width 0 (NAV-13)
        self::assertStringContainsString('no-nav-chrome', (string) $crawler->filter('body')->attr('class'));

        // The way out is the toolbar's first control, a real link to the puzzle page that says the stopwatch keeps running
        $leave = $this->leave($crawler);
        self::assertSame('/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, $leave->attr('href'));
        self::assertSame('Leave', trim($leave->text()));
        self::assertSame('Leave the stopwatch – it keeps running', $leave->attr('aria-label'));
        self::assertSame('page/add/stopwatch_leave', $leave->attr('data-nav'));
        self::assertMatchesRegularExpression(NavClickController::NAV_PATTERN, (string) $leave->attr('data-nav'));
        self::assertSame('leaveLink', $leave->attr('data-stopwatch-target'));
        self::assertSame('true', $leave->filter('i')->attr('aria-hidden'));
        self::assertSame('Leave the stopwatch – it keeps running', $crawler->filter('[data-controller="stopwatch"]')->attr('data-stopwatch-label-leave-running-value'));

        // The stopwatch itself is untouched: running, Stop shown
        $stopwatch = $crawler->filter('[data-controller="stopwatch"]');
        self::assertSame('running', $stopwatch->attr('data-stopwatch-status-value'));
        self::assertStringNotContainsString('d-none', (string) $crawler->filter('[data-stopwatch-target="pauseBtn"]')->attr('class'));
        self::assertStringContainsString('d-none', (string) $crawler->filter('[data-stopwatch-target="startBtn"]')->attr('class'));
    }

    public function testARunningStopwatchWithoutAPuzzleLeavesForAdd(): void
    {
        $browser = $this->signedInBrowser();
        $stopwatchId = $this->insert(StopwatchStatus::Running, null, [['-3 minutes', null]]);

        $crawler = $browser->request('GET', '/en/stopwatch/' . $stopwatchId);
        self::assertResponseIsSuccessful();

        NavChromeAssertions::assertNoWebChrome($crawler, 'running stopwatch without a puzzle');
        $this->assertNoMoreChrome($crawler);
        self::assertSame('/en/puzzle-add', $this->leave($crawler)->attr('href'));
    }

    /**
     * A stopwatch on a puzzle a competition keeps secret from this player shows no puzzle - nor leads to it
     */
    public function testAPuzzleKeptSecretIsNeverTheWayOut(): void
    {
        $browser = self::createClient();
        $puzzleId = $this->secretPuzzle();
        // As if started before the puzzle became secret
        $stopwatchId = $this->insert(StopwatchStatus::Running, $puzzleId, [['-20 minutes', null]]);
        TestingLogin::asPlayer($browser, self::PLAYER_ID);

        $crawler = $browser->request('GET', '/en/stopwatch/' . $stopwatchId);
        self::assertResponseIsSuccessful();

        self::assertSame('/en/puzzle-add', $this->leave($crawler)->attr('href'));
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $browser->getResponse()->getContent());
    }

    public function testAPausedStopwatchKeepsTheBarsAndTheWayOut(): void
    {
        $browser = $this->signedInBrowser();
        $stopwatchId = $this->insert(StopwatchStatus::Paused, PuzzleFixture::PUZZLE_500_01, [['-30 minutes', '-12 minutes']]);

        $crawler = $browser->request('GET', '/en/stopwatch/' . $stopwatchId);
        self::assertResponseIsSuccessful();

        $this->assertBars($crawler, 'paused stopwatch');

        // Paused, the name is "Leave" itself - nothing runs on (stopwatch_controller.js adds the label on a resume)
        $leave = $this->leave($crawler);
        self::assertSame('/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, $leave->attr('href'));
        self::assertNull($leave->attr('aria-label'));
        self::assertStringNotContainsString('d-none', (string) $crawler->filter('[data-stopwatch-target="resumeBtn"]')->attr('class'));
        self::assertStringNotContainsString('d-none', (string) $crawler->filter('[data-stopwatch-target="finishBtn"]')->attr('class'));
    }

    /**
     * The standalone stopwatch (no puzzle, none started): bars, Start, the way out to Add
     */
    public function testTheStandaloneStopwatchStartsWithTheBars(): void
    {
        $browser = $this->signedInBrowser();

        $crawler = $browser->request('GET', '/en/stopwatch');
        self::assertResponseIsSuccessful();

        $this->assertBars($crawler, 'standalone stopwatch');

        $stopwatch = $crawler->filter('[data-controller="stopwatch"]');
        self::assertSame('', $stopwatch->attr('data-stopwatch-status-value'));
        self::assertSame('', $stopwatch->attr('data-stopwatch-puzzle-id-value'));
        self::assertSame('/api/stopwatch/start', $stopwatch->attr('data-stopwatch-start-url-value'));
        self::assertStringNotContainsString('d-none', (string) $crawler->filter('[data-stopwatch-target="startBtn"]')->attr('class'));

        $leave = $this->leave($crawler);
        self::assertSame('/en/puzzle-add', $leave->attr('href'));
        self::assertNull($leave->attr('aria-label'));
    }

    public function testThePuzzleStopwatchLeavesForItsPuzzle(): void
    {
        $browser = $this->signedInBrowser();

        $crawler = $browser->request('GET', '/en/puzzle-stopwatch/' . PuzzleFixture::PUZZLE_500_02);
        self::assertResponseIsSuccessful();

        $this->assertBars($crawler, 'puzzle stopwatch');
        self::assertSame('/en/puzzle/' . PuzzleFixture::PUZZLE_500_02, $this->leave($crawler)->attr('href'));
    }

    /**
     * Inside the app there is no web chrome on any stopwatch page (NAV-14) - and Leave stays, it is the page's own
     */
    public function testTheAppKeepsLeaveWithoutWebChrome(): void
    {
        foreach (NativeUserAgents::native() as $name => [$userAgent]) {
            $browser = self::createClient(server: ['HTTP_USER_AGENT' => $userAgent]);
            TestingLogin::asPlayer($browser, self::PLAYER_ID);
            $paused = $this->insert(StopwatchStatus::Paused, null, [['-30 minutes', '-12 minutes']]);

            $crawler = $browser->request('GET', '/en/stopwatch/' . $paused);
            self::assertResponseIsSuccessful();

            NavChromeAssertions::assertNoWebChrome($crawler, $name);
            self::assertSame('/en/puzzle-add', $this->leave($crawler)->attr('href'), $name);

            self::ensureKernelShutdown();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLocales(): iterable
    {
        foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            yield $locale => [$locale];
        }
    }

    /**
     * "Leave" in every language, its accessible name starting with the visible word (WCAG 2.5.3 label in name)
     */
    #[DataProvider('provideLocales')]
    public function testLeaveIsWordedInEveryLanguage(string $locale): void
    {
        $browser = $this->signedInBrowser();
        $stopwatchId = $this->insert(StopwatchStatus::Running, PuzzleFixture::PUZZLE_500_01, [['-5 minutes', null]]);
        $router = self::getContainer()->get(RouterInterface::class);
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $catalogue = $translator->getCatalogue($locale);

        foreach (['stopwatch.leave', 'stopwatch.leave_label'] as $key) {
            self::assertTrue($catalogue->defines($key), "{$locale}: {$key}");

            if ($locale !== 'en') {
                self::assertNotSame($translator->trans($key, [], null, 'en'), $translator->trans($key, [], null, $locale), "{$locale}: {$key}");
            }
        }

        $word = $translator->trans('stopwatch.leave', [], null, $locale);
        $name = $translator->trans('stopwatch.leave_label', [], null, $locale);
        self::assertNotSame('', $word);
        self::assertSame(0, mb_strpos($name, $word), "{$locale}: the name starts with the visible word");

        $crawler = $browser->request('GET', $router->generate('stopwatch', ['stopwatchId' => $stopwatchId, '_locale' => $locale]));
        self::assertResponseIsSuccessful();

        $leave = $this->leave($crawler);
        self::assertSame($word, trim($leave->text()));
        self::assertSame($name, $leave->attr('aria-label'));
        self::assertSame($router->generate('puzzle_detail', ['puzzleId' => PuzzleFixture::PUZZLE_500_01, '_locale' => $locale]), $leave->attr('href'));
    }

    private function signedInBrowser(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER_ID);

        return $browser;
    }

    private function leave(Crawler $crawler): Crawler
    {
        $leave = $crawler->filter('a[data-stopwatch-leave]');
        self::assertCount(1, $leave);
        // The toolbar's first control
        self::assertCount(1, $crawler->filter('.stopwatch-toolbar > a[data-stopwatch-leave]:first-child'));

        return $leave;
    }

    private function assertBars(Crawler $crawler, string $message): void
    {
        NavChromeAssertions::assertWebHeaderAndFooter($crawler, $message);
        self::assertSame(1, NavChromeAssertions::count($crawler, 'body > header.site-header'), $message);
        self::assertSame(1, NavChromeAssertions::count($crawler, '#site-menu:not(main #site-menu)'), $message);
        self::assertSame(1, NavChromeAssertions::count($crawler, '#menu-layer'), $message);
        self::assertSame(1, NavChromeAssertions::count($crawler, '.page-help-line'), $message);
        self::assertStringNotContainsString('no-nav-chrome', (string) $crawler->filter('body')->attr('class'), $message);
    }

    private function assertNoMoreChrome(Crawler $crawler): void
    {
        foreach (self::MORE_CHROME as $selector) {
            self::assertSame(0, NavChromeAssertions::count($crawler, $selector), $selector);
        }
    }

    /**
     * @param list<array{string, null|string}> $laps [start, end] relative to the clock
     */
    private function insert(StopwatchStatus $status, null|string $puzzleId, array $laps): string
    {
        $id = Uuid::uuid7()->toString();
        $now = self::getContainer()->get(ClockInterface::class)->now();

        self::getContainer()->get(Connection::class)->insert('stopwatch', [
            'id' => $id,
            'player_id' => self::PLAYER_ID,
            'puzzle_id' => $puzzleId,
            'status' => $status->value,
            'laps' => json_encode(array_map(
                static fn (array $lap): array => [
                    'start' => $now->modify($lap[0])->format('Y-m-d H:i:s'),
                    'end' => $lap[1] === null ? null : $now->modify($lap[1])->format('Y-m-d H:i:s'),
                ],
                $laps,
            ), JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }

    /**
     * A puzzle of a future round kept secret until it starts, added by somebody else (SecretPuzzleWritesHttpTest)
     */
    private function secretPuzzle(): string
    {
        $roundPuzzleId = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionApiFixture::ROUND_FUTURE,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: self::SECRET_NAME,
            piecesCount: 1000,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
            shownAutomaticRevealAt: self::automaticRevealOf(CompetitionApiFixture::ROUND_FUTURE),
        ));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $roundPuzzle = $entityManager->find(CompetitionRoundPuzzle::class, $roundPuzzleId->toString());
        self::assertNotNull($roundPuzzle);

        return $roundPuzzle->puzzle->id->toString();
    }
}
