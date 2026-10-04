<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a change request did to a puzzle's other names (PuzzleNames::diff()) - applied as a diff onto the names the
 * puzzle has when it is approved, never as a replacement, so names changed meanwhile by someone else stay.
 */
readonly final class PuzzleNamesDiff
{
    /**
     * @param list<PuzzleName> $added
     * @param list<PuzzleName> $removed
     * @param list<array{from: PuzzleName, to: PuzzleName}> $changed Renamed or re-tagged
     */
    public function __construct(
        public array $added,
        public array $removed,
        public array $changed,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->added === [] && $this->removed === [] && $this->changed === [];
    }

    /**
     * A changed name takes the place of the one it changes; when that one is gone meanwhile, it is added. A removed
     * name that is gone already is nothing to do. Added names come last (a name the list holds already merges with
     * it, PuzzleNames::union()).
     */
    public function applyTo(PuzzleNames $current): PuzzleNames
    {
        $names = $current->all();

        foreach ($this->changed as $change) {
            $index = self::find($names, $change['from']);

            if ($index !== null) {
                $names[$index] = $change['to'];
            } else {
                $names[] = $change['to'];
            }
        }

        foreach ($this->removed as $removed) {
            $index = self::find($names, $removed);

            if ($index !== null) {
                unset($names[$index]);
                $names = array_values($names);
            }
        }

        return (new PuzzleNames(array_values($names)))->union(new PuzzleNames($this->added));
    }

    /**
     * The same name in the same language, else the first that folds equal.
     *
     * @param array<int, PuzzleName> $names
     */
    private static function find(array $names, PuzzleName $target): null|int
    {
        foreach ($names as $index => $name) {
            if ($name->name === $target->name && $name->language === $target->language) {
                return $index;
            }
        }

        $key = SearchText::fold($target->name);

        foreach ($names as $index => $name) {
            if (SearchText::fold($name->name) === $key) {
                return $index;
            }
        }

        return null;
    }
}
