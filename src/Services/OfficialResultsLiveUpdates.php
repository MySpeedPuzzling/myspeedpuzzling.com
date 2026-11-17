<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Query\GetTimeProposals;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells the organisers' open pages what changed in a round's official results - private Mercure updates on
 * `/round-results/{roundId}` (topic()), authorised only on the organiser pages: each page subscribes with a token of
 * its own that its state carries (OfficialResultsSubscription, assets/official_results_events.js) - never through the
 * subscribe cookie, which every signed-in response rewrites with its own topics.
 *
 * Referees (docs/features/competitions-management/live-results.md "Referees") follow `/round-results/{roundId}/referees`
 * (refereesTopic()) instead: the same updates, but an update has no viewer, so every private linked player's #code,
 * profile name and id are withheld there (RoundResultEntry::forReferee(), flagged `playerWithheld` - the live entry
 * keeps what the referee's own state showed). An update without anything withheld goes to both topics at once.
 *
 * Called by the controllers after the dispatch returned, i.e. after the commit. A Mercure failure never fails the
 * write: it is logged (warning) and the pages catch up on their next state fetch.
 *
 * Payloads (`type` always prefixed `official_results.`):
 * - official_results.entries {roundId, entries: [RoundResultEntry...]} - the changed entries as they are now
 * - official_results.refresh {roundId} - too many entries changed at once: fetch the round's state again
 * - official_results.round {roundId, round: RoundResultsOverview} - publication, table numbers usage, counts
 * - official_results.proposals {roundId, proposals: [TimeProposalView...]} - competitors' time proposals created,
 *   withdrawn or decided (competition toolkit P1, proposalsChanged()); only a competition with time proposals has any.
 *   One payload for both topics: a proposal names the participant as the organiser recorded them, never a profile.
 */
final readonly class OfficialResultsLiveUpdates
{
    // More entries than this in one update = a refresh signal instead (a seating or an advancement of a whole round)
    public const int MAX_ENTRIES_PER_UPDATE = 50;

    public function __construct(
        private HubInterface $hub,
        private GetRoundResultEntries $getRoundResultEntries,
        private GetRoundResultsOverview $getRoundResultsOverview,
        private LoggerInterface $logger,
        private GetTimeProposals $getTimeProposals,
    ) {
    }

    public static function topic(string $roundId): string
    {
        return '/round-results/' . strtolower($roundId);
    }

    public static function refereesTopic(string $roundId): string
    {
        return self::topic($roundId) . '/referees';
    }

    /**
     * @param array<string> $entryRefs
     */
    public function entriesChanged(string $roundId, array $entryRefs): void
    {
        if ($entryRefs === []) {
            return;
        }

        $this->publish($roundId, function () use ($roundId, $entryRefs): array {
            if (count($entryRefs) > self::MAX_ENTRIES_PER_UPDATE) {
                return ['type' => 'official_results.refresh', 'roundId' => $roundId];
            }

            return [
                'type' => 'official_results.entries',
                'roundId' => $roundId,
                'entries' => $this->getRoundResultEntries->byRefs($roundId, $entryRefs),
                'round' => $this->getRoundResultsOverview->forRound($roundId),
            ];
        });
    }

    /**
     * Entries left the round (taken out of it) - an entries update cannot say so: the pages fetch the round again.
     */
    public function refresh(string $roundId): void
    {
        $this->publish($roundId, static fn (): array => [
            'type' => 'official_results.refresh',
            'roundId' => $roundId,
        ]);
    }

    /**
     * The reveal push (toolkit/R-review-and-plan.md §1.5): what of the round's puzzles is visible changed, or will at a
     * new moment - a stopwatch start with "Reveal the puzzles", "Reveal now", a reveal setting saved. The open pages
     * fetch the round again (they read the puzzles through RoundPuzzlesVisibleNow, never from this message: it carries
     * no puzzle). Called by the controller after the dispatch returned - after the commit.
     */
    public function puzzlesRevealed(string $roundId): void
    {
        $this->refresh($roundId);
    }

    /**
     * Time proposals of the round changed (competition toolkit P1) - more than MAX_ENTRIES_PER_UPDATE at once (a
     * stopwatch reset) is a refresh signal instead.
     *
     * @param array<string> $proposalIds
     */
    public function proposalsChanged(string $roundId, array $proposalIds): void
    {
        if ($proposalIds === []) {
            return;
        }

        $this->publish($roundId, function () use ($roundId, $proposalIds): array {
            if (count($proposalIds) > self::MAX_ENTRIES_PER_UPDATE) {
                return ['type' => 'official_results.refresh', 'roundId' => $roundId];
            }

            return [
                'type' => 'official_results.proposals',
                'roundId' => $roundId,
                'proposals' => $this->getTimeProposals->byIds($roundId, $proposalIds),
            ];
        });
    }

    public function roundChanged(string $roundId): void
    {
        $this->publish($roundId, fn (): array => [
            'type' => 'official_results.round',
            'roundId' => $roundId,
            'round' => $this->getRoundResultsOverview->forRound($roundId),
        ]);
    }

    /**
     * @param callable(): array<string, mixed> $payload
     */
    private function publish(string $roundId, callable $payload): void
    {
        try {
            $organisers = $payload();
            $referees = $organisers;

            if (is_array($organisers['entries'] ?? null)) {
                // No viewer here - nobody's allow list counts: every private player is withheld
                $referees['entries'] = array_map(
                    static fn (mixed $entry): mixed => $entry instanceof RoundResultEntry ? $entry->forReferee(static fn (string $playerId): bool => false) : $entry,
                    $organisers['entries'],
                );
            }

            $organisersData = json_encode($organisers, JSON_THROW_ON_ERROR);
            $refereesData = json_encode($referees, JSON_THROW_ON_ERROR);

            if ($organisersData === $refereesData) {
                $this->hub->publish(new Update([self::topic($roundId), self::refereesTopic($roundId)], $organisersData, private: true));

                return;
            }

            $this->hub->publish(new Update(self::topic($roundId), $organisersData, private: true));
            $this->hub->publish(new Update(self::refereesTopic($roundId), $refereesData, private: true));
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not publish an official results update', [
                'round_id' => $roundId,
                'exception' => $exception,
            ]);
        }
    }
}
