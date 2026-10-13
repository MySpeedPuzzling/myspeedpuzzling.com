<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventDetailFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\StopwatchFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\PuzzleAddMode;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The add page opens in the mode of the player's last save from it (docs/features/navigation README §4.6, ADD-01;
 * precedence = plan decision 42, PuzzleAddController::initialMode()). The switch, the form's hidden mode field and the
 * Stimulus controller always agree - also on a refused submit, which is shown in the mode it was sent in.
 */
final class PuzzleAddLastModeTest extends WebTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;

    public function testWithoutALastModeThePageOpensInSpeedPuzzling(): void
    {
        $browser = $this->signedIn();
        self::assertNull($this->lastMode());

        $crawler = $browser->request('GET', '/en/puzzle-add');

        $this->assertResponseIsSuccessful();
        self::assertSame('speed_puzzling', $this->renderedMode($crawler));
    }

    public function testASaveInRelaxOpensTheNextAddInRelax(): void
    {
        $browser = $this->signedIn();

        $this->save($browser, 'relax');
        self::assertResponseRedirects();
        self::assertSame('relax', $this->lastMode());

        $crawler = $browser->request('GET', '/en/puzzle-add');
        self::assertSame('relax', $this->renderedMode($crawler));
    }

    public function testASaveInSpeedPuzzlingIsRememberedToo(): void
    {
        $browser = $this->signedIn();
        $this->rememberMode(PuzzleAddMode::Relax);

        $this->save($browser, 'speed_puzzling');
        self::assertResponseRedirects();

        self::assertSame('speed_puzzling', $this->lastMode());
        self::assertSame('speed_puzzling', $this->renderedMode($browser->request('GET', '/en/puzzle-add')));
    }

    public function testASaveInCollectionOpensAPuzzlesAddInCollectionWithThatPuzzle(): void
    {
        $browser = $this->signedIn();

        $this->save($browser, 'collection');
        self::assertResponseRedirects();
        self::assertSame('collection', $this->lastMode());

        // The puzzle page's Add (navigation add_entry_url()) - the last mode, the puzzle chosen
        $crawler = $browser->request('GET', '/en/puzzle-add/' . PuzzleFixture::PUZZLE_1000_05);

        $this->assertResponseIsSuccessful();
        self::assertSame('collection', $this->renderedMode($crawler));
        self::assertSame(PuzzleFixture::PUZZLE_1000_05, $crawler->filter('input[name="puzzle_add_form[puzzle]"]')->attr('value'));
    }

    public function testAnExplicitModeInTheLinkWins(): void
    {
        $browser = $this->signedIn();
        $this->rememberMode(PuzzleAddMode::Relax);

        // The "Add a time" links (onboarding, statistics, my activity, the welcome screen) name Speed puzzling
        self::assertSame('speed_puzzling', $this->renderedMode($browser->request('GET', '/en/puzzle-add?mode=speed_puzzling')));
        self::assertSame('collection', $this->renderedMode($browser->request('GET', '/en/puzzle-add?mode=collection')));
        // Nothing to name: the last mode
        self::assertSame('relax', $this->renderedMode($browser->request('GET', '/en/puzzle-add?mode=nonsense')));
    }

    public function testTheGuidesAddATimeLinkNamesSpeedPuzzling(): void
    {
        $browser = $this->signedIn();

        $crawler = $browser->request('GET', '/en/getting-started');

        $this->assertResponseIsSuccessful();
        $links = $crawler->filter('main a')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertContains('/en/puzzle-add?mode=speed_puzzling', $links, '"I finished a puzzle"');
        // Its library step keeps naming Collection
        self::assertContains('/en/puzzle-add?mode=collection', $links);
        self::assertNotContains('/en/puzzle-add', $links, 'no "Add" link left to the last mode on the guide');
    }

    public function testAnEventsAddTimeOpensInSpeedPuzzlingWithTheEventChosen(): void
    {
        $browser = $this->signedIn();
        $this->rememberMode(PuzzleAddMode::Relax);

        $crawler = $browser->request('GET', '/en/puzzle-add?competition=' . EventDetailFixture::COMPETITION_HILLTOP_WEEKEND);
        self::assertSame('speed_puzzling', $this->renderedMode($crawler));
        self::assertSame(EventDetailFixture::COMPETITION_HILLTOP_WEEKEND, $crawler->filter('input[name="puzzle_add_form[competition]"]')->attr('value'));

        $crawler = $browser->request('GET', '/en/puzzle-add?series=' . EventsPageFixture::SERIES_HARBOR_NIGHTS);
        self::assertSame('speed_puzzling', $this->renderedMode($crawler));
        self::assertSame('series:' . EventsPageFixture::SERIES_HARBOR_NIGHTS, $crawler->filter('input[name="puzzle_add_form[competition]"]')->attr('value'));

        // An explicit mode still wins over the event (the event is then ignored, as before)
        $crawler = $browser->request('GET', '/en/puzzle-add?mode=relax&competition=' . EventDetailFixture::COMPETITION_HILLTOP_WEEKEND);
        self::assertSame('relax', $this->renderedMode($crawler));
        self::assertSame('', (string) $crawler->filter('input[name="puzzle_add_form[competition]"]')->attr('value'));
    }

    public function testAPairsAddTimeKeepsTheLastModeButNeverCollection(): void
    {
        $browser = $this->signedIn();
        $team = Uuid::uuid7()->toString();

        $this->rememberMode(PuzzleAddMode::Collection);
        self::assertSame('speed_puzzling', $this->renderedMode($browser->request('GET', '/en/puzzle-add?team=' . $team)));

        $this->rememberMode(PuzzleAddMode::Relax);
        self::assertSame('relax', $this->renderedMode($browser->request('GET', '/en/puzzle-add?team=' . $team)));
    }

    public function testAddSolvingKeepsTheLastModeButNeverCollection(): void
    {
        $browser = $this->signedIn();
        $this->rememberMode(PuzzleAddMode::Collection);
        $puzzlePage = '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_05;
        $addSolving = '/en/puzzle-add/' . PuzzleFixture::PUZZLE_1000_05 . '?solve=1';

        // The puzzle page's "Add solving" - its button and the same action in its ⋯ menu - names a solve
        $crawler = $browser->request('GET', $puzzlePage);
        $this->assertResponseIsSuccessful();
        self::assertSame([$addSolving, $addSolving], $crawler->filter('.puzzle-head-actions a[href^="/en/puzzle-add/"]')->each(
            static fn (Crawler $link): string => (string) $link->attr('href'),
        ));
        // The Add place itself (README D7) keeps the plain last mode
        self::assertSame('/en/puzzle-add/' . PuzzleFixture::PUZZLE_1000_05, $crawler->filter('a.site-header-add')->attr('href'));

        // Last Collection: the solve opens Speed puzzling, with the puzzle chosen; the collection save is never offered
        $crawler = $browser->request('GET', $addSolving);
        $this->assertResponseIsSuccessful();
        self::assertSame('speed_puzzling', $this->renderedMode($crawler));
        self::assertSame(PuzzleFixture::PUZZLE_1000_05, $crawler->filter('input[name="puzzle_add_form[puzzle]"]')->attr('value'));

        // Relax and Speed puzzling are both solves: the last one is kept
        $this->rememberMode(PuzzleAddMode::Relax);
        self::assertSame('relax', $this->renderedMode($browser->request('GET', $addSolving)));
        $this->rememberMode(PuzzleAddMode::SpeedPuzzling);
        self::assertSame('speed_puzzling', $this->renderedMode($browser->request('GET', $addSolving)));
    }

    public function testACollectionPagesAddSolvingNamesASolve(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // The owner's list items: ⋯ → "Add solving" - even for a puzzle already in that collection never Collection
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);

        $this->assertResponseIsSuccessful();
        $links = $crawler->filter('a[href^="/en/puzzle-add/"]')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertNotEmpty($links);

        foreach ($links as $href) {
            self::assertMatchesRegularExpression('~^/en/puzzle-add/[0-9a-f-]{36}\?solve=1$~', $href);
        }
    }

    /**
     * Every "Add solving" link of every template carries `solve: 1` - the puzzle page and its ⋯, the puzzle list menus,
     * the collection / unsolved list items and the puzzle picker card (a new one is caught here)
     */
    public function testEveryAddSolvingLinkNamesASolve(): void
    {
        $templates = dirname(__DIR__, 2) . '/templates';
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templates, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            assert($file instanceof \SplFileInfo);
            $source = (string) file_get_contents($file->getPathname());
            $offset = 0;

            while (preg_match("~\{\{\s*'add_solving'\|trans\s*\}\}~", $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $position = $match[0][1];
                $link = substr($source, (int) strrpos(substr($source, 0, $position), '<a '), $position - (int) strrpos(substr($source, 0, $position), '<a '));
                $name = substr($file->getPathname(), strlen($templates) + 1);
                $found[] = $name;

                self::assertStringContainsString("path('puzzle_add', {", $link, $name);
                self::assertMatchesRegularExpression('~path\(\'puzzle_add\', \{[^}]*\bsolve: 1\b[^}]*\}\)~', $link, $name . ': "Add solving" names a solve');
                $offset = $position + strlen($match[0][0]);
            }
        }

        sort($found);
        self::assertSame([
            '_puzzle_item.html.twig',
            '_puzzle_library_item.html.twig',
            'puzzle/_detail_actions_menu.html.twig',
            'puzzle_detail.html.twig',
            'puzzle_picker/_card.html.twig',
        ], $found);
    }

    public function testTheLinksThatPromiseATimeOpenSpeedPuzzling(): void
    {
        $browser = $this->signedIn();
        $this->rememberMode(PuzzleAddMode::Collection);

        // The old "Add time" URLs (bookmarks, old links)
        $browser->request('GET', '/en/add-time/' . PuzzleFixture::PUZZLE_1000_05);
        self::assertResponseRedirects('/en/puzzle-add/' . PuzzleFixture::PUZZLE_1000_05 . '?mode=speed_puzzling', 301);
        $browser->request('GET', '/en/add-time');
        self::assertResponseRedirects('/en/puzzle-add?mode=speed_puzzling', 301);
        self::assertSame('speed_puzzling', $this->renderedMode($browser->request('GET', '/en/puzzle-add/' . PuzzleFixture::PUZZLE_1000_05 . '?mode=speed_puzzling')));

        // Also mode=speed_puzzling: the puzzle summary's "log yours and be the first on the leaderboard" (guests only -
        // PuzzleDetailControllerTest::testPuzzleWithoutTimesInvitesToLogTheFirstOne) and the "miss you" e-mail's "Add a
        // time" (SendPlayerContentDigestHandlerTest)
    }

    public function testTheRecapsAddAnotherInTheModeJustSaved(): void
    {
        $browser = $this->signedIn();

        // A time: "Add new time" and "For the same puzzle" stay Speed puzzling, whatever the last mode becomes later
        $this->save($browser, 'speed_puzzling');
        $recap = (string) $browser->getResponse()->headers->get('Location');
        $this->rememberMode(PuzzleAddMode::Collection);
        $links = $browser->request('GET', $recap)->filter('.recap-actions a[href^="/en/puzzle-add"]')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertSame(['/en/puzzle-add?mode=speed_puzzling', '/en/puzzle-add/' . PuzzleFixture::PUZZLE_500_01 . '?mode=speed_puzzling'], $links);

        // A tracked puzzle (Relax): "Track another puzzle" and "Track the same puzzle again" stay Relax
        $this->save($browser, 'relax');
        $recap = (string) $browser->getResponse()->headers->get('Location');
        $this->rememberMode(PuzzleAddMode::Collection);
        $links = $browser->request('GET', $recap)->filter('.recap-actions a[href^="/en/puzzle-add"]')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertSame(['/en/puzzle-add?mode=relax', '/en/puzzle-add/' . PuzzleFixture::PUZZLE_500_01 . '?mode=relax'], $links);
    }

    /**
     * The server renders the form for the mode it opens in - the same sections puzzle_add_form_controller.js
     * updateVisibility() shows - so a full load shows no other mode's form first and the form is right without JS
     * (navigation review ADD F3)
     *
     * @return iterable<string, array{PuzzleAddMode}>
     */
    public static function provideLastModes(): iterable
    {
        yield 'speed puzzling' => [PuzzleAddMode::SpeedPuzzling];
        yield 'relax' => [PuzzleAddMode::Relax];
        yield 'collection' => [PuzzleAddMode::Collection];
    }

    #[DataProvider('provideLastModes')]
    public function testTheFormIsRenderedForTheModeItOpensIn(PuzzleAddMode $mode): void
    {
        $browser = $this->signedIn();
        $this->rememberMode($mode);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        self::assertSame($mode->value, $this->renderedMode($crawler));

        $speed = $mode === PuzzleAddMode::SpeedPuzzling;
        $relax = $mode === PuzzleAddMode::Relax;
        $collection = $mode === PuzzleAddMode::Collection;

        // updateVisibility()'s truth table
        $expected = [
            'timeAndDateSection' => !$collection,
            'timeSection' => $speed,
            'firstAttemptSection' => $speed,
            'unboxedSection' => $speed,
            'groupSection' => !$collection,
            'competitionSection' => $speed,
            'commonSection' => !$collection,
            'collectionSection' => $collection,
            'noDateCheckboxSection' => $relax,
            // The add form has a date: "I don't remember it" is not ticked, the date field shows
            'dateFieldSection' => true,
            'speedTools' => $speed,
            'collectionTools' => $collection,
        ];
        $shown = [];

        foreach (array_keys($expected) as $target) {
            $section = $crawler->filter('[data-controller="puzzle-add-form"] [data-puzzle-add-form-target="' . $target . '"]');
            self::assertCount(1, $section, $target);
            $shown[$target] = !in_array('d-none', explode(' ', (string) $section->attr('class')), true);
        }

        self::assertSame($expected, $shown);
        // The date is required only for a measured time
        self::assertSame($speed, in_array('required', explode(' ', (string) $crawler->filter('label[data-puzzle-add-form-target="dateLabel"]')->attr('class')), true));
        self::assertCount(0, $crawler->filter('#no-date-checkbox[checked]'));
    }

    public function testSavingAStopwatchOpensInSpeedPuzzlingAndRemembersIt(): void
    {
        $browser = $this->signedIn();
        $this->rememberMode(PuzzleAddMode::Collection);
        $url = '/en/save-stopwatch/' . StopwatchFixture::STOPWATCH_PAUSED;

        $crawler = $browser->request('GET', $url);
        $this->assertResponseIsSuccessful();
        self::assertSame('speed_puzzling', $this->renderedMode($crawler));

        $browser->request('POST', $url, [
            'puzzle_add_form' => $this->submission($crawler, 'speed_puzzling'),
            'time_id' => $crawler->filter('input[name="time_id"]')->attr('value'),
        ]);
        self::assertResponseRedirects();

        self::assertSame('speed_puzzling', $this->lastMode());
    }

    public function testARefusedRelaxSubmitIsShownInRelaxKeepingItsIds(): void
    {
        $browser = $this->signedIn();

        // Opens in Speed puzzling (no last mode); the player switches to Relax and forgets the puzzle
        $crawler = $browser->request('GET', '/en/puzzle-add');
        self::assertSame('speed_puzzling', $this->renderedMode($crawler));
        $timeId = $crawler->filter('input[name="time_id"]')->attr('value');
        $newPuzzleId = $crawler->filter('input[name="new_puzzle_id"]')->attr('value');

        $submission = $this->submission($crawler, 'relax');
        unset($submission['puzzle']);

        $crawler = $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $submission,
            'time_id' => $timeId,
            'new_puzzle_id' => $newPuzzleId,
        ]);

        $this->assertResponseStatusCodeSame(422);
        self::assertSame('relax', $this->renderedMode($crawler));
        self::assertSame($timeId, $crawler->filter('input[name="time_id"]')->attr('value'));
        self::assertSame($newPuzzleId, $crawler->filter('input[name="new_puzzle_id"]')->attr('value'));
        // Refused: nothing remembered
        self::assertNull($this->lastMode());
    }

    public function testOpeningThePageNeverChangesTheLastMode(): void
    {
        $browser = $this->signedIn();
        $this->rememberMode(PuzzleAddMode::Relax);

        $browser->request('GET', '/en/puzzle-add?mode=collection');
        $browser->request('GET', '/en/save-stopwatch/' . StopwatchFixture::STOPWATCH_PAUSED);

        self::assertSame('relax', $this->lastMode());
    }

    private function signedIn(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        return $browser;
    }

    /**
     * The mode the page is rendered in - asserting that the switch, the hidden mode field and the controller agree.
     */
    private function renderedMode(Crawler $crawler): string
    {
        $checked = $crawler->filter('input[name="form-mode"][checked]');
        self::assertCount(1, $checked, 'exactly one mode is checked');
        $switch = (string) $checked->attr('value');

        $hidden = $crawler->filter('select[name="puzzle_add_form[mode]"] option[selected]');
        self::assertCount(1, $hidden, 'the hidden mode field holds one mode');
        self::assertSame($switch, $hidden->attr('value'), 'the switch and the hidden mode field agree');

        self::assertSame($switch, $crawler->filter('[data-controller="puzzle-add-form"]')->attr('data-puzzle-add-form-mode-value'));

        return $switch;
    }

    private function save(KernelBrowser $browser, string $mode): void
    {
        $crawler = $browser->request('GET', '/en/puzzle-add');
        $this->assertResponseIsSuccessful();

        $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $this->submission($crawler, $mode),
            'time_id' => $crawler->filter('input[name="time_id"]')->attr('value'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function submission(Crawler $crawler, string $mode): array
    {
        $token = $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value');
        self::assertNotNull($token);

        return [
            '_token' => $token,
            'mode' => $mode,
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => PuzzleFixture::PUZZLE_500_01,
            'timeHours' => '0',
            'timeMinutes' => '41',
            'timeSeconds' => '13',
            'finishedAt' => '12.07.2026',
            'collection' => '__system_collection__',
        ];
    }

    private function rememberMode(PuzzleAddMode $mode): void
    {
        $this->database()->executeStatement('UPDATE player SET last_add_mode = :mode WHERE id = :id', ['mode' => $mode->value, 'id' => self::PLAYER]);
    }

    private function lastMode(): null|string
    {
        /** @var false|null|string $mode */
        $mode = $this->database()->fetchOne('SELECT last_add_mode FROM player WHERE id = :id', ['id' => self::PLAYER]);
        self::assertNotFalse($mode);

        return $mode;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
