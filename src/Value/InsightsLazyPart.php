<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Services\Insights\InsightsCriteria;

/**
 * The lazy parts of the Insights page (docs/features/insights/page.md "Lazy frames"): each loads in its own Turbo
 * Frame below the fold, from its own route, after the first response. A part reads only the filters it uses
 * (filterKeys()) - its URL carries only those, and its frame id is keyed by them, so a filter it ignores never reloads
 * it: the Insights live component leaves a frame alone while its id stays the same (`data-live-ignore`) and replaces
 * it - a new `src`, Turbo loads it again - once the id changes.
 */
enum InsightsLazyPart: string
{
    /** Sections 9 (rating opportunities + pending + portfolio) and 10 (forecast) - one frame, the rating model loads once */
    case Rating = 'rating';
    /** Section 11, re-solve radar */
    case Resolve = 'resolve';
    /** Section 13, pairs & teams */
    case Pairs = 'pairs';
    /** Section 15, improvement curve (pace history) + rating over time (snapshots) */
    case Improvement = 'improvement';

    public function route(): string
    {
        return 'insights_' . $this->value;
    }

    /**
     * The section anchors in the frame, in page order (the question chips jump to them)
     *
     * @return list<string>
     */
    public function anchors(): array
    {
        return match ($this) {
            self::Rating => ['insights-rating', 'insights-forecast'],
            self::Resolve => ['insights-resolve'],
            self::Pairs => ['insights-pairs'],
            self::Improvement => ['insights-improvement'],
        };
    }

    /**
     * The URL query keys (InsightsCriteria::toQueryParameters()) the part reads. The MSP rating has its own window
     * (24 months) and piece count (500), so no filter applies to it.
     *
     * @return list<string>
     */
    public function filterKeys(): array
    {
        return match ($this) {
            self::Rating => [],
            self::Resolve => ['pieces'],
            self::Pairs, self::Improvement => ['period', 'year', 'from', 'to', 'pieces'],
        };
    }

    /**
     * Only the keys the part reads, from a request's query or the page's criteria
     *
     * @param array<mixed> $query
     * @return array<mixed>
     */
    public function filterQuery(array $query): array
    {
        return array_intersect_key($query, array_flip($this->filterKeys()));
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function query(InsightsCriteria $criteria): array
    {
        $query = array_intersect_key($criteria->toQueryParameters(), array_flip($this->filterKeys()));
        ksort($query);

        return $query;
    }

    /**
     * The frame's id: stable while the filters it reads stay the same
     */
    public function frameId(InsightsCriteria $criteria): string
    {
        return 'insights-frame-' . $this->value . '-' . substr(md5(($criteria->isMember ? 'm' : 'f') . json_encode($this->query($criteria), JSON_THROW_ON_ERROR)), 0, 8);
    }
}
