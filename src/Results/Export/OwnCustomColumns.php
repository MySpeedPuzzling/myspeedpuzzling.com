<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Export;

use DOMDocument;
use DOMElement;

/**
 * The signed-in player's own fields as extra columns of their own export (docs/features/data-export.md "Own
 * fields"), built for them alone by OwnCustomValuesInExport - one column per field, after every other column.
 *
 * One label names a field in every format: `custom:<key>` for a ready-made field (its fixed key - the export's
 * column names are English and fixed, whatever language the page is in), `custom:own:<name>` for the player's own
 * field (as they named it). A key has no colon, so an own field named like a key (`price_paid`) is still its own
 * column: `custom:own:price_paid` beside `custom:price_paid`.
 *
 * - XLSX / CSV: a column per label; a number is a number, yes/no is true / false, tags are joined with ", "
 * - JSON: an object `custom` per row, label => typed value (number, boolean, list of tags, `Y-m-d` date), null = empty
 * - XML: `<custom name="label">value</custom>` per label (a field's name is no element name), tags as `<tag>` children;
 *   a character XML 1.0 can not hold is left out (xmlText())
 */
readonly final class OwnCustomColumns
{
    public const string ELEMENT = 'custom';
    public const string STANDARD_PREFIX = 'custom:';
    public const string OWN_PREFIX = 'custom:own:';
    private const string TAG_SEPARATOR = ', ';

    /**
     * @param list<string> $labels the columns, in order
     * @param array<string, array<string, string|int|float|bool|list<string>>> $valuesByRow row id (solving time id,
     *                                                                                       collection item id) =>
     *                                                                                       label => stored value
     */
    public function __construct(
        public array $labels = [],
        private array $valuesByRow = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->labels === [];
    }

    /**
     * Every label of the row, typed as stored; null = the field is empty there.
     *
     * @return array<string, null|string|int|float|bool|list<string>>
     */
    public function of(string $rowId): array
    {
        $row = [];

        foreach ($this->labels as $label) {
            $row[$label] = $this->valuesByRow[$rowId][$label] ?? null;
        }

        return $row;
    }

    /**
     * One value as a spreadsheet cell - the caller writes it through SpreadsheetSafeValue like every other cell.
     *
     * @param null|string|int|float|bool|list<string> $value
     */
    public static function cell(null|string|int|float|bool|array $value): null|string|int|float|bool
    {
        if (is_array($value)) {
            return $value === [] ? null : implode(self::TAG_SEPARATOR, $value);
        }

        return $value;
    }

    /**
     * `<custom name="label">…</custom>` under a record - an empty element for an empty field, like every other column.
     *
     * @param null|string|int|float|bool|list<string> $value
     */
    public static function appendXml(DOMDocument $dom, DOMElement $record, string $label, null|string|int|float|bool|array $value): void
    {
        $element = $dom->createElement(self::ELEMENT);
        $element->setAttribute('name', self::xmlText($label));
        $record->appendChild($element);

        if (is_array($value)) {
            foreach ($value as $tag) {
                $tagElement = $dom->createElement('tag');
                $tagElement->appendChild($dom->createTextNode(self::xmlText($tag)));
                $element->appendChild($tagElement);
            }

            return;
        }

        $element->appendChild($dom->createTextNode(self::xmlText(match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        })));
    }

    /**
     * Text an XML 1.0 document can hold: tab, line feed, carriage return and everything from U+0020 on except the
     * surrogates, U+FFFE and U+FFFF. Anything else is left out - one such character makes the whole file unreadable.
     * New values never hold one (CustomValues::isStorableText()); this is for what was stored before that rule, and
     * for text that is no UTF-8 at all (written as nothing).
     */
    public static function xmlText(string $text): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    }
}
