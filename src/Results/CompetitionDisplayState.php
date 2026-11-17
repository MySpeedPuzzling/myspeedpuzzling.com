<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DisplayKind;

/**
 * What a competition display needs now (D-displays-overlays.md §7.2, GetCompetitionDisplayState::forToken()) - the data
 * part of `GET /display/{token}/state`; CompetitionDisplayPayload adds the display's texts, its Mercure subscription
 * and `serverNowMs`.
 *
 * Nothing in here identifies a person (no participant, player or entry id, no #code - opaque keys only), and nothing
 * of a round puzzle that is not out for everybody yet (RoundPuzzlesVisibleNow): not its name, picture, label or even
 * how many there are.
 */
readonly final class CompetitionDisplayState implements \JsonSerializable
{
    /**
     * @param array{name: string, logoUrl: null|string, seriesLogoUrl: null|string, organizationLogoUrl: null|string, location: null|string, countryCode: null|string, dateFrom: null|string, dateTo: null|string} $competition
     * @param null|array{id: string, name: string, category: string, badge: array{bg: string, fg: string}, minutesLimit: null|int, startsAtMs: int, timezone: string, resultsPublished: bool} $round
     * @param list<array{name: string, brand: null|string, pieces: int, imageUrl: null|string}> $puzzles revealed only
     * @param array<string, string> $images every picture the design uses: stored path => URL
     * @param list<string> $followedRoundIds the rounds the display may switch between (one when pinned)
     */
    public function __construct(
        public string $displayId,
        public string $competitionId,
        public DisplayKind $kind,
        public string $locale,
        public int $designVersion,
        public array $competition,
        public null|array $round,
        public null|RoundStopwatchState $stopwatch,
        public array $puzzles,
        public bool $anyPuzzleRevealed,
        public null|int $refreshAtMs,
        public null|DisplayResults $results,
        public array $images,
        public array $followedRoundIds,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'displayId' => $this->displayId,
            'kind' => $this->kind->value,
            'locale' => $this->locale,
            'designVersion' => $this->designVersion,
            'competition' => $this->competition,
            'round' => $this->round,
            'stopwatch' => $this->stopwatch,
            'puzzles' => $this->puzzles,
            'anyPuzzleRevealed' => $this->anyPuzzleRevealed,
            'refreshAtMs' => $this->refreshAtMs,
            'results' => $this->results,
            'images' => $this->images === [] ? new \stdClass() : $this->images,
            'followedRoundIds' => $this->followedRoundIds,
        ];
    }
}
