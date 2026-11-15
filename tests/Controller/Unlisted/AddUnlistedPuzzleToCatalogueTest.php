<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Unlisted;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Query\GetPuzzleApprovals;
use SpeedPuzzling\Web\Results\PendingPuzzleApproval;
use SpeedPuzzling\Web\Services\Unlisted\UnlistedPuzzlePage;
use SpeedPuzzling\Web\Tests\MessageHandler\DataImport\UnlistedScenario;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * docs/features/data-import/unlisted-puzzles.md "Add to the catalogue" and "Where it is linked from": an unlisted
 * puzzle becomes a catalogue puzzle waiting for approval - made by the add-result form's own message, its results
 * moved by the unlisted page's own matching - from the list's modal (a Turbo Stream answer) and from a page of its
 * own (a redirect); who may, how often, what is offered first, and what the import's Undo does afterwards. The
 * screens are driven as a browser drives them; the worker's turns are taken by hand with the real handlers. Every
 * puzzle and every row here is made up.
 */
final class AddUnlistedPuzzleToCatalogueTest extends WebTestCase
{
    use QueryCountAssertions;
    use UnlistedScenario;

    private const array MODAL = ['HTTP_TURBO_FRAME' => 'modal-frame', 'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheListOffersBothActionsAndTheFormIsFilledFromTheFileAndNeedsAPhoto(): void
    {
        $owner = $this->seedPairingPlayer('catalogue-form');
        $this->seedBrand('Zqform');
        [, $ids] = $this->seedUnlisted($owner, 'zqform', [['Zq Form Okapi Lagoon', 750, '2024-01-02', 2400]]);
        $unlistedId = $ids['Zq Form Okapi Lagoon'];
        $address = '/en/unlisted-puzzles/' . $unlistedId . '/add-to-catalogue';
        TestingLogin::asPlayer($this->browser, $owner->id->toString());

        // The list: what an unlisted puzzle is and what can be done with it, and each row carries the two actions
        $crawler = $this->browser->request('GET', '/en/unlisted-puzzles');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('you can match it with a catalogue puzzle, or add it to the catalogue with a photo of its box', self::spaced($crawler->filter('[data-testid="unlisted-intro"]')->text()));
        $row = $crawler->filter('#unlisted-puzzle-' . $unlistedId);
        self::assertSame('/en/unlisted-puzzles/' . $unlistedId . '#match', $row->filter('[data-testid="unlisted-puzzle-match"]')->attr('href'));
        $add = $row->filter('[data-testid="unlisted-puzzle-add-to-catalogue"]');
        self::assertSame([$address, 'modal-frame'], [$add->attr('href'), $add->attr('data-turbo-frame')]);
        // … and its own page offers it too, as a plain link (a page of its own)
        $crawler = $this->browser->request('GET', '/en/unlisted-puzzles/' . $unlistedId);
        self::assertCount(1, $crawler->filter('#match'));
        self::assertSame($address, $crawler->filter('[data-testid="unlisted-add-to-catalogue-link"]')->attr('href'));

        // Opened from the list: the modal's frame, the form with an explicit action that stays in the frame
        $crawler = $this->browser->request('GET', $address, server: self::MODAL);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#modal-frame .modal-title'));
        $form = $crawler->filter('[data-testid="catalogue-form"]');
        self::assertSame([$address, 'modal-frame'], [$form->attr('action'), $form->attr('data-turbo-frame')]);
        // The modal's content only - never the whole page inside the frame
        self::assertStringNotContainsString('<html', (string) $this->browser->getResponse()->getContent());

        // As a page of its own: filled with what the file said - the brand by the catalogue's own name of it
        $crawler = $this->browser->request('GET', $address);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', (string) $this->browser->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame(
            ['Zq Form Okapi Lagoon', 'Zqform', '750'],
            [
                $crawler->filter('input[name="catalogue[name]"]')->attr('value'),
                $crawler->filter('input[name="catalogue[brand]"]')->attr('value'),
                $crawler->filter('input[name="catalogue[piecesCount]"]')->attr('value'),
            ],
        );
        self::assertCount(1, $crawler->filter('input[type="file"][name="catalogue[photo]"]'));
        // What becomes public is said before anything is sent
        self::assertStringContainsString('Your results on it show on your profile and count like any other', self::spaced($crawler->filter('[data-testid="catalogue-what-happens"]')->text()));
        $fields = $this->fieldsOf($crawler);
        $puzzlesBefore = $this->pairingCount('SELECT COUNT(*) FROM puzzle');

        // Without a photo: refused with a 422, like the add form refuses a new puzzle without one
        $crawler = $this->browser->request('POST', $address, $fields);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A photo of the box is needed', self::spaced($crawler->filter('[data-testid="catalogue-form"]')->text()));
        // … and the ids of the opened form are kept, so a resend stays one puzzle
        self::assertSame($fields['new_puzzle_id'], $crawler->filter('input[name="new_puzzle_id"]')->attr('value'));

        // The same in the modal: a 422 with the frame, never a stream
        $crawler = $this->browser->request('POST', $address, $fields, server: self::MODAL);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('turbo-frame#modal-frame [data-testid="catalogue-form"]'));

