<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;

/**
 * The core sections of one player's Insights page for one filter state (InsightsBuilder) - plain data, serializable
 * (the page cache stores it). Every section carries its sample size and a "not sure yet" state; the page's wording
 * (Phase 6) turns codes and numbers into sentences. What is free and what members only: InsightsSection::isFree();
 * the builder computes everything for everybody, so a lock can show real counts - a template must not render a
 * members-only section's content for a free player. docs/features/insights/computation.md "Core sections".
 */
readonly final class InsightsReport
{
    /** The hydrate statement reads at most this many puzzles (GetInsightsPuzzles) */
    public const int MAX_SHOWN_PUZZLES = 50;

    /**
     * @param array<string, string|list<string>> $criteria the applied filters (InsightsCriteria::toQueryParameters())
     * @param list<string> $strippedForMembers filters a free player asked for and did not get
     * @param list<InsightsFinding> $findings at most 8, page order
     * @param list<string> $puzzleIdsShown the puzzles the sections list, at most MAX_SHOWN_PUZZLES - hydrate these
     */
    public function __construct(
        public string $playerId,
        public bool $isMember,
        public array $criteria,
        public array $strippedForMembers,
        public DateTimeImmutable $builtAt,
        public int $generation,
        public bool $hasTimezone,
        public InsightsSummary $summary,
        public array $findings,
        public InsightsProfileCardSection $profileCard,
        public InsightsFormSection $form,
        public InsightsSkillMapSection $skillMap,
        public InsightsExpectedSection $expectedVsActual,
        public InsightsHistogramSection $histogram,
        public InsightsCloseCallsSection $closeCalls,
        public InsightsLearningSection $learningCurve,
        public InsightsHabitsSection $habits,
        public InsightsConsistencySection $consistency,
        public InsightsAccuracySection $predictionAccuracy,
        public array $puzzleIdsShown,
    ) {
    }
}
