<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;

/**
 * One XML part of a workbook, read node by node from the stream XlsxPackage opened for it - never unpacked to disk,
 * never loaded whole.
 *
 * Untrusted input: no network, no DTD or external entity is loaded (libxml's defaults, never switched on here); the
 * part is read as UTF-8 whatever it says of itself; a part with a DOCTYPE or an entity is refused (a workbook never
 * has one - entities are what an XML attack is made of); a part that ends before its root element closes is damaged;
 * and every chunk it unpacks to passes the part's meter first (PartMeter: size, budget, time, flood shapes).
 *
 * Nothing here relies on a PHP warning: every call of the parser is checked by its result, and why it stopped is
 * asked from the meter. (The workbook's ReadBudget keeps the parser's warnings away from the application meanwhile.)
 */
final class XmlPart
{
    private bool $complete = false;
    private bool $closed = false;

    /**
     * @param resource $stream
     */
    private function __construct(
        public readonly \XMLReader $reader,
        private $stream,
        private readonly PartMeter $meter,
        private readonly ReadBudget $budget,
        private readonly string $entry,
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @param resource $stream the part's stream, with its meter already on it
     */
    public static function over($stream, PartMeter $meter, ReadBudget $budget, string $entry): self
    {
        try {
            // The parser reads the first bytes at once: a compressed part that is corrupt fails here already
            $reader = @\XMLReader::fromStream($stream, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT);
        } catch (\Throwable $e) {
            @fclose($stream);

            throw self::refusal($meter, $budget, $entry) ?? SpreadsheetUnreadable::damaged(sprintf('the part "%s" can not be unpacked', $entry), $e);
        }

        $part = new self($reader, $stream, $meter, $budget, $entry);
        $refusal = self::refusal($meter, $budget, $entry);

        if ($refusal !== null) {
            $part->close();

            throw $refusal;
        }

        return $part;
    }

    /**
     * Moves to the next node; false at the end of a complete document.
     */
    public function read(): bool
    {
        $reader = $this->reader;

        if (@$reader->read() === false) {
            return $this->ended();
        }

        $type = $reader->nodeType;

        if ($type === \XMLReader::DOC_TYPE || $type === \XMLReader::ENTITY_REF || $type === \XMLReader::ENTITY) {
            throw SpreadsheetUnreadable::damaged(sprintf('the part "%s" declares a document type or an entity', $this->entry));
        }

        if ($reader->depth === 0 && ($type === \XMLReader::END_ELEMENT || ($type === \XMLReader::ELEMENT && $reader->isEmptyElement))) {
            $this->complete = true;
        }

        return true;
    }

    /**
     * From an element: moves past it and everything inside it, to whatever follows. The inside is parsed by libxml
     * alone - and every chunk of it still passes the meter, with its clock and its scanner.
     */
    public function next(): bool
    {
        $reader = $this->reader;

        if (@$reader->next() === false) {
            return $this->ended();
        }

        if ($reader->depth === 0 && $reader->nodeType === \XMLReader::END_ELEMENT) {
            $this->complete = true;
        }

        return true;
    }

    /**
     * Reading stopped inside an element (a row), where a document can not simply be over: says why. For the passes
     * that step through a row with the reader itself - past the prolog no DOCTYPE can come any more, and without one
     * no entity.
     */
    public function stoppedInside(): never
    {
        $this->complete = false;
        $this->ended();

        throw SpreadsheetUnreadable::damaged(sprintf('the part "%s" is not well-formed XML', $this->entry));
    }

    public function attribute(string $name): null|string
    {
        return $this->reader->getAttribute($name);
    }

    /**
     * A whole number written as an attribute (a style index, a format number, a count). Anything else - a sign,
     * a letter, a number no workbook has - is a damaged part, never a guess.
     */
    public function number(string $name, int $default, int $max): int
    {
        $value = $this->reader->getAttribute($name);

        if ($value === null) {
            return $default;
        }

        if ($value === '' || strlen($value) > 9 || ctype_digit($value) === false || (int) $value > $max) {
            throw SpreadsheetUnreadable::damaged(sprintf('the part "%s" holds an attribute "%s" that is no number', $this->entry, $name));
        }

        return (int) $value;
    }

    /**
     * The r:id of an element, whatever prefix the part gave the relationships namespace.
     */
    public function relationshipId(): null|string
    {
        foreach (XlsxPackage::RELATIONSHIP_NAMESPACES as $namespace) {
            $id = $this->reader->getAttributeNs('id', $namespace);

            if ($id !== null && $id !== '') {
                return $id;
            }
        }

        // A part that uses the prefix without declaring it
        return $this->reader->getAttribute('r:id');
    }

    /**
     * Never throws.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        @$this->reader->close();

        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
    }

    /**
     * Why reading stopped: the meter stopped it, the document is cut short or broken - or it simply ended.
     */
    private function ended(): bool
    {
        $refusal = self::refusal($this->meter, $this->budget, $this->entry);

        if ($refusal !== null) {
            throw $refusal;
        }

        if ($this->complete === false) {
            // The parser's own limit on one text (10 MB) is a cell that is too large, not a broken file
            $this->budget->countParserErrors();

            throw $this->budget->hugeText
                ? SpreadsheetUnreadable::cellTooLarge('a text longer than the XML parser reads')
                : SpreadsheetUnreadable::damaged(sprintf('the part "%s" is not well-formed XML, or can not be unpacked', $this->entry));
        }

        return false;
    }

    private static function refusal(PartMeter $meter, ReadBudget $budget, string $entry): null|SpreadsheetUnreadable
    {
        return match ($meter->failure) {
            null => null,
            PartMeter::OVER_BUDGET => SpreadsheetUnreadable::tooLarge('the workbook holds more XML than is read from one file'),
            PartMeter::OUT_OF_TIME => SpreadsheetUnreadable::tooSlow(sprintf('reading took more than the %.1f s a file gets', $budget->nanoseconds() / 1e9)),
            PartMeter::LONGER_THAN_DECLARED => SpreadsheetUnreadable::damaged(sprintf('the part "%s" is longer than the archive says', $entry)),
            PartMeter::TOO_MANY_PARSER_ERRORS => SpreadsheetUnreadable::damaged(sprintf('the part "%s" is XML the parser complains about over and over', $entry)),
            XmlFloodScanner::TEXT_TOO_LONG => SpreadsheetUnreadable::cellTooLarge('a text longer than a cell holds'),
            default => SpreadsheetUnreadable::damaged(sprintf('the part "%s" holds %s', $entry, $meter->failure)),
        };
    }
}