        // A name, a brand and the pieces are needed; a barcode must be one
        $crawler = $this->browser->request('POST', $address, [...$fields, 'catalogue' => ['name' => ' ', 'brand' => '', 'piecesCount' => '5', 'ean' => 'not-a-code', 'brandCode' => '']], ['catalogue' => ['photo' => self::photo()]]);
        self::assertResponseStatusCodeSame(422);
        self::assertGreaterThanOrEqual(4, $crawler->filter('[data-testid="catalogue-form"] .invalid-feedback')->count());

        // Not a file that is no picture
        $this->browser->request('POST', $address, $fields, ['catalogue' => ['photo' => self::notAPhoto()]]);
        self::assertResponseStatusCodeSame(422);

        // Without the page's token nothing is taken
        $this->browser->request('POST', $address, [...$fields, '_token' => 'wrong'], ['catalogue' => ['photo' => self::photo()]]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame($puzzlesBefore, $this->pairingCount('SELECT COUNT(*) FROM puzzle'));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM unlisted_pairing'));
    }

    public function testFromTheModalThePuzzleWaitsForApprovalItsResultsMoveAndTheRowChangesInPlace(): void
    {
        $owner = $this->seedPairingPlayer('catalogue-modal');
        $brand = $this->seedBrand('Zqmodal');
        [$import, $ids] = $this->seedUnlisted($owner, 'Zqmodal', [
            ['Zq Modal Serval Terrace', 500, '2024-02-02', 3000],
            ['Zq Modal Serval Terrace', 500, '2024-02-09', 2900],
        ], owned: ['Zq Modal Serval Terrace']);
        $unlistedId = $ids['Zq Modal Serval Terrace'];
        $address = '/en/unlisted-puzzles/' . $unlistedId . '/add-to-catalogue';
        TestingLogin::asPlayer($this->browser, $owner->id->toString());

        $fields = $this->fieldsOf($this->browser->request('GET', $address, server: self::MODAL));
        $fields['catalogue']['ean'] = '4005556123452';
        $puzzleId = $fields['new_puzzle_id'];

        $this->browser->request('POST', $address, $fields, ['catalogue' => ['photo' => self::photo()]], self::MODAL);

        // A Turbo Stream: the modal closes, the row says where the puzzle is now and links to it - no reload
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/vnd.turbo-stream.html', (string) $this->browser->getResponse()->headers->get('Content-Type'));
        $stream = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="update" target="modal-frame">', $stream);
        self::assertStringContainsString('<turbo-stream action="replace" target="unlisted-puzzle-' . $unlistedId . '">', $stream);
        self::assertStringContainsString('Added to the catalogue - waiting for approval', $stream);
        self::assertStringContainsString('href="/en/puzzle/' . $puzzleId . '"', $stream);

        // The puzzle: the player's, with the file's brand as the catalogue has it (no second brand), not approved -
        // and in the moderators' queue like any puzzle a player adds
        $puzzle = $this->database->fetchAssociative('SELECT name, pieces_count, approved, manufacturer_id, added_by_user_id, image, ean FROM puzzle WHERE id = :id', ['id' => $puzzleId]);
        self::assertIsArray($puzzle);
        self::assertSame(['Zq Modal Serval Terrace', 500, false, $brand->id->toString(), $owner->id->toString()], [$puzzle['name'], $puzzle['pieces_count'], $puzzle['approved'], $puzzle['manufacturer_id'], $puzzle['added_by_user_id']]);
        self::assertNotEmpty($puzzle['image']);
        self::assertIsString($puzzle['ean']);
        self::assertStringContainsString('4005556123452', $puzzle['ean']);
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM manufacturer WHERE LOWER(name) = :name', ['name' => 'zqmodal']));
        self::assertContains($puzzleId, array_map(static fn (PendingPuzzleApproval $pending): string => $pending->puzzleId, self::getContainer()->get(GetPuzzleApprovals::class)->pending()));

        // Its results and the owned mark move by the unlisted page's own matching - the worker's turn
        self::assertCount(0, $this->resultsOf($owner, $puzzleId));
        $pairingId = $this->database->fetchOne('SELECT id FROM unlisted_pairing WHERE unlisted_puzzle_id = :id AND puzzle_id = :puzzleId', ['id' => $unlistedId, 'puzzleId' => $puzzleId]);
        self::assertIsString($pairingId);
        $this->workPairing($pairingId);
        self::assertCount(2, $this->resultsOf($owner, $puzzleId));
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM collection_item WHERE player_id = :id AND puzzle_id = :puzzleId', ['id' => $owner->id->toString(), 'puzzleId' => $puzzleId]));
        self::assertSame([true, true], array_column($this->unlistedResults($unlistedId), 'converted'));

        // The same form again (a double tap, a lost answer): the same answer, nothing twice
        $this->browser->request('POST', $address, $fields, ['catalogue' => ['photo' => self::photo()]], self::MODAL);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('target="unlisted-puzzle-' . $unlistedId . '"', (string) $this->browser->getResponse()->getContent());
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM puzzle WHERE added_by_user_id = :id', ['id' => $owner->id->toString()]));
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM unlisted_pairing WHERE unlisted_puzzle_id = :id', ['id' => $unlistedId]));
        self::assertCount(2, $this->resultsOf($owner, $puzzleId));

        // Everything moved: it left the list, its page says what it became, and the form is not offered any more
        self::assertCount(1, $this->browser->request('GET', '/en/unlisted-puzzles')->filter('[data-testid="unlisted-empty"]'));
        $crawler = $this->browser->request('GET', '/en/unlisted-puzzles/' . $unlistedId);
        self::assertCount(1, $crawler->filter('[data-testid="unlisted-paired-puzzle"]'));
        self::assertCount(0, $crawler->filter('[data-testid="unlisted-add-to-catalogue"]'));
        $this->browser->request('GET', $address);
        self::assertResponseRedirects('/en/unlisted-puzzles/' . $unlistedId);
        // … and from a list that went stale the modal says so in its frame - never a redirect into an empty frame
        $crawler = $this->browser->request('GET', $address, server: self::MODAL);
        self::assertResponseIsSuccessful();
        self::assertSame('/en/unlisted-puzzles/' . $unlistedId, $crawler->filter('turbo-frame#modal-frame [data-testid="catalogue-gone-link"]')->attr('href'));
        self::assertSame('_top', $crawler->filter('[data-testid="catalogue-gone-link"]')->attr('data-turbo-frame'));
        self::assertStringContainsString('already matched', self::spaced($crawler->filter('[data-testid="catalogue-gone"]')->text()));
        $crawler = $this->browser->request('POST', $address, [...$fields, 'new_puzzle_id' => '0193f000-0000-7000-8000-00000000000a', 'request_id' => '0193f000-0000-7000-8000-00000000000b'], ['catalogue' => ['photo' => self::photo()]], self::MODAL);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('turbo-frame#modal-frame [data-testid="catalogue-gone"]'));
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM puzzle WHERE added_by_user_id = :id', ['id' => $owner->id->toString()]));

