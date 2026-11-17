<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What the analytics studio's data-free shell renders (AnalyticsStudioPageBuilder, analytics-studio.md §1.1): the round
 * and its event's names, the round switcher (the event's slugged rounds) and the labels the browser modules draw with.
 * No entrant, result or puzzle - the page fills from the JSON answers.
 */
readonly final class AnalyticsStudioPage
{
    /**
     * @param array<string, string> $labels LABEL_NAMES of assets/analytics-studio/render.js => text in the page's locale
     */
    public function __construct(
        public AnalyticsStudioRound $round,
        public array $labels,
        public int $serverNowMs,
    ) {
    }

    /**
     * @return list<AnalyticsStudioEventRound> the round switcher: every slugged round of the event, this one included
     */
    public function switcher(): array
    {
        return $this->round->eventRounds;
    }
}
