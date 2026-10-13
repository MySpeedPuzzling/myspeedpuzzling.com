<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
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
