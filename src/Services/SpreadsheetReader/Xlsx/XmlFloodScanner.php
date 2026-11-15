<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

/**
 * Reads every byte of a part **before the XML parser gets it** and stops the shapes the parser is slow on - where no
 * check in PHP would see them, because the time is spent inside one call of the parser (libxml 2.9: a start tag of
 * 300,000 attributes took 90 s, three million distinct names 117 s, 150,000 namespace declarations in scope 11 s for
 * every megabyte after them).
 *
 * A tokenizer, strictly in sequence: it stands in one of five states and enters a construct only from the text
 * state, at the "<" that really starts it - it never looks for a marker further on in the buffer. So what it takes for
 * a comment, a CDATA section or an attribute value is what the parser takes for one, and nothing can hide in between.
 *
 *   DECLARATION  the start of the part: an optional byte-order mark, then "<" - and if that opens an XML declaration,
 *                the declaration to its end (UTF-8 or nothing)
 *   TEXT         up to the next "<", which opens: a comment, a CDATA section, an end tag, a start tag - or is refused
 *                (a processing instruction: a workbook's parts have none, and a parser ends one at the first "?>"
 *                whatever stands in it; a document type; anything else)
 *   TAG          a tag the chunk's end cuts short, carried to the next chunk: followed from quote to quote for its
 *                ">" (each byte looked at once, however the stream is cut), its attribute values counted as they
 *                close - then it is a start or an end tag like any other
 *   COMMENT      up to "-->"
 *   CDATA        up to "]]>"
 *
 * Refused:
 * - a tag of more than MAX_ATTRIBUTES attributes (what the parser is quadratic in), or longer than MAX_TAG_BYTES;
 * - more than MAX_NAMESPACES namespace declarations in a part (a workbook's parts declare a handful, on their root);
 * - more than MAX_NAMES distinct element and attribute names in a part (a workbook's vocabulary is a few hundred);
 * - a text between two tags of more than RAW_TEXT_FACTOR times a cell's size (raw bytes: "&amp;" is one character),
 *   a comment of more than MAX_COMMENT_BYTES;
 * - more than MAX_BLANK_RUNS **distinct** runs of white space between two tags in a part: the parser keeps every
 *   distinct blank text of under 60 bytes in its dictionary for good (1.5 million distinct ones of 21 bytes cost it
 *   tens of seconds and hundreds of megabytes). Distinct, not all: a pretty-printed sheet has a run per element and
 *   a handful of different ones - one per depth of indentation. Counted on the raw bytes (">", white space, "<"),
 *   whatever the tokenizer's state - a superset of what the parser can take for a blank text, so nothing is missed;
 *   nothing is rewritten;
 * - a part that is not UTF-8, so that what is read here is what is parsed;
 * - a tag that is no tag: the grammar of a start and an end tag is the parser's, nothing looser.
 *
 * Bounded memory: one unfinished tag at most is carried from chunk to chunk. Cheap: a run of text and of the tags a
 * sheet is made of (<c>, <v>, <row> … with the attributes they are known to have - a finite vocabulary, bounded by
 * the pattern itself) is consumed by one anchored regular expression; only the tags it stops at are looked at one by
 * one.
 */
final class XmlFloodScanner
{
    public const int MAX_TAG_BYTES = 1_048_576;
    public const int MAX_ATTRIBUTES = 64;
    public const int MAX_NAMESPACES = 64;
    public const int MAX_NAMES = 2000;
    public const int MAX_COMMENT_BYTES = 65_536;
    /** Distinct runs of white space between two tags. A pretty-printed part has one per depth of its nesting (and
     *  per kind of line end): the fixtures and generated files have 0 to 8 */
    public const int MAX_BLANK_RUNS = 1000;
    /** Runs up to this long are told apart; the parser keeps blank texts of under 60 bytes */
    private const int BLANK_RUN_BYTES = 64;
    /** The longest character reference, "&quot;" and "&apos;", is six bytes for one character */
    public const int RAW_TEXT_FACTOR = 6;

