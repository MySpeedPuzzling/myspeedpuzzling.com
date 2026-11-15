<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use Doctrine\DBAL\Connection;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\DataImport\ImportsHomeController;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportMappingChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\ImportUploadRefused;
use SpeedPuzzling\Web\Message\AnswerImportRows;
use SpeedPuzzling\Web\Message\AnswerImportRowsGroup;
use SpeedPuzzling\Web\Message\ConfirmImportMapping;
use SpeedPuzzling\Web\Message\ConfirmImportRows;
use SpeedPuzzling\Web\Message\ConfirmImportUploaded;
use SpeedPuzzling\Web\Message\CreateImportFromGoogleSheet;
use SpeedPuzzling\Web\Message\DecideImportPuzzles;
use SpeedPuzzling\Web\Message\FinishImport;
use SpeedPuzzling\Web\Message\GoOnWithImport;
use SpeedPuzzling\Web\Message\PruneDataImports;
use SpeedPuzzling\Web\Message\StartImport;
use SpeedPuzzling\Web\MessageHandler\AnswerImportRowsGroupHandler;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Services\DataImport\ImportSlots;
use SpeedPuzzling\Web\Tests\MessageHandler\DataImport\ImportAddingScenario;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImport\AddImportRowsOutcome;
use SpeedPuzzling\Web\Value\DataImport\ImportClosingOutcome;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleDecisionChange;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/features/data-import/upload.md "Finished": an import that added rows can never be discarded, so it must not
 * hold one of the player's three places for ever - it is finished by the player's "I'm done with this import", or by
 * the daily sweep once nothing of it is left to add; and it holds no place from the moment every row is settled.
 * Every puzzle and every row here is made up.
 */
final class ImportFinishTest extends WebTestCase
{
    use ImportAddingScenario;

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testThreeImportsThatAddedRowsNoLongerLockThePlayerOut(): void
    {
        $player = $this->seedPairingPlayer('slots');
        $playerId = $player->id->toString();
        $this->cataloguePuzzles('Zqslots');

        // Each added a result and has a row left whose puzzle waits for the player's choice: in progress
        $first = $this->importThatAdded($player, 'Zqslots', 'first.xlsx', leftover: 'undecided');
        $second = $this->importThatAdded($player, 'Zqslots', 'second.xlsx', leftover: 'undecided', day: '2024-02-02');
        $third = $this->importThatAdded($player, 'Zqslots', 'third.xlsx', leftover: 'undecided', day: '2024-02-03');

        self::assertSame([$first, $second, $third], array_map(static fn ($held): string => $held->importId, $this->slots()->heldBy($playerId)));
        // Every way an import starts asks the one rule
        self::assertSame('too_many', $this->startRefusal($playerId));
        self::assertSame('too_many', $this->googleRefusal($playerId));
        self::assertSame('too_many', $this->confirmRefusal($playerId));

        // The page says so before a file is picked, and names the way out: the imports, by their titles
        TestingLogin::asPlayer($this->browser, $playerId);
        $crawler = $this->browser->request('GET', '/en/import/new');
        self::assertResponseIsSuccessful();
        $taken = $crawler->filter('[data-testid="import-places-taken"]');
        self::assertStringContainsString('You have 3 imports in progress. Finish or discard one first.', self::spaced($taken->text()));
        self::assertSame(
            [['first.xlsx', '/en/import/' . $first], ['second.xlsx', '/en/import/' . $second], ['third.xlsx', '/en/import/' . $third]],
            $taken->filter('[data-testid="import-in-progress"]')->each(static fn ($link): array => [self::spaced($link->text()), $link->attr('href')]),
        );
        // What the page promises about Discard is true
        self::assertStringContainsString('Until you add something from it you can discard an import; afterwards you can take back what it added.', self::spaced($crawler->text()));
        self::assertStringNotContainsString('at any time', $crawler->text());

        // None of them can be discarded - the lock-out this is about
        $this->browser->request('POST', '/en/import/' . $first . '/discard', ['_token' => $this->token()]);
        self::assertResponseRedirects('/en/import/' . $first);
        self::assertSame('setting_up', $this->statusOf($first));

        // "I'm done with this import": a place is free
        $crawler = $this->browser->request('GET', '/en/import/' . $first);
        $button = $crawler->filter('[data-testid="import-finish"] [data-testid="import-finish-button"]');
        self::assertSame("I'm done with this import", self::spaced($button->text()));
        self::assertStringContainsString('What it added stays on your profile and can still be taken back', self::spaced($crawler->filter('[data-testid="import-finish"]')->text()));
        $this->browser->submit($button->form());
        self::assertResponseRedirects('/en/import/' . $first);
        self::assertSame('done', $this->statusOf($first));
        self::assertNull($this->startRefusal($playerId));

        $crawler = $this->browser->request('GET', '/en/import/new');
        self::assertCount(0, $crawler->filter('[data-testid="import-places-taken"]'));
    }

