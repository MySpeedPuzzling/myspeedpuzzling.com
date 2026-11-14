<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;

/**
 * One XML part of a workbook, read node by node from the stream XlsxPackage opened for it - never unpacked to disk,
 * never loaded whole.
 *
 * Untrusted input: no network, no DTD or external entity is loaded (libxml's defaults, never switched on here); a
 * part with a DOCTYPE or an entity is refused (a workbook never has one - entities are what an XML attack is made
 * of); a part that ends before its root element closes is damaged; and every byte it unpacks to passes the part's
 * meter (longer than the archive says = damaged, over the workbook's budget = too large).
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
        private readonly string $entry,
    ) {
    }

    /**
     * @param resource $stream the part's stream, with its meter already on it
     */
    public static function over($stream, PartMeter $meter, string $entry): self
    {
        return new self(\XMLReader::fromStream($stream, null, LIBXML_NONET | LIBXML_COMPACT), $stream, $meter, $entry);
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
     * alone (and metered like everything else).
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
        if ($this->meter->failure === PartMeter::OVER_BUDGET) {
            throw SpreadsheetUnreadable::tooLarge('the workbook holds more XML than is read from one file');
        }

        if ($this->meter->failure === PartMeter::LONGER_THAN_DECLARED) {
            throw SpreadsheetUnreadable::damaged(sprintf('the part "%s" is longer than the archive says', $this->entry));
        }

        if ($this->complete === false) {
            throw SpreadsheetUnreadable::damaged(sprintf('the part "%s" is not well-formed XML', $this->entry));
        }

        return false;
    }
}