    public const string TAG_TOO_LONG = 'a tag longer than a tag is';
    public const string TOO_MANY_ATTRIBUTES = 'a tag with more attributes than a tag has';
    public const string TOO_MANY_NAMESPACES = 'more namespace declarations than a part has';
    public const string TOO_MANY_NAMES = 'more distinct names than a part has';
    public const string TEXT_TOO_LONG = 'a text longer than a cell holds';
    public const string COMMENT_TOO_LONG = 'a comment longer than a comment is';
    public const string TOO_MANY_BLANK_RUNS = 'more different runs of white space between its tags than a part has';
    public const string NOT_UTF8 = 'a part that is not UTF-8';
    public const string PROCESSING_INSTRUCTION = 'a processing instruction';
    public const string NOT_XML = 'markup that is no tag';

    private const int DECLARATION = 0;
    private const int TEXT = 1;
    private const int TAG = 2;
    private const int COMMENT = 3;
    private const int CDATA = 4;

    private const int MAX_DECLARATION_BYTES = 1024;

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

    /** A character of a name: anything but white space and what delimits markup (the parser is stricter, never looser) */
    private const string NAME = '[^\s<>/"\'=!?&]';

    /**
     * From where the scanner stands: text, end tags and common start tags, as far as they go. A common start tag may
     * carry a prefix - a part can declare 64 of them, and a prefix nobody declared is a complaint of the parser, which
     * are counted (PartMeter): the names this lets through stay a finite set.
     */
    private const string COMMON_RUN = '~\G(?:[^<]++|</' . self::NAME . '{1,255}+\s{0,8}+>|<(?:[A-Za-z][A-Za-z0-9]{0,15}:)?(?:' . self::COMMON_ELEMENTS . ')'
        . '(?:\s{1,8}+(?:' . self::COMMON_ATTRIBUTES . ')\s{0,2}+=\s{0,2}+"[^"<]{0,255}+"){0,32}+\s{0,8}+/?+>)*+~';

    private const string ATTRIBUTE = '\s++' . self::NAME . '++\s*+=\s*+(?:"[^"<]*+"|\'[^\'<]*+\')';

    /** A start tag from its "<" to its ">": its name (1) and its attributes (2) */
    private const string START_TAG = '~\G<(' . self::NAME . '++)((?:' . self::ATTRIBUTE . ')*+)\s*+/?+>~';

    private const string END_TAG = '~\G</' . self::NAME . '++\s*+>~';

    /** tagEnd(): the tag is not all here yet; it is no tag; it has more attribute values than a tag has attributes */
    private const int CUT = -1;
    private const int NO_TAG = -2;
    private const int FLOOD = -3;

    private const string XML_DECLARATION = '~\G<\?xml(?:\s++[A-Za-z]++\s*+=\s*+(?:"[^"<>?]*+"|\'[^\'<>?]*+\'))*+\s*+\?>~';

    /** A run of white space that stands between two tags, whole in one chunk */
    private const string BLANK_RUNS = '~>([ \t\r\n]{1,' . self::BLANK_RUN_BYTES . '})<~';

    /** A chunk's end that may be the start of such a run: a ">" and white space up to the end */
    private const string BLANK_RUN_OPEN = '~>([ \t\r\n]{0,' . self::BLANK_RUN_BYTES . '})\z~';

    private const string ATTRIBUTE_NAMES = '~\s++(' . self::NAME . '++)\s*+=\s*+(?:"[^"<]*+"|\'[^\'<]*+\')~';

    private int $state = self::DECLARATION;
    /** What the last chunk ended in the middle of: an unfinished tag, the first bytes of a comment's or CDATA
     *  section's opening or of the part itself, the last two bytes of a comment or CDATA section */
    private string $carry = '';
    /** In a carried tag: how far it was followed, the quote of the attribute value that is open there, and how many
     *  values have closed. -1: what is carried is too short to tell what it opens */
    private int $tagFollowed = -1;
    private string $tagQuote = '';
    private int $tagValues = 0;
    private int $textRun = 0;
    private int $commentBytes = 0;
    private int $namespaces = 0;
    /** @var array<string, true> */
    private array $names = [];
    /** @var array<string, true> the distinct runs of white space between two tags seen in this part */
    private array $blankRuns = [];
    /** White space since a ">" the last chunk ended in - the run may go on in this one; null: none is open */
    private null|string $blankOpen = null;
    private readonly int $maxTextBytes;

