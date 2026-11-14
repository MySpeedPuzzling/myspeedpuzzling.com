<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CustomFields;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Message\ArchiveCustomField;
use SpeedPuzzling\Web\Message\EditCustomField;
use SpeedPuzzling\Web\Tests\CustomFieldsScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionItemFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\StandardCustomField;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The player's own fields in their two downloads (docs/features/data-export.md "Own fields"), through the real
 * routes: which fields become columns and in which order, whose values they are, and what they cost. How each format
 * writes them is OwnFieldsInExportsTest.
 *
 * PLAYER_REGULAR has nine collection items in the fixtures (ITEM_04-11 and ITEM_22).
 */
final class MyFieldsExportTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;
    private const string PLAYER_USER = PlayerFixture::PLAYER_REGULAR_USER_ID;
    private const string PARTNER = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string RESULTS = '/en/export-puzzler-data/' . self::PLAYER . '/download/';
    private const string LIBRARY = '/en/export-puzzler-data/' . self::PLAYER . '/library/';

    public function testTheResultsFileCarriesTheFieldsForResultsInThePlayersOrderThenWhatStillHoldsAValue(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $messageBus = self::getContainer()->get(MessageBusInterface::class);

        $notes = $scenario->field(self::PLAYER, 'Notes <b>bold</b>');
        $felt = $scenario->readyMade(self::PLAYER, StandardCustomField::HowHardItFelt);
        $tags = $scenario->readyMade(self::PLAYER, StandardCustomField::MyTags);
        $scenario->field(self::PLAYER, 'Never filled in');
        // Not columns of this file: a field for collection puzzles only, an archived field without a value
        $scenario->readyMade(self::PLAYER, StandardCustomField::PricePaid);
        $archivedEmpty = $scenario->field(self::PLAYER, 'Archived and empty');
        $messageBus->dispatch(new ArchiveCustomField($archivedEmpty, self::PLAYER));
        // Columns after the fields in use: one that is no longer for results, and an archived one - both with a value
        $archived = $scenario->field(self::PLAYER, 'Archived with a value', CustomFieldType::Number);
        $moved = $scenario->field(self::PLAYER, 'Now for the collection', CustomFieldType::YesNo);

        $timeId = $scenario->result(self::PLAYER_USER, ['#player3']);
        $otherTimeId = $scenario->result(self::PLAYER_USER, time: '04:00:00', daysAgo: 9);
        $scenario->setValues($timeId, self::PLAYER, [$notes => '=1+1', $felt => '3', $tags => 'gradient, cats', $archived => '7', $moved => 'yes']);
        $messageBus->dispatch(new ArchiveCustomField($archived, self::PLAYER));
        $messageBus->dispatch(new EditCustomField($moved, self::PLAYER, 'Now for the collection', [], false, true));
        // The partner's own values on the same result are the partner's
        $partnerNotes = $scenario->field(self::PARTNER, 'Notes of the partner');
        $scenario->setValues($timeId, self::PARTNER, [$partnerNotes => 'partner-only-words']);

        $labels = ['custom: Notes <b>bold</b>', 'custom:how_hard_it_felt', 'custom:my_tags', 'custom: Never filled in', 'custom: Now for the collection', 'custom: Archived with a value'];

        $rows = $this->json($browser, self::RESULTS . 'json');
        $byId = self::rowsBy('result_id', $rows);
        self::assertArrayHasKey($timeId, $byId);
        self::assertSame($labels, array_keys(self::customOf($byId[$timeId])));
        self::assertSame([
            'custom: Notes <b>bold</b>' => '=1+1',
            'custom:how_hard_it_felt' => 3,
            'custom:my_tags' => ['gradient', 'cats'],
            'custom: Never filled in' => null,
            'custom: Now for the collection' => true,
            'custom: Archived with a value' => 7,
        ], self::customOf($byId[$timeId]));
        // Every row has the same columns, also one without a value
        self::assertSame(array_fill_keys($labels, null), self::customOf($byId[$otherTimeId]));
        self::assertSame('custom', array_key_last($byId[$timeId]));

        $csv = $this->download($browser, self::RESULTS . 'csv');
        $header = strtok($csv, "\n");
        self::assertIsString($header);
        self::assertStringEndsWith('"msp_rating_puzzle_first_try_solvers","' . implode('","', $labels) . '"', trim($header));
        self::assertStringContainsString('"\'=1+1","3","gradient, cats","","true","7"', $csv);

        $xml = $this->download($browser, self::RESULTS . 'xml');
        self::assertStringContainsString('<custom name="custom:how_hard_it_felt">3</custom>', $xml);
        self::assertStringContainsString('<custom name="custom: Notes &lt;b&gt;bold&lt;/b&gt;">=1+1</custom>', $xml);
        self::assertStringContainsString('<tag>gradient</tag>', $xml);

        self::assertStringStartsWith('PK', $this->download($browser, self::RESULTS . 'xlsx'));

        foreach ([$csv, $xml, json_encode($rows, JSON_THROW_ON_ERROR)] as $file) {
            self::assertStringNotContainsString('partner-only-words', $file);
            self::assertStringNotContainsString('Notes of the partner', $file);
            self::assertStringNotContainsString('Archived and empty', $file);
            self::assertStringNotContainsString('price_paid', $file);
        }
    }

    public function testTheLibraryFileCarriesTheFieldsForCollectionPuzzlesOnCollectionItemsOnly(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());

        $price = $scenario->readyMade(self::PLAYER, StandardCustomField::PricePaid);
        $condition = $scenario->readyMade(self::PLAYER, StandardCustomField::Condition);
        $quality = $scenario->readyMade(self::PLAYER, StandardCustomField::Quality);
        $shelf = $scenario->field(self::PLAYER, 'Shelf "A"', forResults: false, forCollectionItems: true);
        // Not a column of this file: a field for results only
        $scenario->field(self::PLAYER, 'Notes on a solve');
        $scenario->setItemValues(CollectionItemFixture::ITEM_04, self::PLAYER, [$price => '12,5', $condition => 'like_new', $quality => '5', $shelf => '+top']);

        $labels = ['custom:price_paid', 'custom:condition', 'custom:quality', 'custom: Shelf "A"'];

        $json = $this->json($browser, self::LIBRARY . 'json');
        self::assertIsArray($json['collection_items'] ?? null);
        $items = self::rowsBy('collection_item_id', $json['collection_items']);
        self::assertCount(9, $items);
        self::assertSame(['custom:price_paid' => 12.5, 'custom:condition' => 'like_new', 'custom:quality' => 5, 'custom: Shelf "A"' => '+top'], self::customOf($items[CollectionItemFixture::ITEM_04]));
        self::assertSame(array_fill_keys($labels, null), self::customOf($items[CollectionItemFixture::ITEM_05]));
        self::assertSame('custom', array_key_last($items[CollectionItemFixture::ITEM_04]));

        // The other sections have no fields: a collection item is the only thing of the library that holds them
        foreach ($json as $section => $rows) {
            if ($section === 'collection_items' || $section === 'export' || is_array($rows) === false) {
                continue;
            }

            foreach ($rows as $row) {
                self::assertIsArray($row);
                self::assertArrayNotHasKey('custom', $row, (string) $section);
            }
        }

        // The format's version is what it was: columns were added, none renamed or removed
        self::assertIsArray($json['export'] ?? null);
        self::assertSame(1, $json['export']['format_version']);

        $xml = $this->download($browser, self::LIBRARY . 'xml');
        self::assertStringContainsString('<custom name="custom:price_paid">12.5</custom>', $xml);
        self::assertStringContainsString('<custom name="custom: Shelf &quot;A&quot;">+top</custom>', $xml);
        self::assertStringNotContainsString('Notes on a solve', $xml);

        self::assertStringStartsWith('PK', $this->download($browser, self::LIBRARY . 'xlsx'));
        self::assertStringStartsWith('PK', $this->download($browser, self::LIBRARY . 'csv'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideExports(): iterable
    {
        yield 'the results file' => [self::RESULTS];
        yield 'the library file' => [self::LIBRARY];
    }

    #[DataProvider('provideExports')]
    public function testThePlayersOwnFieldsCostTwoStatementsWhateverTheyHold(string $url): void
    {
        $without = $this->countStatements($url . 'json', static fn (): null => null);

        // A field and no value: the fields statement and the values statement
        $withField = $this->countStatements($url . 'json', static function (CustomFieldsScenario $scenario): void {
            $scenario->field(self::PLAYER, 'Notes', forResults: true, forCollectionItems: true);
        });

        // Many fields, values on many rows: the same
        $withMany = $this->countStatements($url . 'json', static function (CustomFieldsScenario $scenario): void {
            $fields = [];

            foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $name) {
                $fields[] = $scenario->field(self::PLAYER, $name, forResults: true, forCollectionItems: true);
            }

            foreach ([CollectionItemFixture::ITEM_04, CollectionItemFixture::ITEM_05, CollectionItemFixture::ITEM_06, CollectionItemFixture::ITEM_07, CollectionItemFixture::ITEM_09] as $itemId) {
                $scenario->setItemValues($itemId, self::PLAYER, array_fill_keys($fields, 'value'));
            }

            foreach ([2, 4, 6, 8] as $daysAgo) {
                $timeId = $scenario->result(self::PLAYER_USER, time: sprintf('0%d:00:00', $daysAgo), daysAgo: $daysAgo);
                $scenario->setValues($timeId, self::PLAYER, array_fill_keys($fields, 'value'));
            }
        });

        // One statement for a player without any field (the fields), two with fields (+ the values) - constant
        self::assertSame($withField, $withMany, $url . ': more fields and values must not cost more statements');
        self::assertSame(1, $withField - $without, $url . ': a player with fields pays one statement more than one without');
    }

    public function testThePlayerWithoutFieldsPaysOneStatementAndGetsTheFilesAsTheyWere(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        foreach ([self::RESULTS, self::LIBRARY] as $url) {
            $this->startCountingQueries($browser);
            $content = $this->download($browser, $url . 'json');

            self::assertStringNotContainsString('"custom"', $content);
            $ownFields = array_values(array_filter($this->executedSql($browser), static fn (string $sql): bool => str_contains($sql, 'custom_field') || str_contains($sql, 'custom_values')));
            self::assertCount(1, $ownFields, $url);
            self::assertStringContainsString('player_custom_field', $ownFields[0]);
        }
    }

    /**
     * @param callable(CustomFieldsScenario): (null|void) $arrange
     */
    private function countStatements(string $url, callable $arrange): int
    {
        self::ensureKernelShutdown();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $arrange(new CustomFieldsScenario(self::getContainer()));

        // Once before counting: what a first request of a session pays is nothing of the export
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }

    private function download(KernelBrowser $browser, string $url): string
    {
        $browser->request('GET', $url);

        self::assertResponseIsSuccessful($url);
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));

        return (string) $browser->getResponse()->getContent();
    }

    /**
     * @param array<mixed> $rows
     * @return array<string, array<array-key, mixed>> the rows by the id in the given column
     */
    private static function rowsBy(string $idColumn, array $rows): array
    {
        $byId = [];

        foreach ($rows as $row) {
            self::assertIsArray($row);
            $id = $row[$idColumn] ?? null;
            self::assertIsString($id);
            $byId[$id] = $row;
        }

        return $byId;
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<array-key, mixed> the row's own fields
     */
    private static function customOf(array $row): array
    {
        $custom = $row['custom'] ?? null;
        self::assertIsArray($custom);

        return $custom;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function json(KernelBrowser $browser, string $url): array
    {
        $decoded = json_decode($this->download($browser, $url), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