    public function testAnImportWhoseEveryRowIsSettledHoldsNoPlaceEvenBeforeItIsFinished(): void
    {
        $player = $this->seedPairingPlayer('settled');
        $playerId = $player->id->toString();
        $this->cataloguePuzzles('Zqsettled');

        $one = $this->importThatAdded($player, 'Zqsettled', 'one.xlsx');
        $two = $this->importThatAdded($player, 'Zqsettled', 'two.xlsx', day: '2024-02-02');
        $three = $this->importThatAdded($player, 'Zqsettled', 'three.xlsx', day: '2024-02-03');

        // All three added everything they had: none is finished yet, none holds a place
        self::assertSame(['setting_up', 'setting_up', 'setting_up'], array_map($this->statusOf(...), [$one, $two, $three]));
        self::assertSame([], $this->slots()->heldBy($playerId));
        self::assertNull($this->startRefusal($playerId));
        self::assertNull($this->googleRefusal($playerId));

        // A row that is ready, one that waits for a date, one that could not be added: each makes its import count
        $ready = $this->importThatAdded($player, 'Zqsettled', 'ready.xlsx', leftover: 'ready', day: '2024-02-04');
        $dateless = $this->importThatAdded($player, 'Zqsettled', 'dateless.xlsx', leftover: 'no_date', day: '2024-02-05');
        self::assertSame([$ready, $dateless], array_map(static fn ($held): string => $held->importId, $this->slots()->heldBy($playerId)));
        self::assertSame([true, false], [$this->slots()->standingOf($ready)?->hasReady, $this->slots()->standingOf($dateless)?->hasReady]);
        self::assertSame([true, true], [$this->slots()->standingOf($ready)?->hasWaiting, $this->slots()->standingOf($dateless)?->hasWaiting]);

        // Taking back what an import added makes its rows ready again: it is in progress once more
        $this->undo($one, $player, DataImportUndoScope::Import);
        self::assertSame([$one, $ready, $dateless], array_map(static fn ($held): string => $held->importId, $this->slots()->heldBy($playerId)));
        self::assertSame('too_many', $this->startRefusal($playerId));
    }

