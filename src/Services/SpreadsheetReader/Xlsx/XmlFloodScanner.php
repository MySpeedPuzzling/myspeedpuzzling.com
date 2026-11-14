<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

/**
 * Looks at every byte of a part **before the XML parser gets it** and stops the shapes the parser is slow on - where
 * no check in PHP would see them, because the time is spent inside one call of the parser (libxml 2.9: a start tag of
 * 300,000 attributes took 90 s, three million distinct names 117 s, 150,000 namespace declarations in scope 11 s for
 * every megabyte after them):
 *
 * - a tag longer than MAX_TAG_BYTES, or with more than MAX_ATTRIBUTES attributes;
 * - more than MAX_NAMESPACES namespace declarations in a part (a workbook's parts declare a handful, on their root);
 * - more than MAX_NAMES distinct element and attribute names in a part (a workbook's vocabulary is a few hundred);
 * - a text of more than a cell's size between two tags;
 * - a part that is not UTF-8 (so that what is scanned is what is parsed), and anything that is no tag, comment or
 *   CDATA section where one has to stand - a document type first of all.
 *
 * Constant memory: one unfinished tag at most is carried from chunk to chunk. Cheap: the tags a sheet is made of
 * (<c>, <v>, <row> … with the attributes they are known to have - a finite vocabulary, bounded by the pattern itself)
 * are taken out by one regular expression per chunk; only what is left is looked at tag by tag.
 */
final class XmlFloodScanner
{
    public const int MAX_TAG_BYTES = 8192;
    public const int MAX_ATTRIBUTES = 64;
    public const int MAX_NAMESPACES = 64;
    public const int MAX_NAMES = 2000;

    public const string TAG_TOO_LONG = 'a tag longer than a tag is';
    public const string TOO_MANY_ATTRIBUTES = 'a tag with more attributes than a tag has';
    public const string TOO_MANY_NAMESPACES = 'more namespace declarations than a part has';
    public const string TOO_MANY_NAMES = 'more distinct names than a part has';
    public const string TEXT_TOO_LONG = 'a text longer than a cell holds';
    public const string NOT_UTF8 = 'a part that is not UTF-8';
    public const string NOT_XML = 'markup that is no tag';

    private const int TEXT = 0;
    private const int COMMENT = 1;
    private const int CDATA = 2;

    /** Element names a sheet, its strings, styles and relationships are made of by the million */
    private const string COMMON_ELEMENTS = 'c|v|row|f|is|t|si|r|rPr|rPh|phoneticPr|b|i|u|strike|sz|color|rFont|family|scheme|charset|vertAlign|'
        . 'outline|shadow|condense|extend|xf|alignment|protection|Relationship|mergeCell|col|font|name|fill|patternFill|fgColor|bgColor|'
        . 'border|left|right|top|bottom|diagonal|numFmt|sheet|tableColumn';

    /** The attributes those elements have - every one a literal: nothing here lets a file make up names */
    private const string COMMON_ATTRIBUTES = 'r|s|t|cm|vm|ph|spans|customFormat|ht|hidden|customHeight|outlineLevel|collapsed|thickTop|thickBot|'
        . 'x14ac:dyDescent|ref|si|aca|ca|xml:space|val|theme|rgb|tint|indexed|auto|numFmtId|fontId|fillId|borderId|xfId|applyNumberFormat|'
        . 'applyFont|applyFill|applyBorder|applyAlignment|applyProtection|quotePrefix|pivotButton|horizontal|vertical|wrapText|textRotation|'
        . 'indent|shrinkToFit|readingOrder|locked|Id|Type|Target|TargetMode|min|max|width|style|bestFit|customWidth|patternType|formatCode|'
        . 'name|sheetId|state|r:id|sb|eb|type|id|uniqueName|dataDxfId|totalsRowFunction';

