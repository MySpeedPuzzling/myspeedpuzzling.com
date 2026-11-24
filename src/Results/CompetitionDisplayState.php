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
     * @param array<string, mixed> $design the stored (normalised) design - not part of the state answer; the page and the
     *                                     QR codes read it
     * @param null|CompetitionReference $event the event's address (QR codes to its page) - not part of the state answer
     * @param null|string $roundSlug the shown round's address (QR codes to its results) - not part of the state answer
     * @param null|array<string, null|array<string, mixed>> $standings element id => the published standing it shows
     *                                                                 (DisplayStandings); null = no shown standings
     *                                                                 element - the key is left out of the answer then
     * @param null|array{message: null|string, spotlight: null|DisplayResultRow} $control the live control (v2 L1/L2) -
     *                                                                                 null = no shown message / spotlight
     *                                                                                 element, the key is left out then
     * @param null|array<string, string> $puzzleLabels
     * @param null|array<string, null|array<string, mixed>> $bracket element id => the bracket it shows (DisplayBracket,
     *                                                               v2 S6); null = no shown bracket element - the key is
     *                                                               left out of the answer then
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
        public array $design = [],
        public null|CompetitionReference $event = null,
        public null|string $roundSlug = null,
        public null|array $standings = null,
        public null|array $control = null,
        public null|array $bracket = null,
        // position => label of the round's puzzles - only for a design with a `puzzle_label` field, else the key is left
        // out of the answer (spws-demo-ux-leftovers.md §4)
        public null|array $puzzleLabels = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $answer = [
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

        // Only a design with a standings element has the key - every other design's answer stays as it was
        if ($this->standings !== null) {
            $answer['standings'] = $this->standings === [] ? new \stdClass() : $this->standings;
        }

        // Only a design with a message / spotlight element has the key (v2 L1/L2)
        if ($this->control !== null) {
            $answer['control'] = $this->control;
        }

        // Only a design naming a puzzle's label has the key
        if ($this->puzzleLabels !== null) {
            $answer['puzzleLabels'] = $this->puzzleLabels === [] ? new \stdClass() : $this->puzzleLabels;
        }

        // Only a design with a bracket element has the key (v2 S6)
        if ($this->bracket !== null) {
            $answer['bracket'] = $this->bracket === [] ? new \stdClass() : $this->bracket;
        }

        return $answer;
    }
}