        // The player sees the new puzzle, and the results on it are on their profile like any other
        $this->browser->request('GET', '/en/puzzle/' . $puzzleId);
        self::assertResponseIsSuccessful();
        $this->browser->request('GET', '/en/player-profile/' . $owner->id->toString());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Zq Modal Serval Terrace', (string) $this->browser->getResponse()->getContent());

        // --- The import's Undo afterwards: its rows are real results now, so they stay - the matching's Undo is first ---
        $this->undo($import, $owner, DataImportUndoScope::Import);
        self::assertCount(2, $this->resultsOf($owner, $puzzleId));
        self::assertSame(0, $this->pairingCount("SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND state <> 'added'", ['id' => $import->id->toString()]));

        // Undo of the matching (30 days, from the unlisted puzzle's page): the results are unlisted again …
        $crawler = $this->browser->request('GET', '/en/unlisted-puzzles/' . $unlistedId);
        $this->browser->submit($crawler->filter('[data-testid="unlisted-pairing-undo"]')->form());
        self::assertResponseRedirects('/en/unlisted-puzzles/' . $unlistedId);
        $this->workPairing($pairingId);
        self::assertCount(0, $this->resultsOf($owner, $puzzleId));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM collection_item WHERE player_id = :id', ['id' => $owner->id->toString()]));
        self::assertSame([false, false], array_column($this->unlistedResults($unlistedId), 'converted'));
        // … the catalogue puzzle stays in the queue, like any puzzle a player added
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM puzzle WHERE id = :id AND approved = false', ['id' => $puzzleId]));