    /** An end tag (its name is the start tag's, or the parser stops there), or a common start tag */
    private const string COMMON_TAG = '~</[^<>\s]{1,255}+\s{0,8}+>|<(?:x:)?(?:' . self::COMMON_ELEMENTS . ')'
        . '(?:\s{1,8}+(?:' . self::COMMON_ATTRIBUTES . ')\s{0,2}+=\s{0,2}+"[^"<]{0,255}+"){0,32}+\s{0,8}+/?+>~';

    /** A whole tag from "<" on, quotes respected */
    private const string WHOLE_TAG = '~\G<(?:[^<>"\']++|"[^"]*+"|\'[^\']*+\')*+>~';

    /** Any tag: an end tag or a processing instruction, or a start tag with its name (1) and the rest of it (2) */
    private const string ANY_TAG = '~<(?:[/?](?:[^<>"\']++|"[^"]*+"|\'[^\']*+\')*+>|([^\s<>/"\'=!?]++)((?:[^<>"\']++|"[^"]*+"|\'[^\']*+\')*+)>)~';

    private const string ATTRIBUTE = '~([^\s=/"\']++)\s*+=\s*+(?:"[^"]*+"|\'[^\']*+\')~';

    private bool $started = false;
    private int $state = self::TEXT;
    /** An unfinished tag (or the first bytes of a comment's or CDATA section's opening) at the end of the last chunk */
    private string $carry = '';
    /** Inside a comment or a CDATA section: its last two bytes, where its end may have begun */
    private string $pending = '';
    private int $textRun = 0;
    private int $namespaces = 0;
    /** @var array<string, true> */
    private array $names = [];

    public function __construct(
        private readonly int $maxTextBytes,
    ) {
    }

    /**
     * @return null|string why the part is refused (one of the reasons above); null: the chunk may go to the parser
     */
    public function scan(string $chunk): null|string
    {
        if ($this->started === false) {
            $this->started = true;

            if (str_starts_with($chunk, "\xEF\xBB\xBF")) {
                $chunk = substr($chunk, 3);
            }

            // UTF-16 and UTF-32 begin with a byte-order mark or hold a NUL in their first character
            if (str_starts_with($chunk, "\xFF\xFE") || str_starts_with($chunk, "\xFE\xFF") || str_contains(substr($chunk, 0, 4), "\0")) {
                return self::NOT_UTF8;
            }

            if (str_starts_with($chunk, '<?xml')) {
                $end = strpos($chunk, '?>');

                if ($end === false || $end > 1024) {
                    return self::NOT_XML;
                }

                if (
                    preg_match('~\sencoding\s*=\s*["\']([^"\']*)["\']~', substr($chunk, 0, $end), $declared) === 1
                    && in_array(strtolower($declared[1]), ['utf-8', 'utf8', 'us-ascii', 'ascii'], true) === false
                ) {
                    return self::NOT_UTF8;
                }
            }
        }

        $buffer = $this->carry . $chunk;
        $this->carry = '';

        if ($this->state !== self::TEXT) {
            $search = $this->pending . $buffer;
            $end = strpos($search, $this->state === self::COMMENT ? '-->' : ']]>');

            if ($end === false) {
                $this->pending = substr($search, -2);

                if ($this->state === self::CDATA && ($this->textRun += strlen($buffer)) > $this->maxTextBytes) {
                    return self::TEXT_TOO_LONG;
                }

                return null;
            }

            $this->state = self::TEXT;
            $this->pending = '';
            $buffer = substr($search, $end + 3);
        }

        if (str_contains($buffer, '<!')) {
            $failure = $this->withoutCommentsAndCdata($buffer);

            if ($failure !== null) {
                return $failure;
            }
        }

        // What is left is text and tags. An unfinished tag at the end waits for the next chunk.
        $lastTag = strrpos($buffer, '<');

        if ($lastTag !== false && preg_match(self::WHOLE_TAG, $buffer, $whole, 0, $lastTag) !== 1) {
            $this->carry = substr($buffer, $lastTag);
            $buffer = substr($buffer, 0, $lastTag);

            if (strlen($this->carry) > self::MAX_TAG_BYTES) {
                return self::TAG_TOO_LONG;
            }
        }

        $firstTag = strpos($buffer, '<');

        if ($firstTag === false) {
            if (($this->textRun += strlen($buffer)) > $this->maxTextBytes) {
                return self::TEXT_TOO_LONG;
            }

            return null;
        }

        if ($this->textRun + $firstTag > $this->maxTextBytes) {
            return self::TEXT_TOO_LONG;
        }

        $this->textRun = strlen($buffer) - 1 - (int) strrpos($buffer, '>');

        $rest = preg_replace(self::COMMON_TAG, '', $buffer);

        if ($rest === null) {
            return self::NOT_XML;
        }

        return str_contains($rest, '<') ? $this->uncommonTags($rest) : null;
    }

