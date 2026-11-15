<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value\DataImport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\DataImport\ImportRowOrigin;

/**
 * docs/features/data-import/pairing-screens.md "The way back": the `?return=` of a link into a row's editor is taken
 * only when it is a screen of this very import - and is named after that screen.
 */
final class ImportRowOriginTest extends TestCase
{
    private const string IMPORT = '01937f5e-0000-7000-8000-00000000c001';
    private const string OTHER = '01937f5e-0000-7000-8000-00000000c002';
    private const string ROW = '01937f5e-0000-7000-8000-00000000c003';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function screens(): iterable
    {
        $import = '/en/import/' . self::IMPORT;

        yield 'where you are' => [$import, 'overview'];
        yield 'rows we could not read, a page and a group' => [$import . '/to-fix?page=2#group-0123456789abcdef0123456789abcdef', 'to_fix'];
        yield 'how we read your file' => [$import . '/columns', 'check'];
        yield "a sheet's rows" => [$import . '/sheets/' . self::ROW . '/need-a-look?page=3', 'sheet'];
        yield 'people' => [$import . '/people', 'people'];
        yield 'puzzles' => [$import . '/puzzles', 'puzzles'];
        yield 'a list of puzzles' => [$import . '/puzzles/probably?page=2#puzzle-' . self::ROW, 'puzzles'];
        yield 'one puzzle of the file' => [$import . '/puzzle/' . self::ROW . '?flow=choose&done=3', 'puzzle'];
        yield 'check and add' => [$import . '/add', 'add'];
        yield 'what will be added, filtered, at a row' => [$import . '/add/list?sheet=' . self::ROW . '&page=2#row-' . self::ROW, 'add_list'];
        yield 'unusual times' => [$import . '/add/times', 'add_times'];
        yield 'rows that wait' => [$import . '/not-added/times', 'not_added'];
        yield 'what was added' => [$import . '/added', 'added'];
        yield 'a screen built later' => [$import . '/something-new', 'other'];
        yield 'another language' => ['/cs/import/' . self::IMPORT . '/people', 'people'];
        yield 'the id written in capitals' => ['/en/import/' . strtoupper(self::IMPORT) . '/people', 'people'];
    }

    #[DataProvider('screens')]
    public function testAScreenOfTheImportIsTheWayBackAndIsNamed(string $return, string $screen): void
    {
        $origin = ImportRowOrigin::tryFrom($return, self::IMPORT);

        self::assertNotNull($origin);
        self::assertSame([$return, $screen], [$origin->url, $origin->screen]);
    }

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function refused(): iterable
    {
        $import = '/en/import/' . self::IMPORT;

        yield 'nothing' => [null];
        yield 'empty' => [''];
        yield 'another site' => ['https://evil.example/en/import/' . self::IMPORT];
        yield 'another site, scheme-relative' => ['//evil.example/en/import/' . self::IMPORT];
        yield 'another site, by a backslash' => ['/\\evil.example/en/import/' . self::IMPORT];
        yield 'another site, encoded' => ['%2F%2Fevil.example'];
        yield 'a script' => ['javascript:alert(1)'];
        yield 'a new line' => [$import . "/to-fix\r\nLocation: https://evil.example"];
        yield 'another import' => ['/en/import/' . self::OTHER . '/to-fix'];
        yield 'the imports home' => ['/en/import'];
        yield 'another page of the site' => ['/en/puzzle/' . self::ROW];
        yield 'out of the import by dots' => [$import . '/../../admin'];
        yield 'out of the import by encoded dots' => [$import . '/%2e%2e/%2e%2e/admin'];
        yield 'an encoded slash' => [$import . '%2Fto-fix'];
        yield 'a prefix only' => [$import . 'x/to-fix'];
        yield 'a language nobody has' => ['/xx/import/' . self::IMPORT . '/to-fix'];
        yield "a row's editor - never the way back from one" => [$import . '/rows/' . self::ROW];
        yield "a row's editor with a way back of its own" => [$import . '/rows/' . self::ROW . '?return=' . rawurlencode($import . '/to-fix')];
    }

    #[DataProvider('refused')]
    public function testAnythingElseIsNoWayBack(null|string $return): void
    {
        self::assertNull(ImportRowOrigin::tryFrom($return, self::IMPORT));
    }
}