        // "Add to the catalogue" again: the puzzle the player added leads, with "It is this one" - and the form under
        // it goes on with that very puzzle, so what is sent corrects it instead of making a second one
        foreach ([[], self::MODAL] as $server) {
            $crawler = $this->browser->request('GET', $address, server: $server);
            self::assertResponseIsSuccessful();
            self::assertSame($puzzleId, $crawler->filter('[data-testid="catalogue-own-puzzle"]')->attr('data-puzzle-id'));
            self::assertCount(0, $crawler->filter('[data-testid="catalogue-likely-puzzle"][data-puzzle-id="' . $puzzleId . '"]'));
            self::assertCount(1, $crawler->filter('details[data-testid="catalogue-form-fold"]:not([open]) [data-testid="catalogue-form"]'));
        }

        $again = $this->fieldsOf($crawler);
        self::assertSame($puzzleId, $again['new_puzzle_id']);
        $again['catalogue']['name'] = 'Zq Modal Serval Terrace at Night';
        $this->browser->request('POST', $address, $again, ['catalogue' => ['photo' => self::photo()]], self::MODAL);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('href="/en/puzzle/' . $puzzleId . '"', (string) $this->browser->getResponse()->getContent());
        self::assertSame(['Zq Modal Serval Terrace at Night'], $this->database->fetchFirstColumn('SELECT name FROM puzzle WHERE added_by_user_id = :id', ['id' => $owner->id->toString()]));
        $secondPairingId = $this->database->fetchOne("SELECT id FROM unlisted_pairing WHERE unlisted_puzzle_id = :id AND state = 'running'", ['id' => $unlistedId]);
        self::assertIsString($secondPairingId);
        $this->workPairing($secondPairingId);
        self::assertCount(2, $this->resultsOf($owner, $puzzleId));

        // … or without the form: taken back once more, "It is this one" on their own puzzle moves the results
        $this->undoPairing($owner, $secondPairingId);
        $crawler = $this->browser->request('GET', $address);
        $form = $crawler->filter('[data-testid="catalogue-own-puzzle"]')->closest('form');
        self::assertInstanceOf(Crawler::class, $form);
        $this->browser->submit($form->form());
        self::assertResponseRedirects('/en/unlisted-puzzles/' . $unlistedId);
        $thirdPairingId = $this->database->fetchOne("SELECT id FROM unlisted_pairing WHERE unlisted_puzzle_id = :id AND state = 'running'", ['id' => $unlistedId]);
        self::assertIsString($thirdPairingId);
        $this->workPairing($thirdPairingId);
        self::assertCount(2, $this->resultsOf($owner, $puzzleId));
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM puzzle WHERE added_by_user_id = :id', ['id' => $owner->id->toString()]));
        $this->undoPairing($owner, $thirdPairingId);
        self::assertSame([false, false], array_column($this->unlistedResults($unlistedId), 'converted'));

