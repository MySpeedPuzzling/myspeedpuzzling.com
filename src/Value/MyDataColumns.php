<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\Uuid;

/**
 * Which columns of "My data" a player switched off, per tab (docs/features/my-data.md "The column chooser") - what
 * `player.my_data_columns` holds: `{"results": ["ppm"], "puzzles": []}`. A tab the player never chose anything on
 * is not in it and shows the default columns (MyDataTab::hiddenByDefault()).
 *
 * The hidden ones are stored, not the shown ones: a column added later - a new field of the player's - shows up by
 * itself, and a field that is archived keeps its choice for the day it comes back (withChoice()). A column id is a built-in one of the tab (MyDataTab::columns()) or `f:<uuid>` for a field of the player's;
 * anything else is dropped, here and again when it is read.
 */
readonly final class MyDataColumns
{
    public const string FIELD_PREFIX = 'f:';
    public const int MAX_HIDDEN = 60;

    /**
     * @param array<string, list<string>> $hidden tab => column ids
     */
    private function __construct(
        private array $hidden = [],
    ) {
    }

    public static function fromStored(mixed $stored): self
    {
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        if (is_array($stored) === false) {
            return new self();
        }

        $columns = new self();

        foreach (MyDataTab::cases() as $tab) {
            if (is_array($stored[$tab->value] ?? null)) {
                $columns = $columns->withHidden($tab, $stored[$tab->value]);
            }
        }

        return $columns;
    }

    public static function fieldColumn(string $fieldId): string
    {
        return self::FIELD_PREFIX . $fieldId;
    }

    /**
     * @param array<mixed> $hidden what was submitted or stored - everything that is no column of the tab is dropped
     */
    public function withHidden(MyDataTab $tab, array $hidden): self
    {
        $clean = [];

        foreach ($hidden as $column) {
            if (is_string($column) === false) {
                continue;
            }

            $isField = $tab->hasOwnFields()
                && str_starts_with($column, self::FIELD_PREFIX)
                && Uuid::isValid(substr($column, strlen(self::FIELD_PREFIX)));

            if ($isField) {
                $column = strtolower($column);
            } elseif (in_array($column, $tab->columns(), true) === false) {
                continue;
            }

            $clean[$column] = $column;

            if (count($clean) >= self::MAX_HIDDEN) {
                break;
            }
        }

        return new self([...$this->hidden, $tab->value => array_values($clean)]);
    }

    /**
     * What the chooser's form says: of the columns it offered, the ticked ones are shown and the rest hidden. A
     * hidden column the form did **not** offer stays hidden - the field of the player's it belongs to was not in the
     * form (archived for now, or the fields could not be read for that page), and its choice must be there when the
     * field is back.
     *
     * @param array<mixed> $offered
     * @param array<mixed> $shown
     */
    public function withChoice(MyDataTab $tab, array $offered, array $shown): self
    {
        $offered = array_values(array_filter($offered, is_string(...)));
        $offeredLower = array_map(strtolower(...), $offered);
        $shownLower = array_map(strtolower(...), array_filter($shown, is_string(...)));

        $hidden = array_values(array_filter($offered, static fn (string $column): bool => in_array(strtolower($column), $shownLower, true) === false));
        $kept = array_values(array_filter($this->hiddenOn($tab), static fn (string $column): bool => in_array($column, $offeredLower, true) === false));

        return $this->withHidden($tab, [...$hidden, ...$kept]);
    }

    /**
     * @return list<string>
     */
    public function hiddenOn(MyDataTab $tab): array
    {
        return $this->hidden[$tab->value] ?? $tab->hiddenByDefault();
    }

    public function shows(MyDataTab $tab, string $column): bool
    {
        return in_array($column, $this->hiddenOn($tab), true) === false;
    }

    /**
     * @return null|array<string, list<string>> null = nothing chosen anywhere
     */
    public function toStored(): null|array
    {
        return $this->hidden === [] ? null : $this->hidden;
    }
}
