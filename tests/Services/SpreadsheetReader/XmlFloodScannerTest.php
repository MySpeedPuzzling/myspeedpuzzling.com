<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SpreadsheetReader;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx\XmlFloodScanner;

/**
 * The scanner against the parser it guards. Whatever the scanner lets through, the parser must find nothing in that
 * is over the scanner's caps - for thousands of generated documents that try to hide markup from it: in attribute
 * values of both quote kinds, comments, CDATA sections, processing instructions, behind entities, cut at every byte.
 */
final class XmlFloodScannerTest extends TestCase
{
    private const int DOCUMENTS = 3_000;

    /** Names the scanner knows by heart and does not count; the generator uses a few of them */
    private const int UNCOUNTED_NAMES = 200;

    public function testWhatTheScannerLetsThroughTheParserFindsWithinTheCaps(): void
    {
        $random = new Randomizer(new Mt19937(20261114));
        $passed = 0;
        $refused = [];
        $elements = 0;

        for ($number = 0; $number < self::DOCUMENTS; $number++) {
            $document = self::document($random);
            // The stream filter's chunk, a small one, and now and then every byte for itself
            $sizes = [8192, $random->getInt(1, 300), $random->getInt(2, 40)];

            if (strlen($document) < 3_000 && $number % 5 === 0) {
                $sizes[] = 1;
            }

            $verdict = self::scanned($document, $sizes[0]);

            // The scanner reads a stream: where the stream is cut is none of the document's business
            foreach (array_slice($sizes, 1) as $size) {
                self::assertSame($verdict, self::scanned($document, $size), sprintf("Document %d is judged differently in chunks of %d bytes:\n%s", $number, $size, substr($document, 0, 2_000)));
            }

            if ($verdict !== null) {
                $refused[$verdict] = ($refused[$verdict] ?? 0) + 1;

                continue;
            }

            $passed++;
            $elements += $this->assertParserFindsNothingOverTheCaps($document, $number);
        }

        // The generator is neither tame nor wild: both sides are well tried
        self::assertGreaterThan(self::DOCUMENTS / 5, $passed);
        self::assertGreaterThan(self::DOCUMENTS / 5, array_sum($refused));
        self::assertGreaterThan(5 * $passed, $elements);

        foreach ([XmlFloodScanner::PROCESSING_INSTRUCTION, XmlFloodScanner::TOO_MANY_ATTRIBUTES, XmlFloodScanner::TOO_MANY_NAMESPACES, XmlFloodScanner::TOO_MANY_NAMES, XmlFloodScanner::NOT_XML] as $reason) {
            self::assertGreaterThan(10, $refused[$reason] ?? 0, $reason);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hidingPlaces(): iterable
    {
        $flood = '<flood';

        for ($number = 0; $number < 5_000; $number++) {
            $flood .= ' a' . $number . '=""';
        }

        $flood .= '/>';
        $namespaces = '';

        for ($level = 0; $level < 50; $level++) {
            $namespaces .= '<g xmlns:p' . $level . '="urn:made-up" xmlns:q' . $level . '="urn:made-up">';
        }

        // A parser ends a processing instruction where it first closes - whatever stands in it
        yield 'a tag behind a comment opened inside a processing instruction' => ['<worksheet><?a <!-- ?>' . $flood . '<?b --> ?><sheetData/></worksheet>', XmlFloodScanner::PROCESSING_INSTRUCTION];
        yield 'namespace declarations behind the same' => ['<worksheet><?a <!-- ?>' . $namespaces . '<?b --> ?><sheetData/></worksheet>', XmlFloodScanner::PROCESSING_INSTRUCTION];
        yield 'a tag behind a CDATA section opened inside a processing instruction' => ['<worksheet><?a <![CDATA[ ?>' . $flood . '<?b ]]> ?><sheetData/></worksheet>', XmlFloodScanner::PROCESSING_INSTRUCTION];
        yield 'a document type behind the same' => ['<?xml version="1.0"?><?a <!-- ?><!DOCTYPE x [<!ENTITY e "v">]><?b --> ?><worksheet/>', XmlFloodScanner::PROCESSING_INSTRUCTION];
        yield 'a processing instruction of any kind' => ['<worksheet><?made-up instruction="1"?><sheetData/></worksheet>', XmlFloodScanner::PROCESSING_INSTRUCTION];
        yield 'a second XML declaration' => ['<?xml version="1.0"?><worksheet><?xml version="1.0"?></worksheet>', XmlFloodScanner::PROCESSING_INSTRUCTION];
        yield 'a processing instruction instead of the declaration' => ['<?xml-stylesheet href="x"?><worksheet/>', XmlFloodScanner::PROCESSING_INSTRUCTION];
        yield 'a declaration that holds more than a declaration' => ['<?xml version="1.0" <!-- ?>' . $flood . '<worksheet/>', XmlFloodScanner::NOT_XML];
        yield 'a tag behind a comment that an attribute value seems to open' => ['<worksheet><a b="<!--">' . $flood . '<c d="-->"/></a></worksheet>', XmlFloodScanner::NOT_XML];
        yield 'a tag after a comment that ended early' => ['<worksheet><!-- a --> b -->' . $flood . '</worksheet>', XmlFloodScanner::TOO_MANY_ATTRIBUTES];
        yield 'a tag after a CDATA section that ended early' => ['<worksheet><![CDATA[ a ]]> b ]]>' . $flood . '</worksheet>', XmlFloodScanner::TOO_MANY_ATTRIBUTES];
        yield 'a tag after a quote in a text' => ['<worksheet>it\'s "text" ' . $flood . '</worksheet>', XmlFloodScanner::TOO_MANY_ATTRIBUTES];
        yield 'a tag after an attribute value with a ">" and the other quote' => ['<worksheet><a b=\'>"\' c=">\'"/>' . $flood . '</worksheet>', XmlFloodScanner::TOO_MANY_ATTRIBUTES];
        yield 'a tag whose attributes come in single quotes' => ['<worksheet>' . str_replace('""', "''", $flood) . '</worksheet>', XmlFloodScanner::TOO_MANY_ATTRIBUTES];
        yield 'a tag under a prefix' => ['<worksheet>' . str_replace('<flood', '<x:c', $flood) . '</worksheet>', XmlFloodScanner::TOO_MANY_ATTRIBUTES];
        yield 'a document type' => ['<!DOCTYPE worksheet [<!ENTITY e "v">]><worksheet>&e;</worksheet>', XmlFloodScanner::NOT_XML];
        yield 'a document type after the declaration' => ['<?xml version="1.0"?>' . "\n" . '<!DOCTYPE worksheet SYSTEM "x.dtd"><worksheet/>', XmlFloodScanner::NOT_XML];
        yield 'a "<" in an attribute value' => ['<worksheet><a b="<c"/></worksheet>', XmlFloodScanner::NOT_XML];
        yield 'a quote where no value stands' => ['<worksheet><a "b>' . $flood . '"/></worksheet>', XmlFloodScanner::NOT_XML];
        yield 'an end tag with more than a name' => ['<worksheet><a></a b="\'>' . $flood . '\'"></worksheet>', XmlFloodScanner::NOT_XML];
        yield 'EBCDIC' => ["\x4C\x6F\xA7\x94\x93\x40\xA5\x85\x99\xA2\x89\x96\x95\x7E\x7F\xF1\x4B\xF0\x7F\x6F\x6E\x4C\x81\x61\x6E", XmlFloodScanner::NOT_UTF8];
        yield 'UTF-16 with a mark' => ["\xFF\xFE" . mb_convert_encoding('<worksheet/>', 'UTF-16LE', 'UTF-8'), XmlFloodScanner::NOT_UTF8];
        yield 'UTF-16 without a mark' => [mb_convert_encoding('<worksheet/>', 'UTF-16BE', 'UTF-8'), XmlFloodScanner::NOT_UTF8];
        yield 'UTF-32' => [mb_convert_encoding('<worksheet/>', 'UTF-32LE', 'UTF-8'), XmlFloodScanner::NOT_UTF8];
        yield 'a declared encoding that is not UTF-8' => ['<?xml version="1.0" encoding="ISO-8859-1"?><worksheet/>', XmlFloodScanner::NOT_UTF8];
        yield 'text before the first tag' => ['  <worksheet/>', XmlFloodScanner::NOT_XML];
        yield 'a comment of more than a comment' => ['<worksheet><!--' . str_repeat('-', XmlFloodScanner::MAX_COMMENT_BYTES + 1) . '--></worksheet>', XmlFloodScanner::COMMENT_TOO_LONG];
        yield 'a tag of more than a megabyte' => ['<worksheet><a b="' . str_repeat('x', XmlFloodScanner::MAX_TAG_BYTES) . '"/></worksheet>', XmlFloodScanner::TAG_TOO_LONG];
        yield 'a text of more than six cells\' worth' => ['<worksheet><t>' . str_repeat('&amp;', 4 * 131_072) . '</t></worksheet>', XmlFloodScanner::TEXT_TOO_LONG];
        yield 'a CDATA section of the same' => ['<worksheet><t><![CDATA[' . str_repeat('x', 6 * 131_072 + 1) . ']]></t></worksheet>', XmlFloodScanner::TEXT_TOO_LONG];
    }

    #[DataProvider('hidingPlaces')]
    public function testNothingHidesFromTheScanner(string $document, string $reason): void
    {
        $started = hrtime(true);

        foreach ([8192, 4_000, 300, 97, 7] as $size) {
            self::assertSame($reason, self::scanned($document, $size), sprintf('In chunks of %d bytes.', $size));
        }

        self::assertLessThan(5.0, (hrtime(true) - $started) / 1e9);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function honestDocuments(): iterable
    {
        yield 'no declaration' => ['<worksheet><sheetData/></worksheet>'];
        yield 'a declaration in single quotes after a mark' => ["\xEF\xBB\xBF<?xml version='1.0' encoding='utf-8' standalone='yes'?>\n<worksheet/>"];
        yield 'a declaration without an encoding' => ['<?xml version="1.0"?><worksheet/>'];
        yield 'US-ASCII' => ['<?xml version="1.0" encoding="US-ASCII"?><worksheet/>'];
        yield 'comments, CDATA and quotes everywhere' => ['<?xml version="1.0"?><!-- before --><worksheet a="it\'s" b=\'say "x"\' c="a > b" d="-->" e="?>" f="]]>"><!-- <flood a="1"> "quote \' --><t><![CDATA[<flood a="1"> <!-- <?pi?> ]]>]]&gt; ?&gt;</t>x = "y" > \'z\'</worksheet><!-- after -->'];
        yield 'a start tag as long as a megabyte, almost' => ['<worksheet><conditionalFormatting sqref="' . str_repeat('A1:B2 ', 170_000) . '" pivot="0"/></worksheet>'];
        yield 'a text as long as a cell of nothing but ampersands' => ['<worksheet><t>' . str_repeat('&amp;', 32_767) . '</t><t>' . str_repeat('&quot;', 32_767) . '</t></worksheet>'];
        yield 'sixty-four attributes and sixty-four namespaces' => ['<worksheet ' . implode(' ', array_map(static fn (int $n): string => 'xmlns:p' . $n . '="urn:made-up"', range(1, 64))) . '><a ' . implode(' ', array_map(static fn (int $n): string => 'a' . $n . "='1'", range(1, 64))) . '/></worksheet>'];
        yield 'two thousand names' => ['<worksheet>' . implode('', array_map(static fn (int $n): string => '<n' . $n . '/>', range(1, 1_999))) . '</worksheet>'];
        yield 'many comments, none long' => ['<worksheet>' . str_repeat('<!--' . str_repeat('x', 1_000) . '-->', 500) . '</worksheet>'];
        yield 'white space where a tag may have it' => ["<worksheet\n  a = \"1\"\n\tb\t=\t'2'\r\n><c\n/></worksheet\n>"];
    }

    #[DataProvider('honestDocuments')]
    public function testWhatAWorkbookMayHoldPassesHoweverTheStreamIsCut(string $document): void
    {
        foreach ([8192, 4_000, 300, 97, 7] as $size) {
            self::assertNull(self::scanned($document, $size), sprintf('In chunks of %d bytes.', $size));
        }

        if (strlen($document) < 5_000) {
            self::assertNull(self::scanned($document, 1));
        }

        $this->assertParserFindsNothingOverTheCaps($document, 0);
    }

    /**
     * @return null|string why the scanner refuses the document, fed in chunks of a size
     */
    private static function scanned(string $document, int $chunkBytes): null|string
    {
        $scanner = new XmlFloodScanner(131_072);

        for ($at = 0, $length = strlen($document); $at < $length; $at += $chunkBytes) {
            $reason = $scanner->scan(substr($document, $at, $chunkBytes));

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Reads a document the scanner let through with the parser, as far as the parser reads it.
     *
     * @return int how many elements the parser found
     */
    private function assertParserFindsNothingOverTheCaps(string $document, int $number): int
    {
        $internal = libxml_use_internal_errors(true);
        $elements = 0;
        $namespaces = 0;
        $names = [];

        try {
            $reader = \XMLReader::fromString($document, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT);

            while (@$reader->read()) {
                $shown = sprintf("Document %d:\n%s", $number, substr($document, 0, 2_000));
                self::assertNotContains($reader->nodeType, [\XMLReader::DOC_TYPE, \XMLReader::PI, \XMLReader::ENTITY_REF], $shown);

                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }

                $elements++;
                $names[$reader->name] = true;
                self::assertLessThanOrEqual(XmlFloodScanner::MAX_ATTRIBUTES, $reader->attributeCount, $shown);

                while ($reader->moveToNextAttribute()) {
                    $names[$reader->name] = true;
                    $namespaces += $reader->name === 'xmlns' || str_starts_with($reader->name, 'xmlns:') ? 1 : 0;
                }

                $reader->moveToElement();
                self::assertLessThanOrEqual(XmlFloodScanner::MAX_NAMESPACES, $namespaces, $shown);
                self::assertLessThanOrEqual(XmlFloodScanner::MAX_NAMES + self::UNCOUNTED_NAMES, count($names), $shown);
            }

            $reader->close();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($internal);
        }

        return $elements;
    }

    /**
     * A document that is mostly a workbook part and now and then tries something.
     */
    private static function document(Randomizer $random): string
    {
        $pick = static function (array $from) use ($random): string {
            $picked = $from[$random->getInt(0, count($from) - 1)] ?? '';

            return is_string($picked) ? $picked : '';
        };
        $chance = static fn (int $in): bool => $random->getInt(1, $in) === 1;
        $values = ['1', 'A1', 'a > b', 'x', '', '-->', '?>', ']]>', '>', 'http://made-up.test/?a=1&amp;b=2', '&lt;c a=&quot;1&quot;&gt;', '&#60;!--', '<!--', '<?', '<![CDATA[', 'a <b'];
        $attribute = static function (string $name) use ($random, $pick, $values, $chance): string {
            $value = $pick($values);

            // The first values are harmless; the last four hold a "<", which no attribute value may
            if ($chance(3) === false) {
                $value = $values[$random->getInt(0, 10)];
            }

            return $chance(3)
                ? sprintf(" %s='%s'", $name, $chance(4) ? $value . ' "quoted"' : $value)
                : sprintf(' %s="%s"', $name, $chance(4) ? $value . " it's" : $value);
        };
        $element = static function (string $name, int $attributes, string $inside = '') use ($random, $attribute, $chance): string {
            $tag = '<' . $name;

            for ($number = 0; $number < $attributes; $number++) {
                // A tag of many attributes is a flood and nothing else: its values are harmless
                $tag .= $attributes > 30
                    ? sprintf($chance(2) ? ' a%d="%d"' : " a%d='%d'", $number, $random->getInt(0, 9))
                    : $attribute(($chance(2) ? 'a' : 'made:up') . $number);
            }

            return $inside === '' && $chance(2) ? $tag . ($chance(3) ? ' />' : '/>') : $tag . '>' . $inside . '</' . $name . ($chance(5) ? ' >' : '>');
        };
        $flood = static fn (): string => $element($pick(['flood', 'c', 'x:c', 'row']), $random->getInt(65, 140));
        $text = ['plain text', 'a > b', 'say "hi" &amp; \'bye\'', ']] >', 'x = "y"', "\n  ", '&lt;c a=&quot;1&quot;&gt;', '&#60;!-- &#62;', '-->', '?>', "it's", '"'];
        $inComment = ['plain', '<flood a="1">', 'a "quote', "it's", '<?pi?>', '<![CDATA[', ' - ', '>', ']]>', '<!- -', '?>'];
        $inCdata = ['<c a="1">', '<!-- not a comment -->', '<?pi?>', 'x "y', ']] >', '&amp;', "it's", '-->'];

        $document = $pick(['', '', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n", "\xEF\xBB\xBF<?xml version='1.0'?>", '<?xml version="1.0"?>']);
        $document .= '<worksheet xmlns="urn:made-up" xmlns:x="urn:made-up">';

        for ($pieces = $random->getInt(1, 14); $pieces > 0; $pieces--) {
            $document .= match ($random->getInt(0, 21)) {
                0, 1 => $pick($text),
                2, 3, 4 => $element($pick(['c', 'v', 'row', 'f', 't']), $random->getInt(0, 2), $chance(2) ? $pick($text) : ''),
                5, 6, 7 => $element($pick(['g', 'made-up', 'x:c', 'sheetPr', 'n' . $random->getInt(0, 50)]), $random->getInt(0, 6), $chance(3) ? $element('v', 0, $pick($text)) : ''),
                8, 9 => '<!--' . $pick($inComment) . ($chance(3) ? $pick($inComment) : '') . '-->',
                10, 11 => '<t><![CDATA[' . $pick($inCdata) . ($chance(3) ? $pick($inCdata) : '') . ']]></t>',
                // A tag with more attributes than a tag has, in the open
                12 => $chance(3) ? $flood() : $element('g', $random->getInt(40, 64)),
                // … behind a processing instruction that seems to open a comment or a CDATA section
                13 => '<?' . $pick(['a', 'made-up', 'xml-x']) . ' ' . $pick(['<!--', '<![CDATA[', 'x="1"', '"', "'"]) . ' ?>' . ($chance(2) ? $flood() : '') . ($chance(2) ? '<?b ' . $pick(['-->', ']]>', '"']) . ' ?>' : ''),
                // … behind a comment or a CDATA section that has ended, or one a text or a value only seems to open
                14 => $pick(['<!-- a --> b -->', '<![CDATA[ a ]]> b ]]>', '&#60;!--', '<a b="-->" c=\'<!--\'/>', '<a b="&lt;!--"/>', 'it\'s "']) . ($chance(2) ? $flood() : '') . $pick(['', '-->', ']]>', '"']),
                // Namespace declarations, a handful or a flood
                15 => (static function () use ($random, $chance): string {
                    $tags = '';

                    for ($tag = $chance(4) ? $random->getInt(2, 4) : 1; $tag > 0; $tag--) {
                        $tags .= '<g';

                        for ($number = $random->getInt(1, 30); $number > 0; $number--) {
                            $tags .= sprintf(' xmlns:p%d="urn:made-up"', $random->getInt(0, 1_000_000));
                        }

                        $tags .= '/>';
                    }

                    return $tags;
                })(),
                // Distinct names, a handful or a flood
                16 => (static function () use ($random, $chance): string {
                    $tags = '';
                    $from = $random->getInt(0, 1_000_000);

                    for ($number = $chance(12) ? 2_100 : $random->getInt(1, 40); $number > 0; $number--) {
                        $tags .= $chance(2) ? '<n' . ($from + $number) . '/>' : '<g q' . ($from + $number) . '=""/>';
                    }

                    return $tags;
                })(),
                17 => $chance(3) ? '<!DOCTYPE x [<!ENTITY e "v">]>' : $pick(['<a b>', '< a/>', '<a b="c>', '<', '<a b=c/>', '</a "x">', '<a/ >', '<a b="1"c="2"/>', '<!x>', '<!-', '&e;']),
                18 => str_repeat('<g>', $depth = $random->getInt(1, 6)) . $pick($text) . str_repeat('</g>', $depth),
                19 => $element($pick(['c', 'made-up']), 1, $element('is', 0, $element('t', $chance(2) ? 1 : 0, $pick($text)))),
                default => $element('row', $random->getInt(0, 3), $element('c', $random->getInt(0, 3), $element('v', 0, (string) $random->getInt(0, 99_999)))),
            };
        }

        return $document . ($chance(20) ? '' : '</worksheet>');
    }
}
