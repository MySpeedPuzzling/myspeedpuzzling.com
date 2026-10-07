<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ParticipantsSheet;

/**
 * The event of a participants spreadsheet (ParticipantsSheetState).
 */
readonly final class ParticipantsSheetCompetition implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $isOnline,
        public bool $registrationManaged,
        // null = no limit (managed registration)
        public null|int $capacity,
        // The public page of the event or the edition (the events list when it has none)
        public string $eventUrl,
        public string $editUrl,
    ) {
    }

    /**
     * @return array{id: string, name: string, isOnline: bool, registrationManaged: bool, capacity: null|int, eventUrl: string, editUrl: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'isOnline' => $this->isOnline,
            'registrationManaged' => $this->registrationManaged,
            'capacity' => $this->capacity,
            'eventUrl' => $this->eventUrl,
            'editUrl' => $this->editUrl,
        ];
    }
}
