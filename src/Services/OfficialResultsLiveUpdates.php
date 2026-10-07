<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells the organisers' open pages what changed in a round's official results - private Mercure updates on
 * `/round-results/{roundId}` (topic()), authorised only on the organiser pages: a page adds the topic with
 * MercureTopicCollector::addTopic(OfficialResultsLiveUpdates::topic($roundId)) and the base layout's mercure-hub
 * controller subscribes, dispatching each update as a `mercure:message` event on `document`.
 *
 * Called by the controllers after the dispatch returned, i.e. after the commit. A Mercure failure never fails the
 * write: it is logged (warning) and the pages catch up on their next state fetch.
 *
 * Payloads (`type` always prefixed `official_results.`):
 * - official_results.entries {roundId, entries: [RoundResultEntry...]} - the changed entries as they are now
 * - official_results.refresh {roundId} - too many entries changed at once: fetch the round's state again
 * - official_results.round {roundId, round: RoundResultsOverview} - publication, table numbers usage, counts
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
    ) {
    }

    public static function topic(string $roundId): string
    {
        return '/round-results/' . strtolower($roundId);
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
            $this->hub->publish(new Update(
                self::topic($roundId),
                json_encode($payload(), JSON_THROW_ON_ERROR),
                private: true,
            ));
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not publish an official results update', [
                'round_id' => $roundId,
                'exception' => $exception,
            ]);
        }
    }
}
