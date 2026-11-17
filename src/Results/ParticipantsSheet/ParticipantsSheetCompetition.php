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
        // The claim policy in effect (open | approval), the organiser's choice (null = automatic) and whether time
        // proposals make automatic mean approval (docs/features/competitions-management/participant-linking.md D3)
        public string $claimPolicy = 'open',
        public null|string $claimPolicyChosen = null,
    ) {
    }

    /**
     * @return array{id: string, name: string, isOnline: bool, registrationManaged: bool, capacity: null|int, eventUrl: string, editUrl: string, claimPolicy: string, claimPolicyChosen: null|string}
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
            'claimPolicy' => $this->claimPolicy,
            'claimPolicyChosen' => $this->claimPolicyChosen,
        ];
    }
}