    /**
     * @param int $maxCellBytes how long a cell's text may be, decoded
     */
    public function __construct(int $maxCellBytes)
    {
        $this->maxTextBytes = self::RAW_TEXT_FACTOR * $maxCellBytes;
    }

    /**
     * @return null|string why the part is refused (one of the reasons above); null: the chunk may go to the parser
     */
    public function scan(string $chunk): null|string
    {
        if ($this->tooManyBlankRuns($chunk)) {
            return self::TOO_MANY_BLANK_RUNS;
        }

        if ($this->state === self::TAG && $this->tagFollowed >= 0) {
            // Appended in place: a tag of a megabyte that comes in a thousand chunks is not copied a thousand times
            $this->carry .= $chunk;
            $end = $this->tagEnd($this->carry, strlen($this->carry));

            if ($end === self::CUT) {
                return strlen($this->carry) > self::MAX_TAG_BYTES ? self::TAG_TOO_LONG : null;
            }

            if ($end < 0) {
                return $end === self::FLOOD ? self::TOO_MANY_ATTRIBUTES : self::NOT_XML;
            }

            $buffer = $this->carry;
        } else {
            $buffer = $this->carry . $chunk;
        }

        $this->carry = '';
        $length = strlen($buffer);
        $at = 0;

        if ($this->state === self::DECLARATION) {
            $at = $this->declaration($buffer, $length);

            if ($at === null) {
                $this->carry = $buffer;

                return null;
            }

            if (is_string($at)) {
                return $at;
            }

            $this->state = self::TEXT;
        }

        if ($this->state === self::TAG) {
            $this->state = self::TEXT;
        }

        while ($at < $length) {
            if ($this->state === self::COMMENT || $this->state === self::CDATA) {
                $end = strpos($buffer, $this->state === self::COMMENT ? '-->' : ']]>', $at);
                // Without its end, all but the last two bytes are behind us: the end may have begun in those
                $passed = $end === false ? max(0, $length - 2 - $at) : $end - $at;

                if ($this->state === self::COMMENT) {
                    if (($this->commentBytes += $passed) > self::MAX_COMMENT_BYTES) {
                        return self::COMMENT_TOO_LONG;
                    }
                } elseif (($this->textRun += $passed) > $this->maxTextBytes) {
                    return self::TEXT_TOO_LONG;
                }

                if ($end === false) {
                    $this->carry = substr($buffer, $at + $passed);

                    return null;
                }

                $this->state = self::TEXT;
                $at = $end + 3;

                continue;
            }

            if (preg_match(self::COMMON_RUN, $buffer, $run, 0, $at) !== 1) {
                return self::NOT_XML;
            }

            $ran = strlen($run[0]);

            if ($ran > 0) {
                $lastTagEnd = strrpos($run[0], '>');
                // Text may hold a ">" of its own: then the text counted is the shorter for it, and the parser's own
                // limit on a text and the cell's cap in PHP are what holds
                $this->textRun = $lastTagEnd === false || str_contains($run[0], '<') === false ? $this->textRun + $ran : $ran - 1 - $lastTagEnd;

                if ($this->textRun > $this->maxTextBytes) {
                    return self::TEXT_TOO_LONG;
                }

                $at += $ran;

                if ($at >= $length) {
                    return null;
                }
            }

            // $buffer[$at] is a "<" that opens something but a common tag
            $failure = $this->markup($buffer, $length, $at);

            if ($failure !== null) {
                return $failure === '' ? null : $failure;
            }
        }

        return null;
    }

