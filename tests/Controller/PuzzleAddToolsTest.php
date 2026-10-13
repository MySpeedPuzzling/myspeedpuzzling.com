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
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\StopwatchFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\StopwatchStatus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

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

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $tool = $crawler->filter('a[data-add-tool="use_stopwatch"]');
        self::assertSame('/en/stopwatch', $tool->attr('href'));
        self::assertSame('Use the stopwatch', trim($tool->text()));
        // The client side follows the puzzle field (puzzle_add_form_controller.js updateStopwatchLink())
        self::assertSame('useStopwatch', $tool->attr('data-puzzle-add-form-target'));
        self::assertSame('/en/stopwatch', $tool->attr('data-stopwatch-url'));
        self::assertSame('/en/puzzle-stopwatch/__PUZZLE_ID__', $tool->attr('data-puzzle-stopwatch-url'));
        self::assertCount(1, $crawler->filter('[data-controller="puzzle-add-form"] input[name="puzzle_add_form[puzzle]"][data-puzzle-add-form-target="puzzleField"]'));

        $tool = $browser->request('GET', '/en/puzzle-add/' . PuzzleFixture::PUZZLE_1000_05)->filter('a[data-add-tool="use_stopwatch"]');
        self::assertSame('/en/puzzle-stopwatch/' . PuzzleFixture::PUZZLE_1000_05, $tool->attr('href'));
    }

    public function testARefusedSubmitKeepsTheChosenPuzzleForTheStopwatch(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/puzzle-add');
        $token = $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value');

        // A puzzle picked in the form, the time forgotten: refused, the stopwatch is offered for that puzzle (review Q1)
        $crawler = $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => [
                '_token' => $token,
                'mode' => 'speed_puzzling',
                'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                'puzzle' => PuzzleFixture::PUZZLE_500_01,
                'finishedAt' => '12.07.2026',
            ],
            'time_id' => $crawler->filter('input[name="time_id"]')->attr('value'),
        ]);

        $this->assertResponseStatusCodeSame(422);
        self::assertSame('/en/puzzle-stopwatch/' . PuzzleFixture::PUZZLE_500_01, $crawler->filter('a[data-add-tool="use_stopwatch"]')->attr('href'));

        // A new puzzle (a name, no id yet): the plain stopwatch
        $crawler = $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => [
                '_token' => $token,
                'mode' => 'speed_puzzling',
                'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                'puzzle' => 'A puzzle nobody added yet',
                'finishedAt' => '12.07.2026',
            ],
            'time_id' => $crawler->filter('input[name="time_id"]')->attr('value'),
        ]);

        $this->assertResponseStatusCodeSame(422);
        self::assertSame('/en/stopwatch', $crawler->filter('a[data-add-tool="use_stopwatch"]')->attr('href'));
    }

    /**
     * The way back to a running stopwatch is no tool of one mode (navigation review ADD Q4): Leave from a stopwatch
     * without a puzzle lands on Add in the last mode, whatever it is
     *
     * @return iterable<string, array{string}>
     */
    public static function provideEveryMode(): iterable
    {
        yield 'speed puzzling' => ['/en/puzzle-add?mode=speed_puzzling'];
        yield 'relax' => ['/en/puzzle-add?mode=relax'];
        yield 'collection' => ['/en/puzzle-add?mode=collection'];
    }

    #[DataProvider('provideEveryMode')]
    public function testARunningStopwatchIsOfferedInEveryMode(string $url): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);

        // None running: no way back
        self::assertCount(0, $browser->request('GET', $url)->filter('a[data-add-tool="back_to_stopwatch"]'));

        $stopwatchId = $this->runningStopwatch(PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', $url);

        $back = $crawler->filter('[data-add-tools="running_stopwatch"]');
        self::assertCount(1, $back);
        self::assertNotContains('d-none', explode(' ', (string) $back->attr('class')), 'shown in every mode');
        self::assertNull($back->attr('data-puzzle-add-form-target'), 'never switched with a mode');
        self::assertSame('/en/stopwatch/' . $stopwatchId, $back->filter('a[data-add-tool="back_to_stopwatch"]')->attr('href'));
        self::assertSame('Back to your running stopwatch', trim($back->text()));
        // First on the page, before the switch; the mode rows hold only their own tools
        self::assertCount(3, $crawler->filter('[data-add-tools="running_stopwatch"] ~ .mb-4 input[name="form-mode"]'));
        self::assertSame(['use_stopwatch'], $crawler->filter('[data-add-tools="speed_puzzling"] a')->each(static fn (Crawler $tool): string => (string) $tool->attr('data-add-tool')));
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
        // The scanning button's own title, as on the library pages (navigation review ADD Q5)
        self::assertSame('Scan a pile of boxes and lend, return, add or wishlist them all at once', $scan->attr('title'));

        // A collection page's "Add" names its collection: scanning goes into it too
        $scan = $browser->request('GET', '/en/puzzle-add?mode=collection&collection=' . CollectionFixture::COLLECTION_PUBLIC)->filter('a[data-nav="page/add/scan_boxes"]');
        self::assertSame('/en/multiscan?action=add_to_library&collection=' . CollectionFixture::COLLECTION_PUBLIC, $scan->attr('href'));
    }

    /**
     * "Scan several boxes" reads like the Menu's row of the same place in every language - one key, nav.row.multiscan
     * (navigation review ADD F4) - and its title like the scanning button of the library pages
     */
    public function testScanningIsNamedLikeTheMenuRowInEveryLanguage(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $paths = ['en' => '/en/puzzle-add', 'cs' => '/pridat-puzzle', 'de' => '/de/puzzle-hinzufuegen', 'es' => '/es/agregar-puzzle', 'fr' => '/fr/ajouter-puzzle', 'ja' => '/ja/パズル追加'];

        foreach ($paths as $locale => $path) {
            $scan = $browser->request('GET', $path . '?mode=collection')->filter('a[data-nav="page/add/scan_boxes"]');
            self::assertCount(1, $scan, $locale);
            self::assertSame($translator->trans('nav.row.multiscan', locale: $locale), $this->ownText($scan), $locale);
            self::assertSame($translator->trans('multiscan.entry.library_title', locale: $locale), $scan->attr('title'), $locale);
        }

        // Same words as the library page's scanning button
        $library = $browser->request('GET', '/en/puzzle-library/' . PlayerFixture::PLAYER_WITH_STRIPE)->filter('a.multiscan-entry');
        self::assertCount(1, $library);
        self::assertSame($translator->trans('multiscan.entry.library_title', locale: 'en'), $library->attr('title'));
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
