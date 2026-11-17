<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ParticipantsSheet;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ParticipantLinkMethod;

/**
 * Everything the participants spreadsheet of one event shows (docs/features/competitions-management/participants-spreadsheet.md):
 * the event, its rounds, every participant (removed ones too), every place of a person in a round, every pair/team, and
 * the live updates subscription of the page. The page embeds jsonSerialize() as its first state, the state endpoint
 * answers it - one shape for both (GetParticipantsSheetState).
 *
 * `version` (GetParticipantsSheetVersion) was read before the data: a change committed meanwhile makes the page fetch
 * again at its next version check, never the other way round.
 */
readonly final class ParticipantsSheetState implements \JsonSerializable
{
    /**
     * @param list<ParticipantsSheetRound> $rounds
     * @param list<ParticipantsSheetPerson> $people
     * @param list<ParticipantsSheetPlace> $places
     * @param list<ParticipantsSheetTeam> $teams
     * @param null|array{url: string, topics: list<string>, token: string, expiresAt: string, expiresIn: int} $mercure
     * @param list<ParticipantsSheetLinkRequest> $requests pending link requests (participant-linking.md §7.1)
     */
    public function __construct(
        public DateTimeImmutable $serverNow,
        public string $version,
        public ParticipantsSheetCompetition $competition,
        public array $rounds,
        public array $people,
        public array $places,
        public array $teams,
        public null|array $mercure,
        public array $requests = [],
    ) {
    }

    /**
     * The People tab's filters (participant-linking.md §7.1) over the active people: linked, not linked, linked by
     * themselves (ParticipantLinkMethod::isThePlayersOwn()), and the pending requests.
     *
     * @return array{linked: int, notLinked: int, linkedByThemselves: int, requests: int}
     */
    public function linkCounts(): array
    {
        $linked = 0;
        $notLinked = 0;
        $byThemselves = 0;

        foreach ($this->people as $person) {
            if ($person->removedAt !== null) {
                continue;
            }

            if ($person->player === null) {
                $notLinked++;

                continue;
            }

            $linked++;
            $via = $person->linkedVia !== null ? ParticipantLinkMethod::tryFrom($person->linkedVia) : null;

            if ($via !== null && $via->isThePlayersOwn()) {
                $byThemselves++;
            }
        }

        return ['linked' => $linked, 'notLinked' => $notLinked, 'linkedByThemselves' => $byThemselves, 'requests' => count($this->requests)];
    }

    public function round(string $roundId): null|ParticipantsSheetRound
    {
        foreach ($this->rounds as $round) {
            if ($round->id === strtolower($roundId)) {
                return $round;
            }
        }

        return null;
    }

    public function activePeopleCount(): int
    {
        return count(array_filter($this->people, static fn (ParticipantsSheetPerson $person): bool => $person->removedAt === null));
    }

    /**
     * The setup checklist applies: the event has no rounds yet, or nobody on its list and no pairs/teams either - an
     * event of named pairs/teams without their people (a names-only night) is set up and never nagged.
     */
    public function needsSetup(): bool
    {
        return $this->rounds === [] || ($this->activePeopleCount() === 0 && $this->teams === []);
    }

    /**
     * @return array{
     *     serverNow: string,
     *     version: string,
     *     competition: ParticipantsSheetCompetition,
     *     rounds: list<ParticipantsSheetRound>,
     *     people: list<ParticipantsSheetPerson>,
     *     places: list<ParticipantsSheetPlace>,
     *     teams: list<ParticipantsSheetTeam>,
     *     mercure: null|array{url: string, topics: list<string>, token: string, expiresAt: string, expiresIn: int},
     *     requests: list<ParticipantsSheetLinkRequest>,
     *     counts: array{linked: int, notLinked: int, linkedByThemselves: int, requests: int},
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'serverNow' => $this->serverNow->format(DateTimeImmutable::ATOM),
            'version' => $this->version,
            'competition' => $this->competition,
            'rounds' => $this->rounds,
            'people' => $this->people,
            'places' => $this->places,
            'teams' => $this->teams,
            'mercure' => $this->mercure,
            'requests' => $this->requests,
            'counts' => $this->linkCounts(),
        ];
    }
}
