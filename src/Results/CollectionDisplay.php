<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CollectionDisplayMode;

/**
 * What a collection page renders next to its items for the current viewer:
 * the effective display mode (a persisted "times + predictions" collapses to
 * "times" for a viewer who may not see predictions) and the data that mode
 * needs - both maps keyed by puzzle id and only filled for the active mode.
 */
readonly final class CollectionDisplay
{
    /**
     * @param array<string, PlayerPuzzleTimes> $myTimes
     * @param array<string, TimePredictionResult> $predictions
     */
    public function __construct(
        public CollectionDisplayMode $mode,
        public array $myTimes = [],
        public array $predictions = [],
    ) {
    }

    public static function off(): self
    {
        return new self(CollectionDisplayMode::Off);
    }

    /**
     * Template variables: `display_mode` always, the two maps only while the
     * mode shows them - the item partial keys "render my times" on `my_times`
     * being defined at all, so Off must not hand it an empty map.
     *
     * @return array<string, mixed>
     */
    public function templateParameters(): array
    {
        $parameters = ['display_mode' => $this->mode];

        if ($this->mode->showsTimes()) {
            $parameters['my_times'] = $this->myTimes;
        }

        if ($this->mode->showsPredictions()) {
            $parameters['predictions'] = $this->predictions;
        }

        return $parameters;
    }
}