    public function testAFinishedImportIsReadOnlyKeepsUndoAndCanBeGoneOnWith(): void
    {
        $player = $this->seedPairingPlayer('finished');
        $playerId = $player->id->toString();
        $this->cataloguePuzzles('Zqfinished');
        $importId = $this->importThatAdded($player, 'Zqfinished', 'history.xlsx', leftover: 'ready');
        $rows = $this->database->fetchAllAssociative('SELECT id, sheet_id, state, import_puzzle_id FROM data_import_row WHERE import_id = :id ORDER BY row_number', ['id' => $importId]);
        self::assertSame(['added', 'waiting'], array_column($rows, 'state'));
        TestingLogin::asPlayer($this->browser, $playerId);

        // A wrong token, another player's import
        $this->browser->request('POST', '/en/import/' . $importId . '/done', ['_token' => 'not-the-token']);
        self::assertResponseStatusCodeSame(403);
        $somebodyElse = $this->importThatAdded($this->seedPairingPlayer('finished-other'), 'Zqfinished', 'theirs.xlsx', day: '2024-02-09');
        $this->browser->request('POST', '/en/import/' . $somebodyElse . '/done', ['_token' => $this->token()]);
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('POST', '/en/import/' . $somebodyElse . '/go-on', ['_token' => $this->token()]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['setting_up', 'setting_up'], [$this->statusOf($importId), $this->statusOf($somebodyElse)]);

        $this->browser->request('POST', '/en/import/' . $importId . '/done', ['_token' => $this->token()]);
        self::assertResponseRedirects('/en/import/' . $importId);
        self::assertSame('done', $this->statusOf($importId));
        self::assertSame(TestClock::now()->format('Y-m-d H:i:s'), $this->database->fetchOne('SELECT finished_at FROM data_import WHERE id = :id', ['id' => $importId]));
        // Sent again: the same answer, nothing changes
        self::assertSame(ImportClosingOutcome::Finished, $this->dispatched(new FinishImport($importId, $playerId)));

        // Its page: finished, what it added, the way to go on - and nothing to add or to finish
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        self::assertResponseIsSuccessful();
        self::assertSame('Finished', self::spaced($crawler->filter('[data-testid="import-status"]')->text()));
        self::assertStringContainsString('Added so far - results: 1', self::spaced($crawler->filter('[data-testid="import-finished-added"]')->text()));
        self::assertCount(1, $crawler->filter('[data-testid="import-go-on"]'));
        self::assertCount(1, $crawler->filter('[data-testid="import-undo-import"]'));
        self::assertCount(0, $crawler->filter('[data-testid="import-ready"]'));
        self::assertCount(0, $crawler->filter('[data-testid="import-finish"]'));
        self::assertCount(0, $crawler->filter('[data-testid="discard-import"]'));

        // Imports home: folded under "Finished", with what it added
        $crawler = $this->browser->request('GET', '/en/import');
        self::assertCount(0, $crawler->filter('[data-testid="data-imports-in-progress"]'));
        $group = $crawler->filter('details[data-testid="data-imports-finished"]');
        self::assertNull($group->attr('open'));
        self::assertSame('Finished (1)', self::spaced($group->filter('summary')->text()));
        self::assertStringContainsString('history.xlsx', $group->text());
        self::assertSame('1', $group->filter('[data-testid="data-import-added"]')->attr('data-results'));

        // The engine refuses a finished import the way it refuses any import that is not being set up
        self::assertSame(AddImportRowsOutcome::NOT_ADDABLE, $this->add($importId, $playerId)->status);
        /** @var array{id: string, sheet_id: string, import_puzzle_id: string} $waiting */
        $waiting = $rows[1];
        self::assertInstanceOf(DataImportNotFound::class, $this->refusalOf(new DecideImportPuzzles($importId, $playerId, [new ImportPuzzleDecisionChange($waiting['import_puzzle_id'], ImportPuzzleChoice::LeftOut, null, 1)])));
        self::assertInstanceOf(DataImportNotFound::class, $this->refusalOf(new AnswerImportRows($importId, $playerId, [$waiting['id']], leaveOut: true)));
        self::assertInstanceOf(DataImportNotFound::class, $this->refusalOf(new AnswerImportRowsGroup(Uuid::uuid7()->toString(), $importId, $playerId, AnswerImportRowsGroupHandler::CODES[0], 'leave_out')));
        self::assertInstanceOf(DataImportNotFound::class, $this->refusalOf(new ConfirmImportRows($importId, $playerId, [$waiting['id']], ['unusual_time'])));
        self::assertInstanceOf(ImportMappingChangedMeanwhile::class, $this->refusalOf(new ConfirmImportMapping($importId, $playerId, $waiting['sheet_id'], 1)));
        self::assertSame(['added', 'waiting'], $this->database->fetchFirstColumn('SELECT state FROM data_import_row WHERE import_id = :id ORDER BY row_number', ['id' => $importId]));
        // The review page of a finished import adds nothing either
        $this->browser->request('POST', '/en/import/' . $importId . '/add', ['_token' => $this->token(), 'request_id' => Uuid::uuid7()->toString()]);
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM data_import_batch WHERE import_id = :id', ['id' => $importId]));

        // Undo works as before - and taking back everything opens nothing by itself
        self::assertCount(1, $this->resultsOf($playerId));
        $undo = $this->undo($importId, $playerId, DataImportUndoScope::Import);
        self::assertSame([AddImportRowsOutcome::STARTED, 1], [$undo->status, $undo->rows]);
        self::assertSame([], $this->resultsOf($playerId));
        self::assertSame('done', $this->statusOf($importId));
        self::assertSame([], $this->slots()->heldBy($playerId));

        // "Go on with this import"
        $this->browser->request('POST', '/en/import/' . $importId . '/go-on', ['_token' => 'not-the-token']);
        self::assertResponseStatusCodeSame(403);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        $this->browser->submit($crawler->filter('[data-testid="import-go-on"]')->form());
        self::assertResponseRedirects('/en/import/' . $importId);
        self::assertSame(['setting_up', null], [$this->statusOf($importId), $this->database->fetchOne('SELECT finished_at FROM data_import WHERE id = :id', ['id' => $importId])]);
        // Sent again: it goes on already
        self::assertSame(ImportClosingOutcome::GoesOn, $this->dispatched(new GoOnWithImport($importId, $playerId)));

        // It is what it was: both rows can be added again
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        self::assertSame(['ready', '2'], [$crawler->filter('[data-testid="import-ready"]')->attr('data-state'), $crawler->filter('[data-testid="import-ready"]')->attr('data-rows')]);
        $again = $this->addAndWork($importId, $playerId);
        self::assertSame(2, $this->batchNow($again->id)['rows_added']);
    }

