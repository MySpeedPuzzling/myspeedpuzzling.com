<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One of the five steps of an import as "Where you are" and the flow's header show it
 * (Services\DataImport\ImportFlow, docs/features/data-import/guided-flow.md §2).
 */
readonly final class ImportFlowStep
{
    /** Passed - by the player, or by itself because it has nothing to ask */
    public const string DONE = 'done';
    /** Something waits for the player here */
    public const string OPEN = 'open';
    /** MySpeedPuzzling works on it by itself - nothing to press yet */
    public const string WORKING = 'working';
    /** Not reached yet - or nothing is known about it on this page */
    public const string AHEAD = 'ahead';

    /**
     * @param list<ImportFlowText> $lines its status, one sentence a line
     * @param array<string, string> $parameters of the route
     */
    public function __construct(
        public int $number,
        /** `file`, `check`, `people`, `puzzles`, `add` - the step's name is `data_import.flow.step.<key>` */
        public string $key,
        public string $state,
        public array $lines,
        /** The step's own screen - null while it can not be opened */
        public null|string $route,
        public array $parameters,
        /** What waits here, for the step's accessible name - 0 when nothing or not known */
        public int $waiting = 0,
    ) {
    }

    public function isDone(): bool
    {
        return $this->state === self::DONE;
    }

    public function isOpen(): bool
    {
        return $this->state === self::OPEN;
    }
}
