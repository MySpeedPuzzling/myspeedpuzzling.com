<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One result of a puzzle with everybody who took part in it - what the first-try rules are checked against.
 */
readonly final class FirstTryTime
{
    /**
     * @param list<FirstTryPerson> $people whoever took part, tracker first
     */
    public function __construct(
        public string $timeId,
        public DateTimeImmutable $solvedAt,
        public bool $firstAttempt,
        public null|int $secondsToSolve,
        public array $people,
    ) {
    }

    /**
     * Results are compared by day: the date of a result is often typed in without a time of day.
     */
    public function solvedDay(): string
    {
        return $this->solvedAt->format('Y-m-d');
    }

    public function involves(string $playerId): bool
    {
        return $this->person($playerId) !== null;
    }

    public function person(string $playerId): null|FirstTryPerson
    {
        foreach ($this->people as $person) {
            if ($person->is($playerId)) {
                return $person;
            }
        }

        return null;
    }

    public function isGroup(): bool
    {
        return count($this->people) > 1;
    }

    public function isPair(): bool
    {
        return count($this->people) === 2;
    }

    /**
     * @return list<FirstTryPerson>
     */
    public function peopleExcept(string $playerId): array
    {
        return array_values(array_filter(
            $this->people,
            static fn(FirstTryPerson $person): bool => $person->is($playerId) === false,
        ));
    }

    /**
     * @return list<string>
     */
    public function registeredPlayerIds(): array
    {
        $ids = [];

        foreach ($this->people as $person) {
            if ($person->playerId !== null) {
                $ids[] = strtolower($person->playerId);
            }
        }

        return $ids;
    }
}
