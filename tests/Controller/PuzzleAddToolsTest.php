<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\NavClickController;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\StopwatchFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\StopwatchStatus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tools under their intent (docs/features/navigation README §4.6, ADD-02): "Use the stopwatch" under Speed puzzling,
 * "Scan several boxes" under Collection, "Other ways to add" below the form. Each row is rendered for every mode and
 * hidden (d-none) unless it belongs to the initial one - the switch shows the right one without a request. Nothing of
 * it while a stopwatch's result or an official result is being saved.
 */
final class PuzzleAddToolsTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, null|string}>
     */
    public static function provideInitialModes(): iterable
    {
        yield 'speed puzzling (default)' => ['/en/puzzle-add', 'speed_puzzling'];
        yield 'relax' => ['/en/puzzle-add?mode=relax', null];
        yield 'collection' => ['/en/puzzle-add?mode=collection', 'collection'];
    }

    #[DataProvider('provideInitialModes')]
    public function testOnlyTheInitialModesToolsAreShown(string $url, null|string $shownTools): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', $url);

        $this->assertResponseIsSuccessful();
        self::assertSame($shownTools === 'speed_puzzling', $this->isShown($crawler, 'speed_puzzling'), 'stopwatch row');
        self::assertSame($shownTools === 'collection', $this->isShown($crawler, 'collection'), 'scanning row');

        // The rows are always there for the switch, each a target of the form's controller
        self::assertCount(1, $crawler->filter('[data-controller="puzzle-add-form"] [data-puzzle-add-form-target="speedTools"][data-add-tools="speed_puzzling"]'));
        self::assertCount(1, $crawler->filter('[data-controller="puzzle-add-form"] [data-puzzle-add-form-target="collectionTools"][data-add-tools="collection"]'));

        // "Other ways to add" in every mode, below the form: the event form under its registry label
        $otherWays = $crawler->filter('[data-add-tools="other_ways"]');
        self::assertCount(1, $otherWays);
        self::assertSame('Other ways to add', trim($otherWays->filter('h2')->text()));
        $addEvent = $otherWays->filter('a[data-add-tool="add_event"]');
        self::assertSame('/en/add-event', $addEvent->attr('href'));
        self::assertSame('Add Event', trim($addEvent->text()));
        self::assertCount(1, $crawler->filter('[data-controller="puzzle-add-form"] ~ [data-add-tools="other_ways"]'), 'below the form');
    }

    public function testTheStopwatchToolStartsAStopwatchForTheChosenPuzzle(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);

        $tool = $browser->request('GET', '/en/puzzle-add')->filter('a[data-add-tool="use_stopwatch"]');
        self::assertSame('/en/stopwatch', $tool->attr('href'));
        self::assertSame('Use the stopwatch', trim($tool->text()));

        $tool = $browser->request('GET', '/en/puzzle-add/' . PuzzleFixture::PUZZLE_1000_05)->filter('a[data-add-tool="use_stopwatch"]');
        self::assertSame('/en/puzzle-stopwatch/' . PuzzleFixture::PUZZLE_1000_05, $tool->attr('href'));
    }

    public function testARunningStopwatchIsOfferedFirst(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);

        // None running: no way back
        self::assertCount(0, $browser->request('GET', '/en/puzzle-add')->filter('a[data-add-tool="back_to_stopwatch"]'));

        $stopwatchId = $this->runningStopwatch(PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/puzzle-add');

        $tools = $crawler->filter('[data-add-tools="speed_puzzling"] a');
        self::assertSame(['back_to_stopwatch', 'use_stopwatch'], $tools->each(static fn (Crawler $tool): string => (string) $tool->attr('data-add-tool')));
        self::assertSame('/en/stopwatch/' . $stopwatchId, $tools->first()->attr('href'));
        self::assertSame('Back to your running stopwatch', trim($tools->first()->text()));
    }

    public function testScanningTakesTheCollectionAlong(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);

        // A member with several collections and none named: multiscan lets them choose
        $scan = $browser->request('GET', '/en/puzzle-add?mode=collection')->filter('a[data-nav="page/add/scan_boxes"]');
        self::assertCount(1, $scan);
        self::assertSame('/en/multiscan?action=add_to_library', $scan->attr('href'));
        self::assertSame('Scan several boxes', $this->ownText($scan));
        self::assertCount(1, $scan->filter('i.bi-upc-scan'));

        // A collection page's "Add" names its collection: scanning goes into it too
        $scan = $browser->request('GET', '/en/puzzle-add?mode=collection&collection=' . CollectionFixture::COLLECTION_PUBLIC)->filter('a[data-nav="page/add/scan_boxes"]');
        self::assertSame('/en/multiscan?action=add_to_library&collection=' . CollectionFixture::COLLECTION_PUBLIC, $scan->attr('href'));
    }

    public function testANonMemberSeesScanningLockedAsEverywhereElse(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $scan = $browser->request('GET', '/en/puzzle-add?mode=collection')->filter('a[data-nav="page/add/scan_boxes"]');

        self::assertCount(1, $scan);
        // Non-members always save into the system collection
        self::assertSame('/en/multiscan?action=add_to_library&collection=' . Collection::SYSTEM_ID, $scan->attr('href'));
        self::assertCount(1, $scan->filter('i.ci-locked'));
        self::assertCount(0, $scan->filter('i.bi-upc-scan'));
    }

    public function testEveryToolLinkIsInstrumented(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->runningStopwatch(PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $navs = $crawler->filter('[data-add-tools] a')->each(static fn (Crawler $link): string => (string) $link->attr('data-nav'));

        self::assertSame(['page/add/back_to_stopwatch', 'page/add/use_stopwatch', 'page/add/scan_boxes', 'page/add/add_event'], $navs);

        foreach ($navs as $nav) {
            self::assertMatchesRegularExpression(NavClickController::NAV_PATTERN, $nav);
        }
    }

    public function testNoToolsWhileSavingAStopwatch(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/save-stopwatch/' . StopwatchFixture::STOPWATCH_PAUSED);

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-add-tools]'));
        self::assertCount(0, $crawler->filter('[data-add-tool]'));
    }

    public function testNoToolsWhileSavingAnOfficialResult(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', sprintf(
            '/en/puzzle-add/%s?competition=%s&official_entry=participant_round:%s',
            PuzzleFixture::PUZZLE_1000_05,
            OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            OfficialResultsFixture::ENTRY_A_ANNA,
        ));

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-official-entry-notice]'));
        self::assertCount(0, $crawler->filter('[data-add-tools]'));
    }

    private function signedIn(string $playerId): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }

    private function isShown(Crawler $crawler, string $tools): bool
    {
        $row = $crawler->filter('[data-add-tools="' . $tools . '"]');
        self::assertCount(1, $row);

        return in_array('d-none', explode(' ', (string) $row->attr('class')), true) === false;
    }

    /**
     * The link's text without the "New" badge
     */
    private function ownText(Crawler $link): string
    {
        $node = $link->getNode(0);
        self::assertNotNull($node);
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text .= $child->textContent;
            }
        }

        return trim($text);
    }

    /**
     * A fresh running stopwatch (its lap started ten minutes ago) - the profile row's runningStopwatch
     */
    private function runningStopwatch(string $playerId): string
    {
        $id = Uuid::uuid7()->toString();
        $now = self::getContainer()->get(ClockInterface::class)->now();

        self::getContainer()->get(Connection::class)->insert('stopwatch', [
            'id' => $id,
            'player_id' => $playerId,
            'puzzle_id' => null,
            'status' => StopwatchStatus::Running->value,
            'laps' => json_encode([['start' => $now->modify('-10 minutes')->format('Y-m-d H:i:s'), 'end' => null]], JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }
}
