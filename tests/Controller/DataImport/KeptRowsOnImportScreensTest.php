<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\MessageHandler\DataImport\UnlistedScenario;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Rows kept just for the player (docs/features/data-import/unlisted-puzzles.md "On the import's own screens") are
 * something an import added - although nothing of them is on the profile: Imports home names them and leads to what
 * was added (where the Undo is), the page after an "Add" does not call them "nothing new", and the page of an Undo
 * says how many it removed. For an import of kept rows only, and for one that added results as well. Every puzzle
 * and every row here is made up.
 */
final class KeptRowsOnImportScreensTest extends WebTestCase
{
    use UnlistedScenario;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
    }

    public function testAnImportThatOnlyKeptRowsSaysSoAndCanBeUndone(): void
    {
        $player = $this->seedPairingPlayer('kept-only');
        $this->seedBrand('Zqkept');
        [$import, $sheet] = $this->importOf($player);
        $this->resultRow($import, $sheet, 'Zq Kept Tarsier Dock', 500, 'Zqkept', '2024-01-02', 2000);
        $this->resultRow($import, $sheet, 'Zq Kept Tarsier Dock', 500, 'Zqkept', '2024-01-09', 1900);
        $this->resultRow($import, $sheet, 'Zq Kept Loris Wharf', 750, 'Zqkept', '2024-02-02', 3000);
        $this->pair($import);
        $this->keepUnlisted($import, $player, 'Zq Kept Tarsier Dock', 'Zq Kept Loris Wharf');
        $batch = $this->addAndWork($import, $player);
        $importId = $import->id->toString();
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        // Imports home: the kept rows are named, and the button leads to what was added - where the Undo is
        $card = $this->browser->request('GET', '/en/import')->filter('[data-import-id="' . $importId . '"]');
        self::assertResponseIsSuccessful();
        self::assertSame(['3 rows are kept just for you.'], $card->filter('[data-testid="data-import-status-line"]')->each(static fn (Crawler $line): string => self::spaced($line->text())));
        self::assertSame('data_import_added', $card->attr('data-route'));
        self::assertStringNotContainsString('Nothing of it is on your profile now', self::spaced($card->text()));
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/added');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a[href*="/en/import/' . $importId . '/undo"], form[action*="/en/import/' . $importId . '/undo"]')->count());

        // The page after the "Add": what it did, never "nothing new" under "Added to your profile"
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/batches/' . $batch->id);
        self::assertResponseIsSuccessful();
        self::assertSame('Kept just for you', self::spaced($crawler->filter('h1')->text()));
        self::assertSame('3 rows are kept just for you - nothing went onto your public profile.', self::spaced($crawler->filter('[data-testid="import-batch-only-kept"]')->text()));
        self::assertSame('/en/unlisted-puzzles', $crawler->filter('[data-testid="import-batch-unlisted-link"]')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-testid="import-batch-nothing-new"]'));

        // Undo everything: the page says what it removed
        $undo = $this->undo($import, $player, DataImportUndoScope::Import);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/undos/' . $undo->id);
        self::assertResponseIsSuccessful();
        $outcome = $crawler->filter('[data-testid="import-undo-outcome"]');
        self::assertSame('3', $outcome->filter('[data-testid="import-undo-kept-removed"]')->attr('data-rows'));
        self::assertStringContainsString('Kept just for you, removed3', self::spaced($outcome->text()));
        self::assertStringNotContainsString('Results removed', self::spaced($outcome->text()));
        self::assertSame(0, self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM unlisted_puzzle WHERE player_id = :id', ['id' => $player->id->toString()]));

        // … and now nothing of it is anywhere
        $card = $this->browser->request('GET', '/en/import')->filter('[data-import-id="' . $importId . '"]');
        self::assertStringNotContainsString('kept just for you', self::spaced($card->text()));

        foreach (['cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            $this->browser->request('GET', '/' . $locale . '/import/' . $importId . '/undos/' . $undo->id);
            self::assertResponseIsSuccessful($locale);
            self::assertStringNotContainsString('data_import.', (string) $this->browser->getResponse()->getContent(), $locale);
        }
    }

    public function testAnImportThatAddedResultsAndKeptRowsNamesBoth(): void
    {
        $player = $this->seedPairingPlayer('kept-mixed');
        $brand = $this->seedBrand('Zqmixed');
        $catalogue = $this->seedCataloguePuzzle('Zq Mixed Catalogue Quay', 500, $brand);
        [$import, $sheet] = $this->importOf($player);
        $this->resultRow($import, $sheet, 'Zq Mixed Catalogue Quay', 500, 'Zqmixed', '2024-03-02', 2100);
        $this->resultRow($import, $sheet, 'Zq Mixed Aye-aye Jetty', 1000, 'Zqmixed', '2024-03-09', 6100);
        $this->pair($import);
        $this->keepUnlisted($import, $player, 'Zq Mixed Aye-aye Jetty');
        $batch = $this->addAndWork($import, $player);
        self::assertCount(1, $this->resultsOf($player, $catalogue));
        $importId = $import->id->toString();
        TestingLogin::asPlayer($this->browser, $player->id->toString());

        $card = $this->browser->request('GET', '/en/import')->filter('[data-import-id="' . $importId . '"]');
        $lines = $card->filter('[data-testid="data-import-status-line"]')->each(static fn (Crawler $line): string => self::spaced($line->text()));
        self::assertCount(2, $lines);
        self::assertSame('1 row is kept just for you.', $lines[1]);
        self::assertSame('data_import_added', $card->attr('data-route'));

        foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            $this->browser->request('GET', '/' . $locale . '/import');
            self::assertResponseIsSuccessful($locale);
            self::assertStringNotContainsString('data_import.', (string) $this->browser->getResponse()->getContent(), $locale);
            $this->browser->request('GET', '/' . $locale . '/import/' . $importId . '/batches/' . $batch->id);
            self::assertResponseIsSuccessful($locale);
            self::assertStringNotContainsString('data_import.', (string) $this->browser->getResponse()->getContent(), $locale);
        }

        $crawler = $this->browser->request('GET', '/en/import/' . $importId . '/batches/' . $batch->id);
        self::assertSame('Added to your profile', self::spaced($crawler->filter('h1')->text()));
        self::assertCount(1, $crawler->filter('[data-testid="import-batch-made-results"]'));
        self::assertSame('1', $crawler->filter('[data-testid="import-batch-unlisted"]')->attr('data-rows'));
        self::assertCount(0, $crawler->filter('[data-testid="import-batch-only-kept"], [data-testid="import-batch-nothing-new"]'));

        $undo = $this->undo($import, $player, DataImportUndoScope::Import);
        $outcome = $this->browser->request('GET', '/en/import/' . $importId . '/undos/' . $undo->id)->filter('[data-testid="import-undo-outcome"]');
        self::assertSame(['1', '1'], [$outcome->attr('data-results'), $outcome->filter('[data-testid="import-undo-kept-removed"]')->attr('data-rows')]);
        self::assertStringContainsString('Results removed1', self::spaced($outcome->text()));
        self::assertStringContainsString('Kept just for you, removed1', self::spaced($outcome->text()));
    }

    private static function spaced(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