    public function testGoingOnNeedsAFreePlaceAndRowsThatAreStillThere(): void
    {
        $player = $this->seedPairingPlayer('go-on');
        $playerId = $player->id->toString();
        $this->cataloguePuzzles('Zqgoon');
        $finished = $this->importThatAdded($player, 'Zqgoon', 'old.xlsx', leftover: 'undecided');
        self::assertSame(ImportClosingOutcome::Finished, $this->dispatched(new FinishImport($finished, $playerId)));

        $held = [];

        foreach (['a', 'b', 'c'] as $number => $name) {
            $held[] = $this->importThatAdded($player, 'Zqgoon', $name . '.xlsx', leftover: 'undecided', day: '2024-03-0' . ($number + 1));
        }

        TestingLogin::asPlayer($this->browser, $playerId);
        $crawler = $this->browser->request('POST', '/en/import/' . $finished . '/go-on', ['_token' => $this->token()]);
        // A full-page form POST never answers 200: the page that names the imports in progress is a 422
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('You have 3 imports in progress. Finish or discard one first.', self::spaced($crawler->filter('[data-testid="import-places-taken"]')->text()));
        self::assertSame(
            array_map(static fn (string $id): string => '/en/import/' . $id, $held),
            $crawler->filter('[data-testid="import-in-progress"]')->each(static fn ($link): string => (string) $link->attr('href')),
        );
        self::assertSame('done', $this->statusOf($finished));

        self::assertSame(ImportClosingOutcome::Finished, $this->dispatched(new FinishImport($held[0], $playerId)));
        self::assertSame(ImportClosingOutcome::GoesOn, $this->dispatched(new GoOnWithImport($finished, $playerId)));
        self::assertSame('setting_up', $this->statusOf($finished));

        // Rows whose content is gone can not be gone on with - the page says so instead of offering the button
        $this->database->update('data_import', ['cells_wiped_at' => TestClock::sqlParameter()], ['id' => $held[0]]);
        self::assertSame(ImportClosingOutcome::CanNotGoOn, $this->dispatched(new GoOnWithImport($held[0], $playerId)));
        $crawler = $this->browser->request('GET', '/en/import/' . $held[0]);
        self::assertCount(0, $crawler->filter('[data-testid="import-go-on"]'));
        self::assertCount(1, $crawler->filter('[data-testid="import-can-not-go-on"]'));
    }