    /**
     * Counts the distinct runs of white space between a ">" and a "<" - also one the stream cuts in two.
     */
    private function tooManyBlankRuns(string $chunk): bool
    {
        if ($chunk === '') {
            return false;
        }

        if ($this->blankOpen !== null) {
            $leading = strspn($chunk, " \t\r\n");

            if ($leading === strlen($chunk)) {
                // Still nothing but white space: a run longer than the parser keeps is no run to count any more
                $this->blankOpen = strlen($this->blankOpen) + $leading > self::BLANK_RUN_BYTES ? null : $this->blankOpen . $chunk;

                return false;
            }

            if ($chunk[$leading] === '<' && strlen($this->blankOpen) + $leading <= self::BLANK_RUN_BYTES && strlen($this->blankOpen) + $leading > 0) {
                $this->blankRuns[$this->blankOpen . substr($chunk, 0, $leading)] = true;
            }

            $this->blankOpen = null;
        }

        // The cheap question first: most workbooks have no white space between their tags at all
        if (preg_match_all(self::BLANK_RUNS, $chunk, $runs) > 0) {
            foreach ($runs[1] as $run) {
                $this->blankRuns[$run] = true;
            }
        }

        // Only the chunk's last bytes can be such an end
        if (preg_match(self::BLANK_RUN_OPEN, substr($chunk, -(self::BLANK_RUN_BYTES + 1)), $open) === 1) {
            $this->blankOpen = $open[1];
        }

        return count($this->blankRuns) > self::MAX_BLANK_RUNS;
    }

    /**
     * The start of a part: an optional byte-order mark, then "<" - of the XML declaration or of the first tag.
     *
     * @return null|int|string where the text begins; why the part is refused; null: too few bytes yet to tell
     */
    private function declaration(string $buffer, int $length): null|int|string
    {
        $mark = "\xEF\xBB\xBF";

        if ($length < 3 && str_starts_with($mark, $buffer)) {
            return null;
        }

        $start = str_starts_with($buffer, $mark) ? 3 : 0;

        if ($length === $start) {
            return null;
        }

        // UTF-16 and UTF-32 (a mark of their own, or a NUL in the first character), EBCDIC (4C 6F A7 94), white
        // space, text: a part starts with "<" as ASCII has it
        if ($buffer[$start] !== '<') {
            return str_contains(substr($buffer, 0, 4), "\0") || in_array(substr($buffer, 0, 2), ["\xFF\xFE", "\xFE\xFF", "\x4C\x6F"], true)
                ? self::NOT_UTF8
                : self::NOT_XML;
        }

        if ($length < $start + 2) {
            return null;
        }

        if ($buffer[$start + 1] !== '?') {
            return str_contains(substr($buffer, 0, 4), "\0") ? self::NOT_UTF8 : $start;
        }

        // "<?xml" and a white space: the XML declaration - the one processing instruction there is
        if ($length < $start + 6) {
            return str_starts_with('<?xml', substr($buffer, $start)) ? null : self::PROCESSING_INSTRUCTION;
        }

        if (preg_match('~\G<\?xml\s~', $buffer, $opening, 0, $start) !== 1) {
            return self::PROCESSING_INSTRUCTION;
        }

        $end = strpos($buffer, '?>', $start);

        if ($end === false) {
            return $length - $start > self::MAX_DECLARATION_BYTES ? self::NOT_XML : null;
        }

        // Nothing but what a declaration is made of - the parser ends it at its first question mark and angle bracket too
        if ($end - $start > self::MAX_DECLARATION_BYTES || preg_match(self::XML_DECLARATION, $buffer, $declaration, 0, $start) !== 1 || strlen($declaration[0]) !== $end + 2 - $start) {
            return self::NOT_XML;
        }

        if (
            preg_match('~\sencoding\s*=\s*["\']([^"\']*)["\']~', $declaration[0], $declared) === 1
            && in_array(strtolower($declared[1]), ['utf-8', 'utf8', 'us-ascii', 'ascii'], true) === false
        ) {
            return self::NOT_UTF8;
        }

        return $end + 2;
    }


