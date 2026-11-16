<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\ImportRowAnswerRefused;
use SpeedPuzzling\Web\Message\AnswerImportRows;
use SpeedPuzzling\Web\Message\BuildImportPuzzles;
use SpeedPuzzling\Web\Message\ConfirmImportUploaded;
use SpeedPuzzling\Web\Message\MatchImportPuzzlesFuzzy;
use SpeedPuzzling\Web\Message\ParseImportSheet;
use SpeedPuzzling\Web\Message\ProposeImportMappings;
use SpeedPuzzling\Web\Message\ReadImportFile;
use SpeedPuzzling\Web\Message\StartImport;
use SpeedPuzzling\Web\MessageHandler\ParseImportSheetHandler;
use SpeedPuzzling\Web\MessageHandler\ProposeImportMappingsHandler;
use SpeedPuzzling\Web\MessageHandler\ReadImportFileHandler;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAnswers;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\Services\DataImport\ImportPairingScenario;
use SpeedPuzzling\Web\Tests\Services\SpreadsheetReader\RawXlsx;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * docs/features/data-import/pairing-screens.md - the pairing screens: the Puzzles summary, the lists per group, the
 * chooser with its search, "To fix" and the row sheet. Every route is a 404 for another player, every POST needs
 * its token and answers a redirect or a 422, lists are paged, a stale page is refused with a message, and no screen
 * costs more statements for a larger import. Every puzzle and every file here is made up (their names carry a
 * marker no fixture puzzle has).
 */
final class ImportPairingScreensTest extends WebTestCase
{
    use QueryCountAssertions;
    use ImportPairingScenario;

