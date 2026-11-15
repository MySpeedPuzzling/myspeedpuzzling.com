<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\MessageHandler\DataImport\ImportAddingScenario;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /admin/imports (docs/features/data-import/adding.md "The audit page"): the safety switch, what the imports add, and
 * the admin's Undo of a batch - admins only.
 */
final class ImportsControllerTest extends WebTestCase
{
    use ImportAddingScenario;

    public function testOnlyAdminsReachThePagesOfTheImports(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/admin/imports');
        self::assertResponseRedirects();

        // A moderator reaches the catalogue queues - not this
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/admin/imports');
        self::assertResponseStatusCodeSame(403);
        $browser->request('POST', '/admin/imports/adding', ['paused' => '1', 'reason' => 'No']);
        self::assertResponseStatusCodeSame(403);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', '/admin/imports');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="import-adding-on"]');
    }

    public function testAnAdminPausesAddingWithAReasonSeesABatchAndTakesItBack(): void
    {
        $browser = self::createClient();
        $player = $this->seedPairingPlayer('audited');
        $this->seedCataloguePuzzle('Zq Audit Puzzle', 500, $this->seedBrand('Zqaudit'));
        [$import, $sheet] = $this->importOf($player);
        $row = $this->resultRow($import, $sheet, 'Zq Audit Puzzle', 500, 'Zqaudit', '2024-01-01', 1800, ['comment' => 'Zq a cell nobody else may read']);
        $refused = $this->resultRow($import, $sheet, 'Zq Audit Puzzle', 500, 'Zqaudit', '2024-01-02', 1900, ['type' => 'duo']);
        $this->pair($import);
        $added = $this->addAndWork($import, $player);
        self::assertNotNull($added->id);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        // The switch: pausing needs a reason
        $crawler = $browser->request('GET', '/admin/imports');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/admin/imports/adding"]')->form();
        $browser->submit($form, ['reason' => '']);
        self::assertResponseRedirects('/admin/imports');
        self::assertFalse((bool) $this->pairingDatabase()->fetchOne('SELECT COALESCE((SELECT adding_paused FROM data_import_settings), false)'));

        $crawler = $browser->request('GET', '/admin/imports');
        $browser->submit($crawler->filter('form[action="/admin/imports/adding"]')->form(), ['reason' => 'Wrong results reported']);
        self::assertResponseRedirects('/admin/imports');
        $crawler = $browser->followRedirect();
        self::assertSelectorExists('[data-testid="import-adding-paused"]');
        self::assertStringContainsString('Wrong results reported', $crawler->filter('[data-testid="import-adding-switch"]')->text());
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->pairingDatabase()->fetchOne('SELECT adding_paused_changed_by FROM data_import_settings WHERE adding_paused'));

        // The batch is listed, with its numbers
        $batches = $crawler->filter('[data-testid="import-batches"]')->text();
        self::assertStringContainsString('results.xlsx', $batches);
        self::assertSelectorExists('a[href="/admin/imports/batches/' . $added->id . '"]');

        // Its rows by outcome - what each made by id, never a cell of the spreadsheet
        $crawler = $browser->request('GET', '/admin/imports/batches/' . $added->id);
        self::assertResponseIsSuccessful();
        $page = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('people_do_not_fit_type', $page);
        self::assertStringContainsString((string) $this->rowNow($row)['solving_time_id'], $page);
        self::assertStringNotContainsString('Zq a cell nobody else may read', $page);
        self::assertStringNotContainsString('Zq Audit Puzzle', $page);

        // Undo needs a reason - and works while adding is paused
        $undoForm = $crawler->filter('[data-testid="import-batch-undo-form"]')->form();
        $browser->submit($undoForm, ['reason' => ' ']);
        self::assertResponseRedirects('/admin/imports/batches/' . $added->id);
        self::assertSame('added', $this->rowNow($row)['state']);

        $crawler = $browser->request('GET', '/admin/imports/batches/' . $added->id);
        $browser->submit($crawler->filter('[data-testid="import-batch-undo-form"]')->form(), ['reason' => 'Landed on the wrong puzzle']);
        self::assertResponseRedirects('/admin/imports/batches/' . $added->id);

        $undo = $this->pairingDatabase()->fetchAssociative('SELECT id, by_admin, reason, requested_by_player_id, batch_id FROM data_import_undo');
        self::assertIsArray($undo);
        self::assertSame([true, 'Landed on the wrong puzzle', PlayerFixture::PLAYER_ADMIN, $added->id], [$undo['by_admin'], $undo['reason'], $undo['requested_by_player_id'], $undo['batch_id']]);
        self::assertIsString($undo['id']);
        $this->workUndo($undo['id']);

        self::assertSame([], $this->resultsOf($player));
        self::assertSame(['waiting', 'waiting'], [$this->rowNow($row)['state'], $this->rowNow($refused)['state']]);

        $browser->request('GET', '/admin/imports/batches/' . $added->id);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Landed on the wrong puzzle', (string) $browser->getResponse()->getContent());
        $browser->request('GET', '/admin/imports');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="import-undos"]');
    }
}
