<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One round as the organiser's results tools show it (results desk, results overview, advance dialog): its official
 * results progress (RoundResultsOverview) with how the event pages draw it (badge colours, the zone its start is
 * shown in) and the address of its public results page (null = no address yet).
 *
 * jsonSerialize() = RoundResultsOverview's JSON + `color`, `textColor`, `timezone`, `publicUrl`.
 */
readonly final class OfficialResultsRound implements \JsonSerializable
{
    public function __construct(
        public RoundResultsOverview $overview,
        public string $color,
        public string $textColor,
        public string $timezone,
        public bool $timezoneAssumed,
        public null|string $publicUrl,
    ) {
    }

    public function id(): string
    {
        return $this->overview->roundId;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            ...$this->overview->jsonSerialize(),
            'color' => $this->color,
            'textColor' => $this->textColor,
            'timezone' => $this->timezone,
            'publicUrl' => $this->publicUrl,
        ];
    }
}
