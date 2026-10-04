<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use IntlChar;
use Normalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleSearchKeys;
use SpeedPuzzling\Web\Value\SearchText;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The puzzle lists filter in the browser (collection_filter_controller.js): PHP renders every puzzle's stored search
 * keys, the browser folds only what is typed (assets/search_fold.js). Both folds must read every text the same, or a
 * typed name stops finding its puzzle. Runs the real module under node against SearchText::fold().
 *
 * What the browser cannot mirror: a text that is not valid Unicode (PHP's mb_scrub() turns invalid UTF-8 into "?",
 * a browser string holds lone surrogates as they are - neither comes out of a text field), and letters newer than the
 * Unicode version of PHP's ICU, which a newer browser may already fold (PHP's ICU 72 = Unicode 15.0, the base image's
 * node ICU 78 = Unicode 17.0).
 */
final class SearchFoldParityTest extends TestCase
{
    private const string CORPUS = __DIR__ . '/search-fold-corpus.txt';

    public function testTheBrowserFoldsTheCorpusLikePhp(): void
    {
        $texts = self::corpus();

        // Fixture names, every language sample, the full-width and invisible characters, emoji ...
        self::assertGreaterThan(100, count($texts));
        self::assertSame(self::folded($texts), $this->runInNode($texts, [])['folded']);
    }

    /**
     * ICU's Latin-ASCII is a table the browser carries for the letters it does not reduce by dropping accents - every
     * Latin letter, and every character that turns into one (NFKC: ℏ, 𝚤, full-width letters), must come out the same.
     */
    public function testTheBrowserFoldsEveryLatinLetterLikePhp(): void
    {
        $texts = [];

        for ($codePoint = 0; $codePoint <= 0x1FFFF; $codePoint++) {
            if ($codePoint >= 0xD800 && $codePoint <= 0xDFFF || IntlChar::isdefined($codePoint) === false) {
                continue;
            }

            $character = (string) IntlChar::chr($codePoint);

            if (preg_match('/\p{Latin}/u', (string) Normalizer::normalize($character, Normalizer::NFKC)) === 1) {
                $texts[] = $character;
            }
        }

        self::assertGreaterThan(1500, count($texts));
        self::assertSame(self::folded($texts), $this->runInNode($texts, [])['folded']);
    }

    /**
     * @param list<string> $queries
     */
    #[DataProvider('provideQueries')]
    public function testTypedTextIsFoundInTheStoredKeys(array $queries, bool $found): void
    {
        $key = PuzzleSearchKeys::names('Puzzle 7', new PuzzleNames([
            new PuzzleName('Kouzelná zahrada', 'cs'),
            new PuzzleName('Zauberhafter Garten', 'de'),
            new PuzzleName('魔法の庭', 'ja'),
        ])) . PuzzleSearchKeys::codes('4005556174812, 04005556197484', 'RB-1000-001, 19748-2');

        $matches = $this->runInNode([], array_map(
            static fn (string $query): array => ['query' => $query, 'key' => $key],
            $queries,
        ))['matches'];

        self::assertSame(array_fill(0, count($queries), $found), $matches, implode(' | ', $queries));
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function provideQueries(): iterable
    {
        yield 'nothing typed' => [['', '   ', "\u{200B}"], true];
        yield 'the main title' => [['puzzle 7', 'PUZZLE 7', 'zzle', 'Ｐｕｚｚｌｅ　７'], true];
        yield 'an other name, accents or not' => [['Kouzelná', 'kouzelna zahrada', 'KOUZELNÁ ZAHRADA', 'garten', 'zauberhafter garten'], true];
        yield 'Japanese' => [['魔法', '魔法の庭'], true];
        yield 'barcode' => [['4005556174812', '04005556174812', '4005556-174812', '4 005556 174812', '4005556197484', '5556197'], true];
        yield 'brand code as printed' => [['RB-1000-001', 'rb-1000', 'rb1000', '19748-2', '197482', '1000-001'], true];
        yield 'words from two names' => [['zahrada garten', 'puzzle kouzelna'], false];
        yield 'something else' => [['magic', 'puzzle 8', '4005556174813', 'kouzelny', '%', '_', 'e:4005556174812x'], false];
    }

    public function testApostrophesAreNeverNeededToFindAName(): void
    {
        $key = PuzzleSearchKeys::names('Where´s Wally?', new PuzzleNames([new PuzzleName('Peggy’s Riverside', null)]));
        $queries = ['wheres wally', "where's", 'WHERE’S WALLY?', 'where´s', "peggy's", 'peggys riverside', 'where s'];

        $matches = $this->runInNode([], array_map(
            static fn (string $query): array => ['query' => $query, 'key' => $key],
            $queries,
        ))['matches'];

        self::assertSame([true, true, true, true, true, true, false], $matches);
    }

    /**
     * @return list<string>
     */
    private static function corpus(): array
    {
        $lines = file(self::CORPUS, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        $texts = [];

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '# ')) {
                continue;
            }

            $texts[] = (string) preg_replace_callback(
                '/\\\\u\{([0-9A-F]{4,6})\}/',
                static fn (array $match): string => (string) IntlChar::chr((int) hexdec($match[1])),
                $line,
            );
        }

        return $texts;
    }

    /**
     * @param list<string> $texts
     *
     * @return list<string>
     */
    private static function folded(array $texts): array
    {
        return array_map(SearchText::fold(...), $texts);
    }

    /**
     * @param list<string> $texts
     * @param list<array{query: string, key: string}> $matches
     *
     * @return array{folded: list<string>, matches: list<bool>}
     */
    private function runInNode(array $texts, array $matches): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/search-fold-harness.mjs']);
        $process->setInput(json_encode(['texts' => $texts, 'matches' => $matches], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array{folded: list<string>, matches: list<bool>} $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
