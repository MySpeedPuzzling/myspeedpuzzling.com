<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\AnalyticsStudioNotFound;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioRound;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * The analytics studio's public, data-free signal (analytics-studio.md R21-R24): "something of this public round
 * changed - read the state again". One public Mercure update on `/analytics-studio-signal/{roundId}` (topic()) with a
 * constant payload `{"type": "analytics_studio.signal", "roundId": "…"}` - no counts, no entries, no puzzles: identical
 * for whatever changed (the swap test). The open studios answer it with a settled state fetch
 * (assets/analytics-studio/refresh.js, SIGNAL_SETTLE_MS) - every read-side rule stays the state's.
 *
 * Called by OfficialResultsLiveUpdates only - i.e. after the commit, from the controllers (a rolled back write signals
 * nothing). Every method:
 * 1. a round nobody has open in the studio (AnalyticsStudioWatch, 180 s) - nothing, and no statement (one cache read);
 * 2. a watched round is read once with the studio's own visibility rule (GetAnalyticsStudioRound - a draft, unapproved
 *    or slug-less round never signals), at publish time, so a stale marker never leaks;
 * 3. resultsChanged() only while the round's results are public (published, or live results on) - an unpublished
 *    result must not be told by its timing either.
 * A failure never fails the write: logged (warning), the studio catches up on its fallback poll.
 */
readonly final class AnalyticsStudioSignals
{
    public const string TYPE = 'analytics_studio.signal';

    public function __construct(
        private HubInterface $hub,
        private AnalyticsStudioWatch $watch,
        private GetAnalyticsStudioRound $getAnalyticsStudioRound,
        private LoggerInterface $logger,
    ) {
    }

    public static function topic(string $roundId): string
    {
        return '/analytics-studio-signal/' . strtolower($roundId);
    }

    /**
     * @return array{type: string, roundId: string}
     */
    public static function payload(string $roundId): array
    {
        return ['type' => self::TYPE, 'roundId' => strtolower($roundId)];
    }

    /**
     * Entries recorded, confirmed, seated, advanced into the round or taken out of it.
     */
    public function resultsChanged(string $roundId): void
    {
        $this->signal($roundId, resultsOnly: true);
    }

    /**
     * The round itself changed - publication, table numbers usage, its details.
     */
    public function roundChanged(string $roundId): void
    {
        $this->signal($roundId, resultsOnly: false);
    }

    /**
     * The reveal push - what of the round's puzzles is visible changed or will at another moment.
     */
    public function puzzlesChanged(string $roundId): void
    {
        $this->signal($roundId, resultsOnly: false);
    }

    private function signal(string $roundId, bool $resultsOnly): void
    {
        try {
            if ($this->watch->watched($roundId) === false) {
                return;
            }

            try {
                $round = $this->getAnalyticsStudioRound->byId($roundId);
            } catch (AnalyticsStudioNotFound) {
                return;
            }

            if ($resultsOnly && $round->published() === false && $round->liveResults === false) {
                return;
            }

            $this->hub->publish(new Update(
                self::topic($roundId),
                json_encode(self::payload($roundId), JSON_THROW_ON_ERROR),
                private: false,
            ));
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not publish an analytics studio signal', [
                'round_id' => $roundId,
                'exception' => $exception,
            ]);
        }
    }
}
