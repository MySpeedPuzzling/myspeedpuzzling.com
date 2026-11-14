<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;

/**
 * One XML part of a workbook, read node by node straight out of the zip - never unpacked to disk, never loaded whole.
 *
 * Untrusted input: no network, no DTD or external entity is loaded (libxml's defaults, never switched on here), a
 * part with a DOCTYPE is refused (a workbook never has one - entities are what an XML attack is made of), a part that
 * ends before its root element closes is damaged, and a part with more nodes than its reader expects is too large.
 */
final class XmlPart
{
    private bool $complete = false;
    private int $nodes = 0;

    private function __construct(
        public readonly \XMLReader $reader,
        private readonly string $entry,
        private readonly int $maxNodes,
    ) {
    }

    public static function open(string $zipPath, string $entry, int $maxNodes): self
    {
        $reader = new \XMLReader();

        // zip://<archive>#<entry> - the archive path was checked to hold no "#"
        if (@$reader->open('zip://' . $zipPath . '#' . $entry, null, LIBXML_NONET | LIBXML_COMPACT) === false) {
            throw SpreadsheetUnreadable::damaged(sprintf('the part "%s" can not be opened', $entry));
        }

        return new self($reader, $entry, $maxNodes);
    }

    /**
     * Moves to the next node; false at the end of a complete document.
     */
    public function read(): bool
    {
        $reader = $this->reader;

        if (@$reader->read() === false) {
            if ($this->complete === false) {
                throw SpreadsheetUnreadable::damaged(sprintf('the part "%s" is not well-formed XML', $this->entry));
            }

            return false;
        }

        if (++$this->nodes > $this->maxNodes) {
            throw SpreadsheetUnreadable::tooLarge(sprintf('the part "%s" holds more than %d nodes', $this->entry, $this->maxNodes));
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
     * alone - a sheet's million cells are skipped in a fraction of the time read() would take.
     */
    public function next(): bool
    {
        $reader = $this->reader;

        if (@$reader->next() === false) {
            if ($this->complete === false) {
                throw SpreadsheetUnreadable::damaged(sprintf('the part "%s" is not well-formed XML', $this->entry));
            }

            return false;
        }

        if ($reader->depth === 0 && $reader->nodeType === \XMLReader::END_ELEMENT) {
            $this->complete = true;
        }

        return true;
    }

    public function attribute(string $name): null|string
    {
        return $this->reader->getAttribute($name);
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

        return $this->reader->getAttribute('r:id');
    }

    public function close(): void
    {
        @$this->reader->close();
    }
}
