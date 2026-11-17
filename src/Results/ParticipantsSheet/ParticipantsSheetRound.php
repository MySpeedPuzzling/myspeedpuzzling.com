<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ParticipantsSheet;

use SpeedPuzzling\Web\Results\RoundPuzzleFormatView;
use DateTimeImmutable;

/**
 * One round of the event, a tab of the participants spreadsheet (ParticipantsSheetState).
 */
readonly final class ParticipantsSheetRound implements \JsonSerializable
{
    /**
     * @param array{liveEntry: string, resultsDesk: string, seating: null|string, edit: string} $urls
     */
    public function __construct(
        public string $id,
        public string $name,
        // RoundCategory value: solo | duo | team
        public string $category,
        // The organiser's expected team size - team rounds only (CompetitionRound::$teamSize)
        public null|int $teamSize,
        public DateTimeImmutable $startsAt,
        // The zone startsAt is shown in (RoundTimezone)
        public string $timezone,
        // Its stopwatch ran, or its start has passed - the results columns show by default from then on
        public bool $started,
        // What the round is shown in on the event pages (RoundBadgeColor)
        public string $color,
        public string $textColor,
        public bool $tableNumbersOff,
        // The piece count of the round's puzzle when it has exactly one
        public null|int $piecesCount,
        public bool $resultsPublished,
        public array $urls,
        // A `pick` round's format (competition toolkit A4) - the JSON's `puzzleFormat`, absent while null
        public null|RoundPuzzleFormatView $puzzleFormat = null,
    ) {
    }

    /**
     * @return array{id: string, name: string, category: string, teamSize: null|int, startsAt: string, timezone: string, started: bool, color: string, textColor: string, tableNumbersOff: bool, piecesCount: null|int, resultsPublished: bool, urls: array{liveEntry: string, resultsDesk: string, seating: null|string, edit: string}, puzzleFormat?: RoundPuzzleFormatView}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'teamSize' => $this->teamSize,
            'startsAt' => $this->startsAt->format(DateTimeImmutable::ATOM),
            'timezone' => $this->timezone,
            'started' => $this->started,
            'color' => $this->color,
            'textColor' => $this->textColor,
            'tableNumbersOff' => $this->tableNumbersOff,
            'piecesCount' => $this->piecesCount,
            'resultsPublished' => $this->resultsPublished,
            'urls' => $this->urls,
            ...($this->puzzleFormat !== null ? ['puzzleFormat' => $this->puzzleFormat] : []),
        ];
    }
}