    private KernelBrowser $browser;
    private Connection $database;
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->database = self::getContainer()->get(Connection::class);
        $this->bus = self::getContainer()->get(MessageBusInterface::class);
    }

    public function testTheSummaryShowsEveryGroupWithItsCountsTheNextStepAndFollowsTheRunningPairing(): void
    {
        $scenario = $this->paired();
        $importId = $scenario['import'];
        // The scenario's sheet stands for one that is mapped and read
        $this->database->executeStatement('UPDATE data_import_sheet SET parsed_version = 1, mapping_version = 1 WHERE import_id = :id', ['id' => $importId]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', (string) $this->browser->getResponse()->getContent());

        self::assertSame('We matched 1 of your 5 puzzles.', self::spaced($crawler->filter('[data-testid="import-puzzles-count"]')->text()));
        self::assertSame('4 puzzles wait for you. Do as many as you like.', self::spaced($crawler->filter('[data-testid="import-puzzles-waiting"]')->text()));
        // The piles that wait - each with its count and what it costs; what is matched is a link, not a pile
        self::assertSame(
            [
                ['probably', '1', '1', '1', 'Confirm a screen at a time. About 1 minute.'],
                ['choose', '1', '1', '1', 'One puzzle per screen, the ones with the most rows first.'],
                ['not-found', '2', '2', '2', 'One answer for all of them.'],
            ],
            $crawler->filter('[data-testid="import-puzzle-group"]')->each(static fn (Crawler $group): array => [
                $group->attr('data-group'),
                $group->attr('data-total'),
                $group->attr('data-open'),
                self::spaced($group->filter('[data-testid="import-puzzle-group-open"]')->text()),
                self::spaced($group->filter('[data-testid="import-puzzle-group-cost"]')->text()),
            ]),
        );
        self::assertSame('Matched (1) - see them', self::spaced($crawler->filter('[data-testid="import-puzzles-matched-link"]')->text()));
        self::assertSame('/en/import/' . $importId . '/puzzles/found', $crawler->filter('[data-testid="import-puzzles-matched-link"]')->attr('href'));
        // One clear next step: the first group that needs the player
        self::assertSame('probably', $crawler->filter('[data-testid="import-puzzles-next"]')->attr('data-next'));
        self::assertSame('/en/import/' . $importId . '/puzzles/probably', $crawler->filter('[data-testid="import-puzzles-next-button"]')->attr('href'));
        // No row that could not be read: nothing is said about them (a zero is not printed)
        self::assertCount(0, $crawler->filter('[data-testid="import-puzzles-to-fix"]'));
        // What is ready is added from its own review (docs/features/data-import/adding-screens.md): the card leads
        // there - nothing is added from this page, and nothing "arrives soon" any more
        // (this file's rows carry no day, so none is ready: the card says what waits and where it is settled)
        self::assertCount(0, $crawler->filter('[data-testid="import-flow-add-ready"]'));
        $review = $this->browser->request('GET', '/en/import/' . $importId . '/add');
        self::assertCount(0, $review->filter('[data-testid="import-add-form"]'));
        self::assertSame('2', $review->filter('[data-testid="not-ready-exact-day"]')->attr('data-rows'));
        self::assertSame('/en/import/' . $importId . '/puzzles', $review->filter('[data-testid="not-ready-undecided"] a')->attr('href'));
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles');
        self::assertStringNotContainsString('arrives soon', (string) $this->browser->getResponse()->getContent());
        self::assertStringNotContainsString('Add all', (string) $this->browser->getResponse()->getContent());

        // The pairing rests: the live line is there for the script, hidden - in its pairing mode
        $live = $crawler->filter('[data-testid="import-setup-live"]');
        self::assertSame('true', $live->attr('data-data-import-setup-pairing-value'));
        self::assertSame('false', $live->attr('data-data-import-setup-working-value'));
        self::assertNotNull($live->attr('hidden'));
        self::assertCount(1, $crawler->filter('turbo-frame[data-import-live]'));

        // The payload the line follows carries the pairing's numbers - nothing of the file
        $this->browser->request('GET', '/en/import/' . $importId . '/progress');
        /** @var array{working: bool, revision: string, pairing: array<string, mixed>} $progress */
        $progress = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            ['running' => false, 'puzzles' => 5, 'found' => 1, 'probably' => 1, 'choose' => 1, 'notFound' => 2, 'searching' => 0, 'revision' => '1:1'],
            $progress['pairing'],
        );
        self::assertSame($progress['revision'] . '|1:1', $live->attr('data-data-import-setup-revision-value'));

        // A run is under way (its rows were read again): the page says so by itself and never asks for a refresh
        $this->database->executeStatement('UPDATE data_import SET paired_at = NULL WHERE id = :id', ['id' => $importId]);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles');
        $live = $crawler->filter('[data-testid="import-setup-live"]');
        self::assertSame('true', $live->attr('data-data-import-setup-working-value'));
        self::assertNull($live->attr('hidden'));
        self::assertSame('Matching your puzzles…', self::spaced($live->filter('[data-testid="import-setup-line"]')->text()));
        self::assertStringNotContainsString('does not update by itself', (string) $this->browser->getResponse()->getContent());
        $this->browser->request('GET', '/en/import/' . $importId . '/progress');
        self::assertStringContainsString('"running":true', (string) $this->browser->getResponse()->getContent());

        // The run gave no sign of life for an hour while the player kept working (decisions and answers move the
        // import's last activity, not the pairing's): it is not "running" for the page any more
        $this->database->executeStatement("UPDATE data_import SET pairing_alive_at = :then, last_activity_at = :now WHERE id = :id", ['id' => $importId, 'then' => TestClock::now()->modify('-1 hour')->format('Y-m-d H:i:s'), 'now' => TestClock::FROZEN_NOW]);
        $this->browser->request('GET', '/en/import/' . $importId . '/progress');
        self::assertStringContainsString('"running":false', (string) $this->browser->getResponse()->getContent());
        // ... and the safety net sees it standing
        self::assertContains($importId, self::getContainer()->get(DataImportRepository::class)->idsPairingStandingSince(TestClock::now()->modify('-15 minutes'), TestClock::now()->modify('-1 day'), 10));
        $this->database->executeStatement('UPDATE data_import SET pairing_alive_at = :now WHERE id = :id', ['id' => $importId, 'now' => TestClock::FROZEN_NOW]);
        self::assertNotContains($importId, self::getContainer()->get(DataImportRepository::class)->idsPairingStandingSince(TestClock::now()->modify('-15 minutes'), TestClock::now()->modify('-1 day'), 10));

        // The import's own page follows too, and leads here
        $detail = $this->browser->request('GET', '/en/import/' . $importId);
        self::assertResponseIsSuccessful();
        self::assertSame('/en/import/' . $importId . '/puzzles', $detail->filter('[data-testid="import-flow-line"][data-step="puzzles"] a')->attr('href'));
        self::assertSame('true', $detail->filter('[data-testid="import-setup-live"]')->attr('data-data-import-setup-pairing-value'));
        self::assertStringNotContainsString('does not update by itself', (string) $this->browser->getResponse()->getContent());

        // Step 2 says nothing of the puzzles: only what needs the player about how the file was read (guided-flow.md §5.4)
        $columns = $this->browser->request('GET', '/en/import/' . $importId . '/columns?summary=1');
        self::assertCount(0, $columns->filter('[data-testid="import-found-puzzles"]'));
    }

    public function testAListShowsWhatTheFileSaysTheCataloguePuzzleAndWhyItWasPaired(): void
    {
        $scenario = $this->paired();
        $importId = $scenario['import'];
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        // Found: decided for the player, with the reason - and "Not this one"
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/found');
        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-testid="import-puzzle-card"]');
        self::assertCount(1, $card);
        self::assertSame('catalogue', $card->attr('data-choice'));
        self::assertSame('Zq5b Found Harbour', self::spaced($card->filter('[data-testid="import-puzzle-name"]')->text()));
        self::assertSame('Zq5bbrand · Pieces: 1,000 · Rows: 2', self::spaced($card->filter('[data-testid="import-puzzle-said"]')->text()));
        self::assertSame($scenario['found'], $card->filter('[data-testid="catalogue-puzzle"]')->attr('data-puzzle-id'));
        self::assertSame('Same name, pieces and brand.', self::spaced($card->filter('[data-testid="catalogue-puzzle-why"]')->text()));
        self::assertSame('Matched', self::spaced($card->filter('[data-testid="import-puzzle-state"]')->text()));
        self::assertSame('Wrong puzzle', self::spaced($card->filter('[data-testid="import-puzzle-untick"]')->text()));
        self::assertCount(1, $card->filter('img'));

        // Looks like a match: the picture grid (ImportPuzzlesInBulkTest) - and, by its page, the list: nothing is
        // decided for the player, each card has a button of its own
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/probably');
        self::assertCount(1, $crawler->filter('[data-testid="import-puzzle-tile"]'));
        self::assertCount(0, $crawler->filter('[data-testid="import-puzzle-card"]'));
        self::assertSame('undecided', $this->importPuzzle($importId, 'Zq5b Probably Meadow')['choice']);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/probably?page=1');
        $card = $crawler->filter('[data-testid="import-puzzle-card"]');
        self::assertSame('undecided', $card->attr('data-choice'));
        self::assertSame('Same name and pieces - the brand differs or is missing.', self::spaced($card->filter('[data-testid="catalogue-puzzle-why"]')->text()));
        self::assertSame('Yes, this is it', self::spaced($card->filter('[data-testid="import-puzzle-tick"]')->text()));
        self::assertCount(0, $crawler->filter('input[type="checkbox"]'));
        self::assertCount(0, $crawler->filter('[data-testid="import-puzzle-tick-all-button"]'), 'the list has no button for several');

        // Choose: several candidates - the card leads to the chooser
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/choose');
        $card = $crawler->filter('[data-testid="import-puzzle-card"]');
        self::assertSame('Possible puzzles: 2', self::spaced($card->filter('[data-testid="import-puzzle-candidates"]')->text()));
        self::assertSame('/en/import/' . $importId . '/puzzle/' . $scenario['chooseImportPuzzle'], $card->filter('[data-testid="import-puzzle-choose"]')->attr('href'));
        self::assertCount(1, $crawler->filter('[data-testid="import-puzzle-next-20"]'));

        // Not found: the secret puzzle of the same name is no candidate - nothing fits
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/not-found');
        self::assertCount(2, $crawler->filter('[data-testid="import-puzzle-card"]'));
        self::assertCount(2, $crawler->filter('[data-testid="import-puzzle-no-candidate"]'));
        self::assertStringNotContainsString($scenario['hidden'], (string) $this->browser->getResponse()->getContent());

        // No group of that name
        $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/everything');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAListIsPagedByFiftyAndThePageNumberIsAllTheAddressHolds(): void
    {
        [$import, $sheet] = $this->seedImport($this->regularPlayer());

        for ($number = 1; $number <= 52; $number++) {
            $this->seedRow($import, $sheet, sprintf('Zq5b Paged Nothing Qxv %02d <b>', $number), 300);
        }

        $this->pair($import);
        $importId = $import->id->toString();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/not-found');
        self::assertResponseIsSuccessful();
        self::assertCount(50, $crawler->filter('[data-testid="import-puzzle-card"]'));
        self::assertSame('Zq5b Paged Nothing Qxv 01 <b>', self::spaced($crawler->filter('[data-testid="import-puzzle-name"]')->first()->text()));
        self::assertSame('/en/import/' . $importId . '/puzzles/not-found?page=2', $crawler->filter('[data-testid="import-pages-next"]')->attr('href'));
        // A name of the file is shown as text, never as markup - and never stands in an address
        self::assertStringNotContainsString('Qxv 01 <b>', (string) $this->browser->getResponse()->getContent());
        self::assertSame([], array_filter($crawler->filter('a')->each(static fn (Crawler $link): string => (string) $link->attr('href')), static fn (string $href): bool => str_contains($href, 'Paged')));

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/not-found?page=2');
        self::assertCount(2, $crawler->filter('[data-testid="import-puzzle-card"]'));
        self::assertCount(0, $crawler->filter('[data-testid="import-pages-next"]'));

        // A page that is not there is the last one
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/not-found?page=99');
        self::assertCount(2, $crawler->filter('[data-testid="import-puzzle-card"]'));
    }

    public function testNotThisOneLeavesThePuzzleUndecidedOnTheNextPairingRun(): void
    {
        $scenario = $this->paired();
        $importId = $scenario['import'];
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $page = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/found');
        $found = $this->importPuzzle($importId, 'Zq5b Found Harbour');
        self::assertSame(['catalogue', 'automatic'], [$found['choice'], $found['decided_how']]);

        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', [
            '_token' => $this->token($page),
            'action' => 'undecided',
            'puzzle' => $found['id'],
            'version' => (string) $found['decision_version'],
            'group' => 'found',
            'page' => '1',
        ]);
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzles/found?page=1#puzzle-' . $found['id']);

        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('Undone.', $crawler->filter('body')->text());
        // The card stays on its list, undecided, with its candidate
        $card = $crawler->filter('[data-testid="import-puzzle-card"]');
        self::assertSame('undecided', $card->attr('data-choice'));
        self::assertSame($scenario['found'], $card->filter('[data-testid="catalogue-puzzle"]')->attr('data-puzzle-id'));

        // The next run of the pairing still says "found" - and decides nothing
        $this->pair($importId);
        $after = $this->importPuzzle($importId, 'Zq5b Found Harbour');
        self::assertSame(['found', 'undecided', null, 'player'], [$after['tier'], $after['choice'], $after['puzzle_id'], $after['decided_how']]);

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles');
        self::assertSame('1', $crawler->filter('[data-group="found"]')->attr('data-open'));
    }

    public function testTheGridSendsTheTickedTilesWithTheirVersionsAndAStaleOneIsRefusedWithAMessage(): void
    {
        $player = $this->regularPlayer();
        $brand = $this->seedBrand('Zq5btick');
        [$import, $sheet] = $this->seedImport($player);
        $puzzles = [];

        foreach (['Zq5b Tick Apple', 'Zq5b Tick Birch', 'Zq5b Tick Cedar'] as $name) {
            $puzzles[$name] = $this->seedCataloguePuzzle($name, 500, $brand);
            $this->seedRow($import, $sheet, $name, 500);
        }

        $this->pair($import);
        $importId = $import->id->toString();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $page = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/probably');
        $token = $this->token($page);
        // Every tile of the screen, each with the version and the puzzle it showed - ticked on the screen, and
        // nothing of it stored
        $cards = self::tickAllCards($page);

        self::assertCount(3, $cards);
        $apple = $this->importPuzzle($importId, 'Zq5b Tick Apple');
        $cedar = $this->importPuzzle($importId, 'Zq5b Tick Cedar');
        self::assertSame(['version' => '0', 'catalogue' => $puzzles['Zq5b Tick Apple'], 'tick' => '1'], $cards[$apple['id']]);
        self::assertSame(['undecided', 'undecided', 'undecided'], $this->database->fetchFirstColumn('SELECT choice FROM data_import_puzzle WHERE import_id = :id ORDER BY name', ['id' => $importId]));
        $form = ['_token' => $token, 'action' => 'tick_all', 'group' => 'probably', 'part' => 'same', 'last' => $cedar['id']];

        // One of them is decided meanwhile (another tab)
        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', ['_token' => $token, 'action' => 'left_out', 'puzzle' => $apple['id'], 'version' => '0', 'group' => 'probably', 'page' => '1']);
        self::assertResponseRedirects();

        // The stale screen's "Yes, these are right": refused whole, with a message - nothing is overwritten
        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', [...$form, 'cards' => $cards]);
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzles/probably?part=same');
        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('This was decided meanwhile', $crawler->filter('body')->text());
        self::assertSame(
            ['left_out', 'undecided', 'undecided'],
            $this->database->fetchFirstColumn('SELECT choice FROM data_import_puzzle WHERE import_id = :id ORDER BY name', ['id' => $importId]),
        );

        // The screen as it stands now offers the two that still wait
        $cards = self::tickAllCards($crawler);
        self::assertCount(2, $cards);

        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', [...$form, 'cards' => $cards]);
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzles/probably?part=same&after=' . $cedar['id']);
        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('2 matched.', $crawler->filter('body')->text());
        self::assertSame(
            [['left_out', null], ['catalogue', $puzzles['Zq5b Tick Birch']], ['catalogue', $puzzles['Zq5b Tick Cedar']]],
            array_map(array_values(...), $this->database->fetchAllAssociative('SELECT choice, puzzle_id FROM data_import_puzzle WHERE import_id = :id ORDER BY name', ['id' => $importId])),
        );
        self::assertCount(0, $crawler->filter('[data-testid="import-puzzle-tick-all"]'));
        self::assertCount(1, $crawler->filter('[data-testid="import-pile-nothing-left"]'));
        // The pile's list: a decided card says so and can be changed
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/probably?page=1');
        self::assertCount(3, $crawler->filter('[data-testid="import-puzzle-change"]'));
    }

    public function testTheChooserChoosesAndMovesToTheNextUndecidedPuzzle(): void
    {
        $player = $this->regularPlayer();
        [$import, $sheet] = $this->seedImport($player);
        $candidates = [];

        foreach (['Zq5b Twin Anchor', 'Zq5b Twin Beacon'] as $name) {
            $candidates[$name] = [
                $this->seedCataloguePuzzle($name, 1000, $this->seedBrand('Zq5bone' . substr($name, -3))),
                $this->seedCataloguePuzzle($name, 1000, $this->seedBrand('Zq5btwo' . substr($name, -3))),
            ];
            $this->seedRow($import, $sheet, $name, 1000);
        }

        $this->pair($import);
        $importId = $import->id->toString();
        $anchor = $this->importPuzzle($importId, 'Zq5b Twin Anchor');
        $beacon = $this->importPuzzle($importId, 'Zq5b Twin Beacon');
        self::assertSame(['choose', 'choose'], [$anchor['tier'], $beacon['tier']]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        // "Do the next 20" starts at the first puzzle that waits
        $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/choose/next');
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzle/' . $anchor['id'] . '?flow=choose&done=0');
        $crawler = $this->browser->followRedirect();
        self::assertResponseIsSuccessful();

        // One puzzle per screen: what the file says, its rows, the candidates as buttons, the search, "None of these"
        self::assertSame('Zq5b Twin Anchor', self::spaced($crawler->filter('[data-testid="import-puzzle-name"]')->text()));
        self::assertCount(1, $crawler->filter('[data-testid="import-chooser-row"]'));
        self::assertEqualsCanonicalizing($candidates['Zq5b Twin Anchor'], $crawler->filter('[data-testid="import-chooser-candidate"]')->each(static fn (Crawler $button): string => (string) $button->attr('data-puzzle-id')));
        self::assertSame('Zq5b Twin Anchor', $crawler->filter('[data-testid="import-chooser-search-input"]')->attr('value'));
        self::assertCount(1, $crawler->filter('[data-testid="import-chooser-unlisted"]'));
        self::assertCount(1, $crawler->filter('[data-testid="import-chooser-leave-out"]'));
        self::assertSame('This round: 0 of 20', self::spaced($crawler->filter('[data-testid="import-chooser-portion"]')->text()));
        // Skipping leads to the next one without deciding
        self::assertSame('/en/import/' . $importId . '/puzzles/choose/next?after=' . $anchor['id'] . '&done=0', $crawler->filter('[data-testid="import-chooser-skip"]')->attr('href'));
        $token = $this->token($crawler);

        // A tap chooses - and leads on to the next puzzle that waits
        $choice = ['_token' => $token, 'action' => 'catalogue', 'puzzle' => $anchor['id'], 'version' => '0', 'catalogue' => $candidates['Zq5b Twin Anchor'][1], 'from' => 'chooser', 'group' => 'choose', 'flow' => 'choose', 'done' => '0'];
        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', $choice);
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzles/choose/next?after=' . $anchor['id'] . '&done=1&last=' . $anchor['id']);
        $this->browser->followRedirect();
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzle/' . $beacon['id'] . '?flow=choose&done=1&last=' . $anchor['id']);
        self::assertSame(['catalogue', $candidates['Zq5b Twin Anchor'][1], 'player'], [$this->importPuzzle($importId, 'Zq5b Twin Anchor')['choice'], $this->importPuzzle($importId, 'Zq5b Twin Anchor')['puzzle_id'], $this->importPuzzle($importId, 'Zq5b Twin Anchor')['decided_how']]);

        // The same tap from a page that is stale now: a clear message and the current state - never an overwrite
        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', [...$choice, 'catalogue' => $candidates['Zq5b Twin Anchor'][0]]);
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzle/' . $anchor['id'] . '?flow=choose&done=0');
        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('This was decided meanwhile', $crawler->filter('body')->text());
        self::assertSame($candidates['Zq5b Twin Anchor'][1], $this->importPuzzle($importId, 'Zq5b Twin Anchor')['puzzle_id']);
        self::assertSame('catalogue', $crawler->filter('[data-testid="import-puzzle-chooser"]')->attr('data-choice'));
        self::assertCount(1, $crawler->filter('[data-testid="import-chooser-decided"]'));

        // "None of these" → kept as unlisted, said plainly; then nothing waits any more
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzle/' . $beacon['id'] . '?flow=choose&done=1');
        self::assertStringContainsString('Only you see it', $crawler->filter('[data-testid="import-chooser-none"]')->text());
        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', ['_token' => $token, 'action' => 'unlisted', 'puzzle' => $beacon['id'], 'version' => '0', 'from' => 'chooser', 'group' => 'choose', 'flow' => 'choose', 'done' => '1']);
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzles/choose/next?after=' . $beacon['id'] . '&done=2&last=' . $beacon['id']);
        $this->browser->followRedirect();
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzles/choose');
        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('Nothing else waits for you in this group', $crawler->filter('body')->text());
        self::assertSame(['unlisted', null], [$this->importPuzzle($importId, 'Zq5b Twin Beacon')['choice'], $this->importPuzzle($importId, 'Zq5b Twin Beacon')['puzzle_id']]);

        // After twenty in a row the round rests on the pile's screen (ImportPuzzlesInBulkTest)
        $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/choose/next?done=20');
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzles/choose?done=20');
        self::assertCount(1, $this->browser->followRedirect()->filter('[data-testid="import-pile-nothing-left"]'));

        // Taking a decision back in the chooser stays on the puzzle
        $beaconNow = $this->importPuzzle($importId, 'Zq5b Twin Beacon');
        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', ['_token' => $token, 'action' => 'undecided', 'puzzle' => $beacon['id'], 'version' => (string) $beaconNow['decision_version'], 'from' => 'chooser', 'group' => 'choose']);
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzle/' . $beacon['id']);
        self::assertSame('undecided', $this->importPuzzle($importId, 'Zq5b Twin Beacon')['choice']);
    }

    public function testTheSearchNeverOffersAHiddenPuzzleNorAnotherPlayersUnapprovedOne(): void
    {
        $scenario = $this->paired();
        $importId = $scenario['import'];
        $nothing = $this->importPuzzle($importId, 'Zq5b Nothing Like This Qxv');
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $url = '/en/import/' . $importId . '/puzzle/' . $nothing['id'];
        $token = $this->token($this->browser->request('GET', $url));

        // Asked by the chooser's frame: the page, of which Turbo takes the frame
        $crawler = $this->browser->request('POST', $url . '/search', ['_token' => $token, 'search' => 'Zq5b'], server: ['HTTP_TURBO_FRAME' => 'import-puzzle-search']);
        self::assertResponseIsSuccessful();
        $found = $crawler->filter('turbo-frame#import-puzzle-search [data-testid="import-chooser-search-result"]')->each(static fn (Crawler $result): string => (string) $result->attr('data-puzzle-id'));
        self::assertContains($scenario['found'], $found);
        self::assertContains($scenario['probably'], $found);
        // The player's own unapproved puzzle may be chosen - somebody else's and a hidden one never
        self::assertContains($scenario['ownUnapproved'], $found);
        self::assertNotContains($scenario['hidden'], $found);
        self::assertNotContains($scenario['othersUnapproved'], $found);
        self::assertSame('Zq5b', $crawler->filter('[data-testid="import-chooser-search-input"]')->attr('value'));

        // Without the frame (no script): the same page - a full-page POST never answers 200
        $crawler = $this->browser->request('POST', $url . '/search', ['_token' => $token, 'search' => 'Zq5b Secret Vault']);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $crawler->filter('[data-testid="import-chooser-search-result"]'));
        self::assertCount(1, $crawler->filter('[data-testid="import-chooser-search-empty"]'));

        // One letter is most of the catalogue: nothing is searched, and the page says what to type
        $crawler = $this->browser->request('POST', $url . '/search', ['_token' => $token, 'search' => 'Z'], server: ['HTTP_TURBO_FRAME' => 'import-puzzle-search']);
        self::assertCount(0, $crawler->filter('[data-testid="import-chooser-search-result"]'));
        self::assertStringContainsString('at least two characters', $crawler->filter('[data-testid="import-chooser-search-short"]')->text());

        // A barcode finds its puzzle
        $crawler = $this->browser->request('POST', $url . '/search', ['_token' => $token, 'search' => $scenario['ean']], server: ['HTTP_TURBO_FRAME' => 'import-puzzle-search']);
        self::assertSame([$scenario['probably']], $crawler->filter('[data-testid="import-chooser-search-result"]')->each(static fn (Crawler $result): string => (string) $result->attr('data-puzzle-id')));

        // The search is a POST with its token: nothing typed ever stands in an address
        $this->browser->request('GET', $url . '/search');
        self::assertResponseStatusCodeSame(405);
        $this->browser->request('POST', $url . '/search', ['search' => 'Zq5b']);
        self::assertResponseStatusCodeSame(403);

        // A hidden puzzle sent by hand is refused with a message - what the search offers is taken
        foreach ([$scenario['hidden'], $scenario['othersUnapproved']] as $forged) {
            $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', ['_token' => $token, 'action' => 'catalogue', 'puzzle' => $nothing['id'], 'version' => '0', 'catalogue' => $forged, 'from' => 'chooser', 'group' => 'not-found']);
            self::assertResponseRedirects($url);
            self::assertStringContainsString('That puzzle can not be picked', $this->browser->followRedirect()->filter('body')->text());
            self::assertSame('undecided', $this->importPuzzle($importId, 'Zq5b Nothing Like This Qxv')['choice']);
        }

        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', ['_token' => $token, 'action' => 'catalogue', 'puzzle' => $nothing['id'], 'version' => '0', 'catalogue' => $scenario['ownUnapproved'], 'from' => 'chooser', 'group' => 'not-found', 'confirmed_other_pieces' => '1']);
        self::assertResponseRedirects($url);
        $crawler = $this->browser->followRedirect();
        self::assertSame(['catalogue', $scenario['ownUnapproved']], [$this->importPuzzle($importId, 'Zq5b Nothing Like This Qxv')['choice'], $this->importPuzzle($importId, 'Zq5b Nothing Like This Qxv')['puzzle_id']]);
        self::assertSame('You picked it.', self::spaced($crawler->filter('[data-testid="import-chooser-decided"] [data-testid="catalogue-puzzle-why"]')->text()));
    }

    public function testACandidateMergedSinceIsItsSurvivorAndAnotherPieceCountIsShownProminently(): void
    {
        $scenario = $this->paired();
        $importId = $scenario['import'];
        $survivor = $this->seedCataloguePuzzle('Zq5b Survivor Castle', 2000, $this->seedBrand('Zq5bsurvivor'));
        $merged = Uuid::uuid7()->toString();
        $nothing = $this->importPuzzle($importId, 'Zq5b Nothing Like This Qxv');

        // The candidate the pairing stored was merged into another puzzle since - and that one has another piece count
        $this->database->executeStatement(
            'INSERT INTO puzzle_redirect (id, old_puzzle_id, survivor_puzzle_id, created_at) VALUES (:id, :old, :survivor, :now)',
            ['id' => Uuid::uuid7()->toString(), 'old' => $merged, 'survivor' => $survivor, 'now' => TestClock::FROZEN_NOW],
        );
        $this->database->executeStatement(
            "UPDATE data_import_puzzle SET tier = 'choose', manufacturer_id = (SELECT id FROM manufacturer WHERE name = 'Zq5bbrand'), candidates = CAST(:candidates AS JSONB) WHERE id = :id",
            ['id' => $nothing['id'], 'candidates' => json_encode([['puzzleId' => $merged, 'how' => 'similar_name', 'score' => 0.7, 'piecesDiffer' => false], ['puzzleId' => $scenario['hidden'], 'how' => 'name', 'score' => null, 'piecesDiffer' => false]])],
        );
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzle/' . $nothing['id']);
        self::assertResponseIsSuccessful();
        $token = $this->token($crawler);
        $candidates = $crawler->filter('[data-testid="import-chooser-candidate"]');
        // The survivor - and the puzzle hidden since the pairing is not offered at all
        self::assertSame([$survivor], $candidates->each(static fn (Crawler $button): string => (string) $button->attr('data-puzzle-id')));
        self::assertSame('Zq5b Survivor Castle', self::spaced($candidates->filter('[data-testid="catalogue-puzzle-name"]')->text()));
        self::assertSame('Similar name.', self::spaced($candidates->filter('[data-testid="catalogue-puzzle-why"]')->text()));
        self::assertSame('Another piece count: your file says 300, this puzzle has 2,000.', self::spaced($candidates->filter('[data-testid="catalogue-puzzle-pieces-differ"]')->text()));
        // ... and the file's brand is another one than the candidate's: said as prominently
        self::assertSame('Another brand: Zq5bsurvivor', self::spaced($candidates->filter('[data-testid="catalogue-puzzle-brand-differs"]')->text()));
        self::assertNull($candidates->attr('data-turbo-confirm'));

        // On its list the card does not offer a tick: "Have a look"
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/choose');
        $card = $crawler->filter('#puzzle-' . $nothing['id']);
        self::assertCount(0, $card->filter('[data-testid="import-puzzle-tick"]'));
        self::assertSame('Have a look', self::spaced($card->filter('[data-testid="import-puzzle-choose"]')->text()));

        // A tap on it writes nothing: the server asks once more - with or without a script
        $choice = ['_token' => $token, 'action' => 'catalogue', 'puzzle' => $nothing['id'], 'version' => '0', 'catalogue' => $survivor, 'from' => 'chooser', 'group' => 'choose'];
        $crawler = $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', $choice);
        self::assertResponseStatusCodeSame(422);
        $question = $crawler->filter('[data-testid="import-chooser-ask-pieces"]');
        self::assertStringContainsString('Your file says 300 pieces, this puzzle has 2,000. Pick it anyway?', self::spaced($question->text()));
        self::assertSame('1', $question->filter('input[name="confirmed_other_pieces"]')->attr('value'));
        self::assertSame($survivor, $question->filter('input[name="catalogue"]')->attr('value'));
        self::assertSame('undecided', $this->importPuzzle($importId, 'Zq5b Nothing Like This Qxv')['choice']);

        // Only the button that says yes takes it - and it is the survivor that is stored
        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', [...$choice, 'confirmed_other_pieces' => '1']);
        self::assertResponseRedirects();
        self::assertSame($survivor, $this->importPuzzle($importId, 'Zq5b Nothing Like This Qxv')['puzzle_id']);
    }

    public function testEveryRouteIsNotFoundForAnotherPlayerAndEveryPostNeedsItsToken(): void
    {
        $scenario = $this->paired();
        $importId = $scenario['import'];
        $toFix = $this->imported();
        $rowId = $this->rowId($toFix, 2);
        $found = $this->importPuzzle($importId, 'Zq5b Found Harbour');

        // Somebody else, with an import of their own (the token of their own page)
        [$theirs, $theirSheet] = $this->seedImport(self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->seedRow($theirs, $theirSheet, 'Zq5b Their Own Puzzle', 500);
        $this->pair($theirs);
        $theirId = $theirs->id->toString();
        $theirPuzzle = $this->importPuzzle($theirId, 'Zq5b Their Own Puzzle');

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $token = $this->token($this->browser->request('GET', '/en/import/' . $theirId . '/puzzle/' . $theirPuzzle['id']));

        $gets = [
            '/en/import/' . $importId . '/puzzles',
            '/en/import/' . $importId . '/puzzles/found',
            '/en/import/' . $importId . '/puzzles/choose/next',
            '/en/import/' . $importId . '/puzzle/' . $found['id'],
            '/en/import/' . $importId . '/to-fix',
            '/en/import/' . $toFix . '/to-fix',
            '/en/import/' . $toFix . '/rows/' . $rowId,
            // One's own import with a puzzle or a row of somebody else's
            '/en/import/' . $theirId . '/puzzle/' . $found['id'],
            '/en/import/' . $theirId . '/rows/' . $rowId,
            // Somebody else's import with one's own puzzle
            '/en/import/' . $importId . '/puzzle/' . $theirPuzzle['id'],
        ];

        foreach ($gets as $path) {
            $this->browser->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }

        $posts = [
            ['/en/import/' . $importId . '/puzzles/decide', ['action' => 'undecided', 'puzzle' => $found['id'], 'version' => (string) $found['decision_version']]],
            ['/en/import/' . $importId . '/puzzle/' . $found['id'] . '/search', ['search' => 'Zq5b']],
            ['/en/import/' . $theirId . '/puzzle/' . $found['id'] . '/search', ['search' => 'Zq5b']],
            ['/en/import/' . $toFix . '/to-fix', ['action' => 'take_in', 'row' => $rowId]],
            ['/en/import/' . $toFix . '/to-fix', ['action' => 'leave_out', 'group' => md5('made-up')]],
            ['/en/import/' . $toFix . '/rows/' . $rowId, ['action' => 'leave_out', 'stamp' => 'x']],
            ['/en/import/' . $theirId . '/rows/' . $rowId, ['action' => 'leave_out', 'stamp' => 'x']],
        ];

        foreach ($posts as [$path, $form]) {
            $this->browser->request('POST', $path, ['_token' => $token, ...$form]);
            self::assertResponseStatusCodeSame(404, $path);
        }

        // A puzzle of somebody else's import in one's own: refused, nothing written
        $this->browser->request('POST', '/en/import/' . $theirId . '/puzzles/decide', ['_token' => $token, 'action' => 'left_out', 'puzzle' => $found['id'], 'version' => (string) $found['decision_version']]);
        self::assertResponseRedirects();

        self::assertSame('catalogue', $this->importPuzzle($importId, 'Zq5b Found Harbour')['choice']);
        self::assertSame('waiting', $this->database->fetchOne('SELECT state FROM data_import_row WHERE id = :id', ['id' => $rowId]));

        // The player's own posts without the token, or with a made-up one: nothing is taken
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        foreach ([$posts[0], $posts[1], $posts[3], $posts[5]] as [$path, $form]) {
            $this->browser->request('POST', $path, $form);
            self::assertResponseStatusCodeSame(403, $path);
            $this->browser->request('POST', $path, ['_token' => 'made-up', ...$form]);
            self::assertResponseStatusCodeSame(403, $path);
        }

        self::assertSame('catalogue', $this->importPuzzle($importId, 'Zq5b Found Harbour')['choice']);
        self::assertSame('waiting', $this->database->fetchOne('SELECT state FROM data_import_row WHERE id = :id', ['id' => $rowId]));

        // Signed out: the sign-in page, never the import
        $this->browser->restart();
        $this->browser->request('GET', '/en/import/' . $importId . '/puzzles');
        self::assertResponseRedirects();
    }

    public function testOneAnswerFixesEveryRowOfAValue(): void
    {
        $importId = $this->imported();
        $sheetId = $this->database->fetchOne('SELECT id FROM data_import_sheet WHERE import_id = :id', ['id' => $importId]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/to-fix');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', (string) $this->browser->getResponse()->getContent());

        // Grouped by what is unclear and the value as the sheet has it - the largest group first
        $groups = $crawler->filter('[data-testid="import-to-fix-group"]');
        self::assertSame(
            [['time', 'time_unreadable', '2', 'about an hour <i>'], ['pieces', 'pieces_unreadable', '1', 'lots']],
            $groups->each(static fn (Crawler $group): array => [$group->attr('data-field'), $group->attr('data-code'), $group->attr('data-rows'), self::spaced($group->filter('[data-testid="import-to-fix-value"]')->text())]),
        );
        // A cell's markup is text, and no value stands in an address or a form's action
        self::assertStringNotContainsString('about an hour <i>', (string) $this->browser->getResponse()->getContent());
        self::assertStringNotContainsString('about', implode(' ', $crawler->filter('a, form')->each(static fn (Crawler $node): string => $node->attr('href') . $node->attr('action'))));

        $before = $this->database->fetchAssociative('SELECT rows_understood, rows_need_look, rows_left_out FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]);
        self::assertSame(['rows_understood' => 2, 'rows_need_look' => 3, 'rows_left_out' => 1], $before);

        $token = $this->token($crawler);
        $key = (string) $groups->eq(0)->filter('input[name="group"]')->first()->attr('value');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);
        $this->queue()->reset();

        $this->browser->request('POST', '/en/import/' . $importId . '/to-fix', ['_token' => $token, 'group' => $key, 'page' => '1', 'action' => 'answer', 'answer' => '1:15:00']);
        self::assertResponseRedirects('/en/import/' . $importId . '/to-fix?page=1');
        // The import's puzzles are built and paired again - queued once this was committed
        self::assertCount(1, array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $this->queue()->getSent()), static fn (object $message): bool => $message instanceof BuildImportPuzzles));
        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('Rows fixed: 2', $crawler->filter('body')->text());

        // Both rows keep the answer and were read again at once: the time is there, nothing is unclear about them
        $rows = $this->database->fetchAllAssociative("SELECT row_number, parsed ->> 'seconds' AS seconds, problems, answers ->> 'time' AS answer, state FROM data_import_row WHERE import_id = :id AND row_number IN (2, 3) ORDER BY row_number", ['id' => $importId]);
        self::assertSame([[2, '4500', null, 'waiting'], [3, '4500', null, 'waiting']], array_map(static fn (array $row): array => [$row['row_number'], $row['seconds'], $row['problems'], $row['state']], $rows));
        self::assertIsString($rows[0]['answer']);
        self::assertSame(['text' => '1:15:00', 'value' => 4500], json_decode($rows[0]['answer'], true));
        // The sheet's counts moved with them, and the pairing is due again
        self::assertSame(
            ['rows_understood' => 4, 'rows_need_look' => 1, 'rows_left_out' => 1],
            $this->database->fetchAssociative('SELECT rows_understood, rows_need_look, rows_left_out FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]),
        );
        self::assertNull($this->database->fetchOne('SELECT paired_at FROM data_import WHERE id = :id', ['id' => $importId]));

        // The group is gone from the page
        self::assertSame(['pieces_unreadable'], $crawler->filter('[data-testid="import-to-fix-group"]')->each(static fn (Crawler $group): string => (string) $group->attr('data-code')));

        // A later parse of the whole sheet honours the answers
        $this->database->executeStatement('UPDATE data_import_sheet SET mapping_version = mapping_version + 1 WHERE id = :id', ['id' => $sheetId]);
        self::assertIsString($sheetId);
        $this->handle([new ParseImportSheet($importId, $sheetId, 2)]);
        self::assertSame(
            ['4500', '4500'],
            $this->database->fetchFirstColumn("SELECT parsed ->> 'seconds' FROM data_import_row WHERE import_id = :id AND row_number IN (2, 3) ORDER BY row_number", ['id' => $importId]),
        );
        self::assertSame(
            ['rows_understood' => 4, 'rows_need_look' => 1, 'rows_left_out' => 1],
            $this->database->fetchAssociative('SELECT rows_understood, rows_need_look, rows_left_out FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]),
        );
    }

    public function testAnAnswerNobodyCanReadIsRefusedAtItsFieldAndAStaleOneWithAMessage(): void
    {
        $importId = $this->imported();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/to-fix');
        $token = $this->token($crawler);
        $key = (string) $crawler->filter('[data-testid="import-to-fix-group"]')->eq(0)->filter('input[name="group"]')->first()->attr('value');
        $unchanged = fn (): array => $this->database->fetchFirstColumn('SELECT COALESCE(answers::text, problems::text) FROM data_import_row WHERE import_id = :id ORDER BY row_number', ['id' => $importId]);
        $before = $unchanged();

        // "45:10" is minutes or hours; "no idea" is no time; nothing typed is nothing
        foreach (['45:10' => 'We could not read this for sure', 'no idea' => 'We could not read this for sure', '' => 'Please fill in a value first'] as $typed => $message) {
            $crawler = $this->browser->request('POST', '/en/import/' . $importId . '/to-fix', ['_token' => $token, 'group' => $key, 'page' => '1', 'action' => 'answer', 'answer' => (string) $typed]);
            self::assertResponseStatusCodeSame(422, (string) $typed);
            $group = $crawler->filter('#group-' . $key);
            self::assertStringContainsString($message, $group->filter('[data-testid="import-to-fix-error"]')->text());
            // What was typed is kept
            self::assertSame((string) $typed, $group->filter('[data-testid="import-answer-input"]')->attr('value'));
            self::assertSame($before, $unchanged());
        }

        // The sheet is being read again (its mapping changed in another tab): nothing is answered into it
        $this->database->executeStatement('UPDATE data_import_sheet SET parsed_version = NULL WHERE import_id = :id', ['id' => $importId]);
        $rowId = $this->rowId($importId, 2);
        $this->browser->request('POST', '/en/import/' . $importId . '/rows/' . $rowId, ['_token' => $token, 'action' => 'leave_out', 'stamp' => 'whatever']);
        self::assertResponseRedirects('/en/import/' . $importId . '/rows/' . $rowId);
        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('This row changed meanwhile', $crawler->filter('body')->text());
        self::assertCount(1, $crawler->filter('[data-testid="import-row-not-answerable"]'));
        self::assertSame($before, $unchanged());

        // A group nobody has any more (answered in another tab)
        $this->database->executeStatement('UPDATE data_import_sheet SET parsed_version = mapping_version WHERE import_id = :id', ['id' => $importId]);
        $this->browser->request('POST', '/en/import/' . $importId . '/to-fix', ['_token' => $token, 'group' => md5('gone'), 'page' => '1', 'action' => 'answer', 'answer' => '1:00:00']);
        self::assertResponseRedirects('/en/import/' . $importId . '/to-fix?page=1');
        self::assertStringContainsString('These rows changed meanwhile', $this->browser->followRedirect()->filter('body')->text());
        self::assertSame($before, $unchanged());
    }

    public function testRowsOfAValueAreLeftOutAndOneIsTakenInAgain(): void
    {
        $importId = $this->imported();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/to-fix');
        $token = $this->token($crawler);
        $key = (string) $crawler->filter('[data-testid="import-to-fix-group"]')->eq(0)->filter('input[name="group"]')->first()->attr('value');

        $this->browser->request('POST', '/en/import/' . $importId . '/to-fix', ['_token' => $token, 'group' => $key, 'page' => '1', 'action' => 'leave_out']);
        self::assertResponseRedirects();
        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('Rows not imported: 2', $crawler->filter('body')->text());
        self::assertSame(
            [['left_out', 'not_a_puzzle', 'by_player'], ['left_out', 'not_a_puzzle', 'by_player']],
            array_map(array_values(...), $this->database->fetchAllAssociative("SELECT state, kind, parsed ->> 'leftOut' AS why FROM data_import_row WHERE import_id = :id AND row_number IN (2, 3) ORDER BY row_number", ['id' => $importId])),
        );
        self::assertSame(
            ['rows_understood' => 2, 'rows_need_look' => 1, 'rows_left_out' => 3],
            $this->database->fetchAssociative('SELECT rows_understood, rows_need_look, rows_left_out FROM data_import_sheet WHERE import_id = :id', ['id' => $importId]),
        );

        // They are listed, each with the way back
        $leftOut = $crawler->filter('[data-testid="import-to-fix-left-out-row"]');
        self::assertCount(2, $leftOut);
        self::assertStringContainsString('Row 2 · Results', self::spaced($leftOut->eq(0)->text()));

        $this->browser->request('POST', '/en/import/' . $importId . '/to-fix', ['_token' => $token, 'action' => 'take_in', 'row' => $this->rowId($importId, 2), 'page' => '1']);
        self::assertResponseRedirects();
        self::assertStringContainsString('The row is put back.', $this->browser->followRedirect()->filter('body')->text());
        self::assertSame(
            [['waiting', 'result'], ['left_out', 'not_a_puzzle']],
            array_map(array_values(...), $this->database->fetchAllAssociative('SELECT state, kind FROM data_import_row WHERE import_id = :id AND row_number IN (2, 3) ORDER BY row_number', ['id' => $importId])),
        );
        self::assertSame(
            ['rows_understood' => 2, 'rows_need_look' => 2, 'rows_left_out' => 2],
            $this->database->fetchAssociative('SELECT rows_understood, rows_need_look, rows_left_out FROM data_import_sheet WHERE import_id = :id', ['id' => $importId]),
        );
    }

    public function testTheRowSheetShowsEveryFieldWithTheFilesTextAndSavesAnswers(): void
    {
        $importId = $this->imported();
        $rowId = $this->rowId($importId, 5);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        // Reached from the list of rows that need a look, and from "To fix"
        $sheetId = $this->database->fetchOne('SELECT id FROM data_import_sheet WHERE import_id = :id', ['id' => $importId]);
        self::assertIsString($sheetId);
        $list = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $sheetId . '/need-a-look');
        // The link says where it leads back to
        self::assertContains(
            ['/en/import/' . $importId . '/rows/' . $rowId, '/en/import/' . $importId . '/sheets/' . $sheetId . '/need-a-look'],
            $list->filter('[data-testid="parsed-row-open"]')->each(static fn (Crawler $link): array => self::linkAndReturn((string) $link->attr('href'))),
        );

        $url = '/en/import/' . $importId . '/rows/' . $rowId;
        $crawler = $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', (string) $this->browser->getResponse()->getContent());

        // Every mapped field, the file's own text beside its input
        self::assertSame(
            [['puzzle_name', 'Zq5b Fix Delta'], ['pieces', 'lots'], ['solved_on', '2026-01-13'], ['time', '0:30:11']],
            $crawler->filter('[data-testid="import-row-field"]')->each(static fn (Crawler $field): array => [$field->attr('data-field'), self::spaced($field->filter('[data-testid="import-row-original"]')->text())]),
        );
        self::assertSame('pieces_unreadable', $crawler->filter('[data-field="pieces"] [data-testid="import-row-problem"]')->attr('data-code'));
        self::assertSame('', $crawler->filter('[data-field="pieces"] [data-testid="import-answer-input"]')->attr('value'));
        self::assertSame('date', $crawler->filter('[data-field="solved_on"] [data-testid="import-answer-input"]')->attr('type'));
        $token = $this->token($crawler);
        $stamp = (string) $crawler->filter('input[name="stamp"]')->first()->attr('value');
        $this->queue()->reset();

        // A value nobody can read: the same page, the reason at its field, everything typed kept
        $crawler = $this->browser->request('POST', $url, ['_token' => $token, 'stamp' => $stamp, 'action' => 'save', 'answers' => ['pieces' => 'a few', 'puzzle_name' => 'Zq5b Fix Delta Renamed']]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('We could not read this for sure', $crawler->filter('[data-field="pieces"] [data-testid="import-row-error"]')->text());
        self::assertSame('a few', $crawler->filter('[data-field="pieces"] [data-testid="import-answer-input"]')->attr('value'));
        self::assertSame('Zq5b Fix Delta Renamed', $crawler->filter('[data-field="puzzle_name"] [data-testid="import-answer-input"]')->attr('value'));
        self::assertNull($this->database->fetchOne('SELECT answers FROM data_import_row WHERE id = :id', ['id' => $rowId]));

        // Saved: the answers stand instead of the cells, the row is read again, its key is another
        $keyBefore = $this->database->fetchOne('SELECT puzzle_key FROM data_import_row WHERE id = :id', ['id' => $rowId]);
        $this->browser->request('POST', $url, ['_token' => $token, 'stamp' => $stamp, 'action' => 'save', 'answers' => ['pieces' => '500', 'puzzle_name' => '', 'solved_on' => '', 'time' => '']]);
        // Saved, and no screen to go back to: on to the next row that needs a look, which says what was saved
        self::assertResponseRedirects('/en/import/' . $importId . '/rows/' . $this->rowId($importId, 2));
        self::assertStringContainsString('Row 5 is saved. We can read all of it now.', $this->browser->followRedirect()->filter('body')->text());
        $crawler = $this->browser->request('GET', $url);
        $row = $this->database->fetchAssociative("SELECT parsed ->> 'pieces' AS pieces, problems, puzzle_key, answers FROM data_import_row WHERE id = :id", ['id' => $rowId]);
        self::assertIsArray($row);
        self::assertSame(['500', null], [$row['pieces'], $row['problems']]);
        self::assertNotSame($keyBefore, $row['puzzle_key']);
        self::assertSame('500', $crawler->filter('[data-field="pieces"] [data-testid="import-answer-input"]')->attr('value'));
        self::assertCount(0, $crawler->filter('[data-testid="import-row-problem"]'));

        // The page that is stale now (the old stamp): a message, nothing saved
        $this->browser->request('POST', $url, ['_token' => $token, 'stamp' => $stamp, 'action' => 'save', 'answers' => ['pieces' => '1000']]);
        self::assertResponseRedirects($url);
        self::assertStringContainsString('This row changed meanwhile', $this->browser->followRedirect()->filter('body')->text());
        self::assertSame('500', $this->database->fetchOne("SELECT parsed ->> 'pieces' FROM data_import_row WHERE id = :id", ['id' => $rowId]));

        // Nothing changed: nothing is sent
        $stamp = (string) $crawler->filter('input[name="stamp"]')->first()->attr('value');
        $this->browser->request('POST', $url, ['_token' => $token, 'stamp' => $stamp, 'action' => 'save', 'answers' => ['pieces' => '500']]);
        self::assertResponseRedirects($url);
        self::assertStringContainsString('Nothing was changed', $this->browser->followRedirect()->filter('body')->text());

        // An emptied field goes back to what the file says - and needs a look again
        $this->browser->request('POST', $url, ['_token' => $token, 'stamp' => $stamp, 'action' => 'save', 'answers' => ['pieces' => '']]);
        // ... so the player stays with this row, although another one needs a look too
        self::assertResponseRedirects($url);
        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('Row 5 is saved. Something in it still needs a look.', $crawler->filter('body')->text());
        self::assertNull($this->database->fetchOne('SELECT answers FROM data_import_row WHERE id = :id', ['id' => $rowId]));
        self::assertSame($keyBefore, $this->database->fetchOne('SELECT puzzle_key FROM data_import_row WHERE id = :id', ['id' => $rowId]));
        self::assertCount(1, $crawler->filter('[data-field="pieces"] [data-testid="import-row-problem"]'));

        // "Leave this row out", and back
        $stamp = (string) $crawler->filter('input[name="stamp"]')->first()->attr('value');
        $this->browser->request('POST', $url, ['_token' => $token, 'stamp' => $stamp, 'action' => 'leave_out']);
        self::assertResponseRedirects();
        self::assertStringContainsString('Row 5 is not imported.', $this->browser->followRedirect()->filter('body')->text());
        $crawler = $this->browser->request('GET', $url);
        self::assertSame('left_out', $crawler->filter('[data-testid="import-row-page"]')->attr('data-state'));
        self::assertCount(0, $crawler->filter('[data-testid="import-row-form"]'));
        $stamp = (string) $crawler->filter('input[name="stamp"]')->first()->attr('value');
        $this->browser->request('POST', $url, ['_token' => $token, 'stamp' => $stamp, 'action' => 'take_in']);
        self::assertResponseRedirects($url);
        self::assertSame('waiting', $this->database->fetchOne('SELECT state FROM data_import_row WHERE id = :id', ['id' => $rowId]));
    }

    public function testTheRowEditorLeadsBackToTheScreenThePlayerCameFrom(): void
    {
        $importId = $this->imported();
        $base = '/en/import/' . $importId;
        [$row2, $row3, $row5] = [$this->rowId($importId, 2), $this->rowId($importId, 3), $this->rowId($importId, 5)];
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        // "Rows we could not read" says where its links lead back to: the list, at the group
        $toFix = $this->browser->request('GET', $base . '/to-fix');
        $key = (string) $toFix->filter('[data-testid="import-to-fix-group"]')->eq(0)->filter('input[name="group"]')->first()->attr('value');
        [$opened, $return] = self::linkAndReturn((string) $toFix->filter('[data-testid="import-to-fix-open-row"]')->eq(0)->attr('href'));
        self::assertContains($opened, [$base . '/rows/' . $row2, $base . '/rows/' . $row3]);
        self::assertSame($base . '/to-fix#group-' . $key, $return);

        // The editor's back link is that screen, by its name - and the form carries it
        $addList = $base . '/add/list?page=2#row-' . $row5;
        $editor = $this->browser->request('GET', $base . '/rows/' . $row5 . '?return=' . rawurlencode($addList));
        self::assertResponseIsSuccessful();
        self::assertSame($addList, $editor->filter('[data-testid="import-flow-back-link"]')->attr('href'));
        self::assertStringContainsString('Back to “What will be added”', $editor->filter('[data-testid="import-flow-back-link"]')->text());
        self::assertSame([$addList, $addList], $editor->filter('input[name="return"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')));

        // Without one - or with one that is not a screen of this import - the way back is "Rows we could not read"
        $othersImport = Uuid::uuid7()->toString();

        foreach ([null, '//evil.example/x', 'https://evil.example' . $base, '/en/import/' . $othersImport . '/to-fix', $base . '/../../admin', $base . '/rows/' . $row2, '/en/hub'] as $hostile) {
            $editor = $this->browser->request('GET', $base . '/rows/' . $row5 . ($hostile === null ? '' : '?return=' . rawurlencode($hostile)));
            self::assertResponseIsSuccessful();
            self::assertSame($base . '/to-fix', $editor->filter('[data-testid="import-flow-back-link"]')->attr('href'), (string) $hostile);
            self::assertStringContainsString('Rows we could not read', $editor->filter('[data-testid="import-flow-back-link"]')->text());
            self::assertCount(0, $editor->filter('input[name="return"]'));
        }

        $token = $this->token($editor);
        $stampOf = fn (string $rowId): string => (string) $this->browser->request('GET', $base . '/rows/' . $rowId)->filter('input[name="stamp"]')->first()->attr('value');

        // A value nobody can read: the same page with a 422 - the way back is still there
        $refused = $this->browser->request('POST', $base . '/rows/' . $row5, ['_token' => $token, 'stamp' => $stampOf($row5), 'action' => 'save', 'return' => $return, 'answers' => ['pieces' => 'a few']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($return, $refused->filter('[data-testid="import-flow-back-link"]')->attr('href'));
        self::assertSame($return, $refused->filter('[data-testid="import-row-form"] input[name="return"]')->attr('value'));

        // Saved: back to where the player came from, which names the row - fixed in view, the page being scrolled
        // to the group. The row's own group (it was its only row) is gone by then: the list of groups it is
        $piecesKey = (string) $toFix->filter('[data-testid="import-to-fix-group"]')->eq(1)->filter('input[name="group"]')->first()->attr('value');
        $this->browser->request('POST', $base . '/rows/' . $row5, ['_token' => $token, 'stamp' => $stampOf($row5), 'action' => 'save', 'return' => $base . '/to-fix#group-' . $piecesKey, 'answers' => ['pieces' => '500']]);
        self::assertResponseRedirects($base . '/to-fix#import-to-fix-count');
        $back = $this->browser->followRedirect();
        self::assertStringContainsString('Row 5 is saved. We can read all of it now.', $back->filter('[data-testid="import-saved-notice"]')->text());
        self::assertCount(1, $back->filter('[data-testid="import-to-fix-page"] #import-to-fix-count'));
        // Said once
        self::assertCount(0, $this->browser->request('GET', $base . '/to-fix')->filter('[data-testid="import-saved-notice"]'));

        // A group that is still there keeps its anchor (below: the way back travels on unchanged)
        // "Save and fix the next one" is offered while another row needs a look, and takes the way back along
        $editor = $this->browser->request('GET', $base . '/rows/' . $row2 . '?return=' . rawurlencode($return));
        self::assertSame(['save', 'save_next'], $editor->filter('[data-testid="import-row-form"] button[type="submit"]')->each(static fn (Crawler $button): string => (string) $button->attr('value')));
        $this->browser->request('POST', $base . '/rows/' . $row2, ['_token' => $token, 'stamp' => $stampOf($row2), 'action' => 'save_next', 'return' => $return, 'answers' => ['time' => '1:00:00']]);
        self::assertResponseRedirects();
        self::assertSame([$base . '/rows/' . $row3, $return], self::linkAndReturn((string) $this->browser->getResponse()->headers->get('Location')));
        $next = $this->browser->followRedirect();
        self::assertStringContainsString('Row 2 is saved.', $next->filter('body')->text());
        self::assertSame($return, $next->filter('[data-testid="import-flow-back-link"]')->attr('href'));
        // The last row that needs a look has no next one
        self::assertCount(0, $next->filter('[data-testid="import-row-save-next"]'));

        // A way back that is not of this import is not followed on a save either: none is left, so the list it is
        $this->browser->request('POST', $base . '/rows/' . $row3, ['_token' => $token, 'stamp' => $stampOf($row3), 'action' => 'save', 'return' => '//evil.example/x', 'answers' => ['time' => '1:00:00']]);
        self::assertResponseRedirects($base . '/to-fix');
        $list = $this->browser->followRedirect();
        self::assertStringContainsString('Row 3 is saved.', $list->filter('body')->text());
        self::assertCount(1, $list->filter('[data-testid="import-to-fix-empty"]'));
    }

    public function testNoTimeMakesARowAResultWithoutATimeAndBack(): void
    {
        $importId = $this->imported();
        $base = '/en/import/' . $importId;
        [$row2, $row4] = [$this->rowId($importId, 2), $this->rowId($importId, 4)];
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $state = fn (string $rowId): array => array_values((array) $this->database->fetchAssociative(
            "SELECT kind, parsed ->> 'seconds' AS seconds, problems::text AS problems, answers ->> 'time' AS answer FROM data_import_row WHERE id = :id",
            ['id' => $rowId],
        ));
        $save = function (string $rowId, array $form) use ($base): void {
            $page = $this->browser->request('GET', $base . '/rows/' . $rowId);
            $this->browser->request('POST', $base . '/rows/' . $rowId, ['_token' => $this->token($page), 'stamp' => (string) $page->filter('input[name="stamp"]')->first()->attr('value'), 'action' => 'save', ...$form]);
            self::assertResponseRedirects();
        };

        // Beside the time's input - not ticked for a row whose time nobody could read
        $editor = $this->browser->request('GET', $base . '/rows/' . $row2);
        $choice = $editor->filter('[data-field="time"] [data-testid="import-row-no-time"]');
        self::assertCount(1, $choice);
        self::assertNull($choice->attr('checked'));
        self::assertStringContainsString("No time - I didn't time this one", $editor->filter('[data-testid="import-row-no-time-label"]')->text());
        self::assertCount(0, $editor->filter('[data-field="pieces"] [data-testid="import-row-no-time"]'));
        self::assertSame('result', $state($row2)[0]);
        $problems = $state($row2)[2];
        self::assertIsString($problems);
        self::assertStringContainsString('time_unreadable', self::spaced($problems));

        // Ticked (the input is switched off, so no time is sent): a result without a time, nothing unclear
        $save($row2, ['no_time' => '1']);
        self::assertSame(['result', null, null, '{"text": "no_time", "value": 0}'], $state($row2));
        $editor = $this->browser->request('GET', $base . '/rows/' . $row2);
        self::assertNotNull($editor->filter('[data-testid="import-row-no-time"]')->attr('checked'));
        self::assertSame('', $editor->filter('[data-field="time"] [data-testid="import-answer-input"]')->attr('value'));
        self::assertCount(0, $editor->filter('[data-testid="import-row-problem"]'));

        // Saved once more as it is: nothing changes
        $save($row2, ['no_time' => '1']);
        self::assertStringContainsString('Nothing was changed', $this->browser->followRedirect()->filter('body')->text());

        // Back to a time: typed instead
        $save($row2, ['answers' => ['time' => '1:15:00']]);
        self::assertSame(['result', '4500', null, '{"text": "1:15:00", "value": 4500}'], $state($row2));

        // A row whose time was read: without a script the tick wins over what is typed
        self::assertSame(['result', '2893', null, null], $state($row4));
        $save($row4, ['no_time' => '1', 'answers' => ['time' => '0:40:00']]);
        self::assertSame(['result', null, null, '{"text": "no_time", "value": 0}'], $state($row4));
        // ... and the tick taken away with nothing typed: the file's own time again
        $save($row4, ['answers' => ['time' => '']]);
        self::assertSame(['result', '2893', null, null], $state($row4));

        // A row of a shelf whose time cell is empty is never offered it - "No time" would say "solved" by itself -
        // and a tick sent all the same is not taken
        $row6 = $this->rowId($importId, 6);
        $stored = $this->database->fetchOne('SELECT cells FROM data_import_row WHERE id = :id', ['id' => $row6]);
        self::assertIsString($stored);
        $cells = json_decode($stored, true);
        self::assertIsArray($cells);
        array_pop($cells);
        $this->database->executeStatement("UPDATE data_import_row SET kind = 'owned', cells = :cells WHERE id = :id", ['cells' => json_encode($cells), 'id' => $row6]);
        $editor = $this->browser->request('GET', $base . '/rows/' . $row6);
        self::assertCount(1, $editor->filter('[data-field="time"] [data-testid="import-answer-input"]'));
        self::assertCount(0, $editor->filter('[data-testid="import-row-no-time"]'));
        $save($row6, ['no_time' => '1']);
        self::assertStringContainsString('Nothing was changed', $this->browser->followRedirect()->filter('body')->text());
        self::assertNull($this->database->fetchOne('SELECT answers FROM data_import_row WHERE id = :id', ['id' => $row6]));
    }

    public function testAGroupOfUnreadableTimesIsImportedWithoutATime(): void
    {
        $importId = $this->imported();
        $base = '/en/import/' . $importId;
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', $base . '/to-fix');
        $groups = $crawler->filter('[data-testid="import-to-fix-group"]');
        $token = $this->token($crawler);
        [$timeKey, $piecesKey] = [(string) $groups->eq(0)->filter('input[name="group"]')->first()->attr('value'), (string) $groups->eq(1)->filter('input[name="group"]')->first()->attr('value')];

        // Offered beside the answer of a group of times only
        self::assertStringContainsString('Import these without a time (2)', $groups->eq(0)->filter('[data-testid="import-to-fix-no-time"]')->text());
        self::assertCount(0, $groups->eq(1)->filter('[data-testid="import-to-fix-no-time"]'));

        // A group of another field is never made time-less
        $this->browser->request('POST', $base . '/to-fix', ['_token' => $token, 'group' => $piecesKey, 'page' => '1', 'action' => 'no_time']);
        self::assertResponseRedirects($base . '/to-fix?page=1');
        self::assertStringContainsString('These rows changed meanwhile', $this->browser->followRedirect()->filter('body')->text());
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND answers IS NOT NULL', ['id' => $importId]));

        // Without a script: back to the list, which says it
        $this->browser->request('POST', $base . '/to-fix', ['_token' => $token, 'group' => $timeKey, 'page' => '1', 'action' => 'no_time', 'answer' => '']);
        self::assertResponseRedirects($base . '/to-fix?page=1');
        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('Rows that will be imported without a time: 2', $crawler->filter('body')->text());
        self::assertSame(['pieces_unreadable'], $crawler->filter('[data-testid="import-to-fix-group"]')->each(static fn (Crawler $group): string => (string) $group->attr('data-code')));

        // Both rows are results without a time, with nothing unclear - what an empty time cell makes of a row
        self::assertSame(
            [[2, 'result', null, null, '{"text": "no_time", "value": 0}'], [3, 'result', null, null, '{"text": "no_time", "value": 0}']],
            array_map(array_values(...), $this->database->fetchAllAssociative(
                "SELECT row_number, kind, parsed ->> 'seconds' AS seconds, problems::text AS problems, answers ->> 'time' AS answer FROM data_import_row WHERE import_id = :id AND row_number IN (2, 3) ORDER BY row_number",
                ['id' => $importId],
            )),
        );
    }

    public function testEveryGroupAboutATimeIsImportedWithoutATimeByOneAnswer(): void
    {
        // Three groups about a time, told apart by the cell's text: two rows of one text, and two texts of one row
        $streamed = $this->imported(more: 2);
        // (another file: the very same one again would be the first import)
        $plain = $this->imported(more: 3);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $timeless = fn (string $importId): array => array_map(array_values(...), $this->database->fetchAllAssociative(
            "SELECT kind, parsed ->> 'seconds' AS seconds, problems::text AS problems, answers ->> 'time' AS answer FROM data_import_row WHERE import_id = :id AND answers IS NOT NULL ORDER BY row_number",
            ['id' => $importId],
        ));
        $asEmptyTimeCell = ['result', null, null, '{"text": "no_time", "value": 0}'];

        $crawler = $this->browser->request('GET', '/en/import/' . $streamed . '/to-fix');
        $all = $crawler->filter('[data-testid="import-to-fix-all-no-time"]');
        self::assertSame(['3', '4'], [$all->attr('data-groups'), $all->attr('data-rows')]);
        self::assertStringContainsString('Rows whose time we could not read: 4.', $all->text());
        self::assertStringContainsString('Import all of them without a time (4)', $all->filter('[data-testid="import-to-fix-all-no-time-use"]')->text());
        $token = $this->token($crawler);

        // In place: the groups are listed anew under the confirmation - only the piece count is left
        $this->browser->request('POST', '/en/import/' . $streamed . '/to-fix', ['_token' => $token, 'page' => '1', 'action' => 'no_time_all'], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $this->browser->getResponse()->headers->get('Content-Type'));
        $answer = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="import-to-fix-all-no-time">', $answer);
        self::assertStringContainsString('Rows that will be imported without a time: 4', $answer);
        self::assertStringNotContainsString('data-testid="import-to-fix-all-no-time"', $answer);
        self::assertStringContainsString('<turbo-stream action="update" target="import-to-fix-groups">', $answer);
        self::assertSame(1, substr_count($answer, 'data-testid="import-to-fix-group"'));
        self::assertStringContainsString('data-code="pieces_unreadable"', $answer);
        self::assertStringContainsString('Groups still to answer: 1', $answer);
        // Every row whose time cell held a text is a result without a time - and no other row was touched
        self::assertSame([$asEmptyTimeCell, $asEmptyTimeCell, $asEmptyTimeCell, $asEmptyTimeCell], $timeless($streamed));

        // Without a script: back to the list, which says it
        $this->browser->request('POST', '/en/import/' . $plain . '/to-fix', ['_token' => $token, 'page' => '1', 'action' => 'no_time_all']);
        self::assertResponseRedirects('/en/import/' . $plain . '/to-fix?page=1');
        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('Rows that will be imported without a time: 5', $crawler->filter('body')->text());
        self::assertCount(0, $crawler->filter('[data-testid="import-to-fix-all-no-time"]'));
        self::assertCount(5, $timeless($plain));

        // Asked once more with no group about a time left: nothing is changed
        $this->browser->request('POST', '/en/import/' . $plain . '/to-fix', ['_token' => $token, 'page' => '1', 'action' => 'no_time_all']);
        self::assertResponseRedirects('/en/import/' . $plain . '/to-fix?page=1');
        self::assertStringContainsString('These rows changed meanwhile', $this->browser->followRedirect()->filter('body')->text());
        self::assertCount(5, $timeless($plain));
    }

    public function testAnswersOnRowsWeCouldNotReadAreGivenInPlaceForTurboStreams(): void
    {
        $importId = $this->imported();
        $base = '/en/import/' . $importId;
        $row5 = $this->rowId($importId, 5);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $stream = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];

        $crawler = $this->browser->request('GET', $base . '/to-fix');
        $groups = $crawler->filter('[data-testid="import-to-fix-group"]');
        $token = $this->token($crawler);
        [$timeKey, $piecesKey] = [(string) $groups->eq(0)->filter('input[name="group"]')->first()->attr('value'), (string) $groups->eq(1)->filter('input[name="group"]')->first()->attr('value')];

        // Everything an answer changes has its place on the page
        self::assertSame('2', $crawler->filter('#import-to-fix-count')->attr('data-total'));
        self::assertStringContainsString('Groups still to answer: 2', $crawler->filter('#import-to-fix-count')->text());
        self::assertCount(2, $crawler->filter('#import-to-fix-groups > [data-testid="import-to-fix-group"]'));
        self::assertSame('group-' . $timeKey, $groups->eq(0)->attr('id'));
        self::assertCount(1, $crawler->filter('#import-to-fix-left-out'));
        self::assertCount(1, $crawler->filter('#import-to-fix-pages'));
        // One group about a time: nothing to answer "for all of them"
        self::assertCount(1, $crawler->filter('#import-to-fix-all-no-time'));
        self::assertCount(0, $crawler->filter('[data-testid="import-to-fix-all-no-time"]'));

        // A value nobody can read: the group's card alone, with the reason and what was typed - a 422
        $this->browser->request('POST', $base . '/to-fix', ['_token' => $token, 'group' => $timeKey, 'page' => '1', 'action' => 'answer', 'answer' => 'about an hour'], [], $stream);
        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $this->browser->getResponse()->headers->get('Content-Type'));
        $refused = (string) $this->browser->getResponse()->getContent();
        self::assertSame(1, substr_count($refused, '<turbo-stream'));
        self::assertStringContainsString('<turbo-stream action="replace" target="group-' . $timeKey . '">', $refused);
        self::assertStringContainsString('We could not read this for sure', $refused);
        self::assertStringContainsString('value="about an hour"', $refused);
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND answers IS NOT NULL', ['id' => $importId]));

        // Answered: a short confirmation where the group stood, the count follows - nothing else of the page
        $this->browser->request('POST', $base . '/to-fix', ['_token' => $token, 'group' => $timeKey, 'page' => '1', 'action' => 'answer', 'answer' => '1:15:00'], [], $stream);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $this->browser->getResponse()->headers->get('Content-Type'));
        $answered = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="group-' . $timeKey . '">', $answered);
        self::assertStringContainsString('id="group-' . $timeKey . '"', $answered);
        self::assertStringContainsString('Rows fixed: 2', $answered);
        self::assertStringNotContainsString('data-testid="import-to-fix-group"', $answered);
        self::assertStringContainsString('<turbo-stream action="replace" target="import-to-fix-count">', $answered);
        self::assertStringContainsString('Groups still to answer: 1', $answered);
        self::assertStringContainsString('<turbo-stream action="replace" target="import-to-fix-left-out">', $answered);
        self::assertStringContainsString('<turbo-stream action="update" target="import-to-fix-pages">', $answered);
        self::assertStringContainsString('<turbo-stream action="replace" target="import-to-fix-all-no-time">', $answered);
        self::assertSame(['4500', '4500'], $this->database->fetchFirstColumn("SELECT parsed ->> 'seconds' FROM data_import_row WHERE import_id = :id AND row_number IN (2, 3) ORDER BY row_number", ['id' => $importId]));

        // "Don't import them": the rows not imported are listed anew, and nothing is left to answer
        $this->browser->request('POST', $base . '/to-fix', ['_token' => $token, 'group' => $piecesKey, 'page' => '1', 'action' => 'leave_out'], [], $stream);
        self::assertResponseIsSuccessful();
        $leftOut = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="group-' . $piecesKey . '">', $leftOut);
        self::assertStringContainsString('Rows not imported: 1', $leftOut);
        self::assertStringContainsString('id="left-out-row-' . $row5 . '"', $leftOut);
        self::assertStringContainsString('<span id="import-to-fix-left-out-title">Rows you chose not to import: 1</span>', $leftOut);
        self::assertStringContainsString('Nothing to do here.', $leftOut);
        self::assertSame('left_out', $this->database->fetchOne('SELECT state FROM data_import_row WHERE id = :id', ['id' => $row5]));

        // "Import after all": the row gives way to its confirmation, and its group is listed again
        $this->browser->request('POST', $base . '/to-fix', ['_token' => $token, 'row' => $row5, 'page' => '1', 'action' => 'take_in'], [], $stream);
        self::assertResponseIsSuccessful();
        $takenIn = (string) $this->browser->getResponse()->getContent();
        // It was the only row not imported: the block goes, its confirmation stays
        self::assertStringContainsString('<turbo-stream action="replace" target="import-to-fix-left-out">', $takenIn);
        self::assertStringNotContainsString('data-testid="import-to-fix-left-out"', $takenIn);
        self::assertStringContainsString('The row is put back.', $takenIn);
        self::assertStringContainsString('<turbo-stream action="update" target="import-to-fix-groups">', $takenIn);
        self::assertStringContainsString('id="group-' . $piecesKey . '"', $takenIn);
        self::assertStringContainsString('data-code="pieces_unreadable"', $takenIn);
        self::assertStringContainsString('Groups still to answer: 1', $takenIn);
        self::assertSame('waiting', $this->database->fetchOne('SELECT state FROM data_import_row WHERE id = :id', ['id' => $row5]));

        // Changed meanwhile (the group is gone): the list as it stands, by a redirect - also for a stream request
        $this->browser->request('POST', $base . '/to-fix', ['_token' => $token, 'group' => $timeKey, 'page' => '1', 'action' => 'answer', 'answer' => '1:15:00'], [], $stream);
        self::assertResponseRedirects($base . '/to-fix?page=1');
    }

    public function testRowsWithoutAValueNeverGetOneSharedAnswer(): void
    {
        $importId = $this->imported();
        // Two rows that share a problem without a value - as rows without a name do
        $this->database->executeStatement(
            "UPDATE data_import_row SET problems = '[{\"field\": \"puzzle_name\", \"code\": \"no_name\"}]'::jsonb WHERE import_id = :id AND row_number IN (2, 3)",
            ['id' => $importId],
        );
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/to-fix');
        $group = $crawler->filter('[data-testid="import-to-fix-group"][data-code="no_name"]');
        self::assertSame('2', $group->attr('data-rows'));
        // No shared answer: only "Leave these rows out" and a row to open
        self::assertCount(0, $group->filter('[data-testid="import-to-fix-answer"]'));
        self::assertCount(1, $group->filter('[data-testid="import-to-fix-not-answerable"]'));
        self::assertCount(1, $group->filter('[data-testid="import-to-fix-leave-out"]'));
        self::assertCount(1, $group->filter('[data-testid="import-to-fix-open-row"]'));
        $key = (string) $group->filter('input[name="group"]')->first()->attr('value');

        // Sent by hand: refused by the page ...
        $this->browser->request('POST', '/en/import/' . $importId . '/to-fix', ['_token' => $this->token($crawler), 'group' => $key, 'page' => '1', 'action' => 'answer', 'answer' => 'Zq5b One Name For All']);
        self::assertResponseStatusCodeSame(422);

        // ... and by the handler itself
        try {
            $this->bus->dispatch(new AnswerImportRows($importId, PlayerFixture::PLAYER_REGULAR, [$this->rowId($importId, 2), $this->rowId($importId, 3)], ['puzzle_name' => 'Zq5b One Name For All'], onlyWithProblem: $key));
            self::fail('A shared answer for rows without a value must be refused.');
        } catch (HandlerFailedException $refused) {
            $cause = $refused->getPrevious();
            self::assertInstanceOf(ImportRowAnswerRefused::class, $cause);
            self::assertSame(ImportRowAnswerRefused::NOT_ANSWERABLE, $cause->reason);
        }

        // An answer for another field than the group's is refused the same way
        $timeKey = ImportRowAnswers::problemKey('pieces', 'pieces_unreadable', 'lots');

        try {
            $this->bus->dispatch(new AnswerImportRows($importId, PlayerFixture::PLAYER_REGULAR, [$this->rowId($importId, 5)], ['puzzle_name' => 'Zq5b Renamed'], onlyWithProblem: $timeKey));
            self::fail('A group is answered in its own field only.');
        } catch (HandlerFailedException $refused) {
            self::assertInstanceOf(ImportRowAnswerRefused::class, $refused->getPrevious());
        }

        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND answers IS NOT NULL', ['id' => $importId]));
    }

    public function testAnAnsweredRowLooksItsBrandAndPiecesUpOnThePuzzleListByItsNameOnly(): void
    {
        $text = RawXlsx::inline(...);
        $number = static fn (int $value): string => RawXlsx::n((string) $value);
        $time = static fn (int $seconds): string => RawXlsx::n((string) ($seconds / 86400), 1);
        $date = static fn (string $day): string => RawXlsx::n((string) (intdiv((int) (new \DateTimeImmutable($day . ' UTC'))->format('U'), 86400) + 25569), 2);
        $importId = $this->imported(sheets: [
            'Puzzle List' => [
                1 => [$text('Puzzle'), $text('Brand'), $text('Pieces')],
                2 => [$text('Zq5b List Alpha'), $text('Ravensburger'), $number(500)],
                3 => [$text('Zq5b List Beta'), $text('Jumbo'), $number(1000)],
                4 => [$text('Zq5b List Gamma'), $text('Trefl'), $number(1500)],
                5 => [$text('Zq5b List Twice'), $text('Trefl'), $number(500)],
                6 => [$text('Zq5b List Twice'), $text('Jumbo'), $number(2000)],
            ],
            'Results' => [
                1 => [$text('Puzzle'), $text('Date'), $text('Time')],
                2 => [$text('Zq5b List Alpha'), $date('2026-01-10'), $time(2893)],
                3 => [$text('Zq5b List Beta'), $date('2026-01-11'), $time(2750)],
                4 => [$text('Zq5b List Gamma'), $date('2026-01-12'), $time(2411)],
                5 => [$text('Zq5b List Alpha'), $date('2026-01-13'), $time(2380)],
                6 => [$text('Zq5b List Gamma'), $date('2026-01-14'), $text('about an hour')],
            ],
        ]);
        $results = $this->database->fetchOne("SELECT id FROM data_import_sheet WHERE import_id = :id AND title = 'Results'", ['id' => $importId]);
        self::assertIsString($results);
        $rowId = $this->database->fetchOne('SELECT id FROM data_import_row WHERE sheet_id = :sheet AND row_number = 6', ['sheet' => $results]);
        self::assertIsString($rowId);
        // The results name their puzzle only: brand and pieces come from the list
        self::assertSame(['Trefl', '1500'], array_values((array) $this->database->fetchAssociative("SELECT parsed ->> 'brand' AS brand, parsed ->> 'pieces' AS pieces FROM data_import_row WHERE id = :id", ['id' => $rowId])));
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $url = '/en/import/' . $importId . '/rows/' . $rowId;
        $crawler = $this->browser->request('GET', $url);
        $token = $this->token($crawler);
        $save = function (array $answers) use ($url, $token): void {
            $stamp = (string) $this->browser->request('GET', $url)->filter('input[name="stamp"]')->first()->attr('value');
            $this->browser->request('POST', $url, ['_token' => $token, 'stamp' => $stamp, 'action' => 'save', 'answers' => $answers]);
            self::assertResponseRedirects();
        };

        // Only the time is answered: the list is still asked about the row's name
        $save(['time' => '1:02:03']);
        self::assertSame(['Trefl', '1500', '3723'], array_values((array) $this->database->fetchAssociative("SELECT parsed ->> 'brand' AS brand, parsed ->> 'pieces' AS pieces, parsed ->> 'seconds' AS seconds FROM data_import_row WHERE id = :id", ['id' => $rowId])));

        // The name is answered: the list's word on the answered name
        $save(['puzzle_name' => 'zq5b list  BETA']);
        self::assertSame(['Jumbo', '1000', null], array_values((array) $this->database->fetchAssociative("SELECT parsed ->> 'brand' AS brand, parsed ->> 'pieces' AS pieces, problems::text AS problems FROM data_import_row WHERE id = :id", ['id' => $rowId])));

        // A name the list has twice, differently, is still a question of the row - found although only that name was read
        $save(['puzzle_name' => 'Zq5b List Twice']);
        $problems = $this->database->fetchOne('SELECT problems::text FROM data_import_row WHERE id = :id', ['id' => $rowId]);
        self::assertIsString($problems);
        self::assertStringContainsString('lookup_ambiguous', $problems);
    }

    public function testRowsThatBecomeEqualByAnAnswerAreOneResultAndNumbered(): void
    {
        $importId = $this->imported();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        // Rows 2 and 3 differ in their name only once their time is the same
        $first = $this->rowId($importId, 2);
        $second = $this->rowId($importId, 3);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/rows/' . $second);
        $token = $this->token($crawler);
        $stamp = (string) $crawler->filter('input[name="stamp"]')->first()->attr('value');
        $this->browser->request('POST', '/en/import/' . $importId . '/rows/' . $second, ['_token' => $token, 'stamp' => $stamp, 'action' => 'save', 'answers' => ['puzzle_name' => 'Zq5b Fix Alpha', 'solved_on' => '2026-01-10', 'time' => '1:15:00']]);
        self::assertResponseRedirects();
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/rows/' . $first);
        $stamp = (string) $crawler->filter('input[name="stamp"]')->first()->attr('value');
        $this->browser->request('POST', '/en/import/' . $importId . '/rows/' . $first, ['_token' => $token, 'stamp' => $stamp, 'action' => 'save', 'answers' => ['time' => '1:15:00']]);
        self::assertResponseRedirects();

        $rows = $this->database->fetchAllAssociative("SELECT id, equal_key, source_key, parsed ->> 'occurrence' AS occurrence, same_as_row_id FROM data_import_row WHERE id IN (:first, :second) ORDER BY row_number", ['first' => $first, 'second' => $second]);
        self::assertSame($rows[0]['equal_key'], $rows[1]['equal_key']);
        self::assertNotSame($rows[0]['source_key'], $rows[1]['source_key']);
        self::assertEqualsCanonicalizing(['1', '2'], [$rows[0]['occurrence'], $rows[1]['occurrence']]);
        // One result unless the player says two: the second points at the first of them
        self::assertCount(1, array_filter($rows, static fn (array $row): bool => $row['same_as_row_id'] !== null));
    }

    public function testNoPuzzlesScreenCostsMoreStatementsForALargerImport(): void
    {
        $brand = $this->seedBrand('Zq5bbudget');
        $imports = [];

        foreach (['few' => 2, 'many' => 60] as $size => $puzzles) {
            // After a pairing the entity manager is cleared: the player is loaded for each import
            [$import, $sheet] = $this->seedImport($this->regularPlayer());

            for ($number = 1; $number <= $puzzles; $number++) {
                $name = sprintf('Zq5b Budget %s %02d', $size, $number);
                $this->seedCataloguePuzzle($name, 500, $this->pairingEntityManager()->getReference(Manufacturer::class, $brand->id));
                $this->seedRow($import, $sheet, $name, 500);
                $this->seedRow($import, $sheet, $name, 500);
            }

            $this->pair($import);
            $imports[$size] = $import->id->toString();
        }

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $budgets = [];

        foreach ($imports as $size => $importId) {
            $first = $this->importPuzzle($importId, sprintf('Zq5b Budget %s 01', $size));
            $budgets[$size] = $this->statements([
                'summary' => '/en/import/' . $importId . '/puzzles',
                'list' => '/en/import/' . $importId . '/puzzles/probably?page=1',
                'grid' => '/en/import/' . $importId . '/puzzles/probably',
                'chooser' => '/en/import/' . $importId . '/puzzle/' . $first['id'],
            ]);
        }

        self::assertSame($budgets['few'], $budgets['many'], 'the number of statements must not grow with puzzles or rows');

        // What each screen reads on top of what every signed-in page loads: the import, the overview, the live
        // line's progress and what is ready to be added (4 - docs/features/data-import/adding-screens.md "Ready to
        // add"); the first three and a page of cards (4); the first three, the two parts' counts and a screen of
        // tiles with their pictures (5); the import, the puzzle and its rows (3)
        $signedIn = $budgets['few']['summary'] - 4;
        self::assertLessThanOrEqual(8, $signedIn, 'what every signed-in page loads');
        self::assertSame(['summary' => $signedIn + 4, 'list' => $signedIn + 4, 'grid' => $signedIn + 5, 'chooser' => $signedIn + 3], $budgets['few']);
    }

    public function testToFixAndTheRowSheetCostTheSameStatementsForALargerSheet(): void
    {
        $imports = ['few' => $this->imported(), 'many' => $this->imported(more: 60)];
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $budgets = [];

        foreach ($imports as $size => $importId) {
            $budgets[$size] = $this->statements([
                'to-fix' => '/en/import/' . $importId . '/to-fix',
                'row' => '/en/import/' . $importId . '/rows/' . $this->rowId($importId, 2),
                'summary' => '/en/import/' . $importId . '/puzzles',
            ]);
        }

        // The larger sheet has a page full of groups (fifty of its sixty-two) - and costs the same
        self::assertCount(50, $this->browser->request('GET', '/en/import/' . $imports['many'] . '/to-fix')->filter('[data-testid="import-to-fix-group"]'));
        self::assertCount(1, $this->browser->getCrawler()->filter('[data-testid="import-pages-next"]'));
        self::assertSame($budgets['few'], $budgets['many'], 'the number of statements must not grow with rows or groups');

        // The import, a page of groups, the groups about a time ("Import all of them without a time") and the rows
        // left out (4); the row, the import and whether another row needs
        // a look - "Save and fix the next one" (3); the summary (3) and what is ready to be added (1 -
        // docs/features/data-import/adding-screens.md "Ready to add")
        $signedIn = $budgets['few']['summary'] - 4;
        self::assertSame(['to-fix' => $signedIn + 4, 'row' => $signedIn + 3, 'summary' => $signedIn + 4], $budgets['few']);
    }

    /**
     * @param array<string, string> $paths
     *
     * @return array<string, int> the statements of each page's second request
     */
    private function statements(array $paths): array
    {
        $counts = [];

        foreach ($paths as $name => $path) {
            $this->browser->request('GET', $path);
            $this->startCountingQueries($this->browser);
            $this->browser->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            $counts[$name] = $this->queryCount($this->browser);
        }

        return $counts;
    }

    /**
     * An import of the regular player with a puzzle in every group, paired: one found (name, pieces and brand), one
     * probably (the brand is missing), one to choose (two puzzles of the name), two not found - one of them named
     * like a secret puzzle. Beside them in the catalogue: the player's own unapproved puzzle and somebody else's.
     *
     * @return array{import: string, found: string, probably: string, hidden: string, ownUnapproved: string, othersUnapproved: string, chooseImportPuzzle: string, ean: string}
     */
    private function paired(): array
    {
        $player = $this->regularPlayer();
        $other = self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_WITH_STRIPE);
        $brand = $this->seedBrand('Zq5bbrand');
        $ean = self::ean('400555987654');

        $found = $this->seedCataloguePuzzle('Zq5b Found Harbour', 1000, $brand);
        $probably = $this->seedCataloguePuzzle('Zq5b Probably Meadow', 500, $brand, eans: [$ean]);
        $this->seedCataloguePuzzle('Zq5b Twin Lighthouse', 1000, $this->seedBrand('Zq5bfirst'));
        $this->seedCataloguePuzzle('Zq5b Twin Lighthouse', 1000, $this->seedBrand('Zq5bsecond'));
        $hidden = $this->seedCataloguePuzzle('Zq5b Secret Vault', 500, $brand, hideUntil: TestClock::now()->modify('+30 days'), hideImageUntil: TestClock::now()->modify('+30 days'));
        $ownUnapproved = $this->seedCataloguePuzzle('Zq5b Own Unapproved Garden', 750, $brand, approved: false, addedBy: $player);
        $othersUnapproved = $this->seedCataloguePuzzle('Zq5b Others Unapproved Garden', 750, $brand, approved: false, addedBy: $other);

        [$import, $sheet] = $this->seedImport($player);
        $this->seedRow($import, $sheet, 'Zq5b Found Harbour', 1000, 'Zq5bbrand');
        $this->seedRow($import, $sheet, 'Zq5b Found Harbour', 1000, 'Zq5bbrand');
        $this->seedRow($import, $sheet, 'Zq5b Probably Meadow', 500);
        $this->seedRow($import, $sheet, 'Zq5b Twin Lighthouse', 1000);
        $this->seedRow($import, $sheet, 'Zq5b Nothing Like This Qxv', 300);
        $this->seedRow($import, $sheet, 'Zq5b Secret Vault', 500);
        $this->pair($import);
        $importId = $import->id->toString();

        return [
            'import' => $importId,
            'found' => $found,
            'probably' => $probably,
            'hidden' => $hidden,
            'ownUnapproved' => $ownUnapproved,
            'othersUnapproved' => $othersUnapproved,
            'chooseImportPuzzle' => $this->importPuzzle($importId, 'Zq5b Twin Lighthouse')['id'],
            'ean' => $ean,
        ];
    }

    private function regularPlayer(): Player
    {
        return self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
    }

    /**
     * An import of the regular player read, recognised and parsed by the real handlers: one sheet of results in
     * which two rows have a time nobody can read (the same text), one a piece count nobody can read, and two are
     * plain. Every puzzle is invented.
     *
     * @param null|array<string, array<int, list<string>>> $sheets the workbook's sheets instead: title => row number => cells from column A
     * @param int $more rows on top that each have an unreadable time of their own (and six times as many plain rows) - for the statement budgets
     */
    private function imported(int $more = 0, null|array $sheets = null): string
    {
        $text = RawXlsx::inline(...);
        $number = static fn (int $value): string => RawXlsx::n((string) $value);
        $time = static fn (int $seconds): string => RawXlsx::n((string) ($seconds / 86400), 1);
        $date = static fn (string $day): string => RawXlsx::n((string) (intdiv((int) (new \DateTimeImmutable($day . ' UTC'))->format('U'), 86400) + 25569), 2);

        $rows = [
            1 => [$text('Puzzle'), $text('Pieces'), $text('Date'), $text('Time')],
            2 => [$text('Zq5b Fix Alpha'), $number(500), $date('2026-01-10'), $text('about an hour <i>')],
            3 => [$text('Zq5b Fix Beta'), $number(500), $date('2026-01-11'), $text('about an hour <i>')],
            4 => [$text('Zq5b Fix Gamma'), $number(500), $date('2026-01-12'), $time(2893)],
            5 => [$text('Zq5b Fix Delta'), $text('lots'), $date('2026-01-13'), $time(1811)],
            6 => [$text('Zq5b Fix Epsilon'), $number(1000), $date('2026-02-01'), $time(3007)],
        ];

        // Many plain rows (a column of mostly unreadable values would not be taken for times at all), then the rows
        // whose time nobody can read - a word without a digit: a number would be a question of the sheet
        for ($plain = 1; $plain <= $more * 6; $plain++) {
            $rows[6 + $plain] = [$text('Zq5b Fix Plain ' . $plain), $number(1000), $date('2026-03-01'), $time(2001 + 60 * $plain)];
        }

        for ($extra = 1; $extra <= $more; $extra++) {
            $rows[6 + $more * 6 + $extra] = [$text('Zq5b Fix More ' . $extra), $number(1000), $date('2026-03-02'), $text('somewhen ' . strtr(base_convert((string) $extra, 10, 26), '0123456789', 'qrstuvwxyz'))];
        }

        $cells = [];

        foreach ($rows as $rowNumber => $row) {
            foreach ($row as $index => $cell) {
                $cells[$rowNumber][['A', 'B', 'C', 'D'][$index]] = $cell;
            }
        }

        $workbook = (new RawXlsx())->styles(['[h]:mm:ss', 'yyyy-mm-dd']);

        foreach ($sheets ?? [] as $title => $sheetRows) {
            $sheetCells = [];

            foreach ($sheetRows as $rowNumber => $row) {
                foreach ($row as $index => $cell) {
                    $sheetCells[$rowNumber][['A', 'B', 'C', 'D'][$index]] = $cell;
                }
            }

            $workbook->sheet($title, RawXlsx::worksheet($sheetCells));
        }

        if ($sheets === null) {
            $workbook->sheet('Results', RawXlsx::worksheet($cells));
        }

        $path = tempnam(sys_get_temp_dir(), 'msp-import-test');
        assert($path !== false);
        $workbook->save($path);
        $content = (string) file_get_contents($path);
        unlink($path);

        $importId = Uuid::uuid7()->toString();
        $this->bus->dispatch(new StartImport($importId, PlayerFixture::PLAYER_REGULAR, 'Training.xlsx', strlen($content)));
        self::getContainer()->get(Filesystem::class)->write(ImportFileStorage::objectKey(PlayerFixture::PLAYER_REGULAR, $importId, DataImportFileKind::Xlsx), $content);
        $this->bus->dispatch(new ConfirmImportUploaded($importId, PlayerFixture::PLAYER_REGULAR));

        while (($messages = $this->queued()) !== []) {
            $this->queue()->reset();
            $this->handle($messages);
        }

        return $importId;
    }

    /**
     * @param list<object> $messages
     */
    private function handle(array $messages): void
    {
        foreach ($messages as $message) {
            $handler = match (true) {
                $message instanceof ReadImportFile => ReadImportFileHandler::class,
                $message instanceof ProposeImportMappings => ProposeImportMappingsHandler::class,
                $message instanceof ParseImportSheet => ParseImportSheetHandler::class,
                default => self::fail('Nothing handles ' . $message::class),
            };

            $entityManager = self::getContainer()->get(EntityManagerInterface::class);
            $entityManager->clear();
            $service = self::getContainer()->get($handler);
            self::assertIsCallable($service);
            $service($message);
            $entityManager->clear();
        }
    }

    /**
     * What waits on the queue - without the pairing's own messages: a test that wants the pairing runs pair().
     *
     * @return list<object>
     */
    private function queued(): array
    {
        return array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $this->queue()->getSent()),
            static fn (object $message): bool => $message instanceof BuildImportPuzzles === false && $message instanceof MatchImportPuzzlesFuzzy === false,
        ));
    }

    private function queue(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.imports');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function rowId(string $importId, int $rowNumber): string
    {
        $id = $this->database->fetchOne('SELECT id FROM data_import_row WHERE import_id = :id AND row_number = :row', ['id' => $importId, 'row' => $rowNumber]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * What "Yes, these are right" of a screen of the grid sends: import puzzle id => the version and the catalogue
     * puzzle its tile showed - and its tick, for a box that is ticked.
     *
     * @return array<string, array<string, string>>
     */
    private static function tickAllCards(Crawler $page): array
    {
        $cards = [];

        $page->filter('[data-testid="import-puzzle-tick-all"] input[name^="cards["]')->each(static function (Crawler $input) use (&$cards): void {
            if (preg_match('/^cards\[([^\]]+)\]\[(version|catalogue|tick)\]$/', (string) $input->attr('name'), $match) === 1 && ($match[2] !== 'tick' || $input->attr('checked') !== null)) {
                $cards[$match[1]][$match[2]] = (string) $input->attr('value');
            }
        });

        return $cards;
    }

    /**
     * A link to a row's editor: where it leads, and where it says it leads back to.
     *
     * @return array{string, null|string}
     */
    private static function linkAndReturn(string $href): array
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

        return [(string) parse_url($href, PHP_URL_PATH), is_string($query['return'] ?? null) ? $query['return'] : null];
    }

    private function token(Crawler $page): string
    {
        return (string) $page->filter('form[action*="/import/"] input[name="_token"]')->first()->attr('value');
    }

    private static function spaced(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
