<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Message\WarmAnalyticsStudioPredictions;

/**
 * The analytics studio's `round-predictions` answer (AnalyticsStudioRoundPredictionsBuilder) and the warm the
 * controller is to send with it - null when everything is cached, nothing can be predicted, or a warm was sent for the
 * same visible puzzles within the last minute.
 */
readonly final class AnalyticsStudioRoundPredictionsAnswer
{
    /**
     * @param array<string, mixed> $json
     */
    public function __construct(
        public array $json,
        public null|WarmAnalyticsStudioPredictions $warm,
    ) {
    }
}
