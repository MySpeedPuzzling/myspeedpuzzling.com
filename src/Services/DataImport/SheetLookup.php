<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Value\DataImport\ImportKeyFold;
use SpeedPuzzling\Web\Value\DataImportLimits;

/**
 * Brand and pieces of a results row from the workbook's puzzle list, by the puzzle's name
 * (docs/features/data-import/README.md "What real spreadsheets look like": results sheets that name the puzzle only
 * by a dropdown into the list - two of them without a brand column).
 *
 * Built from the list sheet's rows as they are stored - whatever that sheet itself is imported as. A name is found
 * folded like a key's (ImportKeyFold::v1()). **A name that stands on the list twice with another brand or piece
 * count is no answer** - the row gets a problem and the player chooses; the same puzzle listed twice alike is one.
 */
final class SheetLookup
{
    /** The share of a results sheet's names a list must hold to be offered as the list they point to */
    public const float COVERS_FROM = 0.6;
    /** From here the offer is taken as certain */
    public const float SURELY_COVERS_FROM = 0.9;

    /** @var array<string, array{brand: null|string, pieces: null|int}> folded name => what the list says */
    private array $puzzles = [];
    /** @var array<string, true> folded names the list has twice, differently */
    private array $twice = [];

    public function add(string $name, null|string $brand, null|int $pieces): void
    {
        $key = ImportKeyFold::v1($name);
        $brand = $brand === null || trim($brand) === '' ? null : trim($brand);

        if ($key === '' || ($brand === null && $pieces === null)) {
            return;
        }

        $known = $this->puzzles[$key] ?? null;

        if ($known === null) {
            if (count($this->puzzles) < DataImportLimits::MAX_LOOKUP_NAMES) {
                $this->puzzles[$key] = ['brand' => $brand, 'pieces' => $pieces];
            }

            return;
        }

        if (
            ($known['brand'] !== null && $brand !== null && ImportKeyFold::lettersAndDigitsV1($known['brand']) !== ImportKeyFold::lettersAndDigitsV1($brand))
            || ($known['pieces'] !== null && $pieces !== null && $known['pieces'] !== $pieces)
        ) {
            $this->twice[$key] = true;

            return;
        }

        // The same puzzle again: what one row lacks the other may have
        $this->puzzles[$key] = ['brand' => $known['brand'] ?? $brand, 'pieces' => $known['pieces'] ?? $pieces];
    }

    public function has(string $name): bool
    {
        return isset($this->puzzles[ImportKeyFold::v1($name)]);
    }

    /**
     * @return null|array{brand: null|string, pieces: null|int} null: not on the list - or on it twice (isAmbiguous())
     */
    public function find(string $name): null|array
    {
        $key = ImportKeyFold::v1($name);

        return isset($this->twice[$key]) ? null : $this->puzzles[$key] ?? null;
    }

    public function isAmbiguous(string $name): bool
    {
        return isset($this->twice[ImportKeyFold::v1($name)]);
    }

    public function count(): int
    {
        return count($this->puzzles);
    }

    /**
     * The share of these names the list holds - how a sheet is recognised as "the puzzle list the others point to".
     *
     * @param iterable<string> $names
     */
    public function coverage(iterable $names): float
    {
        $all = 0;
        $found = 0;

        foreach ($names as $name) {
            if (ImportKeyFold::v1($name) === '') {
                continue;
            }

            $all++;
            $found += $this->has($name) ? 1 : 0;
        }

        return $all === 0 ? 0.0 : $found / $all;
    }
}
