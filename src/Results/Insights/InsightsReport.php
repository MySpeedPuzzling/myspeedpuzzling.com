<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;

/**
 * The core sections of one player's Insights page for one filter state (InsightsBuilder) - plain data, serializable
 * (the page cache stores it). Every section carries its sample size and a "not sure yet" state; the page's wording
 * (Phase 6) turns codes and numbers into sentences. What a free player's link asked for and did not get is the
 * request's own (InsightsCriteria::$strippedForMembers) - never in the cached report, whose cache key hashes the
 * stripped filters. What is free and what members only: InsightsSection::isFree(); the builder computes everything
 * for everybody, so a lock can show real counts - a template must not render a members-only section's content for a
 * free player. docs/features/insights/computation.md "Core sections".
 */
readonly final class InsightsReport
{
    /** The hydrate statement reads at most this many puzzles (GetInsightsPuzzles) */
    public const int MAX_SHOWN_PUZZLES = 50;

    /** The page works from this many solo results on; fewer = a friendly empty state (README "Free tier") */
    public const int MIN_RESULTS = 10;

    /**
     * What the page tells a player with fewer results it unlocks next (InsightsNudgeCode::MoreResults): the page itself,
     * then clear strengths and weak spots (a split needs 10 results of its own), then a steady form and profile card
     */
    public const array UNLOCK_THRESHOLDS = [self::MIN_RESULTS, 25, 50];

    /**
     * @param array<string, string|list<string>> $criteria the applied filters (InsightsCriteria::toQueryParameters())
     * @param list<InsightsFinding> $findings at most 8, page order
     * @param list<string> $puzzleIdsShown the puzzles the sections list, at most MAX_SHOWN_PUZZLES - hydrate these
     * @param int $totalResultCount every solo result of the player that counts, whatever the filters
     * @param bool $predictionsOff the player switched time predictions off: expected vs actual and prediction accuracy
     *                             are empty, and no finding rests on a prediction
     * @param list<InsightsNudge> $nudges "Make your insights sharper"
     */
    public function __construct(
        public string $playerId,
        public bool $isMember,
        public array $criteria,
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
        public int $totalResultCount = 0,
        public bool $predictionsOff = false,
        public array $nudges = [],
        public InsightsFilterOptions $filterOptions = new InsightsFilterOptions(),
        /** The stored 500-piece MSP rating (player_elo) and its place on today's ladder - the top row's rating tile */
        public null|float $rating500 = null,
        public null|int $ratingPlace500 = null,
    ) {
    }
}
