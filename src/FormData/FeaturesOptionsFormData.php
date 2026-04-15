<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Results\PlayerProfile;

final class FeaturesOptionsFormData
{
    public bool $streakOptedOut = false;
    public bool $rankingOptedOut = false;
    public bool $timePredictionsOptedOut = false;

    public static function fromPlayerProfile(PlayerProfile $playerProfile): self
    {
        $data = new self();
        $data->streakOptedOut = $playerProfile->streakOptedOut;
        $data->rankingOptedOut = $playerProfile->rankingOptedOut;
        $data->timePredictionsOptedOut = $playerProfile->timePredictionsOptedOut;

        return $data;
    }
}