    public function testAnImportIsNotFinishedWhileItsAddIsRunning(): void
    {
        $player = $this->seedPairingPlayer('busy');
        $playerId = $player->id->toString();
        $this->cataloguePuzzles('Zqbusy');
        [$import, $sheet] = $this->importOf($player);
        $this->resultRow($import, $sheet, 'Zqbusy Harbour', 500, 'Zqbusy', '2024-01-01', 3600);
        $this->pair($import);
        $importId = $import->id->toString();
        $this->sheetsAreRead($importId);
        $started = $this->add($importId, $playerId);
        self::assertSame(AddImportRowsOutcome::STARTED, $started->status);

        self::assertSame(ImportClosingOutcome::Busy, $this->dispatched(new FinishImport($importId, $playerId)));
        self::assertSame('setting_up', $this->statusOf($importId));
        // ... and the daily sweep leaves it alone
        $this->restFor($importId, '-40 days');
        self::assertSame(0, $this->sweep());

        $this->work($started->id);
        self::assertSame(ImportClosingOutcome::Finished, $this->dispatched(new FinishImport($importId, $playerId)));

        // An import that is not read yet is not finished - it is discarded or tried again
        $reading = Uuid::uuid7()->toString();
        self::assertNull($this->startRefusal($playerId, $reading));
        self::assertSame(ImportClosingOutcome::NotFinishable, $this->dispatched(new FinishImport($reading, $playerId)));
    }

    public function testTheDailySweepFinishesWhatHasNothingLeftToAdd(): void
    {
        $player = $this->seedPairingPlayer('sweep');
        $other = $this->seedPairingPlayer('sweep-other');
        $this->cataloguePuzzles('Zqsweep');

        $settled = $this->importThatAdded($player, 'Zqsweep', 'settled.xlsx');
        $settledToday = $this->importThatAdded($player, 'Zqsweep', 'settled-today.xlsx', day: '2024-02-02');
        $waitingLong = $this->importThatAdded($player, 'Zqsweep', 'waiting-long.xlsx', leftover: 'undecided', day: '2024-02-03');
        $waitingShort = $this->importThatAdded($other, 'Zqsweep', 'waiting-short.xlsx', leftover: 'undecided', day: '2024-02-04');
        $ready = $this->importThatAdded($other, 'Zqsweep', 'ready.xlsx', leftover: 'ready', day: '2024-02-05');
        // Nothing added: it can be discarded, and is never finished by itself
        [$untouched] = $this->importOf($other);
        $this->sheetsAreRead($untouched->id->toString());

        $this->restFor($settled, '-2 days');
        $this->restFor($waitingLong, '-8 days');
        $this->restFor($waitingShort, '-6 days');
        $this->restFor($ready, '-40 days');
        $this->restFor($untouched->id->toString(), '-40 days');

        self::assertSame(2, $this->sweep());

        self::assertSame(
            ['done', 'setting_up', 'done', 'setting_up', 'setting_up', 'setting_up'],
            array_map($this->statusOf(...), [$settled, $settledToday, $waitingLong, $waitingShort, $ready, $untouched->id->toString()]),
        );
        self::assertSame(TestClock::now()->format('Y-m-d H:i:s'), $this->database->fetchOne('SELECT finished_at FROM data_import WHERE id = :id', ['id' => $settled]));
        // Nothing of a finished import is deleted, nothing on the profile changes
        self::assertSame(2, $this->pairingCount('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id', ['id' => $waitingLong]));
        self::assertCount(3, $this->resultsOf($player));

        // A second run finds nothing more
        self::assertSame(0, $this->sweep());
        // The player can go on with what the sweep finished
        self::assertSame(ImportClosingOutcome::GoesOn, $this->dispatched(new GoOnWithImport($waitingLong, $player->id->toString())));
    }

