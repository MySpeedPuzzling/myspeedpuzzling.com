<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Results\OnlineAccuracyRow;
use SpeedPuzzling\Web\Results\OnlineReviewSuggestion;
use SpeedPuzzling\Web\Results\OnlineReviewTap;
use SpeedPuzzling\Web\Value\AccuracySummary;
use SpeedPuzzling\Web\Value\OnlineReviewAssessment;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * The accuracy report of an online round, Phase 1 (docs/features/online-competitions/README.md §5.9 "Accuracy report"):
 * per participant the tap, the confirmed moment, the reviewer's adjustment (confirmed − tap), its source and how late
 * the tap arrived (OnlineAccuracyRow), and the distribution of the adjustment (AccuracySummary) - how far the Finished
 * button is from the moment a person confirmed. Read from the review's own assessment (OnlineReview::assessed()), so
 * the report and the review never disagree.
 *
 * Extension point for Phase 2: MEASURES names the distributions, each one a column of the rows; the detection error
 * (confirmed − detected) and the delta (tap − detected) join it as `detection_error` and `delta`, read from the finish
 * candidates the same way the adjustment is read from the taps.
 */
readonly final class OnlineAccuracy
{
    public const string ADJUSTMENT = 'adjustment';

    // The distributions the report measures, in order
    public const array MEASURES = [self::ADJUSTMENT];

    public function __construct(
        private OnlineReview $onlineReview,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{rows: list<OnlineAccuracyRow>, summaries: array<string, AccuracySummary>, startedAtMs: null|int}
     */
    public function forRound(CompetitionRound $round): array
    {
        $now = $this->clock->now();
        $clock = RoundClock::fromRound($round, $now);
        [$startedAtMs] = OnlineReviewAssessment::window($clock);
        $rows = [];

        foreach ($this->onlineReview->assessed($round, $clock, RoundClock::milliseconds($now)) as ['entry' => $entry, 'assessment' => $assessment]) {
            $tap = $assessment->adjustedTap ?? self::suggestedTap($assessment->suggestion, $assessment->taps);

            $rows[] = new OnlineAccuracyRow(
                participantId: $entry->participantId,
                name: $entry->name,
                status: $entry->status,
                tapMomentMs: $tap?->momentMs,
                tapElapsedMs: $tap === null || $startedAtMs === null ? null : $tap->momentMs - $startedAtMs,
                confirmedMomentMs: $entry->confirmedMomentMs,
                confirmedElapsedMs: $entry->confirmedMomentMs === null || $startedAtMs === null ? null : $entry->confirmedMomentMs - $startedAtMs,
                adjustmentMs: $assessment->adjustmentMs,
                source: $entry->confirmedSource,
                arrivedLateMs: $tap?->lateMs,
                official: $entry->official,
            );
        }

        // In finishing order - those without a tap after them, by name as the review lists them
        usort($rows, static fn (OnlineAccuracyRow $a, OnlineAccuracyRow $b): int => [$a->tapMomentMs === null, $a->tapMomentMs ?? 0] <=> [$b->tapMomentMs === null, $b->tapMomentMs ?? 0]);

        $adjustments = array_values(array_filter(
            array_map(static fn (OnlineAccuracyRow $row): null|int => $row->adjustmentMs, $rows),
            static fn (null|int $value): bool => $value !== null,
        ));

        return [
            'rows' => $rows,
            'summaries' => [self::ADJUSTMENT => AccuracySummary::of($adjustments)],
            'startedAtMs' => $startedAtMs,
        ];
    }

    /**
     * @param list<OnlineReviewTap> $taps
     */
    private static function suggestedTap(OnlineReviewSuggestion $suggestion, array $taps): null|OnlineReviewTap
    {
        foreach ($taps as $tap) {
            if ($tap->eventId === $suggestion->tapEventId) {
                return $tap;
            }
        }

        return null;
    }
}