    /**
     * A "<" that opens something but a common tag: enters the comment or the CDATA section, passes the tag, carries
     * what the buffer's end cuts short.
     *
     * @return null|string null: go on from $at; '': the rest of the buffer is carried; else why the part is refused
     */
    private function markup(string $buffer, int $length, int &$at): null|string
    {
        $second = $buffer[$at + 1] ?? null;

        if ($second === null) {
            return $this->carried(substr($buffer, $at), -1);
        }

        if ($second === '!') {
            if (substr_compare($buffer, '<!--', $at, 4) === 0) {
                $this->state = self::COMMENT;
                $this->commentBytes = 0;
                $at += 4;

                return null;
            }

            if (substr_compare($buffer, '<![CDATA[', $at, 9) === 0) {
                $this->state = self::CDATA;
                $at += 9;

                return null;
            }

            $rest = substr($buffer, $at);

            // The opening itself is cut by the chunk's end - or it is a document type, an entity: what XML attacks
            // are made of
            return $length - $at < 9 && (str_starts_with('<!--', $rest) || str_starts_with('<![CDATA[', $rest))
                ? $this->carried($rest, -1)
                : self::NOT_XML;
        }

        if ($second === '?') {
            return self::PROCESSING_INSTRUCTION;
        }

        if (preg_match($second === '/' ? self::END_TAG : self::START_TAG, $buffer, $tag, 0, $at) === 1) {
            $at += strlen($tag[0]);
            $this->textRun = 0;

            if (strlen($tag[0]) > self::MAX_TAG_BYTES) {
                return self::TAG_TOO_LONG;
            }

            return $second === '/' ? null : $this->named($tag[1] ?? '', $tag[2] ?? '');
        }

        // Not a whole tag from here: one the buffer's end cuts short - or no tag at all
        $rest = substr($buffer, $at);
        $this->tagFollowed = 1;
        $this->tagQuote = '';
        $this->tagValues = 0;
        $end = $this->tagEnd($rest, strlen($rest));

        if ($end === self::CUT) {
            return $this->carried($rest, $this->tagFollowed);
        }

        return $end === self::FLOOD ? self::TOO_MANY_ATTRIBUTES : self::NOT_XML;
    }

    /**
     * Follows a tag from where it was followed to: outside a quote up to a quote or the ">", inside one up to its end.
     * Every attribute has a value in quotes, so the values that close are the attributes - counted before the tag
     * is all here.
     *
     * @return int where the tag's ">" stands - or CUT, NO_TAG, FLOOD
     */
    private function tagEnd(string $tag, int $length): int
    {
        $at = $this->tagFollowed;

        while ($at < $length) {
            if ($this->tagQuote !== '') {
                $at += strcspn($tag, $this->tagQuote . '<', $at);

                if ($at >= $length) {
                    break;
                }

                if ($tag[$at] === '<') {
                    return self::NO_TAG;
                }

                $this->tagQuote = '';
                $at++;

                if (++$this->tagValues > self::MAX_ATTRIBUTES) {
                    return self::FLOOD;
                }

                continue;
            }

            $at += strcspn($tag, '"\'<>', $at);

            if ($at >= $length) {
                break;
            }

            if ($tag[$at] === '>') {
                return $at;
            }

            if ($tag[$at] === '<') {
                return self::NO_TAG;
            }

            $this->tagQuote = $tag[$at];
            $at++;
        }

        $this->tagFollowed = $length;

        return self::CUT;
    }

    /**
     * @param int $followed how far the tag was followed; -1: too short to tell what it opens
     *
     * @return string '' - or the refusal of a tag that is longer than a tag is
     */
    private function carried(string $rest, int $followed): string
    {
        $this->carry = $rest;
        $this->tagFollowed = $followed;
        $this->state = self::TAG;

        return strlen($rest) > self::MAX_TAG_BYTES ? self::TAG_TOO_LONG : '';
    }

    /**
     * The names of a start tag that is not a common one.
     */
    private function named(string $element, string $attributes): null|string
    {
        $this->names[$element] = true;

        if ($attributes !== '') {
            $count = preg_match_all(self::ATTRIBUTE_NAMES, $attributes, $found);

            if ($count === false || $count > self::MAX_ATTRIBUTES) {
                return self::TOO_MANY_ATTRIBUTES;
            }

            foreach ($found[1] as $attribute) {
                $this->names[$attribute] = true;

                if (($attribute === 'xmlns' || str_starts_with($attribute, 'xmlns:')) && ++$this->namespaces > self::MAX_NAMESPACES) {
                    return self::TOO_MANY_NAMESPACES;
                }
            }
        }

        return count($this->names) > self::MAX_NAMES ? self::TOO_MANY_NAMES : null;
    }
}
