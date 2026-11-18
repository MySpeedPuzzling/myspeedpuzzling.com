<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Whether the public may see the results judges and the desk recorded in a round that is not published yet - the one
 * rule every public surface follows (analytics-studio.md "FU-ST"): the event has competitor pages AND their live results
 * on ("Show results before publishing"). The setting does nothing while the pages are off. Read by the competitor page
 * (GetCompetitorPage - the only public page showing judge-entered results before publishing) and the analytics studio's
 * public state (GetAnalyticsStudioRound). The round results page and the standings show official results only once a
 * round is published - nothing judge-entered before that, whatever this says.
 */
final class LiveResultsBeforePublishing
{
    public static function shown(bool $competitorPages, bool $liveResults): bool
    {
        return $competitorPages && $liveResults;
    }

    /**
     * The same as a boolean SQL expression over a `competition` table alias.
     */
    public static function sql(string $competitionAlias): string
    {
        return "({$competitionAlias}.competitor_pages AND {$competitionAlias}.competitor_pages_live_results)";
    }
}