    /**
     * Takes comments and CDATA sections out of the buffer; one that does not end in it is remembered as the state.
     *
     * @return null|string
     */
    private function withoutCommentsAndCdata(string &$buffer): null|string
    {
        $kept = '';
        $offset = 0;
        $length = strlen($buffer);

        while (($at = strpos($buffer, '<!', $offset)) !== false) {
            $kept .= substr($buffer, $offset, $at - $offset);

            if (substr_compare($buffer, '<!--', $at, 4) === 0) {
                $state = self::COMMENT;
                $from = $at + 4;
                $end = strpos($buffer, '-->', $from);
            } elseif (substr_compare($buffer, '<![CDATA[', $at, 9) === 0) {
                $state = self::CDATA;
                $from = $at + 9;
                $end = strpos($buffer, ']]>', $from);
            } elseif ($length - $at < 9 && (str_starts_with('<!--', substr($buffer, $at)) || str_starts_with('<![CDATA[', substr($buffer, $at)))) {
                // The opening itself is cut by the chunk's end
                $this->carry = substr($buffer, $at);
                $buffer = $kept;

                return null;
            } else {
                // A document type, an entity: what XML attacks are made of
                return self::NOT_XML;
            }

            if ($end === false) {
                $this->state = $state;
                $this->pending = substr($buffer, max($from, $length - 2));
                $this->textRun = $state === self::CDATA ? $this->textRun + $length - $from : $this->textRun;
                $buffer = $kept;

                return $this->textRun > $this->maxTextBytes ? self::TEXT_TOO_LONG : null;
            }

            $offset = $end + 3;
        }

        $buffer = $kept . substr($buffer, $offset);

        return null;
    }

    /**
     * The tags a chunk holds besides the common ones, one by one.
     *
     * @return null|string
     */
    private function uncommonTags(string $rest): null|string
    {
        $found = preg_match_all(self::ANY_TAG, $rest, $tags, PREG_SET_ORDER);

        // A "<" that starts nothing the scanner knows: the parser is not asked what it makes of it
        if ($found === false || $found !== substr_count($rest, '<')) {
            return self::NOT_XML;
        }

        foreach ($tags as $tag) {
            if (strlen($tag[0]) > self::MAX_TAG_BYTES) {
                return self::TAG_TOO_LONG;
            }

            // An end tag, a processing instruction
            if (isset($tag[1]) === false) {
                continue;
            }

            $this->names[$tag[1]] = true;

            if (isset($tag[2]) === false || strlen($tag[2]) < 3) {
                continue;
            }

            $count = preg_match_all(self::ATTRIBUTE, $tag[2], $attributes);

            if ($count === false || $count > self::MAX_ATTRIBUTES) {
                return self::TOO_MANY_ATTRIBUTES;
            }

            foreach ($attributes[1] as $attribute) {
                $this->names[$attribute] = true;

                if (str_starts_with($attribute, 'xmlns') && ++$this->namespaces > self::MAX_NAMESPACES) {
                    return self::TOO_MANY_NAMESPACES;
                }
            }
        }

        return count($this->names) > self::MAX_NAMES ? self::TOO_MANY_NAMES : null;
    }
}
