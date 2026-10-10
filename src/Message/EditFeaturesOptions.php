<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class EditFeaturesOptions
{
    public function __construct(
        public string $playerId,
        public bool $streakOptedOut,
        public bool $rankingOptedOut,
        public bool $timePredictionsOptedOut,
        /** Null = field not shown (feature-flagged), keep the stored preference. */
        public null|bool $experienceSystemOptedOut = null,
        /** Null = field not shown (karma launches with XP), keep the stored preference. */
        public null|bool $karmaOptedOut = null,
    ) {
    }
}
