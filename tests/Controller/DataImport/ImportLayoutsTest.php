<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\BuildImportPuzzles;
use SpeedPuzzling\Web\Message\ConfirmImportMapping;
use SpeedPuzzling\Web\Message\ConfirmImportUploaded;
use SpeedPuzzling\Web\Message\MatchImportPuzzlesFuzzy;
use SpeedPuzzling\Web\Message\ParseImportSheet;
use SpeedPuzzling\Web\Message\ProposeImportMappings;
use SpeedPuzzling\Web\Message\ReadImportFile;
use SpeedPuzzling\Web\Message\StartImport;
use SpeedPuzzling\Web\MessageHandler\ParseImportSheetHandler;
use SpeedPuzzling\Web\MessageHandler\ProposeImportMappingsHandler;
use SpeedPuzzling\Web\MessageHandler\ReadImportFileHandler;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\Services\SpreadsheetReader\RawXlsx;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * docs/features/data-import/layouts.md - remembered layouts, the whole way: a confirmed sheet is saved as the
 * player's layout, the next import with the same headers is set up by itself and says so, the sheets of one workbook
 * follow the one that was confirmed (and never a sheet the player confirmed), "Start over", the list with Rename and
 * Forget, and another player's layouts are nobody else's. Every file here is made up; the imports are built by the
 * real handlers (the worker is played by work(), as in ImportColumnsScreenTest).
 */
final class ImportLayoutsTest extends WebTestCase
{
    private const array HEADERS = ['Puzzle', 'Pieces', 'Time', 'Mood'];