    public function testEveryLocaleHasTheTexts(): void
    {
        $keys = null;

        foreach (explode('|', ImportsHomeController::LOCALES) as $locale) {
            $messages = Yaml::parseFile(dirname(__DIR__, 3) . '/translations/messages.' . $locale . '.yml');
            self::assertIsArray($messages);
            $import = $messages['data_import'] ?? null;
            self::assertIsArray($import, $locale);
            self::assertIsArray($import['finish'] ?? null, $locale);
            self::assertIsArray($import['finish']['flash'] ?? null, $locale);
            $ofLocale = [...array_keys($import['finish']), ...array_map(static fn (string $key): string => 'flash.' . $key, array_keys($import['finish']['flash']))];
            sort($ofLocale);
            $keys ??= $ofLocale;
            self::assertSame($keys, $ofLocale, $locale);

            // Every outcome a page can say has its sentence
            foreach ([ImportClosingOutcome::Finished, ImportClosingOutcome::Busy, ImportClosingOutcome::NotFinishable, ImportClosingOutcome::GoesOn, ImportClosingOutcome::CanNotGoOn] as $outcome) {
                self::assertIsString($import['finish']['flash'][$outcome->value] ?? null, $locale . ' ' . $outcome->value);
            }

            self::assertIsArray($import['refused'] ?? null, $locale);
            self::assertIsArray($import['new'] ?? null, $locale);
            self::assertIsArray($import['status'] ?? null, $locale);
            self::assertStringContainsString('%limit%', self::text($import['refused']['too_many'] ?? null), $locale);
            self::assertStringContainsString('%days%', self::text($import['new']['kept_note'] ?? null), $locale);
            self::assertStringContainsString('%number%', self::text($import['finish']['group'] ?? null), $locale);
            self::assertIsString($import['status']['done'] ?? null, $locale);
        }

        self::assertContains('flash.finished', $keys);
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * Three puzzles of the brand, and a name two puzzles share - the pairing can not decide it, the player chooses.
     */
    private function cataloguePuzzles(string $brand): void
    {
        $manufacturer = $this->seedBrand($brand);

        foreach (['Harbour', 'Meadow', 'Orchard'] as $name) {
            $this->seedCataloguePuzzle($brand . ' ' . $name, 500, $manufacturer);
        }

        $this->seedCataloguePuzzle($brand . ' Twin', 500, $this->seedBrand($brand . 'one'));
        $this->seedCataloguePuzzle($brand . ' Twin', 500, $this->seedBrand($brand . 'two'));
    }

    /**
     * An import of the player that added one result - so it can never be discarded - and, when asked for, has one
     * more row: `undecided` (its puzzle waits for the player's choice), `no_date` (a result without a date), `ready`
     * (a result that could be added and was not).
     */
    private function importThatAdded(Player $player, string $brand, string $fileName, null|string $leftover = null, string $day = '2024-02-01'): string
    {
        [$import, $sheet] = $this->importOf($player);
        $importId = $import->id->toString();
        $this->database->update('data_import', ['file_name' => $fileName], ['id' => $importId]);
        $added = $this->resultRow($import, $sheet, $brand . ' Harbour', 500, $brand, $day, 3600);

        match ($leftover) {
            'undecided' => $this->resultRow($import, $sheet, $brand . ' Twin', 500, '', $day, 2000),
            'no_date' => $this->seedRow($import, $sheet, $brand . ' Meadow', 500, $brand, ['seconds' => 4000, 'type' => 'solo']),
            'ready' => $this->resultRow($import, $sheet, $brand . ' Orchard', 500, $brand, $day, 5000),
            default => null,
        };

        $this->pair($importId);
        $this->sheetsAreRead($importId);
        $outcome = $this->addAndWork($importId, $player, [$added]);
        self::assertSame(AddImportRowsOutcome::STARTED, $outcome->status);
        self::assertSame('added', $this->rowNow($added)['state']);

        return $importId;
    }

    private function sheetsAreRead(string $importId): void
    {
        $this->database->executeStatement('UPDATE data_import_sheet SET parsed_version = 1, mapping_version = 1 WHERE import_id = :id', ['id' => $importId]);
    }

    /**
     * Nothing happened to the import since - the test's clock stands still, so the past is written.
     */
    private function restFor(string $importId, string $since): void
    {
        $at = TestClock::now()->modify($since)->format('Y-m-d H:i:s');
        $this->database->update('data_import', ['last_activity_at' => $at], ['id' => $importId]);
        $this->database->update('data_import_batch', ['last_activity_at' => $at], ['import_id' => $importId]);
    }

    /**
     * The daily prune - how many imports it finished.
     */
    private function sweep(): int
    {
        $this->pairingEntityManager()->clear();
        $pruned = self::getContainer()->get(MessageBusInterface::class)->dispatch(new PruneDataImports())->last(HandledStamp::class)?->getResult();
        self::assertIsArray($pruned);
        self::assertIsInt($pruned['finished'] ?? null);
        $this->pairingEntityManager()->clear();

        return $pruned['finished'];
    }

    private function slots(): ImportSlots
    {
        return self::getContainer()->get(ImportSlots::class);
    }

    private function statusOf(string $importId): string
    {
        $status = $this->database->fetchOne('SELECT status FROM data_import WHERE id = :id', ['id' => $importId]);
        self::assertIsString($status);

        return $status;
    }

    private function token(): string
    {
        // The token the forms of the signed-in player's pages carry
        $crawler = $this->browser->request('GET', '/en/import/new');
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('form[data-controller="data-import-upload"]')->attr('data-data-import-upload-csrf-token-value');
    }

    private function dispatched(object $message): mixed
    {
        $this->pairingEntityManager()->clear();
        $result = self::getContainer()->get(MessageBusInterface::class)->dispatch($message)->last(HandledStamp::class)?->getResult();
        $this->pairingEntityManager()->clear();

        return $result;
    }

    /**
     * What the handler threw - null when it did not.
     */
    private function refusalOf(object $message): null|\Throwable
    {
        try {
            $this->dispatched($message);
        } catch (\Throwable $thrown) {
            $this->pairingEntityManager()->clear();

            // An exception that is an HTTP answer comes as it is, any other in the bus's wrapper
            return $thrown instanceof HandlerFailedException ? $thrown->getPrevious() : $thrown;
        }

        return null;
    }

    /**
     * A new upload of the player - why it was refused, null when it started. Only an upload with a given id stays.
     */
    private function startRefusal(string $playerId, null|string $importId = null): null|string
    {
        $id = $importId ?? Uuid::uuid7()->toString();
        $refusal = $this->refusalOf(new StartImport($id, $playerId, 'next.csv', 10));

        if ($importId === null) {
            $this->database->delete('data_import', ['id' => $id]);
        }

        return $refusal instanceof ImportUploadRefused ? $refusal->reason : ($refusal === null ? null : $refusal::class);
    }

    private function googleRefusal(string $playerId): null|string
    {
        $id = Uuid::uuid7()->toString();
        $refusal = $this->refusalOf(new CreateImportFromGoogleSheet($id, $playerId, '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdEFG'));
        $this->database->delete('data_import', ['id' => $id]);

        return $refusal instanceof ImportUploadRefused ? $refusal->reason : ($refusal === null ? null : $refusal::class);
    }

    /**
     * An upload started before the places were taken, confirmed now: counted once more.
     */
    private function confirmRefusal(string $playerId): null|string
    {
        $player = $this->pairingEntityManager()->find(Player::class, $playerId);
        self::assertNotNull($player);
        $id = Uuid::uuid7();
        $key = ImportFileStorage::objectKey($playerId, $id->toString(), DataImportFileKind::Csv);
        $this->pairingEntityManager()->persist(DataImport::uploadOfFile($id, $player, 'late.csv', DataImportFileKind::Csv, 4, $key, TestClock::now()));
        $this->pairingEntityManager()->flush();
        self::getContainer()->get(Filesystem::class)->write($key, 'a,b,');

        $refusal = $this->refusalOf(new ConfirmImportUploaded($id->toString(), $playerId));
        $this->database->delete('data_import', ['id' => $id->toString()]);

        return $refusal instanceof ImportUploadRefused ? $refusal->reason : ($refusal === null ? null : $refusal::class);
    }

    private static function spaced(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
