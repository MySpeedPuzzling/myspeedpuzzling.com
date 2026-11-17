<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Query\GetCompetitionStandingsPresence;
use SpeedPuzzling\Web\Results\CompetitionStandingsPresent;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Does a published standing count a round, is the round published, does the event have a published standing
 * (competition toolkit v2 S1, displays-standings.md §2.2)? Asked by CompetitionStandingsLiveUpdates after every results
 * and round write of every event - so an event without published standings (nearly all) pays no statement for it: the
 * answer is kept in the shared pool `competition_standings_presence_cache` per round and per event, tagged with the event,
 * for LIFETIME seconds, and in this object for the rest of the request (ResetInterface - FrankenPHP worker mode).
 * The pattern of CompetitionDisplaysPresence.
 */
final class CompetitionStandingsPresence implements ResetInterface
{
    public const int LIFETIME = 300;

    /** @var array<string, CompetitionStandingsPresent> */
    private array $rounds = [];

    /** @var array<string, CompetitionStandingsPresent> */
    private array $competitions = [];

    public function __construct(
        private readonly GetCompetitionStandingsPresence $getCompetitionStandingsPresence,
        private readonly TagAwareCacheInterface $competitionStandingsPresenceCache,
    ) {
    }

    public function forRound(string $roundId): CompetitionStandingsPresent
    {
        $key = strtolower($roundId);

        return $this->rounds[$key] ??= self::fromArray($this->competitionStandingsPresenceCache->get(
            'round_' . $key,
            function (ItemInterface $item) use ($key): array {
                $presence = $this->getCompetitionStandingsPresence->ofRound($key);
                $item->expiresAfter(self::LIFETIME);

                if ($presence->competitionId !== null) {
                    $item->tag(self::tag($presence->competitionId));
                }

                return self::toArray($presence);
            },
        ));
    }

    public function forCompetition(string $competitionId): CompetitionStandingsPresent
    {
        $key = strtolower($competitionId);

        return $this->competitions[$key] ??= self::fromArray($this->competitionStandingsPresenceCache->get(
            'competition_' . $key,
            function (ItemInterface $item) use ($key): array {
                $item->expiresAfter(self::LIFETIME);
                $item->tag(self::tag($key));

                return self::toArray($this->getCompetitionStandingsPresence->ofCompetition($key));
            },
        ));
    }

    /**
     * One round's answer is read again - its results were published or unpublished (`roundPublished` flips).
     */
    public function forgetRound(string $roundId): void
    {
        $key = strtolower($roundId);

        $this->competitionStandingsPresenceCache->delete('round_' . $key);
        unset($this->rounds[$key]);
    }

    /**
     * The event's standings changed (created, edited, deleted, published, unpublished) or its rounds did (added, deleted,
     * moved away or in): every answer of the event is read again.
     */
    public function changed(string $competitionId): void
    {
        $key = strtolower($competitionId);

        $this->competitionStandingsPresenceCache->invalidateTags([self::tag($key)]);
        unset($this->competitions[$key]);

        foreach ($this->rounds as $roundId => $presence) {
            if ($presence->competitionId === $key) {
                unset($this->rounds[$roundId]);
            }
        }
    }

    public function reset(): void
    {
        $this->rounds = [];
        $this->competitions = [];
    }

    private static function tag(string $competitionId): string
    {
        return 'competition_standings_' . strtolower($competitionId);
    }

    /**
     * @return array{competitionId: null|string, inPublishedStanding: bool, roundPublished: bool, anyPublished: bool}
     */
    private static function toArray(CompetitionStandingsPresent $presence): array
    {
        return [
            'competitionId' => $presence->competitionId !== null ? strtolower($presence->competitionId) : null,
            'inPublishedStanding' => $presence->inPublishedStanding,
            'roundPublished' => $presence->roundPublished,
            'anyPublished' => $presence->anyPublished,
        ];
    }

    private static function fromArray(mixed $cached): CompetitionStandingsPresent
    {
        if (!is_array($cached)) {
            return CompetitionStandingsPresent::none(null);
        }

        $competitionId = $cached['competitionId'] ?? null;

        return new CompetitionStandingsPresent(
            is_string($competitionId) ? $competitionId : null,
            ($cached['inPublishedStanding'] ?? false) === true,
            ($cached['roundPublished'] ?? false) === true,
            ($cached['anyPublished'] ?? false) === true,
        );
    }
}