    private KernelBrowser $browser;
    private Connection $database;
    private MessageBusInterface $bus;
    /** BuildImportPuzzles messages on the queue when it was last looked at */
    private int $buildsSeen = 0;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->database = self::getContainer()->get(Connection::class);
        $this->bus = self::getContainer()->get(MessageBusInterface::class);
    }

    public function testAConfirmedSheetIsRememberedAndTheNextImportWithItsHeadersIsSetUpByItself(): void
    {
        $first = $this->imported('Training.xlsx', ['Log' => [self::HEADERS, ['Plum Orchard', 500, '45:10', 'happy'], ['Chalk Cliffs', 500, '52:31', 'tired']]]);
        $sheetId = $this->sheetId($first, 'Log');
        self::assertSame(0, $this->layouts());
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        // Nothing is remembered yet: no notice, no link
        $page = $this->browser->request('GET', '/en/import/' . $first . '/sheets/' . $sheetId);
        self::assertCount(1, $page->filter('[data-testid="import-question"]'));
        self::assertCount(0, $page->filter('[data-testid="import-sheet-remembered"]'));
        self::assertCount(0, $this->browser->request('GET', '/en/import/' . $first . '/columns')->filter('[data-testid="import-saved-layouts"]'));

        // The player's word: the times are minutes and seconds, "Mood" is the public comment
        $this->browser->request('POST', '/en/import/' . $first . '/sheets/' . $sheetId, [
            '_token' => $this->token($page),
            'version' => '1',
            'answers' => ['duration_shape.two_part_text' => 'minutes_seconds'],
            'columns' => ['4' => 'public_comment'],
        ]);
        self::assertResponseRedirects();
        $this->work();

        /** @var false|array{name: string, times_used: int, headers: string, content: string, changes_known_export: bool, player_id: string} $layout */
        $layout = $this->database->fetchAssociative('SELECT name, times_used, headers, content, changes_known_export, player_id FROM import_layout');
        self::assertIsArray($layout);
        self::assertSame(['Training.xlsx - Log', 0, false, PlayerFixture::PLAYER_REGULAR], [$layout['name'], $layout['times_used'], $layout['changes_known_export'], $layout['player_id']]);
        self::assertSame(['puzzle#1', 'pieces#1', 'time#1', 'mood#1'], json_decode($layout['headers'], true));
        /** @var array{columns: array<string, mixed>, durations: array<string, string>} $content */
        $content = json_decode($layout['content'], true);
        self::assertSame(['field' => 'public_comment', 'readyMade' => null], $content['columns']['mood#1']);
        self::assertSame(['two_part_text' => 'minutes_seconds'], $content['durations']);
        // Nothing of the file's rows, and no id of the import, is in it
        self::assertStringNotContainsString('Plum Orchard', $layout['content']);
        self::assertStringNotContainsString($sheetId, $layout['content']);

        // Confirmed again as it is (an answer the player repeats): still one layout
        $this->bus->dispatch(new ConfirmImportMapping($first, PlayerFixture::PLAYER_REGULAR, $sheetId, 2));
        $this->work();
        self::assertSame(1, $this->layouts());

        // Another file, the same header row - other rows, another sheet name
        $second = $this->imported('Autumn.xlsx', ['Evenings' => [self::HEADERS, ['Tea House', 500, '38:20', 'calm'], ['Marble Run', 500, '41:02', 'proud']]]);
        $next = $this->sheetId($second, 'Evenings');
        $mapping = $this->mapping($next);

        self::assertSame([], $mapping['questions']);
        self::assertSame('remembered', $mapping['remembered']['how']);
        self::assertSame('Training.xlsx - Log', $mapping['remembered']['name']);
        self::assertSame(['public_comment', 'certain', 'remembered'], [$mapping['columns'][3]['field'], $mapping['columns'][3]['certainty'], $mapping['columns'][3]['reason']]);
        self::assertSame(
            ['rows_understood' => 2, 'rows_need_look' => 0, 'rows_waiting' => 0, 'parsed_version' => 1, 'confirmed_by_player' => false],
            $this->database->fetchAssociative('SELECT rows_understood, rows_need_look, rows_waiting, parsed_version, confirmed_by_player FROM data_import_sheet WHERE id = :id', ['id' => $next]),
        );
        self::assertSame([2300, 'calm'], [
            $this->database->fetchOne("SELECT (parsed ->> 'seconds')::int FROM data_import_row WHERE sheet_id = :id AND row_number = 2", ['id' => $next]),
            $this->database->fetchOne("SELECT parsed ->> 'comment' FROM data_import_row WHERE sheet_id = :id AND row_number = 2", ['id' => $next]),
        ]);
        self::assertSame(1, $this->database->fetchOne('SELECT times_used FROM import_layout'));

        // The sheet says so, and offers to start over; so does its card; the list leads to the saved layouts
        $page = $this->browser->request('GET', '/en/import/' . $second . '/sheets/' . $next);
        self::assertResponseIsSuccessful();
        $notice = $page->filter('[data-testid="import-sheet-remembered"]');
        self::assertSame('remembered', $notice->attr('data-how'));
        self::assertStringContainsString('We used what you set up last time (Training.xlsx - Log) - change anything that is different.', self::spaced($notice->text()));
        self::assertCount(1, $notice->filter('[data-testid="import-sheet-start-over"]'));
        self::assertCount(0, $page->filter('[data-testid="import-question"]'));

        $list = $this->browser->request('GET', '/en/import/' . $second . '/columns');
        self::assertStringContainsString('We used what you set up last time (Training.xlsx - Log)', $list->filter('[data-testid="sheet-card-remembered"]')->text());
        $link = $list->filter('[data-testid="import-saved-layouts"]');
        self::assertSame('Your saved layouts (1)', self::spaced($link->text()));
        // Nothing of a header or a value is in an address
        self::assertSame('/en/import/layouts?return=/en/import/' . $second . '/columns', urldecode((string) $link->attr('href')));
    }

    public function testStartingOverProposesTheSheetWithoutTheLayoutAndKeepsTheLayout(): void
    {
        $this->remembered();
        $second = $this->imported('Autumn.xlsx', ['Evenings' => [self::HEADERS, ['Tea House', 500, '38:20', 'calm'], ['Marble Run', 500, '41:02', 'proud']]]);
        $sheetId = $this->sheetId($second, 'Evenings');
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $page = $this->browser->request('GET', '/en/import/' . $second . '/sheets/' . $sheetId);
        $form = $page->filter('form[action$="/start-over"]');
        self::assertCount(1, $form);

        // A stale page (another tab changed the sheet) changes nothing
        $this->browser->request('POST', '/en/import/' . $second . '/sheets/' . $sheetId . '/start-over', ['_token' => $form->filter('input[name="_token"]')->attr('value'), 'version' => '7']);
        self::assertResponseRedirects('/en/import/' . $second . '/sheets/' . $sheetId);
        self::assertSame(1, $this->database->fetchOne('SELECT mapping_version FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]));

        // Without the token: refused
        $this->browser->request('POST', '/en/import/' . $second . '/sheets/' . $sheetId . '/start-over', ['version' => '1']);
        self::assertResponseStatusCodeSame(403);

        $this->browser->request('POST', '/en/import/' . $second . '/sheets/' . $sheetId . '/start-over', ['_token' => $form->filter('input[name="_token"]')->attr('value'), 'version' => '1']);
        self::assertResponseRedirects('/en/import/' . $second . '/sheets/' . $sheetId);
        $this->work();

        $mapping = $this->mapping($sheetId);
        self::assertArrayNotHasKey('remembered', $mapping);
        self::assertCount(1, $mapping['questions']);
        self::assertNotSame('public_comment', $mapping['columns'][3]['field']);
        self::assertSame(
            ['mapping_version' => 2, 'parsed_version' => 2, 'confirmed_by_player' => true, 'rows_waiting' => 2],
            $this->database->fetchAssociative('SELECT mapping_version, parsed_version, confirmed_by_player, rows_waiting FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]),
        );

        $page = $this->browser->request('GET', '/en/import/' . $second . '/sheets/' . $sheetId);
        self::assertCount(0, $page->filter('[data-testid="import-sheet-remembered"]'));
        self::assertCount(1, $page->filter('[data-testid="import-question"]'));
        // The saved layout is as it was
        self::assertSame('public_comment', $this->database->fetchOne("SELECT content -> 'columns' -> 'mood#1' ->> 'field' FROM import_layout"));
    }

    public function testTheSheetsOfOneWorkbookFollowTheConfirmedOneButNeverASheetThePlayerConfirmed(): void
    {
        $importId = $this->imported('Years.xlsx', [
            '2021' => [self::HEADERS, ['Plum Orchard', 500, '45:10', 'happy'], ['Chalk Cliffs', 500, '52:31', 'tired']],
            '2022' => [self::HEADERS, ['Tea House', 500, '38:20', 'calm'], ['Marble Run', 500, '41:02', 'proud']],
            '2023' => [self::HEADERS, ['Glass Marbles', 500, '47:55', 'sleepy'], ['Circle of Kites', 500, '44:44', 'glad']],
            // Other headers: not that layout
            'Wishes' => [['Puzzle', 'Brand'], ['Night Harbour', 'Trefl']],
        ]);
        [$y2021, $y2022, $y2023, $wishes] = [$this->sheetId($importId, '2021'), $this->sheetId($importId, '2022'), $this->sheetId($importId, '2023'), $this->sheetId($importId, 'Wishes')];
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        foreach ([$y2021, $y2022, $y2023] as $sheetId) {
            self::assertCount(1, $this->mapping($sheetId)['questions']);
        }

        // One sheet is confirmed ...
        $page = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $y2021);
        $this->browser->request('POST', '/en/import/' . $importId . '/sheets/' . $y2021, [
            '_token' => $this->token($page),
            'version' => '1',
            'answers' => ['duration_shape.two_part_text' => 'minutes_seconds'],
            'columns' => ['4' => 'public_comment'],
        ]);
        $handled = $this->work();
        // ... and the two others follow at once: three sheets are read again, the fourth is left alone
        self::assertCount(3, $handled);
        // Each of those parses asked for the import's puzzles to be built again, as any parse does - nothing more
        self::assertSame(3, $this->buildsSeen);

        foreach ([$y2022, $y2023] as $sheetId) {
            $mapping = $this->mapping($sheetId);
            self::assertSame([], $mapping['questions']);
            self::assertSame(['remembered', 'Years.xlsx - 2021'], [$mapping['remembered']['how'], $mapping['remembered']['name']]);
            self::assertSame(['public_comment', 'remembered'], [$mapping['columns'][3]['field'], $mapping['columns'][3]['reason']]);
            self::assertSame(
                ['mapping_version' => 2, 'parsed_version' => 2, 'confirmed_by_player' => false, 'rows_understood' => 2, 'rows_waiting' => 0],
                $this->database->fetchAssociative('SELECT mapping_version, parsed_version, confirmed_by_player, rows_understood, rows_waiting FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]),
            );
        }

        self::assertSame(1, $this->database->fetchOne('SELECT mapping_version FROM data_import_sheet WHERE id = :id', ['id' => $wishes]));
        self::assertSame('calm', $this->database->fetchOne("SELECT parsed ->> 'comment' FROM data_import_row WHERE sheet_id = :id AND row_number = 2", ['id' => $y2022]));
        // Sharing inside a workbook is not "used again"
        self::assertSame([1, 0], [$this->layouts(), $this->database->fetchOne('SELECT times_used FROM import_layout')]);

        // The player now says on 2022: "Mood" is not imported after all. 2023 follows the newer word; 2021 - which
        // the player confirmed - keeps its own
        $page = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $y2022);
        self::assertCount(1, $page->filter('[data-testid="import-sheet-remembered"]'));
        $this->browser->request('POST', '/en/import/' . $importId . '/sheets/' . $y2022, ['_token' => $this->token($page), 'version' => '2', 'columns' => ['4' => '']]);
        self::assertResponseRedirects();
        self::assertCount(2, $this->work());

        self::assertSame(
            [$y2021 => ['public_comment', 2, true], $y2022 => [null, 3, true], $y2023 => [null, 3, false]],
            array_map(
                fn (string $sheetId): array => [
                    $this->mapping($sheetId)['columns'][3]['field'],
                    $this->database->fetchOne('SELECT mapping_version FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]),
                    $this->database->fetchOne('SELECT confirmed_by_player FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]),
                ],
                [$y2021 => $y2021, $y2022 => $y2022, $y2023 => $y2023],
            ),
        );
        self::assertSame(1, $this->layouts());
        self::assertNull($this->database->fetchOne("SELECT content -> 'columns' -> 'mood#1' ->> 'field' FROM import_layout"));
        // The answer about the times is still the layout's
        self::assertSame([], $this->mapping($y2023)['questions']);
    }

    public function testASheetWhoseOwnValuesPointAnotherWayIsAskedAgain(): void
    {
        // 2,000 pieces in 45 hours and 10 minutes: this player said "hours and minutes"
        $first = $this->imported('Big.xlsx', ['Log' => [self::HEADERS, ['Plum Orchard', 2000, '45:10', 'happy'], ['Chalk Cliffs', 2000, '52:31', 'tired']]]);
        $sheetId = $this->sheetId($first, 'Log');
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $page = $this->browser->request('GET', '/en/import/' . $first . '/sheets/' . $sheetId);
        $this->browser->request('POST', '/en/import/' . $first . '/sheets/' . $sheetId, ['_token' => $this->token($page), 'version' => '1', 'answers' => ['duration_shape.two_part_text' => 'hours_minutes']]);
        $this->work();
        self::assertSame('hours_minutes', $this->database->fetchOne("SELECT content -> 'durations' ->> 'two_part_text' FROM import_layout"));

        // The same headers over 500-piece puzzles: 45 hours each is not what these rows say - asked, not assumed
        $second = $this->imported('Small.xlsx', ['Log' => [self::HEADERS, ['Tea House', 500, '46:20', 'calm'], ['Marble Run', 500, '51:02', 'proud']]]);
        $mapping = $this->mapping($this->sheetId($second, 'Log'));
        self::assertSame('remembered', $mapping['remembered']['how']);
        self::assertCount(1, $mapping['questions']);
        self::assertSame('duration_shape.two_part_text', $mapping['questions'][0]['id']);
        self::assertSame(2, $this->database->fetchOne('SELECT rows_waiting FROM data_import_sheet WHERE import_id = :id', ['id' => $second]));

        // And over 2,000-piece puzzles again: answered as last time
        $third = $this->imported('Big again.xlsx', ['Log' => [self::HEADERS, ['Night Harbour', 2000, '39:15', 'calm'], ['Paper Boats', 2000, '48:02', 'proud']]]);
        self::assertSame([], $this->mapping($this->sheetId($third, 'Log'))['questions']);
        self::assertSame(141300, $this->database->fetchOne("SELECT (parsed ->> 'seconds')::int FROM data_import_row WHERE sheet_id = :id AND row_number = 2", ['id' => $this->sheetId($third, 'Log')]));
    }

    public function testAnotherPlayersLayoutsAreNeverAppliedListedRenamedOrForgotten(): void
    {
        $this->remembered();
        $layoutId = $this->database->fetchOne('SELECT id FROM import_layout');
        self::assertIsString($layoutId);

        // The same header row in another player's file: proposed like for anybody
        $theirs = $this->imported('Mine.xlsx', ['Log' => [self::HEADERS, ['Tea House', 500, '38:20', 'calm'], ['Marble Run', 500, '41:02', 'proud']]], PlayerFixture::PLAYER_PRIVATE);
        $mapping = $this->mapping($this->sheetId($theirs, 'Log'));
        self::assertArrayNotHasKey('remembered', $mapping);
        self::assertCount(1, $mapping['questions']);
        self::assertNotSame('public_comment', $mapping['columns'][3]['field']);
        self::assertSame(0, $this->database->fetchOne('SELECT times_used FROM import_layout'));

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_PRIVATE);
        $list = $this->browser->request('GET', '/en/import/layouts');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $list->filter('[data-testid="import-layout"]'));
        self::assertCount(1, $list->filter('[data-testid="import-layouts-empty"]'));
        self::assertCount(0, $this->browser->request('GET', '/en/import/' . $theirs . '/columns')->filter('[data-testid="import-saved-layouts"]'));

        // A token of their own does not open somebody else's layout
        $token = $this->token($this->browser->request('GET', '/en/import/' . $theirs . '/sheets/' . $this->sheetId($theirs, 'Log')));
        $this->browser->request('POST', '/en/import/layouts/' . $layoutId . '/rename', ['_token' => $token, 'name' => 'Taken']);
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('POST', '/en/import/layouts/' . $layoutId . '/forget', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('POST', '/en/import/layouts/' . Uuid::uuid7()->toString() . '/forget', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Training.xlsx - Log', $this->database->fetchOne('SELECT name FROM import_layout WHERE id = :id', ['id' => $layoutId]));
    }

    public function testTheListRenamesAndForgetsAndAForgottenLayoutIsProposedFromScratch(): void
    {
        $this->remembered();
        $layoutId = $this->database->fetchOne('SELECT id FROM import_layout');
        self::assertIsString($layoutId);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $list = $this->browser->request('GET', '/en/import/layouts');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', (string) $this->browser->getResponse()->getContent());
        $card = $list->filter('[data-testid="import-layout"]');
        self::assertCount(1, $card);
        self::assertSame('Training.xlsx - Log', trim($card->filter('[data-testid="import-layout-name"]')->text()));
        self::assertSame('Columns: 4 · Last used: October 14, 2026 · Used again: 0×', self::spaced($card->filter('[data-testid="import-layout-facts"]')->text()));
        $token = (string) $card->filter('input[name="_token"]')->first()->attr('value');

        // Without the token: refused
        $this->browser->request('POST', '/en/import/layouts/' . $layoutId . '/forget');
        self::assertResponseStatusCodeSame(403);

        // A name is a short line of text; an empty one is not taken
        $this->browser->request('POST', '/en/import/layouts/' . $layoutId . '/rename', ['_token' => $token, 'name' => '   ']);
        self::assertResponseRedirects('/en/import/layouts');
        self::assertSame('Training.xlsx - Log', $this->database->fetchOne('SELECT name FROM import_layout'));
        $this->browser->request('POST', '/en/import/layouts/' . $layoutId . '/rename', ['_token' => $token, 'name' => "  My  evening\n log <b> "]);
        self::assertResponseRedirects('/en/import/layouts');
        self::assertSame('My evening log <b>', $this->database->fetchOne('SELECT name FROM import_layout'));
        $list = $this->browser->followRedirect();
        self::assertSame('My evening log <b>', trim($list->filter('[data-testid="import-layout-name"]')->text()));

        // The next import says the new name
        $second = $this->imported('Autumn.xlsx', ['Evenings' => [self::HEADERS, ['Tea House', 500, '38:20', 'calm'], ['Marble Run', 500, '41:02', 'proud']]]);
        self::assertSame('My evening log <b>', $this->mapping($this->sheetId($second, 'Evenings'))['remembered']['name']);

        $this->browser->request('POST', '/en/import/layouts/' . $layoutId . '/forget', ['_token' => $token]);
        self::assertResponseRedirects('/en/import/layouts');
        self::assertSame(0, $this->layouts());
        self::assertCount(1, $this->browser->followRedirect()->filter('[data-testid="import-layouts-empty"]'));

        // Forgotten: the next sheet with these headers is proposed from scratch - and a sheet set up earlier stays
        $third = $this->imported('Winter.xlsx', ['Nights' => [self::HEADERS, ['Night Harbour', 500, '39:15', 'calm'], ['Paper Boats', 500, '48:02', 'proud']]]);
        $mapping = $this->mapping($this->sheetId($third, 'Nights'));
        self::assertArrayNotHasKey('remembered', $mapping);
        self::assertCount(1, $mapping['questions']);
        self::assertSame('public_comment', $this->mapping($this->sheetId($second, 'Evenings'))['columns'][3]['field']);
        $page = $this->browser->request('GET', '/en/import/' . $second . '/sheets/' . $this->sheetId($second, 'Evenings'));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-testid="import-sheet-remembered"]'));
    }

    /**
     * The regular player's first import, confirmed: the times are minutes and seconds, "Mood" is the public comment.
     */
    private function remembered(): void
    {
        $importId = $this->imported('Training.xlsx', ['Log' => [self::HEADERS, ['Plum Orchard', 500, '45:10', 'happy'], ['Chalk Cliffs', 500, '52:31', 'tired']]]);
        $sheetId = $this->sheetId($importId, 'Log');
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $page = $this->browser->request('GET', '/en/import/' . $importId . '/sheets/' . $sheetId);
        $this->browser->request('POST', '/en/import/' . $importId . '/sheets/' . $sheetId, [
            '_token' => $this->token($page),
            'version' => '1',
            'answers' => ['duration_shape.two_part_text' => 'minutes_seconds'],
            'columns' => ['4' => 'public_comment'],
        ]);
        self::assertResponseRedirects();
        $this->work();
        self::assertSame(1, $this->layouts());
    }

    private function layouts(): int
    {
        $count = $this->database->fetchOne('SELECT COUNT(*) FROM import_layout');
        self::assertIsInt($count);

        return $count;
    }

    /**
     * An import read, recognised and parsed by the real handlers. Every puzzle is invented.
     *
     * @param array<array-key, list<list<int|string>>> $sheets title => rows from row 1, cells from column A (a text or a number)
     */
    private function imported(string $fileName, array $sheets, string $playerId = PlayerFixture::PLAYER_REGULAR): string
    {
        $workbook = new RawXlsx();

        foreach ($sheets as $title => $rows) {
            $cells = [];

            foreach ($rows as $index => $row) {
                foreach ($row as $column => $value) {
                    $cells[$index + 1][['A', 'B', 'C', 'D', 'E'][$column]] = is_int($value) ? RawXlsx::n((string) $value) : RawXlsx::inline($value);
                }
            }

            $workbook->sheet((string) $title, RawXlsx::worksheet($cells));
        }

        $path = tempnam(sys_get_temp_dir(), 'msp-import-test');
        assert($path !== false);
        $workbook->save($path);
        $content = (string) file_get_contents($path);
        unlink($path);

        $importId = Uuid::uuid7()->toString();
        $this->bus->dispatch(new StartImport($importId, $playerId, $fileName, strlen($content)));
        self::getContainer()->get(Filesystem::class)->write(ImportFileStorage::objectKey($playerId, $importId, DataImportFileKind::Xlsx), $content);
        $this->bus->dispatch(new ConfirmImportUploaded($importId, $playerId));
        $this->work();

        return $importId;
    }

    /**
     * The worker: every queued message goes to its handler, and what those queue too - until nothing is left.
     *
     * @return list<object> the messages handled, in order
     */
    private function work(): array
    {
        $handled = [];

        while (($messages = $this->queued()) !== []) {
            $this->queue()->reset();

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

            $handled = [...$handled, ...$messages];
        }

        return $handled;
    }

    /**
     * @return list<object>
     */
    private function queued(): array
    {
        $sent = array_map(static fn ($envelope): object => $envelope->getMessage(), $this->queue()->getSent());
        $this->buildsSeen = count(array_filter($sent, static fn (object $message): bool => $message instanceof BuildImportPuzzles));

        // Without the pairing's own messages (BuildImportPuzzles after every parse, and the search it asks for):
        // those are ImportPuzzleMatcherTest's, and the worker of this test lets them go
        return array_values(array_filter(
            $sent,
            static fn (object $message): bool => $message instanceof BuildImportPuzzles === false && $message instanceof MatchImportPuzzlesFuzzy === false,
        ));
    }

    private function queue(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.imports');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function sheetId(string $importId, string $title): string
    {
        $id = $this->database->fetchOne('SELECT id FROM data_import_sheet WHERE import_id = :id AND title = :title', ['id' => $importId, 'title' => $title]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array{columns: list<array<string, mixed>>, questions: list<array<string, mixed>>, remembered: array{how: string, id: string, name: string}}
     */
    private function mapping(string $sheetId): array
    {
        $json = $this->database->fetchOne('SELECT mapping FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]);
        self::assertIsString($json);
        /** @var array{columns: list<array<string, mixed>>, questions: list<array<string, mixed>>, remembered: array{how: string, id: string, name: string}} $mapping */
        $mapping = json_decode($json, true);

        return $mapping;
    }

    private function token(Crawler $page): string
    {
        return (string) $page->filter('form[action*="/sheets/"] input[name="_token"]')->first()->attr('value');
    }

    private static function spaced(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
