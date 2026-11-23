<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Who a round's displays spotlight (competition toolkit v2 L2): the entry ref and how the organiser's picker labels it -
 * the organiser's name of the participant / pair / team and the table.
 */
readonly final class DisplayControlSpotlight implements \JsonSerializable
{
    public function __construct(
        public string $entryRef,
        public null|string $name,
        public null|int $table,
        // The table's name on a labelled round (table-labels-and-plates.md A4) - the JSON key only when set
        public null|string $tableLabel = null,
    ) {
    }

    /**
     * @return array{entryRef: string, name: null|string, table: null|int, tableLabel?: string}
     */
    public function jsonSerialize(): array
    {
        $json = ['entryRef' => $this->entryRef, 'name' => $this->name, 'table' => $this->table];

        if ($this->tableLabel !== null) {
            $json['tableLabel'] = $this->tableLabel;
        }

        return $json;
    }
}
