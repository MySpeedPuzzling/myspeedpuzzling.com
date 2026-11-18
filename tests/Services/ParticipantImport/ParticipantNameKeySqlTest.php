<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\ParticipantImport;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantNameKey;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ParticipantNameKey::sql() is the SQL twin of ParticipantNameKey::of() (docs/features/competitions-management/
 * participant-linking.md §3.5): the import matches names in PHP, the link suggestions and the join page in Postgres -
 * both must call the same spellings one name. Over the names people are called (diacritics of the site's languages,
 * apostrophes and dashes of every kind, odd whitespace), and the index `custom_player_name_key` is built from exactly
 * this expression - a query using it is answered from the index.
 */
final class ParticipantNameKeySqlTest extends KernelTestCase
{
    private const string MIGRATION = __DIR__ . '/../../../migrations/Version20261118120000.php';

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'curly apostrophe, hyphen, diacritics' => ["Zo\u{00EB} O\u{2019}Brien-Nov\u{00E1}k", 'zoe obrien-novak'];
        yield 'case and whitespace' => ['  j' . "\u{00E1}" . 'ne   UNCONNECTED ', 'jane unconnected'];
        yield 'Polish' => ["\u{0141}ukasz \u{017B}\u{00F3}\u{0142}\u{0107}", 'lukasz zolc'];
        yield 'German sharp s and umlaut' => ["J\u{00FC}rgen Gro\u{00DF}", 'jurgen gross'];
        yield 'Danish' => ["S\u{00F8}ren \u{00D8}rsted", 'soren orsted'];
        yield 'straight apostrophe and backtick' => ["D'Angelo O`Neil", 'dangelo oneil'];
        yield 'acute accent typed for an apostrophe' => ["Peggy\u{00B4}s Time", 'peggys time'];
        yield 'hyphen character' => ["Anne\u{2010}Marie Lef\u{00E8}vre", 'anne-marie lefevre'];
        yield 'en dash' => ["zoe o'brien\u{2013}novak", 'zoe obrien-novak'];
        yield 'em dash' => ["Jean\u{2014}Luc Picard", 'jean-luc picard'];
        yield 'French and Turkish cedilla' => ["Fran\u{00E7}ois \u{00C7}elik", 'francois celik'];
        yield 'Czech' => ["Kate\u{0159}ina Dvo\u{0159}\u{00E1}kov\u{00E1}", 'katerina dvorakova'];
        yield 'Slovak' => ["\u{010E}uro \u{0164}a\u{017E}k\u{00FD}", 'duro tazky'];
        yield 'Portuguese' => ["\u{00C2}ngela Concei\u{00E7}\u{00E3}o", 'angela conceicao'];
        yield 'Hungarian' => ["M\u{00E1}ria Kov\u{00E1}cs", 'maria kovacs'];
        yield 'ligatures of names' => ["\u{00C6}sa \u{0152}uvre", 'aesa oeuvre'];
        yield 'Serbian / Croatian d with stroke' => ["\u{0110}or\u{0111}e \u{0110}uki\u{0107}", 'dorde dukic'];
        yield 'no-break space and a tab' => ["Sea\u{00A0}Shells\tTab", 'sea shells tab'];
        yield 'zero-width space' => ["Zero\u{200B}Width", 'zerowidth'];
        yield 'soft hyphen' => ["Kim\u{00AD}berly Example", 'kimberly example'];
    }

    #[DataProvider('names')]
    public function testTheDatabaseGivesTheKeyPhpGives(string $name, string $key): void
    {
        self::assertSame($key, ParticipantNameKey::of($name), 'the PHP key');

        $sql = $this->database->fetchOne('SELECT ' . ParticipantNameKey::sql('CAST(:name AS TEXT)'), ['name' => $name]);

        self::assertSame($key, $sql, 'the SQL key');
    }

    public function testTheIndexIsBuiltFromTheExpression(): void
    {
        // The migration's constant, read as text - migrations are not autoloaded
        self::assertSame(1, preg_match("/NAME_KEY = <<<'SQL'\n(.+)\nSQL;/", (string) file_get_contents(self::MIGRATION), $match));
        self::assertSame(ParticipantNameKey::sql('name'), $match[1]);
    }

    public function testAMatchOnTheKeyUsesTheIndex(): void
    {
        $key = ParticipantNameKey::sql('player.name');

        $this->database->executeStatement('SET enable_seqscan = off');

        try {
            $lines = $this->database->fetchFirstColumn("EXPLAIN SELECT id FROM player WHERE {$key} = 'zoe obrien-novak'");
            $plan = implode("\n", array_map(static fn (mixed $line): string => is_string($line) ? $line : '', $lines));
        } finally {
            $this->database->executeStatement('RESET enable_seqscan');
        }

        self::assertStringContainsString('custom_player_name_key', $plan);
    }
}