        // … and now the import's Undo takes everything of it back
        $this->undo($import, $owner, DataImportUndoScope::Import);
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM unlisted_result WHERE player_id = :id', ['id' => $owner->id->toString()]));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM unlisted_puzzle WHERE player_id = :id', ['id' => $owner->id->toString()]));
        self::assertSame(0, $this->pairingCount("SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND state = 'added'", ['id' => $import->id->toString()]));
    }

    public function testAsAPageOfItsOwnItRedirectsAndABrandWeDoNotKnowIsANewOne(): void
    {
        $owner = $this->seedPairingPlayer('catalogue-page');
        [, $ids] = $this->seedUnlisted($owner, 'Zqnobrand Workshop', [['Zq Page Gerenuk Canal', 1000, '2024-03-03', 5000]]);
        $unlistedId = $ids['Zq Page Gerenuk Canal'];
        $address = '/en/unlisted-puzzles/' . $unlistedId . '/add-to-catalogue';
        TestingLogin::asPlayer($this->browser, $owner->id->toString());

        $crawler = $this->browser->request('GET', $address);
        // The brand is none of ours: the form shows what the file wrote
        self::assertSame('Zqnobrand Workshop', $crawler->filter('input[name="catalogue[brand]"]')->attr('value'));
        self::assertSame('_top', $crawler->filter('[data-testid="catalogue-form"]')->attr('data-turbo-frame'));
        $fields = $this->fieldsOf($crawler);
        $fields['catalogue']['name'] = 'Zq Page Gerenuk Canal at Dusk';

        // No Turbo Stream asked for: a redirect to the unlisted puzzle's page (never a 200 for a full-page POST)
        $this->browser->request('POST', $address, $fields, ['catalogue' => ['photo' => self::photo()]]);
        self::assertResponseRedirects('/en/unlisted-puzzles/' . $unlistedId);
        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('Added to the catalogue - waiting for approval', self::spaced($crawler->filter('body')->text()));
        self::assertSame('running', $crawler->filter('[data-testid="unlisted-puzzle-page"]')->attr('data-working'));

        // What the player corrected is what the catalogue gets; the new brand waits for approval too, as from the add form
        $puzzle = $this->database->fetchAssociative(
            'SELECT puzzle.name, puzzle.approved, manufacturer.name AS brand, manufacturer.approved AS brand_approved, manufacturer.added_by_user_id AS brand_added_by FROM puzzle INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id WHERE puzzle.id = :id',
            ['id' => $fields['new_puzzle_id']],
        );
        self::assertSame(['name' => 'Zq Page Gerenuk Canal at Dusk', 'approved' => false, 'brand' => 'Zqnobrand Workshop', 'brand_approved' => false, 'brand_added_by' => $owner->id->toString()], $puzzle);
    }

    public function testACataloguePuzzleItMayAlreadyBeIsOfferedFirstAndChoosingItMatchesWithoutANewPuzzle(): void
    {
        $owner = $this->seedPairingPlayer('catalogue-likely');
        $brand = $this->seedBrand('Zqlikely');
        $catalogue = $this->seedCataloguePuzzle('Zq Likely Margay Harbour', 750, $brand);
        $this->seedCataloguePuzzle('Zq Likely Hidden Margay Harbour', 750, $brand, hideUntil: TestClock::now()->modify('+30 days'));
        [, $ids] = $this->seedUnlisted($owner, 'Zqlikely', [['Zq Likely Margay Harbor', 750, '2024-04-04', 2600]]);
        $unlistedId = $ids['Zq Likely Margay Harbor'];
        TestingLogin::asPlayer($this->browser, $owner->id->toString());
        $puzzlesBefore = $this->pairingCount('SELECT COUNT(*) FROM puzzle');

        foreach ([[], self::MODAL] as $server) {
            $crawler = $this->browser->request('GET', '/en/unlisted-puzzles/' . $unlistedId . '/add-to-catalogue', server: $server);
            // The same brand and pieces and a similar name - never a hidden puzzle
            self::assertSame([$catalogue], $crawler->filter('[data-testid="catalogue-likely-puzzle"]')->each(static fn (Crawler $button): string => (string) $button->attr('data-puzzle-id')));
            self::assertStringNotContainsString('Hidden Margay', (string) $this->browser->getResponse()->getContent());
            // Out of the modal: its answer is the unlisted puzzle's page
            $form = $crawler->filter('[data-testid="catalogue-likely-puzzle"]')->closest('form');
            self::assertInstanceOf(Crawler::class, $form);
            self::assertSame('_top', $form->attr('data-turbo-frame'));
        }

        // "It is this one": the unlisted page's own matching
        $form = $crawler->filter('[data-testid="catalogue-likely-puzzle"]')->closest('form');
        self::assertInstanceOf(Crawler::class, $form);
        $this->browser->submit($form->form());
        self::assertResponseRedirects('/en/unlisted-puzzles/' . $unlistedId);
        $pairingId = $this->database->fetchOne('SELECT id FROM unlisted_pairing WHERE unlisted_puzzle_id = :id AND puzzle_id = :puzzleId', ['id' => $unlistedId, 'puzzleId' => $catalogue]);
        self::assertIsString($pairingId);
        $this->workPairing($pairingId);

        self::assertCount(1, $this->resultsOf($owner, $catalogue));
        self::assertSame($puzzlesBefore, $this->pairingCount('SELECT COUNT(*) FROM puzzle'));
    }

    public function testADayTakesTenNewPuzzlesOfAPlayerAndNothingWhileAddingIsPaused(): void
    {
        $owner = $this->seedPairingPlayer('catalogue-limit');
        $this->seedBrand('Zqlimit');
        [, $ids] = $this->seedUnlisted($owner, 'Zqlimit', [['Zq Limit Caracal Bridge', 500, '2024-05-05', 2100]]);
        $address = '/en/unlisted-puzzles/' . $ids['Zq Limit Caracal Bridge'] . '/add-to-catalogue';
        TestingLogin::asPlayer($this->browser, $owner->id->toString());
        $fields = $this->fieldsOf($this->browser->request('GET', $address));

        // The import's safety switch: matching makes results public, so nothing is created while it is on
        $this->database->executeStatement("INSERT INTO data_import_settings (id, adding_paused, adding_paused_reason, adding_paused_changed_at) VALUES (1, true, 'test', :now) ON CONFLICT (id) DO UPDATE SET adding_paused = true", ['now' => TestClock::FROZEN_NOW]);
        $crawler = $this->browser->request('POST', $address, $fields, ['catalogue' => ['photo' => self::photo()]]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Adding is paused', self::spaced($crawler->filter('[data-testid="catalogue-form"]')->text()));
        $this->database->executeStatement('UPDATE data_import_settings SET adding_paused = false');

        // A request above PHP's limit arrives without any field: "the photo is too large", never a bare 403
        $crawler = $this->browser->request('POST', $address, server: ['CONTENT_LENGTH' => '200000000']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The photo is too large - at most 20 MB', self::spaced($crawler->filter('[data-testid="catalogue-notice"]')->text()));
        self::assertSame('Zq Limit Caracal Bridge', $crawler->filter('input[name="catalogue[name]"]')->attr('value'));
        // … while an empty POST without that is what it looks like
        $this->browser->request('POST', $address);
        self::assertResponseStatusCodeSame(403);

        // An id that is a hidden puzzle of the player's own (only a tampered form sends one): the handler refuses it
        // (PuzzleIdTaken) - a refused form with a fresh id, the puzzle untouched, nothing for anybody to look at
        $hidden = $this->seedCataloguePuzzle('Zq Limit Hidden Round Puzzle', 500, hideUntil: TestClock::now()->modify('+30 days'), approved: false);
        $this->database->update('puzzle', ['added_by_user_id' => $owner->id->toString(), 'added_at' => TestClock::now()->modify('-3 days')->format('Y-m-d H:i:s')], ['id' => $hidden]);
        $crawler = $this->browser->request('POST', $address, [...$fields, 'new_puzzle_id' => $hidden], ['catalogue' => ['photo' => self::photo()]]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The puzzle could not be saved', self::spaced($crawler->filter('[data-testid="catalogue-form"]')->text()));
        self::assertNotSame($hidden, $crawler->filter('input[name="new_puzzle_id"]')->attr('value'));
        self::assertSame('Zq Limit Hidden Round Puzzle', $this->database->fetchOne('SELECT name FROM puzzle WHERE id = :id', ['id' => $hidden]));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM unlisted_pairing'));
        $this->database->delete('puzzle', ['id' => $hidden]);

        // Ten puzzles the player added in the last 24 hours - whichever way
        for ($number = 1; $number <= UnlistedPuzzlePage::MAX_CATALOGUE_ADDITIONS_PER_DAY; $number++) {
            $added = $this->seedCataloguePuzzle(sprintf('Zq Limit Earlier %02d', $number), 300, approved: false);
            $this->database->update('puzzle', ['added_by_user_id' => $owner->id->toString(), 'added_at' => TestClock::now()->modify('-2 hours')->format('Y-m-d H:i:s')], ['id' => $added]);
        }

        $crawler = $this->browser->request('POST', $address, $fields, ['catalogue' => ['photo' => self::photo()]]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('You have added 10 new puzzles in the last 24 hours', self::spaced($crawler->filter('[data-testid="catalogue-form"]')->text()));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM puzzle WHERE id = :id', ['id' => $fields['new_puzzle_id']]));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM unlisted_pairing'));

        // A day later there is room again
        $this->database->executeStatement('UPDATE puzzle SET added_at = :then WHERE added_by_user_id = :id', ['then' => TestClock::now()->modify('-25 hours')->format('Y-m-d H:i:s'), 'id' => $owner->id->toString()]);
        $this->browser->request('POST', $address, $fields, ['catalogue' => ['photo' => self::photo()]]);
        self::assertResponseRedirects();
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM puzzle WHERE id = :id', ['id' => $fields['new_puzzle_id']]));
    }

    public function testNobodyButTheOwnerOpensOrSendsIt(): void
    {
        $owner = $this->seedPairingPlayer('catalogue-owner');
        $other = $this->seedPairingPlayer('catalogue-other');
        $this->seedBrand('Zqowner');
        [, $ids] = $this->seedUnlisted($owner, 'Zqowner', [['Zq Owner Fossa Garden', 500, '2024-06-06', 2200]]);
        $address = '/en/unlisted-puzzles/' . $ids['Zq Owner Fossa Garden'] . '/add-to-catalogue';
        TestingLogin::asPlayer($this->browser, $owner->id->toString());
        $fields = $this->fieldsOf($this->browser->request('GET', $address));
        $puzzlesBefore = $this->pairingCount('SELECT COUNT(*) FROM puzzle');

        // Another player: "not found" - the same answer as for an unlisted puzzle that does not exist
        TestingLogin::asPlayer($this->browser, $other->id->toString());

        foreach ([[], self::MODAL] as $server) {
            $this->browser->request('GET', $address, server: $server);
            self::assertResponseStatusCodeSame(404);
            $this->browser->request('POST', $address, $fields, ['catalogue' => ['photo' => self::photo()]], $server);
            self::assertResponseStatusCodeSame(404);
        }

        // A guest: "not found" too, never a sign-in page
        $this->browser->getCookieJar()->clear();
        $this->browser->request('GET', $address);
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('POST', $address, $fields, ['catalogue' => ['photo' => self::photo()]]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame($puzzlesBefore, $this->pairingCount('SELECT COUNT(*) FROM puzzle'));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM unlisted_pairing'));
        self::assertSame([false], array_column($this->unlistedResults($ids['Zq Owner Fossa Garden']), 'converted'));
    }

    public function testTheListIsLinkedFromTheMenuTheImportAndTheGuideOnlyForAPlayerWhoHasSome(): void
    {
        $owner = $this->seedPairingPlayer('catalogue-links');
        $other = $this->seedPairingPlayer('catalogue-nolinks');
        $this->seedBrand('Zqlinks');
        [$import] = $this->seedUnlisted($owner, 'Zqlinks', [['Zq Links Genet Orchard', 500, '2024-07-07', 2300]]);

        // --- A player with unlisted puzzles ---
        TestingLogin::asPlayer($this->browser, $owner->id->toString());
        $crawler = $this->browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();
        self::assertSame('/en/unlisted-puzzles', $crawler->filter('[data-testid="menu-unlisted-puzzles"]')->attr('href'));
        // The answer is remembered: the next page asks nothing
        $this->startCountingQueries($this->browser);
        $crawler = $this->browser->request('GET', '/en/faq');
        self::assertCount(1, $crawler->filter('[data-testid="menu-unlisted-puzzles"]'));
        self::assertCount(0, array_filter($this->executedSql($this->browser), static fn (string $sql): bool => str_contains($sql, 'unlisted_puzzle')));
        // The import's overview says where "Keep it just for me" went
        $crawler = $this->browser->request('GET', '/en/import/' . $import->id->toString());
        self::assertResponseIsSuccessful();
        self::assertSame('/en/unlisted-puzzles', $crawler->filter('[data-testid="import-unlisted-link"] a')->attr('href'));
        // The public guide: the words for everybody, the link for the signed-in reader
        $crawler = $this->browser->request('GET', '/en/import-your-spreadsheet');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('add it to our database with a photo of its box', self::spaced($crawler->filter('[data-testid="guide-unlisted-later"]')->text()));
        self::assertSame('/en/unlisted-puzzles', $crawler->filter('[data-testid="guide-unlisted-link"]')->attr('href'));

        // --- A player without any: no menu item, and the second page costs no statement for it ---
        TestingLogin::asPlayer($this->browser, $other->id->toString());
        self::assertCount(0, $this->browser->request('GET', '/en/faq')->filter('[data-testid="menu-unlisted-puzzles"]'));
        $this->startCountingQueries($this->browser);
        $crawler = $this->browser->request('GET', '/en/faq');
        self::assertCount(0, $crawler->filter('[data-testid="menu-unlisted-puzzles"]'));
        self::assertCount(1, $crawler->filter('[data-testid="menu-import"]'));
        self::assertCount(0, array_filter($this->executedSql($this->browser), static fn (string $sql): bool => str_contains($sql, 'unlisted_puzzle')));
        // Their own list tells the menu at once when that changes
        self::assertCount(0, $this->browser->request('GET', '/en/unlisted-puzzles')->filter('[data-testid="menu-unlisted-puzzles"]'));

        // --- A guest: the guide's words without the link (the page would be "not found" for them) ---
        $this->browser->getCookieJar()->clear();
        $crawler = $this->browser->request('GET', '/en/import-your-spreadsheet');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="guide-unlisted-later"]'));
        self::assertCount(0, $crawler->filter('[data-testid="guide-unlisted-link"]'));
        self::assertCount(0, $crawler->filter('[data-testid="menu-unlisted-puzzles"]'));
    }

    public function testTheFormRendersInEveryLanguage(): void
    {
        $owner = $this->seedPairingPlayer('catalogue-locales');
        $brand = $this->seedBrand('Zqwords');
        $this->seedCataloguePuzzle('Zq Words Kodkod Harbour', 500, $brand);
        [, $ids] = $this->seedUnlisted($owner, 'Zqwords', [['Zq Words Kodkod Harbor', 500, '2024-08-08', 2000]]);
        TestingLogin::asPlayer($this->browser, $owner->id->toString());

        foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            foreach ([[], self::MODAL] as $server) {
                $crawler = $this->browser->request('GET', '/' . $locale . '/unlisted-puzzles/' . $ids['Zq Words Kodkod Harbor'] . '/add-to-catalogue', server: $server);
                self::assertResponseIsSuccessful($locale);
                self::assertCount(1, $crawler->filter('[data-testid="catalogue-likely"]'));
                // No text falls back to its key
                self::assertStringNotContainsString('unlisted_puzzles.', (string) $this->browser->getResponse()->getContent(), $locale);
            }

            $this->browser->request('GET', self::getContainer()->get('router')->generate('import_guide', ['_locale' => $locale]));
            self::assertResponseIsSuccessful($locale);
            self::assertStringNotContainsString('unlisted_puzzles.', (string) $this->browser->getResponse()->getContent(), $locale);
        }
    }

    /**
     * An import of the player whose rows are kept as unlisted puzzles and added - by the real handlers.
     *
     * @param list<array{string, int, string, null|int}> $results name, pieces, day, seconds
     * @param list<string> $owned
     *
     * @return array{DataImport, array<string, string>} the import, and the unlisted puzzle's id by name
     */
    private function seedUnlisted(Player $owner, string $brand, array $results, array $owned = []): array
    {
        [$import, $sheet] = $this->importOf($owner);
        $names = [];

        foreach ($results as [$name, $pieces, $day, $seconds]) {
            $this->resultRow($import, $sheet, $name, $pieces, $brand, $day, $seconds);
            $names[$name] = $pieces;
        }

        foreach ($owned as $name) {
            $this->ownedRow($import, $sheet, $name, $names[$name] ?? 1000, $brand);
            $names[$name] ??= 1000;
        }

        $this->pair($import);
        $this->keepUnlisted($import, $owner, ...array_map(strval(...), array_keys($names)));
        $this->addAndWork($import, $owner);

        /** @var array<string, string> $ids */
        $ids = $this->database->fetchAllKeyValue('SELECT name, id FROM unlisted_puzzle WHERE player_id = :id', ['id' => $owner->id->toString()]);

        return [$import, $ids];
    }

    /**
     * What the rendered form would send, without a photo.
     *
     * @return array{_token: string, new_puzzle_id: string, request_id: string, catalogue: array<string, string>}
     */
    private function fieldsOf(Crawler $crawler): array
    {
        $form = $crawler->filter('[data-testid="catalogue-form"]');
        self::assertCount(1, $form);
        $value = static fn (string $name): string => (string) $form->filter('input[name="' . $name . '"]')->attr('value');

        return [
            '_token' => $value('_token'),
            'new_puzzle_id' => $value('new_puzzle_id'),
            'request_id' => $value('request_id'),
            'catalogue' => [
                'name' => $value('catalogue[name]'),
                'brand' => $value('catalogue[brand]'),
                'piecesCount' => $value('catalogue[piecesCount]'),
                'ean' => $value('catalogue[ean]'),
                'brandCode' => $value('catalogue[brandCode]'),
            ],
        ];
    }

    private static function photo(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'catalogue_box_') . '.jpg';
        $image = imagecreatetruecolor(40, 30);
        self::assertNotFalse($image);
        imagejpeg($image, $path);

        return new UploadedFile($path, 'box.jpg', 'image/jpeg', null, true);
    }

    private static function notAPhoto(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'catalogue_text_') . '.txt';
        file_put_contents($path, 'not a picture of a box');

        return new UploadedFile($path, 'box.txt', 'text/plain', null, true);
    }

    private static function spaced(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
