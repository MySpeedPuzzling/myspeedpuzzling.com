<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Message\FinishImport;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\MessageHandler\DataImport\ImportAddingScenario;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The guards of the import's guided flow (docs/features/data-import/guided-flow.md §10, slice 1): every screen of an
 * import carries the flow's header and exactly ONE primary button outside its list rows, "Continue" leads to the
 * next screen that has something waiting, "Where you are" costs what the import's page cost before, the words of
 * the engine are on no screen, and every text of the flow is there in all six languages with its plural forms.
 * Every puzzle and every row here is made up.
 */
final class ImportFlowScreensTest extends WebTestCase
{
    use QueryCountAssertions;
    use ImportAddingScenario;

    private const array LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];

    /** "Never on a screen" (guided-flow.md §8) - whole words, so "matching" or "pair results" are not caught */
    private const string ENGINE_WORDS = '/\b(pairing|paired|tiers?|candidates?|mappings?|layouts?|batch(es)?|pars(e|ed|ing)|row state|twins?|errors?|invalid)\b/i';

    /** Screens that are not a step of one import: no flow header */
    private const array NOT_A_SCREEN_OF_AN_IMPORT = ['index.html.twig', 'new.html.twig', 'layouts.html.twig', 'places_taken.html.twig', 'emails_unsubscribe.html.twig'];

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testEveryScreenOfAnImportHasTheFlowHeaderAndOnePrimaryButton(): void
    {
        [$player, $importId, $rowIds] = $this->importInTheMiddleOfItsFlow('screens');
        $playerId = $player->id->toString();
        TestingLogin::asPlayer($this->browser, $playerId);
        $base = '/en/import/' . $importId;
        $sheetId = $this->database->fetchOne('SELECT id FROM data_import_sheet WHERE import_id = :id', ['id' => $importId]);
        self::assertIsString($sheetId);
        $twin = $this->importPuzzle($importId, 'Zqscreens Double');

        // ---- Before anything is added: each screen, the step it belongs to, its primary buttons ----
        $this->assertScreen($base, null, 1, 'Match your puzzles');
        $this->assertScreen($base . '/columns', 2, 1, 'Looks right - continue');
        $this->assertScreen($base . '/sheets/' . $sheetId, 2, 1);
        $this->assertScreen($base . '/sheets/' . $sheetId . '/need-a-look', 2, 1, 'Answer many rows at once');
        $this->assertScreen($base . '/to-fix', 2, 1, 'Continue');
        $this->assertScreen($base . '/rows/' . $rowIds[0], 2, 1);
        $this->assertScreen($base . '/people', 3, 1, 'Continue');
        $this->assertScreen($base . '/puzzles', 4, 1, 'Start with “Looks like a match”');
        $this->assertScreen($base . '/puzzles/found', 4, 1, 'Continue');
        // The piles (slice 3): a screen of tiles confirmed with one tap, a round of one puzzle per screen, one answer
        // for all - and each pile's list by its page
        $this->assertScreen($base . '/puzzles/probably', 4, 1, 'Yes, the ticked ones are right');
        $this->assertScreen($base . '/puzzles/probably?part=differs', 4, 1, 'Continue');
        $this->assertScreen($base . '/puzzles/probably?page=1', 4, 1, 'Continue');
        $this->assertScreen($base . '/puzzles/choose', 4, 1, 'Do the next 20');
        $this->assertScreen($base . '/puzzles/choose?done=20', 4, 1, 'Do 1 more');
        $this->assertScreen($base . '/puzzles/choose?page=1', 4, 1, 'Continue');
        $this->assertScreen($base . '/puzzles/not-found', 4, 1, 'Continue');
        $this->assertScreen($base . '/puzzles/not-found?page=1', 4, 1, 'Continue');
        $this->assertScreen($base . '/puzzles/remembered', 4, 1, 'Continue');
        // A tap on a puzzle's card is the answer of the chooser: it has no primary button of its own
        $this->assertScreen($base . '/puzzle/' . $twin['id'], 4, 0);
        $this->assertScreen($base . '/add', 5, 1, 'Add 3 to my profile');
        $this->assertScreen($base . '/add/list', 5, 1, 'Done - back to the check');
        $this->assertScreen($base . '/add/times', 5, 1, 'Back to “Check and add”');
        $this->assertScreen($base . '/add/already-on', 5, 1, 'Back to “Check and add”');

        // ---- An Add ran: the screens after it ----
        $batchId = (string) $this->addAndWork($importId, $player)->id;
        $this->assertScreen($base . '/batches/' . $batchId, 5, 1, 'See them on my profile');
        $this->assertScreen($base . '/added', 5, 1, 'Continue');
        $this->assertScreen($base . '/not-added', 5, 1, 'Continue');
        $this->assertScreen($base . '/not-added/times', 5, 1, 'Rows that wait for your answer');
        $this->assertScreen($base . '/undo?batch=' . $batchId, 5, 1, 'Undo what was added just now');
        $this->assertScreen($base . '/undo?row=' . $rowIds[0], 5, 1, 'Undo this row');
        $this->assertScreen($base . '/undo', 5, 1, 'Undo everything');
        $undoId = (string) $this->undo($importId, $player, DataImportUndoScope::Rows, rowIds: [$rowIds[0]])->id;
        $this->assertScreen($base . '/undos/' . $undoId, 5, 1, 'Check and add');

        // "Where you are" after an Add: still one primary, and the menu offers "I'm done" - not Discard
        $detail = $this->assertScreen($base, null, 1, 'Match your puzzles');
        self::assertCount(1, $detail->filter('[data-testid="import-flow-menu-finish"]'));
        self::assertCount(0, $detail->filter('[data-testid="import-flow-menu-discard"]'));

        // ---- Finished: read-only, one primary ----
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new FinishImport($importId, $playerId));
        $finished = $this->assertScreen($base, null, 1, 'See what was added');
        self::assertSame('finished', $finished->filter('[data-testid="data-import-page"]')->attr('data-stage'));
        self::assertCount(0, $finished->filter('[data-testid="import-flow-step"] a[href$="/columns"]'), 'no step of a finished import can be opened');
    }

    public function testTheHeaderSaysTheStepAndLeadsToEveryStepAndTheMenuHoldsWhatTheOldCardHeld(): void
    {
        [$player, $importId] = $this->importInTheMiddleOfItsFlow('header');
        TestingLogin::asPlayer($this->browser, $player->id->toString());
        $base = '/en/import/' . $importId;

        // "Where you are": the dots know what is passed and what waits
        $crawler = $this->browser->request('GET', $base);
        $header = $crawler->filter('[data-testid="import-flow-header"]');
        self::assertSame('/en/import', $header->filter('[data-testid="import-flow-back"]')->attr('href'));
        self::assertSame('Step 4 of 5 · Puzzles', self::spaced($header->filter('[data-testid="import-flow-step-name"]')->text()));
        self::assertSame(
            // Step 3: the file names nobody else - passed by itself
            [['1', 'done'], ['2', 'done'], ['3', 'done'], ['4', 'open'], ['5', 'open']],
            $header->filter('[data-testid="import-flow-step"]')->each(static fn (Crawler $step): array => [$step->attr('data-number'), $step->attr('data-state')]),
        );
        self::assertSame(
            [$base, $base . '/columns', $base . '/people', $base . '/puzzles', $base . '/add'],
            $header->filter('[data-testid="import-flow-step"] a')->each(static fn (Crawler $link): null|string => $link->attr('href')),
        );
        self::assertSame('4', $header->filter('[data-testid="import-flow-step"][data-current="true"]')->attr('data-number'));
        self::assertStringContainsString('Step 4 of 5 · Puzzles', (string) $header->filter('[data-testid="import-flow-step"][data-number="4"] a')->attr('aria-label'));

        // The menu: what the State / Size / Uploaded card used to say, and Discard - nothing was added yet
        $menu = $header->filter('[data-testid="import-flow-menu"]');
        self::assertStringContainsString('About this file', $menu->text());
        self::assertStringContainsString('Brought on', $menu->text());
        self::assertStringContainsString('Only you see the file. We keep it for 30 days', self::spaced($menu->text()));
        self::assertSame($base . '/columns', $menu->filter('[data-testid="import-flow-menu-how-read"]')->attr('href'));
        self::assertCount(1, $menu->filter('[data-testid="import-flow-menu-discard"]'));
        self::assertCount(0, $menu->filter('[data-testid="import-flow-menu-finish"]'));
        // ... and at the very bottom of the page, as a plain link with its one sentence
        self::assertStringContainsString('btn-link', (string) $crawler->filter('[data-testid="discard-import"]')->attr('class'));

        // A screen inside a step says its own step, claims nothing about the others, and leads to "Where you are"
        $puzzles = $this->browser->request('GET', $base . '/puzzles')->filter('[data-testid="import-flow-header"]');
        self::assertSame('Step 4 of 5 · Puzzles', self::spaced($puzzles->filter('[data-testid="import-flow-step-name"]')->text()));
        self::assertSame(
            [['1', 'done'], ['2', 'ahead'], ['3', 'ahead'], ['4', 'ahead'], ['5', 'ahead']],
            $puzzles->filter('[data-testid="import-flow-step"]')->each(static fn (Crawler $step): array => [$step->attr('data-number'), $step->attr('data-state')]),
        );
        self::assertSame($base, $puzzles->filter('[data-testid="import-flow-file"]')->attr('href'));
        self::assertCount(0, $puzzles->filter('[data-testid="import-flow-menu-discard"]'));
    }

    public function testContinueLeadsToTheNextScreenThatHasSomethingWaiting(): void
    {
        [$player, $importId] = $this->importInTheMiddleOfItsFlow('continue');
        TestingLogin::asPlayer($this->browser, $player->id->toString());
        $base = '/en/import/' . $importId;

        // The end of "How we read your file": nothing to look at in the rows, nobody to ask about - the puzzles wait
        $crawler = $this->browser->request('GET', $base . '/columns');
        self::assertSame($base . '?after=check', $crawler->filter('[data-testid="import-flow-continue"]')->attr('href'));
        $this->browser->request('GET', $base . '?after=check');
        self::assertResponseRedirects($base . '/puzzles');
        $this->browser->request('GET', $base . '?after=rows');
        self::assertResponseRedirects($base . '/puzzles');
        $this->browser->request('GET', $base . '?after=people');
        self::assertResponseRedirects($base . '/puzzles');
        // Step 5 is never passed - and a screen never leads back to itself
        $this->browser->request('GET', $base . '?after=puzzles');
        self::assertResponseRedirects($base . '/add');
        $this->browser->request('GET', $base . '?after=anything-else');
        self::assertResponseRedirects($base);

        // Nothing is stored about where the player is: asking where to go on writes nothing
        $this->browser->request('GET', $base . '?after=check');
        $this->startCountingQueries($this->browser);
        $this->browser->request('GET', $base . '?after=check');
        self::assertSame([], array_values(array_filter($this->executedSql($this->browser), static fn (string $sql): bool => preg_match('/^\s*(INSERT|UPDATE|DELETE)\b(?!.*\bsessions\b)/i', $sql) === 1)));

        // Somebody else's import says nothing to anybody else - with or without "after"
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $this->browser->request('GET', $base . '?after=check');
        self::assertResponseStatusCodeSame(404);
    }

    public function testWhereYouAreCostsNoStatementMoreThanTheImportsPageDidBeforeTheFlow(): void
    {
        [$player, $importId] = $this->importInTheMiddleOfItsFlow('budget');
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        // What every signed-in page loads: Imports home reads two statements of its own on top of it
        $signedIn = $this->statements('/en/import') - 2;

        // The import, its sheets, the matching of its puzzles, what is ready to add, the live line's start: the five
        // statements the import's page ran before it became "Where you are" - the flow is worked out from them - and
        // one more since slice 2: the people the file names, so that step 3 says "Nobody else is named." or who they are
        self::assertSame($signedIn + 6, $this->statements('/en/import/' . $importId));

        // "Continue" works its target out from the same - and renders nothing
        $this->browser->request('GET', '/en/import/' . $importId . '?after=check');
        $this->startCountingQueries($this->browser);
        $this->browser->request('GET', '/en/import/' . $importId . '?after=check');
        self::assertLessThanOrEqual($signedIn + 5, $this->queryCount($this->browser));
    }

    public function testEveryTemplateOfAnImportsScreenCarriesTheFlowHeader(): void
    {
        $templates = dirname(__DIR__, 3) . '/templates/data_import';
        $screens = [];

        foreach ((new Finder())->files()->in($templates)->name('*.html.twig')->notName('_*')->depth(0) as $file) {
            if (in_array($file->getFilename(), self::NOT_A_SCREEN_OF_AN_IMPORT, true)) {
                continue;
            }

            $screens[] = $file->getFilename();
            $source = $file->getContents();
            self::assertSame(1, substr_count($source, "include('data_import/_flow_header.html.twig'"), $file->getFilename() . ' must include the flow header once');
            self::assertStringNotContainsString("include('_page_crumbs.html.twig'", $source, $file->getFilename() . ': the flow header replaces the breadcrumbs');
            self::assertSame(1, substr_count($source, 'data-import-flow-screen="'), $file->getFilename() . ' must mark its screen');
            // The flow's footer - or, for "Where you are", the flow's own primary
            self::assertTrue(
                str_contains($source, "include('data_import/_flow_footer.html.twig'") || str_contains($source, 'data-testid="import-flow-primary"'),
                $file->getFilename() . ' must end with the flow footer',
            );
        }

        sort($screens);
        self::assertSame(
            [
                'add.html.twig', 'add_list.html.twig', 'add_said.html.twig', 'add_times.html.twig', 'added_rows.html.twig', 'batch.html.twig', 'columns.html.twig', 'detail.html.twig',
                'group_answer.html.twig', 'not_added_rows.html.twig', 'not_added_times.html.twig', 'people.html.twig', 'puzzle_choose.html.twig',
                'puzzle_group.html.twig', 'puzzles.html.twig', 'row.html.twig', 'sheet.html.twig', 'sheet_rows.html.twig', 'to_fix.html.twig',
                'undo.html.twig', 'undo_confirm.html.twig',
            ],
            $screens,
            'a new screen of an import joins this list - and the walk of testEveryScreenOfAnImportHasTheFlowHeaderAndOnePrimaryButton()',
        );

        // Nothing of a file is ever put on a page unescaped
        foreach ((new Finder())->files()->in($templates)->name('*.html.twig') as $file) {
            self::assertStringNotContainsString('|raw', $file->getContents(), $file->getFilename());
        }
    }

    public function testNoWordOfTheEngineIsInTheEnglishTexts(): void
    {
        $catalogue = $this->catalogue('en');
        $found = [];

        foreach ($catalogue->all('messages') as $key => $text) {
            if (is_string($text) && str_starts_with((string) $key, 'data_import.') && preg_match(self::ENGINE_WORDS, $text) === 1) {
                $found[$key] = $text;
            }
        }

        self::assertSame([], $found, 'guided-flow.md §8 "Never on a screen": pairing, tier, candidate, mapping, layout, batch, parse, row state, twin, error, invalid');
    }

    public function testEveryTextOfTheFlowIsThereInEveryLanguage(): void
    {
        $english = $this->catalogue('en')->all('messages');
        $keys = array_values(array_filter(array_map(strval(...), array_keys($english)), static fn (string $key): bool => str_starts_with($key, 'data_import.')));
        self::assertGreaterThan(700, count($keys));

        // Words that are the same in another language
        $sameWord = ['data_import.flow.imports', 'data_import.flow.step.puzzles'];

        foreach (self::LOCALES as $locale) {
            $messages = $this->catalogue($locale)->all('messages');
            $own = array_values(array_filter(array_map(strval(...), array_keys($messages)), static fn (string $key): bool => str_starts_with($key, 'data_import.')));
            self::assertSame([], array_values(array_diff($keys, $own)), $locale . ' misses texts of the import');
            self::assertSame([], array_values(array_diff($own, $keys)), $locale . ' has texts of the import English has not');

            if ($locale === 'en') {
                continue;
            }

            foreach ($keys as $key) {
                if (preg_match('/^data_import\.(flow|explain)\./', $key) !== 1 || in_array($key, $sameWord, true)) {
                    continue;
                }

                self::assertNotSame($english[$key], $messages[$key], $locale . ': ' . $key . ' is an English copy');
            }
        }
    }

    #[DataProvider('provideLocales')]
    public function testEveryPluralOfTheFlowRendersForEveryCount(string $locale): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $rendered = 0;

        foreach ($this->catalogue('en')->all('messages') as $key => $english) {
            $key = (string) $key;

            if (is_string($english) === false || preg_match('/^data_import\.(flow|explain|columns\.summary_line)\.|^data_import\.puzzles\.list\.tick_all$/', $key) !== 1) {
                continue;
            }

            foreach ([0, 1, 2, 3, 4, 5, 11, 21, 22, 100, 1240] as $count) {
                $text = $translator->trans($key, ['%count%' => $count, '%number%' => (string) $count, '%rows%' => '792', '%step%' => '4', '%name%' => 'X', '%date%' => 'X', '%found%' => '5', '%puzzles%' => '9', '%players%' => '1', '%guests%' => '2', '%days%' => '30'], 'messages', $locale);
                self::assertNotSame($key, $text, $locale . ': ' . $key);
                self::assertNotSame('', trim($text), $locale . ': ' . $key . ' for ' . $count);
                self::assertDoesNotMatchRegularExpression('/[|{}\[\]]|%[a-z_]+%/', $text, $locale . ': ' . $key . ' for ' . $count . ' left a plural form or a placeholder on the page');

                if (str_contains($english, '%number%') && $count !== 1) {
                    self::assertStringContainsString((string) $count, $text, $locale . ': ' . $key . ' must name the number ' . $count);
                }

                $rendered++;
            }
        }

        self::assertGreaterThan(700, $rendered);
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
     * A screen of the flow: the header with its five steps and the step the screen belongs to, the screen marked,
     * exactly the primary buttons it should have, "Everything is saved", and no word of the engine.
     */
    private function assertScreen(string $url, null|int $step, int $primaries, null|string $primaryText = null): Crawler
    {
        $crawler = $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        $header = $crawler->filter('[data-testid="import-flow-header"]');
        self::assertCount(1, $header, $url . ': the flow header');
        self::assertCount(5, $header->filter('[data-testid="import-flow-step"]'), $url);
        self::assertCount(0, $crawler->filter('.page-crumbs'), $url . ': no breadcrumbs beside the header');

        if ($step !== null) {
            self::assertSame((string) $step, $header->attr('data-step'), $url . ': the step of the screen');
        }

        $screen = $crawler->filter('[data-import-flow-screen]');
        self::assertCount(1, $screen, $url . ': the screen');
        $buttons = $screen->filter('.btn-primary');
        self::assertCount($primaries, $buttons, $url . ': exactly ' . $primaries . ' primary button(s), found: ' . implode(' | ', $buttons->each(static fn (Crawler $button): string => self::spaced($button->text()))));
        self::assertCount(0, $header->filter('.btn-primary'), $url . ': no primary button in the header');

        if ($primaryText !== null) {
            self::assertSame($primaryText, self::spaced($buttons->text()), $url);
        }

        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="import-flow-saved"]')->count() + $crawler->filter('[data-stage="finished"]')->count(), $url . ': "Everything is saved as you go"');

        $text = self::spaced($header->text() . ' ' . $screen->text());
        self::assertSame(0, preg_match(self::ENGINE_WORDS, $text, $word), $url . ' says "' . ($word[0] ?? '') . '"');

        return $crawler;
    }

    /**
     * An import whose file is read: three results of a puzzle that is matched, one of a puzzle two catalogue
     * puzzles fit, one that looks like a match (the file names no brand), one of a puzzle nobody knows.
     *
     * @return array{\SpeedPuzzling\Web\Entity\Player, string, list<string>}
     */
    private function importInTheMiddleOfItsFlow(string $name): array
    {
        $player = $this->seedPairingPlayer('flow' . $name);
        $marker = 'Zq' . $name;
        $this->seedCataloguePuzzle($marker . ' Harbour', 500, $this->seedBrand($marker . 'brand'));
        $this->seedCataloguePuzzle($marker . ' Double', 1000, $this->seedBrand($marker . 'one'));
        $this->seedCataloguePuzzle($marker . ' Double', 1000, $this->seedBrand($marker . 'two'));
        $this->seedCataloguePuzzle($marker . ' Meadow', 300, $this->seedBrand($marker . 'meadow'));

        [$import, $sheet] = $this->importOf($player);
        $rowIds = [];

        foreach ([0, 1, 2] as $row) {
            $rowIds[] = $this->resultRow($import, $sheet, $marker . ' Harbour', 500, $marker . 'brand', (new DateTimeImmutable('2023-01-01'))->modify('+' . $row . ' days')->format('Y-m-d'), 3000 + $row);
        }

        $this->resultRow($import, $sheet, $marker . ' Double', 1000, '', '2023-02-01', 5000);
        $this->resultRow($import, $sheet, $marker . ' Meadow', 300, '', '2023-02-02', 2000);
        $this->resultRow($import, $sheet, $marker . ' Nobody Knows Qxv', 500, '', '2023-02-03', 4000);

        $this->pair($import);
        $importId = $import->id->toString();
        $this->database->executeStatement('UPDATE data_import_sheet SET parsed_version = 1, mapping_version = 1 WHERE import_id = :id', ['id' => $importId]);

        return [$player, $importId, $rowIds];
    }

    /**
     * The statements of a page's second request.
     */
    private function statements(string $path): int
    {
        $this->browser->request('GET', $path);
        $this->startCountingQueries($this->browser);
        $this->browser->request('GET', $path);
        self::assertResponseIsSuccessful($path);

        return $this->queryCount($this->browser);
    }

    private function catalogue(string $locale): MessageCatalogueInterface
    {
        return self::getContainer()->get(TranslatorInterface::class)->getCatalogue($locale);
    }

    private static function spaced(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
