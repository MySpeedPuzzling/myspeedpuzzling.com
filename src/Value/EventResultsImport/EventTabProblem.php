<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

readonly final class EventTabProblem
{
    public function __construct(
        public EventTabProblemCode $code,
        // The column, the team name or the value that is wrong - for the organizer to find it
        public null|string $detail = null,
        // The sheet's row number (the header is 1); null for a problem of the whole tab
        public null|int $line = null,
    ) {
    }

    /**
     * @return array{code: string, detail: null|string, line: null|int}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'detail' => $this->detail,
            'line' => $this->line,
        ];
    }
}
