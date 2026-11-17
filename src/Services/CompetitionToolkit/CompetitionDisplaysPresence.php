<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Query\GetCompetitionDisplaysPresence;
use SpeedPuzzling\Web\Results\CompetitionDisplaysPresent;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Does an event have displays (competition toolkit D5, D-displays-overlays.md §7.3, R-review-and-plan.md §1.6)? Asked
 * by CompetitionDisplayLiveUpdates before every display signal, i.e. on every results write of every event - so an
 * event without displays (nearly all of them) must not pay a statement for it:
 * - the answer is kept in the shared cache pool `competition_displays_presence_cache` per round and per event, tagged
 *   with the event (tag()), for LIFETIME seconds - a results write of an event without displays runs no statement for it
 *   (the first one after LIFETIME runs one, GetCompetitionDisplaysPresence);
 * - and in this object for the rest of the request (ResetInterface - FrankenPHP worker mode);
 * - changed() forgets an event's answers: called by the controllers after a display was added, deleted or its design
 *   saved (uses_results), and after a round moved to another event. Called after the commit, so a request that read
 *   the old answer just before can still store it - the display then follows by its 60 s polling until LIFETIME ends.
 */
final class CompetitionDisplaysPresence implements ResetInterface
{
    public const int LIFETIME = 300;

    /** @var array<string, CompetitionDisplaysPresent> */
    private array $rounds = [];

    /** @var array<string, CompetitionDisplaysPresent> */
    private array $competitions = [];

    public function __construct(
        private readonly GetCompetitionDisplaysPresence $getCompetitionDisplaysPresence,
        private readonly TagAwareCacheInterface $competitionDisplaysPresenceCache,
    ) {
    }

    public function forRound(string $roundId): CompetitionDisplaysPresent
    {
        $key = strtolower($roundId);

        return $this->rounds[$key] ??= self::fromArray($this->competitionDisplaysPresenceCache->get(
            'round_' . $key,
            function (ItemInterface $item) use ($key): array {
                $presence = $this->getCompetitionDisplaysPresence->ofRound($key);
                $item->expiresAfter(self::LIFETIME);

                if ($presence->competitionId !== null) {
                    $item->tag(self::tag($presence->competitionId));
                }

                return self::toArray($presence);
            },
        ));
    }

    public function forCompetition(string $competitionId): CompetitionDisplaysPresent
    {
        $key = strtolower($competitionId);

        return $this->competitions[$key] ??= self::fromArray($this->competitionDisplaysPresenceCache->get(
            'competition_' . $key,
            function (ItemInterface $item) use ($key): array {
                $item->expiresAfter(self::LIFETIME);
                $item->tag(self::tag($key));

                return self::toArray($this->getCompetitionDisplaysPresence->ofCompetition($key));
            },
        ));
    }

    /**
     * The event's displays changed (added, deleted, a design saved) or one of its rounds moved away or in (called for both
     * events - a round's answer is tagged with the event it was read for): its answers are read again.
     */
    public function changed(string $competitionId): void
    {
        $key = strtolower($competitionId);

        $this->competitionDisplaysPresenceCache->invalidateTags([self::tag($key)]);
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
        return 'competition_displays_' . strtolower($competitionId);
    }

    /**
     * @return array{competitionId: null|string, displays: bool, results: bool}
     */
    private static function toArray(CompetitionDisplaysPresent $presence): array
    {
        return [
            'competitionId' => $presence->competitionId !== null ? strtolower($presence->competitionId) : null,
            'displays' => $presence->displays,
            'results' => $presence->results,
        ];
    }

    private static function fromArray(mixed $cached): CompetitionDisplaysPresent
    {
        if (!is_array($cached)) {
            return CompetitionDisplaysPresent::none(null);
        }

        $competitionId = $cached['competitionId'] ?? null;

        return new CompetitionDisplaysPresent(
            is_string($competitionId) ? $competitionId : null,
            ($cached['displays'] ?? false) === true,
            ($cached['results'] ?? false) === true,
        );
    }
}
