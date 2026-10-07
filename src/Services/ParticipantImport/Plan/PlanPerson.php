<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport\Plan;

use DateTimeImmutable;

/**
 * A participant while an import is planned: an existing one (key = its id) or one the file adds (key = "new:<row>").
 * Mutable on purpose - later rows see what earlier rows made of the person (today's rule).
 */
final class PlanPerson
{
    /** @var null|array{name: string, country: null|string, externalId: null|string, playerId: null|string, deleted: bool} null = the import creates them */
    public null|array $before = null;

    /** The file names them - matched or created by at least one row */
    public bool $inFile = false;

    /** @var list<int> rows of the file standing for this person, the first one owns the person's changes */
    public array $rows = [];

    public bool $markAsImported = false;

    /** `status = deleted` on one of their rows */
    public bool $deletedByFile = false;

    /** Full sync removes them (not in the file) */
    public bool $removedBySync = false;

    /** A rounds cell of one of their rows lists something (D14: an empty cell keeps their rounds) */
    public bool $listsRounds = false;

    /** @var array<string, true> round ids the file lists for them */
    public array $roundsListed = [];

    public function __construct(
        public readonly string $key,
        /** null = created by the import */
        public readonly null|string $id,
        public string $name,
        public null|string $country,
        public null|string $externalId,
        public null|string $playerId,
        public bool $deleted,
        public readonly null|DateTimeImmutable $deletedAt = null,
        public readonly bool $selfJoined = false,
        public readonly null|string $playerName = null,
    ) {
    }

    public function isNew(): bool
    {
        return $this->id === null;
    }

    /**
     * @return array{name: string, country: null|string, externalId: null|string, playerId: null|string, deleted: bool}
     */
    public function state(): array
    {
        return [
            'name' => $this->name,
            'country' => $this->country,
            'externalId' => $this->externalId,
            'playerId' => $this->playerId,
            'deleted' => $this->deleted,
        ];
    }

    public function wasActive(): bool
    {
        return $this->before !== null && $this->before['deleted'] === false;
    }

    public function isRestored(): bool
    {
        return $this->before !== null && $this->before['deleted'] === true && $this->deleted === false;
    }
}
