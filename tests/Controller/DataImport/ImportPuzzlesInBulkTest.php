<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\AnswerImportPuzzlePile;
use SpeedPuzzling\Web\Message\DecideImportPuzzles;
use SpeedPuzzling\Web\MessageHandler\AnswerImportPuzzlePileHandler;
use SpeedPuzzling\Web\Query\GetImportPuzzleScreen;
use SpeedPuzzling\Web\Services\DataImport\ImportPuzzlePile;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\MessageHandler\DataImport\ImportAddingScenario;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Puzzles in bulk (docs/features/data-import/guided-flow.md §6, slice 3): one answer for a whole pile, the picture
 * grid of "Looks like a match", the chooser's rounds. What each test pins is a **rule of the server**: a wrong match
 * puts a real result on the wrong puzzle's public leaderboard, so nothing is matched without the player's tap on a
 * set the screen showed, a bulk answer never names a puzzle in its form, and a crafted form gets nothing through.
 * Every puzzle and every row here is made up (their names carry a marker no fixture puzzle has).
 */
final class ImportPuzzlesInBulkTest extends WebTestCase
{
    use QueryCountAssertions;
    use ImportAddingScenario;

    private const array LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->database = self::getContainer()->get(Connection::class);
    }

    // ---- "Not in our database": one answer for all (§6.3) ----

    public function testOneAnswerKeepsEveryPuzzleOfThePileThatWaitsNowAndNothingElse(): void
    {
        $player = $this->seedPairingPlayer('pile');
        $playerId = $player->id->toString();
        $importId = $this->importWithPiles($player, 'Zq9a');
        $otherImportId = $this->importWithPiles($player, 'Zq9b');
        $somebody = $this->seedPairingPlayer('pile-other');
        $theirImportId = $this->importWithPiles($somebody, 'Zq9c');
        TestingLogin::asPlayer($this->browser, $playerId);

        $page = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/not-found');
        self::assertResponseIsSuccessful();
        self::assertSame('3 puzzles are not in our database', self::spaced($page->filter('[data-testid="import-pile-title"]')->text()));
        self::assertSame('We looked by name, barcode and brand code and found nothing that fits.', self::spaced($page->filter('[data-testid="import-explain-not-in-database"]')->text()));

        // The safe answer is selected on the screen - and nothing is stored for it
        $form = $page->filter('[data-testid="import-pile-answer"]');
        self::assertNotNull($form->filter('[data-testid="import-pile-keep"]')->attr('checked'));
        self::assertNull($form->filter('[data-testid="import-pile-leave"]')->attr('checked'));
        self::assertStringContainsString('Keep them just for me', $form->text());
        self::assertStringContainsString('only you see', $form->text());
        self::assertSame('Continue', self::spaced($form->filter('[data-testid="import-pile-continue"]')->text()));
        self::assertNull($form->filter('[data-testid="import-pile-continue"]')->attr('disabled'));
        self::assertSame(0, $this->decidedByThePlayer($importId));

        // The form names the pile and the answer - never a puzzle
        self::assertEqualsCanonicalizing(['_token', 'pile', 'revision', 'choice', 'choice'], $form->filter('input')->each(static fn (Crawler $input): string => (string) $input->attr('name')));
        self::assertSame('all_in_pile', $form->filter('button[name="action"]')->attr('value'));
        // Folded below: the puzzles themselves, each with its way to the search, and what is not kept
        self::assertCount(3, $page->filter('[data-testid="import-pile-see"] [data-testid="import-puzzle-card"]'));
        self::assertStringContainsString('See the 3 - and search for any', $page->filter('[data-testid="import-pile-see"] summary')->text());
        self::assertStringContainsString('Notes and your own fields', $page->filter('[data-testid="import-pile-not-kept"]')->text());

        // One of them the player decides alone meanwhile ("Don't import it"): the answer for the pile leaves it be
        $alone = $this->importPuzzle($importId, 'Zq9a Nobody Knows Qxv 1');
        $token = $this->token($page);
        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', ['_token' => $token, 'action' => 'left_out', 'puzzle' => $alone['id'], 'version' => '0', 'group' => 'not-found', 'page' => '1']);
        self::assertResponseRedirects();

        $answer = ['_token' => $token, 'action' => 'all_in_pile', 'pile' => 'not-found', 'choice' => 'unlisted', 'revision' => (string) $form->filter('input[name="revision"]')->attr('value')];
        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', $answer);
        // Other piles still wait: back to the piles
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzles');
        $overview = $this->browser->followRedirect();
        self::assertStringContainsString('2 puzzles kept just for you.', $overview->filter('body')->text());

        self::assertSame(
            [
                'Zq9a Found Harbour' => ['catalogue', 'automatic'],
                'Zq9a Looks Like Meadow' => ['undecided', null],
                'Zq9a Nobody Knows Qxv 1' => ['left_out', 'player'],
                'Zq9a Nobody Knows Qxv 2' => ['unlisted', 'player'],
                'Zq9a Nobody Knows Qxv 3' => ['unlisted', 'player'],
                'Zq9a Twin Lighthouse' => ['undecided', null],
            ],
            $this->choices($importId),
        );
        // The player's other import, and somebody else's: not a puzzle of theirs is touched
        self::assertSame(0, $this->decidedByThePlayer($otherImportId));
        self::assertSame(0, $this->decidedByThePlayer($theirImportId));
        // Each decision is remembered for the next file, like one made by hand
        self::assertSame(3, $this->number('SELECT COUNT(*) FROM import_puzzle_decision WHERE player_id = :id', ['id' => $playerId]));

        // The overview: the pile is gone, and what was kept can be changed
        self::assertCount(0, $overview->filter('[data-testid="import-puzzle-group"][data-group="not-found"]'));
        self::assertSame('Kept just for you (2) - change', self::spaced($overview->filter('[data-testid="import-puzzles-kept-link"]')->text()));
        self::assertSame('/en/import/' . $importId . '/puzzles/not-found?page=1', $overview->filter('[data-testid="import-puzzles-kept-link"]')->attr('href'));
        self::assertSame('Not imported: 1', self::spaced($overview->filter('[data-testid="import-puzzles-not-imported"]')->text()));

        // Sent again (a second tap, another tab): nothing waits in the pile - nothing changes
        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', $answer);
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzles/not-found');
        $pile = $this->browser->followRedirect();
        self::assertStringContainsString('Nothing was changed.', $pile->filter('body')->text());
        self::assertCount(1, $pile->filter('[data-testid="import-pile-nothing-left"]'));
        self::assertCount(0, $pile->filter('[data-testid="import-pile-answer"]'));
        self::assertSame(3, $this->decidedByThePlayer($importId));

        // "Don't import them" in the other import: its three, nothing of the first
        $page = $this->browser->request('GET', '/en/import/' . $otherImportId . '/puzzles/not-found');
        $this->browser->request('POST', '/en/import/' . $otherImportId . '/puzzles/decide', [...$answer, 'choice' => 'left_out', 'revision' => (string) $page->filter('input[name="revision"]')->attr('value')]);
        self::assertResponseRedirects('/en/import/' . $otherImportId . '/puzzles');
        self::assertStringContainsString('3 puzzles will not be imported.', $this->browser->followRedirect()->filter('body')->text());
        self::assertSame(['left_out', 'left_out', 'left_out'], $this->database->fetchFirstColumn("SELECT choice FROM data_import_puzzle WHERE import_id = :id AND tier = 'none' ORDER BY name", ['id' => $otherImportId]));
        self::assertSame(['unlisted', 'unlisted'], $this->database->fetchFirstColumn("SELECT choice FROM data_import_puzzle WHERE import_id = :id AND choice = 'unlisted'", ['id' => $importId]));
    }

    public function testThePileAnswerIsRefusedForAnotherPlayerWithoutItsTokenOnAStaleScreenAndWhilePuzzlesAreLookedUp(): void
    {
        $player = $this->seedPairingPlayer('pile-guards');
        $importId = $this->importWithPiles($player, 'Zq9d');
        TestingLogin::asPlayer($this->browser, $player->id->toString());
        $page = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/not-found');
        $token = $this->token($page);
        $url = '/en/import/' . $importId . '/puzzles/decide';
        $answer = ['_token' => $token, 'action' => 'all_in_pile', 'pile' => 'not-found', 'choice' => 'unlisted', 'revision' => (string) $page->filter('input[name="revision"]')->attr('value')];

        // No token, a made-up token; a GET
        $this->browser->request('POST', $url, array_diff_key($answer, ['_token' => true]));
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('POST', $url, [...$answer, '_token' => 'made-up']);
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('GET', $url, $answer);
        self::assertResponseStatusCodeSame(405);

        // A pile that has no bulk answer
        foreach (['found', 'probably', 'remembered', 'everything', ''] as $pile) {
            $this->browser->request('POST', $url, [...$answer, 'pile' => $pile]);
            self::assertResponseStatusCodeSame(404, $pile);
        }

        // An answer the pile does not allow: the puzzles to pick one by one are only ever kept - and no answer at all
        foreach ([['pile' => 'choose', 'choice' => 'left_out'], ['choice' => 'catalogue'], ['choice' => 'undecided'], ['choice' => '']] as $crafted) {
            $this->browser->request('POST', $url, [...$answer, ...$crafted]);
            self::assertResponseRedirects();
            self::assertStringContainsString('Nothing was changed.', $this->browser->followRedirect()->filter('body')->text());
        }

        // The screen is stale: the import's sheets were read again since it was shown
        $this->browser->request('POST', $url, [...$answer, 'revision' => 'something-else|1:1']);
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzles/not-found');
        self::assertStringContainsString('This changed in the meantime - here is how it stands now. Nothing was saved.', $this->browser->followRedirect()->filter('body')->text());
        $this->browser->request('POST', $url, array_diff_key($answer, ['revision' => true]));
        self::assertResponseRedirects();

        // Puzzles are still looked up: the pile is not whole yet - the button waits, and the server does too
        $this->database->executeStatement("UPDATE data_import_puzzle SET tier = NULL WHERE import_id = :id AND name = 'Zq9d Twin Lighthouse'", ['id' => $importId]);
        $waiting = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/not-found');
        self::assertNotNull($waiting->filter('[data-testid="import-pile-continue"]')->attr('disabled'));
        self::assertSame('Still looking for 1 of them…', self::spaced($waiting->filter('[data-testid="import-pile-continue"]')->text()));
        $this->browser->request('POST', $url, [...$answer, 'revision' => (string) $waiting->filter('input[name="revision"]')->attr('value')]);
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzles/not-found');
        self::assertStringContainsString('We are still looking for some of your puzzles. Nothing was saved', $this->browser->followRedirect()->filter('body')->text());
        $this->database->executeStatement("UPDATE data_import_puzzle SET tier = 'choose' WHERE import_id = :id AND name = 'Zq9d Twin Lighthouse'", ['id' => $importId]);

        // ... and while the look-up itself runs
        $this->database->executeStatement('UPDATE data_import SET paired_at = NULL, pairing_alive_at = :now WHERE id = :id', ['id' => $importId, 'now' => TestClock::FROZEN_NOW]);
        $this->browser->request('POST', $url, $answer);
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzles/not-found');
        self::assertStringContainsString('We are still looking', $this->browser->followRedirect()->filter('body')->text());
        $this->database->executeStatement('UPDATE data_import SET paired_at = :now WHERE id = :id', ['id' => $importId, 'now' => TestClock::FROZEN_NOW]);

        // Somebody else: the import is not there for them - with their own valid token
        $somebody = $this->seedPairingPlayer('pile-guards-other');
        $theirs = $this->importWithPiles($somebody, 'Zq9e');
        TestingLogin::asPlayer($this->browser, $somebody->id->toString());
        $theirPage = $this->browser->request('GET', '/en/import/' . $theirs . '/puzzles/not-found');
        $this->browser->request('POST', $url, [...$answer, '_token' => $this->token($theirPage)]);
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/not-found');
        self::assertResponseStatusCodeSame(404);

        // Nothing of all that decided anything
        self::assertSame(0, $this->decidedByThePlayer($importId));
        self::assertSame(0, $this->decidedByThePlayer($theirs));

        // The same form, as it stands now, is taken - and when nothing else waits it leads on to the next step
        TestingLogin::asPlayer($this->browser, $player->id->toString());
        $this->database->executeStatement("DELETE FROM data_import_puzzle WHERE import_id = :id AND tier IN ('probably', 'choose')", ['id' => $importId]);
        $page = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/not-found');
        $this->browser->request('POST', $url, [...$answer, 'revision' => (string) $page->filter('input[name="revision"]')->attr('value')]);
        self::assertResponseRedirects('/en/import/' . $importId . '?after=puzzles');
        self::assertSame(3, $this->decidedByThePlayer($importId));
    }

    public function testAPuzzleDecidedBetweenTheReadAndTheLockIsSkippedAndCounted(): void
    {
        $player = $this->seedPairingPlayer('pile-race');
        $playerId = $player->id->toString();
        $importId = $this->importWithPiles($player, 'Zq9f');
        $raced = $this->importPuzzle($importId, 'Zq9f Nobody Knows Qxv 2');
        $database = $this->database;
        $bus = self::getContainer()->get(MessageBusInterface::class);

        // Another tab decides one puzzle of the pile after the pile was read and before its decisions are taken
        $racing = new class ($bus, static function () use ($database, $raced): void {
            $database->executeStatement("UPDATE data_import_puzzle SET choice = 'left_out', decided_how = 'player', decision_version = decision_version + 1 WHERE id = :id", ['id' => $raced['id']]);
        }) implements MessageBusInterface {
            public int $sent = 0;

            public function __construct(
                private readonly MessageBusInterface $bus,
                private readonly \Closure $meanwhile,
            ) {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($message instanceof DecideImportPuzzles && $this->sent++ === 0) {
                    ($this->meanwhile)();
                }

                return $this->bus->dispatch($message, $stamps);
            }
        };

        $pile = new ImportPuzzlePile(
            self::getContainer()->get(GetImportPuzzleScreen::class),
            $racing,
            self::getContainer()->get(EntityManagerInterface::class),
            self::getContainer()->get('import_puzzle_pile_cache'),
        );

        self::assertSame(['state' => ImportPuzzlePile::DONE, 'decided' => 2, 'skipped' => 1, 'waiting' => 0], $pile->answer($importId, $playerId, 'not-found', 'unlisted'));
        // The first message was refused whole; the second named the two that had not changed
        self::assertSame(2, $racing->sent);
        self::assertSame(
            ['Zq9f Nobody Knows Qxv 1' => 'unlisted', 'Zq9f Nobody Knows Qxv 2' => 'left_out', 'Zq9f Nobody Knows Qxv 3' => 'unlisted'],
            $this->database->fetchAllKeyValue("SELECT name, choice FROM data_import_puzzle WHERE import_id = :id AND tier = 'none' ORDER BY name", ['id' => $importId]),
        );

        // An answer the pile does not allow, a pile there is not, somebody else: nothing - or "not found"
        self::assertSame(ImportPuzzlePile::REFUSED, $pile->answer($importId, $playerId, 'choose', 'left_out')['state']);
        self::assertSame(ImportPuzzlePile::REFUSED, $pile->answer($importId, $playerId, 'found', 'unlisted')['state']);
        self::assertSame(['decided' => 0, 'skipped' => 0, 'after' => null], $pile->turn($importId, $playerId, 'choose', ImportPuzzleChoice::LeftOut, null));
        self::assertSame(['decided' => 0, 'skipped' => 0, 'after' => null], $pile->turn($importId, PlayerFixture::PLAYER_REGULAR, 'choose', ImportPuzzleChoice::Unlisted, null));
        self::assertSame('undecided', $this->importPuzzle($importId, 'Zq9f Twin Lighthouse')['choice']);
    }

    public function testALargePileGoesToTheWorkerInChunksAndThePageFollowsIt(): void
    {
        $player = $this->seedPairingPlayer('pile-large');
        $playerId = $player->id->toString();
        $importId = $this->importWithPiles($player, 'Zq9g');
        // 205 more puzzles nobody knows - the pile is 208
        $this->database->executeStatement(
            "INSERT INTO data_import_puzzle (id, import_id, puzzle_key, name, created_at, tier, rows_results) SELECT gen_random_uuid(), :id, 'zq9g-many-' || number, 'Zq9g Many Qxv ' || number, :now, 'none', 1 FROM generate_series(1, 205) number",
            ['id' => $importId, 'now' => TestClock::FROZEN_NOW],
        );
        TestingLogin::asPlayer($this->browser, $playerId);
        $queue = self::getContainer()->get('messenger.transport.imports');
        self::assertInstanceOf(InMemoryTransport::class, $queue);
        $queue->reset();

        $page = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/not-found');
        self::assertSame('208 puzzles are not in our database', self::spaced($page->filter('[data-testid="import-pile-title"]')->text()));
        // The folded list is a page of fifty whatever the pile's size
        self::assertCount(50, $page->filter('[data-testid="import-pile-see"] [data-testid="import-puzzle-card"]'));
        $answer = ['_token' => $this->token($page), 'action' => 'all_in_pile', 'pile' => 'not-found', 'choice' => 'unlisted', 'revision' => (string) $page->filter('input[name="revision"]')->attr('value')];

        // Too many for a request: nothing is decided in it - a job for the worker, and the page says so
        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', $answer);
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzles/not-found');
        // (the queue is looked at before the next request: every request empties the in-memory transport)
        $sent = array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $queue->getSent());
        self::assertEquals([new AnswerImportPuzzlePile($importId, $playerId, 'not-found', 'unlisted')], $sent);
        $first = $sent[0];
        self::assertInstanceOf(AnswerImportPuzzlePile::class, $first);
        $pile = $this->browser->followRedirect();
        self::assertStringContainsString('We are saving your answer for 208 puzzles. You can leave this page', $pile->filter('body')->text());
        self::assertSame(0, $this->decidedByThePlayer($importId));

        // While it runs: the line with how many are left, loaded again by itself - and no form to answer twice
        self::assertSame('Saving your answer… Puzzles left: 208', self::spaced($pile->filter('[data-testid="import-pile-working"] [role="status"]')->text()));
        self::assertCount(1, $pile->filter('turbo-frame#import-puzzle-list-live [data-import-pile-running]'));
        self::assertSame('import-puzzle-list-live', $pile->filter('[data-controller="data-import-pile"]')->attr('data-data-import-pile-frame-value'));
        self::assertCount(0, $pile->filter('[data-testid="import-pile-answer"]'));
        self::assertCount(0, $pile->filter('[data-import-flow-screen] .btn-primary'));

        // A second answer meanwhile (another tab's form): told it is still being saved - never two side by side
        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', [...$answer, 'choice' => 'left_out']);
        self::assertResponseRedirects('/en/import/' . $importId . '/puzzles/not-found');
        self::assertCount(0, $queue->getSent(), 'no second job');
        self::assertStringContainsString('Your earlier answer is still being saved. Nothing was changed.', $this->browser->followRedirect()->filter('body')->text());

        // The worker's first turn: a chunk - and the job sends itself again with where it got to
        $queue->reset();
        $handler = self::getContainer()->get(AnswerImportPuzzlePileHandler::class);
        $handler($first);
        self::assertSame(ImportPuzzlePile::CHUNK, $this->decidedByThePlayer($importId));
        $next = array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $queue->getSent());
        self::assertCount(1, $next);
        self::assertInstanceOf(AnswerImportPuzzlePile::class, $next[0]);
        self::assertNotNull($next[0]->after);
        $pile = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/not-found');
        self::assertSame('Saving your answer… Puzzles left: 8', self::spaced($pile->filter('[data-testid="import-pile-working"] [role="status"]')->text()));

        // The same turn delivered twice repeats nothing: what was decided no longer waits
        $queue->reset();
        $handler($first);
        self::assertSame(208, $this->decidedByThePlayer($importId));
        self::assertSame(208, $this->number("SELECT COUNT(*) FROM data_import_puzzle WHERE import_id = :id AND choice = 'unlisted' AND decision_version = 1", ['id' => $importId]));
        self::assertCount(0, $queue->getSent());

        // The second turn finds nothing left: the answer has ended, the page is itself again
        $handler($next[0]);
        self::assertSame(208, $this->decidedByThePlayer($importId));
        self::assertNull(self::getContainer()->get(ImportPuzzlePile::class)->running($importId));
        $pile = $this->browser->request('GET', '/en/import/' . $importId . '/puzzles/not-found');
        self::assertCount(0, $pile->filter('[data-testid="import-pile-working"]'));
        self::assertCount(0, $pile->filter('[data-controller="data-import-pile"]'));
        self::assertCount(1, $pile->filter('[data-testid="import-pile-nothing-left"]'));
        // The rest of the import was not touched
        self::assertSame('undecided', $this->importPuzzle($importId, 'Zq9g Twin Lighthouse')['choice']);

        // An import that is gone ends the job quietly
        $gone = new AnswerImportPuzzlePile('01900000-0000-7000-8000-000000000000', $playerId, 'not-found', 'unlisted');
        $handler($gone);
        self::assertCount(0, $queue->getSent());
    }

    // ---- "Looks like a match": the picture grid (§6.1) ----

    public function testTheGridShowsTwentyTilesAScreenOfPartAOnlyAndConfirmsExactlyTheTickedOnes(): void
    {
        $player = $this->seedPairingPlayer('grid');
        $importId = $this->importWithAGrid($player, 'Zq9h', 25);
        TestingLogin::asPlayer($this->browser, $player->id->toString());
        $base = '/en/import/' . $importId . '/puzzles/probably';

        $page = $this->browser->request('GET', $base);
        self::assertResponseIsSuccessful();
        $tiles = $page->filter('[data-testid="import-puzzle-tile"]');
        self::assertCount(20, $tiles);
        self::assertSame('1-20 of 25', self::spaced($page->filter('[data-testid="import-grid-position"]')->text()));
        self::assertSame('same', $page->filter('[data-testid="import-grid-position"]')->attr('data-part'));
        self::assertStringContainsString('Nothing is matched until you say so.', $page->filter('[data-testid="import-explain-looks-like"]')->text());

        // A tile: the database's picture and name, the file's own words under them - escaped, never in an address
        $first = $tiles->first();
        self::assertSame('Zq9h Grid 01 <b>', self::spaced($first->filter('[data-testid="import-puzzle-tile-name"]')->text()));
        self::assertSame('Your file: “Zq9h Grid 01 <b>” · Pieces: 500', self::spaced($first->filter('[data-testid="import-puzzle-tile-file"]')->text()));
        self::assertSame('Same name and pieces - the brand differs or is missing.', self::spaced($first->filter('[data-testid="import-puzzle-tile-why"]')->text()));
        self::assertStringNotContainsString('Grid 01 <b>', (string) $this->browser->getResponse()->getContent());
        self::assertSame([], array_filter($page->filter('a')->each(static fn (Crawler $link): string => (string) $link->attr('href')), static fn (string $href): bool => str_contains($href, 'Grid')));
        self::assertCount(1, $first->filter('img, [data-testid="import-puzzle-tile-no-picture"]'));
        self::assertSame('/en/import/' . $importId . '/puzzle/' . $first->attr('data-import-puzzle-id'), $first->filter('[data-testid="import-puzzle-tile-else"]')->attr('href'));

        // Every box is ticked ON THE SCREEN - and nothing of it is stored: the tick is the form's, not a decision
        self::assertCount(20, $page->filter('[data-testid="import-puzzle-tile-tick"][checked]'));
        self::assertSame(0, $this->decidedByThePlayer($importId));
        self::assertSame('Yes, the ticked ones are right', self::spaced($page->filter('[data-testid="import-puzzle-tick-all-button"]')->text()));
        // What the script puts on the button for each number of ticks - rendered here, in the page's language
        $labels = json_decode((string) $page->filter('[data-testid="import-puzzle-tick-all"]')->attr('data-data-import-ticks-labels-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($labels);
        self::assertCount(21, $labels);
        self::assertSame(['Nothing is ticked', 'Yes, this one is right', 'Yes, these 19 are right'], [$labels[0], $labels[1], $labels[19]]);

        // Part A only: no tile of a puzzle whose brand differs - those are confirmed one by one, on their own screen
        $names = $tiles->each(static fn (Crawler $tile): string => self::spaced($tile->filter('[data-testid="import-puzzle-tile-name"]')->text()));
        self::assertSame([], array_values(array_filter($names, static fn (string $name): bool => str_contains($name, 'Other Brand'))));
        self::assertCount(0, $page->filter('[data-testid="catalogue-puzzle-brand-differs"], [data-testid="catalogue-puzzle-pieces-differ"]'));

        // "Not sure - show me the next": saves nothing, goes on after the last tile
        $ids = $tiles->each(static fn (Crawler $tile): string => (string) $tile->attr('data-import-puzzle-id'));
        $next = $page->filter('[data-testid="import-grid-next"]');
        self::assertSame('Not sure - show me the next 5', self::spaced($next->text()));
        self::assertSame($base . '?part=same&after=' . $ids[19], $next->attr('href'));
        $second = $this->browser->request('GET', (string) $next->attr('href'));
        self::assertCount(5, $second->filter('[data-testid="import-puzzle-tile"]'));
        self::assertSame('21-25 of 25', self::spaced($second->filter('[data-testid="import-grid-position"]')->text()));
        self::assertSame('Not sure - leave these for later', self::spaced($second->filter('[data-testid="import-grid-next"]')->text()));
        self::assertSame(0, $this->decidedByThePlayer($importId));

        // The player unticks the third tile and confirms: exactly the nineteen that are still ticked
        $cards = self::tiles($page);
        unset($cards[$ids[2]]['tick']);
        $form = ['_token' => $this->token($page), 'action' => 'tick_all', 'group' => 'probably', 'part' => 'same', 'last' => $ids[19]];
        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', [...$form, 'cards' => $cards]);
        self::assertResponseRedirects($base . '?part=same&after=' . $ids[19]);
        $second = $this->browser->followRedirect();
        self::assertStringContainsString('19 matched.', $second->filter('body')->text());
        self::assertSame(19, $this->decidedByThePlayer($importId));
        self::assertSame('undecided', $this->database->fetchOne('SELECT choice FROM data_import_puzzle WHERE id = :id', ['id' => $ids[2]]));
        // Each of them is matched with the puzzle its tile showed
        self::assertSame(19, $this->number(
            "SELECT COUNT(*) FROM data_import_puzzle import_puzzle INNER JOIN puzzle ON puzzle.id = import_puzzle.puzzle_id WHERE import_puzzle.import_id = :id AND import_puzzle.choice = 'catalogue' AND import_puzzle.decided_how = 'player' AND puzzle.name = import_puzzle.name",
            ['id' => $importId],
        ));
        // The next screen: the five after it (the one left for later stands before them)
        self::assertCount(5, $second->filter('[data-testid="import-puzzle-tile"]'));
        self::assertSame('2-6 of 6', self::spaced($second->filter('[data-testid="import-grid-position"]')->text()));

        // A form with more tiles than a screen holds: a screen's worth is taken, never more
        $this->database->executeStatement("UPDATE data_import_puzzle SET choice = 'undecided', puzzle_id = NULL, decided_how = NULL WHERE import_id = :id", ['id' => $importId]);
        $versions = $this->database->fetchAllKeyValue("SELECT id, decision_version FROM data_import_puzzle WHERE import_id = :id AND name LIKE 'Zq9h Grid%' ORDER BY name", ['id' => $importId]);
        $everything = [];

        foreach (self::tiles($page) + self::tiles($this->browser->request('GET', $base . '?part=same&after=' . $ids[19])) as $id => $card) {
            self::assertIsNumeric($versions[$id] ?? null);
            $everything[$id] = [...$card, 'version' => (string) $versions[$id], 'tick' => '1'];
        }

        self::assertCount(25, $everything);
        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', [...$form, 'cards' => $everything]);
        self::assertResponseRedirects();
        self::assertSame(20, $this->number("SELECT COUNT(*) FROM data_import_puzzle WHERE import_id = :id AND choice = 'catalogue'", ['id' => $importId]));

        // Nothing ticked: nothing is sent, nothing changes
        $this->browser->request('POST', '/en/import/' . $importId . '/puzzles/decide', [...$form, 'cards' => array_map(static fn (array $card): array => array_diff_key($card, ['tick' => true]), $everything)]);
        self::assertResponseRedirects($base . '?part=same');
        self::assertStringContainsString('Nothing was changed.', $this->browser->followRedirect()->filter('body')->text());
        self::assertSame(20, $this->number("SELECT COUNT(*) FROM data_import_puzzle WHERE import_id = :id AND choice = 'catalogue'", ['id' => $importId]));
    }

    public function testWhatNeedsACloserLookIsConfirmedOneByOneAndACraftedFormGetsNothingThrough(): void
    {
        $player = $this->seedPairingPlayer('grid-guards');
        $importId = $this->importWithAGrid($player, 'Zq9i', 3);
        TestingLogin::asPlayer($this->browser, $player->id->toString());
        $base = '/en/import/' . $importId . '/puzzles/probably';
        $decide = '/en/import/' . $importId . '/puzzles/decide';
        $otherBrand = $this->importPuzzle($importId, 'Zq9i Other Brand 1');
        $twin = $this->importPuzzle($importId, 'Zq9i Twin Lighthouse');
        $unknown = $this->importPuzzle($importId, 'Zq9i Nobody Knows Qxv 1');

        $grid = $this->browser->request('GET', $base);
        $token = $this->token($grid);
        $cards = self::tiles($grid);
        self::assertCount(3, $cards);
        $form = ['_token' => $token, 'action' => 'tick_all', 'group' => 'probably', 'part' => 'same'];
        $crafted = self::crafted(...);

        // A crafted "these are right" that holds a puzzle of another brand, one to pick among two, one that is not
        // in the database - with the grid's own tiles, or alone: refused whole, nothing is written
        foreach (
            [
                'another brand' => $crafted($otherBrand, $otherBrand['candidates'][0]['puzzleId']),
                'several possible puzzles' => $crafted($twin, $twin['candidates'][0]['puzzleId']),
                'not in the database' => $crafted($unknown, $otherBrand['candidates'][0]['puzzleId']),
                'not the puzzle its tile showed' => [array_key_first($cards) => [...$cards[array_key_first($cards)], 'catalogue' => $otherBrand['candidates'][0]['puzzleId']]],
            ] as $what => $extra
        ) {
            foreach ([$extra + $cards, $extra] as $sent) {
                $this->browser->request('POST', $decide, [...$form, 'cards' => $sent]);
                self::assertResponseRedirects($base . '?part=same', message: $what);
                self::assertStringContainsString('needs a closer look and can only be confirmed on its own. Nothing was changed.', $this->browser->followRedirect()->filter('body')->text(), $what);
                self::assertSame(0, $this->decidedByThePlayer($importId), $what);
            }
        }

        // Another piece count: the file is corrected to 1,000 pieces after the look-up - its tile leaves the grid,
        // and a form that still holds it is refused
        $first = $this->importPuzzle($importId, 'Zq9i Grid 01 <b>');
        $this->database->executeStatement('UPDATE data_import_puzzle SET pieces_count = 1000 WHERE id = :id', ['id' => $first['id']]);
        self::assertCount(2, $this->browser->request('GET', $base)->filter('[data-testid="import-puzzle-tile"]'));
        $this->browser->request('POST', $decide, [...$form, 'cards' => $cards]);
        self::assertResponseRedirects($base . '?part=same');
        self::assertStringContainsString('Nothing was changed.', $this->browser->followRedirect()->filter('body')->text());
        self::assertSame(0, $this->decidedByThePlayer($importId));
        $this->database->executeStatement('UPDATE data_import_puzzle SET pieces_count = 500 WHERE id = :id', ['id' => $first['id']]);

        // A puzzle hidden since the look-up (a secret puzzle of an event): its tile is gone, its name is on no
        // screen, and a form that still holds it is refused
        $this->database->executeStatement('UPDATE puzzle SET name = :name, hide_until = :until WHERE id = :id', ['id' => $first['candidates'][0]['puzzleId'], 'name' => 'Zq9i Secret Name Vault', 'until' => TestClock::now()->modify('+30 days')->format('Y-m-d H:i:s')]);

        foreach ([$base, $base . '?part=differs', $base . '?page=1', '/en/import/' . $importId . '/puzzle/' . $first['id']] as $screen) {
            $this->browser->request('GET', $screen);
            self::assertResponseIsSuccessful($screen);
            self::assertStringNotContainsString('Secret Name Vault', (string) $this->browser->getResponse()->getContent(), $screen);
        }

        $this->browser->request('POST', $decide, [...$form, 'cards' => $cards]);
        self::assertResponseRedirects($base . '?part=same');
        self::assertStringContainsString('That puzzle can not be picked. Nothing was changed.', $this->browser->followRedirect()->filter('body')->text());
        self::assertSame(0, $this->decidedByThePlayer($importId));
        $this->database->executeStatement('UPDATE puzzle SET name = :name, hide_until = NULL WHERE id = :id', ['id' => $first['candidates'][0]['puzzleId'], 'name' => 'Zq9i Grid 01 <b>']);

        // The grid's end: nothing is left of part A after its last tile - what differs comes next, on its own screen
        $ids = array_keys($cards);
        $end = $this->browser->request('GET', $base . '?part=same&after=' . $ids[2]);
        self::assertCount(0, $end->filter('[data-testid="import-puzzle-tile"]'));
        self::assertSame('3 puzzles you left for later still wait. Look at them again', self::spaced($end->filter('[data-testid="import-grid-left-for-later"]')->text()));
        self::assertSame($base . '?part=same', $end->filter('[data-testid="import-grid-again"]')->attr('href'));
        self::assertSame('Have a closer look at 2 more', self::spaced($end->filter('[data-testid="import-grid-closer"]')->text()));
        self::assertSame($base . '?part=differs', $end->filter('[data-testid="import-grid-closer"]')->attr('href'));

        // Part B: cards, each with its amber line and a button of its own - no box, no button for several
        $closer = $this->browser->request('GET', $base . '?part=differs');
        self::assertSame('Have a closer look at these - something differs', self::spaced($closer->filter('[data-testid="import-grid-closer-title"]')->text()));
        self::assertSame('1-2 of 2', self::spaced($closer->filter('[data-testid="import-grid-position"]')->text()));
        self::assertCount(2, $closer->filter('[data-testid="import-puzzle-closer"] [data-testid="import-puzzle-card"]'));
        self::assertCount(2, $closer->filter('[data-testid="catalogue-puzzle-brand-differs"]'));
        self::assertCount(2, $closer->filter('[data-testid="import-puzzle-tick"]'));
        self::assertCount(0, $closer->filter('input[type="checkbox"]'));
        self::assertCount(0, $closer->filter('[data-testid="import-puzzle-tick-all"], [data-testid="import-puzzle-tick-all-button"], button[value="tick_all"]'));
        self::assertCount(0, $closer->filter('[data-testid="import-puzzle-tile"]'));
        self::assertStringContainsString('The name fits, but your file says another brand (or piece count).', $closer->filter('[data-testid="import-explain-another-brand"]')->text());

        // The player's own tap on one card takes that one puzzle - and leads back to this screen
        $this->browser->request('POST', $decide, ['_token' => $token, 'action' => 'catalogue', 'puzzle' => $otherBrand['id'], 'version' => '0', 'catalogue' => $otherBrand['candidates'][0]['puzzleId'], 'group' => 'probably', 'page' => '1', 'part' => 'differs']);
        self::assertResponseRedirects($base . '?part=differs');
        $closer = $this->browser->followRedirect();
        self::assertStringContainsString('Saved.', $closer->filter('body')->text());
        self::assertCount(1, $closer->filter('[data-testid="import-puzzle-closer"] [data-testid="import-puzzle-card"]'));
        self::assertSame(['catalogue', 'player'], [$this->importPuzzle($importId, 'Zq9i Other Brand 1')['choice'], $this->importPuzzle($importId, 'Zq9i Other Brand 1')['decided_how']]);
        self::assertSame(1, $this->decidedByThePlayer($importId));

        // The grid as it stands is taken: its three, in one message
        $grid = $this->browser->request('GET', $base);
        $this->browser->request('POST', $decide, [...$form, 'cards' => self::tiles($grid), 'last' => $ids[2]]);
        self::assertResponseRedirects($base . '?part=same&after=' . $ids[2]);
        self::assertStringContainsString('3 matched.', $this->browser->followRedirect()->filter('body')->text());
        self::assertSame(4, $this->decidedByThePlayer($importId));

        // Somebody else: neither the grid nor its answer
        $somebody = $this->seedPairingPlayer('grid-guards-other');
        TestingLogin::asPlayer($this->browser, $somebody->id->toString());
        $this->browser->request('GET', $base);
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', $base . '?part=differs');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheGridCostsTheSameStatementsForALargerPile(): void
    {
        $player = $this->seedPairingPlayer('grid-budget');
        $few = $this->importWithAGrid($player, 'Zq9j', 2);
        $many = $this->importWithAGrid($player, 'Zq9k', 45);
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        $budgets = [];

        foreach (['few' => $few, 'many' => $many] as $size => $importId) {
            foreach (['grid' => '/puzzles/probably', 'closer' => '/puzzles/probably?part=differs', 'pile' => '/puzzles/not-found', 'rest' => '/puzzles/choose?done=20', 'overview' => '/puzzles'] as $screen => $path) {
                $this->browser->request('GET', '/en/import/' . $importId . $path);
                $this->startCountingQueries($this->browser);
                $crawler = $this->browser->request('GET', '/en/import/' . $importId . $path);
                self::assertResponseIsSuccessful($path);
                $budgets[$size][$screen] = $this->queryCount($this->browser);

                if ($screen === 'grid') {
                    // A screen full of tiles - each with its picture and its puzzle - out of one statement
                    self::assertCount($size === 'many' ? 20 : 2, $crawler->filter('[data-testid="import-puzzle-tile"]'));
                }
            }
        }

        self::assertSame($budgets['few'], $budgets['many'], 'no screen of the piles may cost more statements for a larger pile');
        // On top of what every signed-in page loads (the overview reads four statements of its own): the import,
        // the overview, the live line's start - then the two parts' counts and a screen of tiles (grid, closer: 5);
        // a page of the folded list (pile: 4); the next round's rows, the folded list and what is ready (rest: 6)
        $signedIn = ($budgets['few']['overview'] ?? 0) - 4;
        self::assertSame(['grid' => $signedIn + 5, 'closer' => $signedIn + 5, 'pile' => $signedIn + 4, 'rest' => $signedIn + 6, 'overview' => $signedIn + 4], $budgets['few']);
    }

    // ---- "Pick the right one": rounds of one puzzle per screen (§6.2) ----

    public function testAChooserRoundGoesByThePuzzlesWithTheMostRowsAndPreselectsNothing(): void
    {
        $player = $this->seedPairingPlayer('round');
        [$import, $sheet] = $this->importOf($player);
        $editions = [];

        foreach (['Zq9m Twin Apple' => 1, 'Zq9m Twin Mango' => 2, 'Zq9m Twin Zebra' => 3] as $name => $rows) {
            $editions[$name] = [
                $this->seedCataloguePuzzle($name, 1000, $this->seedBrand('Zq9mone' . substr($name, -3))),
                $this->seedCataloguePuzzle($name, 1000, $this->seedBrand('Zq9mtwo' . substr($name, -3))),
            ];

            for ($row = 0; $row < $rows; $row++) {
                $this->seedRow($import, $sheet, $name, 1000);
            }
        }

        // Two results of a matched puzzle: ready to be added whatever is picked here
        $this->seedCataloguePuzzle('Zq9m Found Harbour', 500, $this->seedBrand('Zq9mbrand'));
        $this->resultRow($import, $sheet, 'Zq9m Found Harbour', 500, 'Zq9mbrand', '2023-01-01', 3000);
        $this->resultRow($import, $sheet, 'Zq9m Found Harbour', 500, 'Zq9mbrand', '2023-01-02', 3100);
        $importId = $this->settled($import);
        $apple = $this->importPuzzle($importId, 'Zq9m Twin Apple');
        $mango = $this->importPuzzle($importId, 'Zq9m Twin Mango');
        $zebra = $this->importPuzzle($importId, 'Zq9m Twin Zebra');
        TestingLogin::asPlayer($this->browser, $player->id->toString());
        $base = '/en/import/' . $importId;

        // Where a round starts: how many wait, how many rows the next round settles, ONE primary
        $start = $this->browser->request('GET', $base . '/puzzles/choose');
        self::assertSame('3 puzzles left in this pile.', self::spaced($start->filter('[data-testid="import-pile-left"]')->text()));
        self::assertSame('Rows of your file with the next 3 puzzles: 6', self::spaced($start->filter('[data-testid="import-pile-round"]')->text()));
        self::assertCount(0, $start->filter('[data-testid="import-pile-done"]'));
        self::assertSame('Do the next 20', self::spaced($start->filter('[data-testid="import-puzzle-next-20"]')->text()));
        self::assertStringContainsString('so we never pick for you', $start->filter('[data-testid="import-explain-pick"]')->text());

        // The round: the puzzle with the most rows first - not the first by name
        $this->browser->request('GET', $base . '/puzzles/choose/next');
        self::assertResponseRedirects($base . '/puzzle/' . $zebra['id'] . '?flow=choose&done=0');
        $chooser = $this->browser->followRedirect();
        self::assertSame('This round: 0 of 20', self::spaced($chooser->filter('[data-testid="import-chooser-portion"]')->text()));

        // Nothing is chosen for the player: the best fit only stands first - no card is marked, ticked or primary
        $cards = $chooser->filter('[data-testid="import-chooser-candidate"]');
        self::assertCount(2, $cards);
        self::assertSame($zebra['candidates'][0]['puzzleId'], $cards->first()->attr('data-puzzle-id'));
        self::assertCount(0, $chooser->filter('[data-import-flow-screen] .btn-primary'));
        self::assertCount(0, $chooser->filter('[data-import-flow-screen] [checked], [data-import-flow-screen] [selected], [data-import-flow-screen] [autofocus]'));
        self::assertCount(0, $cards->filter('.btn-outline-success, .text-success'));
        self::assertSame([$cards->first()->attr('class')], array_unique($cards->each(static fn (Crawler $card): null|string => $card->attr('class'))), 'every card looks the same');
        self::assertStringNotContainsString('This one', $cards->text());
        self::assertSame('undecided', $this->importPuzzle($importId, 'Zq9m Twin Zebra')['choice']);
        self::assertStringContainsString('so we never pick for you', $chooser->filter('[data-testid="import-explain-pick"]')->text());
        // "None of these" is folded, with the search, "Keep it just for me" and "Don't import it" in it
        $none = $chooser->filter('[data-testid="import-chooser-none"]');
        self::assertNull($none->attr('open'));
        self::assertCount(1, $none->filter('[data-testid="import-chooser-search-input"]'));
        self::assertCount(1, $none->filter('[data-testid="import-chooser-unlisted"]'));
        self::assertCount(1, $none->filter('[data-testid="import-chooser-leave-out"]'));

        // "Later": the next puzzle - nothing is saved
        $later = $chooser->filter('[data-testid="import-chooser-skip"]');
        self::assertSame('Later', self::spaced($later->text()));
        self::assertSame($base . '/puzzles/choose/next?after=' . $zebra['id'] . '&done=0', $later->attr('href'));
        self::assertCount(0, $chooser->filter('[data-testid="import-chooser-undo-last"]'));
        $this->browser->request('GET', (string) $later->attr('href'));
        self::assertResponseRedirects($base . '/puzzle/' . $mango['id'] . '?flow=choose&done=0');
        self::assertSame('undecided', $this->importPuzzle($importId, 'Zq9m Twin Zebra')['choice']);
        // ... and after the last one, what was left for later comes again
        $this->browser->request('GET', $base . '/puzzles/choose/next?after=' . $apple['id'] . '&done=0');
        self::assertResponseRedirects($base . '/puzzle/' . $zebra['id'] . '?flow=choose&done=0');

        // A tap on a card is the answer: saved, and on to the next - which offers to undo the last one
        $token = $this->token($chooser);
        $this->browser->request('POST', $base . '/puzzles/decide', ['_token' => $token, 'action' => 'catalogue', 'puzzle' => $zebra['id'], 'version' => '0', 'catalogue' => $editions['Zq9m Twin Zebra'][1], 'from' => 'chooser', 'group' => 'choose', 'flow' => 'choose', 'done' => '0']);
        self::assertResponseRedirects($base . '/puzzles/choose/next?after=' . $zebra['id'] . '&done=1&last=' . $zebra['id']);
        $this->browser->followRedirect();
        self::assertResponseRedirects($base . '/puzzle/' . $mango['id'] . '?flow=choose&done=1&last=' . $zebra['id']);
        $chooser = $this->browser->followRedirect();
        self::assertSame(['catalogue', $editions['Zq9m Twin Zebra'][1]], [$this->importPuzzle($importId, 'Zq9m Twin Zebra')['choice'], $this->importPuzzle($importId, 'Zq9m Twin Zebra')['puzzle_id']]);
        $undo = $chooser->filter('[data-testid="import-chooser-undo-last"]');
        self::assertSame('Undo the last one', self::spaced($undo->text()));
        self::assertSame($base . '/puzzle/' . $zebra['id'] . '?flow=choose&done=0', $undo->attr('href'));
        // "Later" keeps what can be undone
        self::assertSame($base . '/puzzles/choose/next?after=' . $mango['id'] . '&done=1&last=' . $zebra['id'], $chooser->filter('[data-testid="import-chooser-skip"]')->attr('href'));

        // "Undo the last one": its own screen says what was decided, and takes it back with its version
        $last = $this->browser->request('GET', (string) $undo->attr('href'));
        self::assertCount(1, $last->filter('[data-testid="import-chooser-decided"]'));
        $this->browser->request('POST', $base . '/puzzles/decide', ['_token' => $token, 'action' => 'undecided', 'puzzle' => $zebra['id'], 'version' => '1', 'from' => 'chooser', 'group' => 'choose', 'flow' => 'choose', 'done' => '0']);
        self::assertResponseRedirects($base . '/puzzle/' . $zebra['id'] . '?flow=choose&done=0');
        self::assertStringContainsString('Undone. The puzzle waits for you again.', $this->browser->followRedirect()->filter('body')->text());
        self::assertSame('undecided', $this->importPuzzle($importId, 'Zq9m Twin Zebra')['choice']);

        // "Stop here" leads to the pile's screen with how far the round is
        self::assertSame($base . '/puzzles/choose?done=1', $chooser->filter('[data-testid="import-chooser-back"]')->attr('href'));
        self::assertSame('Stop here - I\'ll come back', self::spaced($chooser->filter('[data-testid="import-chooser-back"]')->text()));

        // After twenty in a row: the rest screen - done, left, carry on, what is ready, stop - and nothing decided by it
        $this->browser->request('GET', $base . '/puzzles/choose/next?after=' . $mango['id'] . '&done=20&last=' . $mango['id']);
        self::assertResponseRedirects($base . '/puzzles/choose?done=20');
        $rest = $this->browser->followRedirect();
        self::assertSame('20 done.', self::spaced($rest->filter('[data-testid="import-pile-done"]')->text()));
        self::assertSame('3 puzzles left in this pile.', self::spaced($rest->filter('[data-testid="import-pile-left"]')->text()));
        self::assertSame('Do 3 more', self::spaced($rest->filter('[data-testid="import-puzzle-next-20"]')->text()));
        self::assertSame($base . '/puzzles/choose/next', $rest->filter('[data-testid="import-puzzle-next-20"]')->attr('href'));
        self::assertSame('2', $rest->filter('[data-testid="import-flow-add-ready"]')->attr('data-ready'));
        self::assertSame('Or add what is ready now (2) - you can come back to the rest any time.', self::spaced($rest->filter('[data-testid="import-flow-add-ready"]')->text()));
        self::assertSame($base . '/add', $rest->filter('[data-testid="import-flow-add-ready"] a')->attr('href'));
        self::assertSame('Stop here - I\'ll come back', self::spaced($rest->filter('[data-testid="import-flow-back-link"]')->text()));
        self::assertSame($base . '/puzzles', $rest->filter('[data-testid="import-flow-back-link"]')->attr('href'));
        self::assertCount(1, $rest->filter('[data-import-flow-screen] .btn-primary'));
        // A made-up number in the address is a number, no more
        self::assertSame('20', $this->browser->request('GET', $base . '/puzzles/choose?done=9999')->filter('[data-testid="import-pile-rest"]')->attr('data-done'));

        // The one bulk answer of this pile is folded, never a button beside "Do 3 more" - and it only ever KEEPS
        $keep = $rest->filter('[data-testid="import-pile-keep-rest"]');
        self::assertNull($keep->attr('open'));
        self::assertSame('Keep the other 3 just for me', self::spaced($keep->filter('summary')->text()));
        self::assertStringContainsString('Only you see it', $keep->filter('[data-testid="import-explain-unlisted"]')->text());
        self::assertStringNotContainsString('btn-primary', (string) $keep->filter('button')->attr('class'));
        self::assertEqualsCanonicalizing(['_token', 'pile', 'choice', 'revision'], $keep->filter('input')->each(static fn (Crawler $input): string => (string) $input->attr('name')));
        self::assertSame(['choose', 'unlisted'], [$keep->filter('input[name="pile"]')->attr('value'), $keep->filter('input[name="choice"]')->attr('value')]);

        // One of them is picked first; "keep the rest" then keeps the two that still wait - privately, matching nothing
        $this->browser->request('POST', $base . '/puzzles/decide', ['_token' => $token, 'action' => 'catalogue', 'puzzle' => $apple['id'], 'version' => '0', 'catalogue' => $editions['Zq9m Twin Apple'][0], 'from' => 'chooser', 'group' => 'choose']);
        self::assertResponseRedirects();
        $this->browser->request('POST', $base . '/puzzles/decide', ['_token' => $token, 'action' => 'all_in_pile', 'pile' => 'choose', 'choice' => 'unlisted', 'revision' => (string) $keep->filter('input[name="revision"]')->attr('value')]);
        // Nothing else waits: on to the next step
        self::assertResponseRedirects($base . '?after=puzzles');
        self::assertSame(
            ['Zq9m Found Harbour' => ['catalogue', 'automatic'], 'Zq9m Twin Apple' => ['catalogue', 'player'], 'Zq9m Twin Mango' => ['unlisted', 'player'], 'Zq9m Twin Zebra' => ['unlisted', 'player']],
            $this->choices($importId),
        );
        self::assertSame(0, $this->number("SELECT COUNT(*) FROM data_import_puzzle WHERE import_id = :id AND choice = 'unlisted' AND puzzle_id IS NOT NULL", ['id' => $importId]));
    }

    // ---- The words: six languages, every plural ----

    #[DataProvider('provideLocales')]
    public function testEveryScreenOfThePilesRendersInEveryLanguage(string $locale): void
    {
        $player = $this->seedPairingPlayer('words-' . $locale);
        $importId = $this->importWithAGrid($player, 'Zq9n' . $locale, 2);
        $twin = $this->importPuzzle($importId, 'Zq9n' . $locale . ' Twin Lighthouse');
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        foreach (['/puzzles', '/puzzles/probably', '/puzzles/probably?part=differs', '/puzzles/probably?page=1', '/puzzles/choose', '/puzzles/choose?done=20', '/puzzles/not-found', '/puzzles/found', '/puzzle/' . $twin['id'] . '?flow=choose&done=3&last=' . $twin['id']] as $path) {
            $crawler = $this->browser->request('GET', '/' . $locale . '/import/' . $importId . $path);
            self::assertResponseIsSuccessful($locale . $path);
            $screen = $crawler->filter('[data-import-flow-screen]');
            self::assertCount(1, $screen, $locale . $path);
            // No key left as it is, no plural form or placeholder on the page
            // (the live line carries its own texts for the script, with their placeholders)
            $html = (string) preg_replace('/ data-data-import-setup-[a-z-]+-value="[^"]*"/', '', $screen->html());
            self::assertDoesNotMatchRegularExpression('/data_import\.[a-z_.]+|%[a-z_]+%|\[0,Inf\[|\{1\}/', $html, $locale . $path);
        }
    }

    #[DataProvider('provideLocales')]
    public function testEveryTextOfThePilesHasItsPluralsAndIsTranslated(string $locale): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $english = $translator->getCatalogue('en')->all('messages');
        $own = $translator->getCatalogue($locale)->all('messages');
        $rendered = 0;

        foreach ($english as $key => $text) {
            $key = (string) $key;

            if (is_string($text) === false || str_starts_with($key, 'data_import.puzzles.bulk.') === false) {
                continue;
            }

            self::assertArrayHasKey($key, $own, $locale);

            if ($locale !== 'en') {
                self::assertNotSame($text, $own[$key], $locale . ': ' . $key . ' is an English copy');
            }

            foreach ([0, 1, 2, 3, 4, 5, 11, 20, 21, 22, 100, 1240] as $count) {
                $said = $translator->trans($key, ['%count%' => $count, '%number%' => (string) $count, '%rows%' => '143', '%from%' => '1', '%to%' => '20', '%total%' => '54', '%name%' => 'X'], 'messages', $locale);
                self::assertNotSame('', trim($said), $locale . ': ' . $key . ' for ' . $count);
                self::assertDoesNotMatchRegularExpression('/[|{}\[\]]|%[a-z_]+%/', $said, $locale . ': ' . $key . ' for ' . $count . ' left a plural form or a placeholder on the page');

                if (str_contains($text, '%number%') && $count !== 1) {
                    self::assertStringContainsString((string) $count, $said, $locale . ': ' . $key . ' must name the number ' . $count);
                }

                $rendered++;
            }
        }

        self::assertGreaterThan(600, $rendered);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLocales(): iterable
    {
        foreach (self::LOCALES as $locale) {
            yield $locale => [$locale];
        }
    }

    /**
     * An import with something in every pile: one puzzle matched by the rules, one that looks like a match (the
     * file names no brand), one to pick among two, three that nobody knows.
     */
    private function importWithPiles(Player $player, string $marker): string
    {
        $brand = $this->seedBrand($marker . 'brand');
        $this->seedCataloguePuzzle($marker . ' Found Harbour', 1000, $brand);
        $this->seedCataloguePuzzle($marker . ' Looks Like Meadow', 500, $this->brand($brand));
        $this->seedCataloguePuzzle($marker . ' Twin Lighthouse', 1000, $this->seedBrand($marker . 'first'));
        $this->seedCataloguePuzzle($marker . ' Twin Lighthouse', 1000, $this->seedBrand($marker . 'second'));

        [$import, $sheet] = $this->importOf($player);
        $this->seedRow($import, $sheet, $marker . ' Found Harbour', 1000, $marker . 'brand');
        $this->seedRow($import, $sheet, $marker . ' Looks Like Meadow', 500);
        $this->seedRow($import, $sheet, $marker . ' Twin Lighthouse', 1000);

        foreach ([1, 2, 3] as $number) {
            $this->seedRow($import, $sheet, $marker . ' Nobody Knows Qxv ' . $number, 300);
        }

        return $this->settled($import);
    }

    /**
     * An import whose "Looks like a match" holds `$same` puzzles that are the same puzzle with less proof (the same
     * name and piece count, the file names no brand) and two whose brand differs - and one to pick, one nobody knows.
     */
    private function importWithAGrid(Player $player, string $marker, int $same): string
    {
        $brand = $this->seedBrand($marker . 'brand');
        $this->seedBrand($marker . 'other');
        [$import, $sheet] = $this->importOf($player);

        for ($number = 1; $number <= $same; $number++) {
            $name = sprintf('%s Grid %02d <b>', $marker, $number);
            $this->seedCataloguePuzzle($name, 500, $this->brand($brand));
            $this->seedRow($import, $sheet, $name, 500);
        }

        foreach ([1, 2] as $number) {
            $this->seedCataloguePuzzle($marker . ' Other Brand ' . $number, 500, $this->brand($brand));
            $this->seedRow($import, $sheet, $marker . ' Other Brand ' . $number, 500, $marker . 'other');
        }

        $this->seedCataloguePuzzle($marker . ' Found Harbour', 1000, $this->brand($brand));
        $this->seedRow($import, $sheet, $marker . ' Found Harbour', 1000, $marker . 'brand');
        $this->seedCataloguePuzzle($marker . ' Twin Lighthouse', 1000, $this->seedBrand($marker . 'first'));
        $this->seedCataloguePuzzle($marker . ' Twin Lighthouse', 1000, $this->seedBrand($marker . 'second'));
        $this->seedRow($import, $sheet, $marker . ' Twin Lighthouse', 1000);
        $this->seedRow($import, $sheet, $marker . ' Nobody Knows Qxv 1', 500);

        return $this->settled($import);
    }

    /**
     * The import's puzzles looked up, and its sheet standing for one that is mapped and read: nothing works by
     * itself any more.
     */
    private function settled(DataImport $import): string
    {
        $this->pair($import);
        $importId = $import->id->toString();
        $this->database->executeStatement('UPDATE data_import_sheet SET parsed_version = 1, mapping_version = 1 WHERE import_id = :id', ['id' => $importId]);

        return $importId;
    }

    /**
     * The brand as the entity manager has it now (every look-up clears it).
     */
    private function brand(Manufacturer $brand): Manufacturer
    {
        $reference = $this->pairingEntityManager()->getReference(Manufacturer::class, $brand->id);
        self::assertNotNull($reference);

        return $reference;
    }

    /**
     * What "Yes, these are right" of a screen of the grid sends: import puzzle id => the version and the puzzle its
     * tile showed, and its tick for a box that is ticked.
     *
     * @return array<string, array<string, string>>
     */
    private static function tiles(Crawler $page): array
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
     * A tile nobody rendered: a puzzle of the import, ticked, with a catalogue puzzle of the sender's choosing.
     *
     * @param array{id: string, decision_version: int, ...} $puzzle
     *
     * @return array<string, array<string, string>>
     */
    private static function crafted(array $puzzle, string $catalogue): array
    {
        return [$puzzle['id'] => ['version' => (string) $puzzle['decision_version'], 'catalogue' => $catalogue, 'tick' => '1']];
    }

    /**
     * @return array<string, array{string, null|string}> name => [choice, decided how]
     */
    private function choices(string $importId): array
    {
        $choices = [];

        foreach ($this->database->fetchAllAssociative('SELECT name, choice, decided_how FROM data_import_puzzle WHERE import_id = :id ORDER BY name', ['id' => $importId]) as $row) {
            self::assertIsString($row['name']);
            self::assertIsString($row['choice']);
            $choices[$row['name']] = [$row['choice'], is_string($row['decided_how']) ? $row['decided_how'] : null];
        }

        return $choices;
    }

    private function decidedByThePlayer(string $importId): int
    {
        return $this->number("SELECT COUNT(*) FROM data_import_puzzle WHERE import_id = :id AND decided_how = 'player'", ['id' => $importId]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function number(string $sql, array $parameters): int
    {
        $count = $this->database->fetchOne($sql, $parameters);
        self::assertIsNumeric($count);

        return (int) $count;
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
