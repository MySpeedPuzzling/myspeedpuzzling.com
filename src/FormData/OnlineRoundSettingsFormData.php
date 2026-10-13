<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineMissingPieceRule;
use SpeedPuzzling\Web\Value\OnlineUnfinishedMeasure;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The settings of a round's online timing the organiser chooses on its "Online timing" page (online_round_settings,
 * docs/features/online-competitions/). Video, participants seeing each other, the studio and recording come with
 * Phase 1B - they are not in the form and keep their values.
 */
final class OnlineRoundSettingsFormData
{
    #[Assert\NotNull]
    public null|OnlineDetectionMode $detectionMode = null;

    #[Assert\NotNull]
    public null|OnlineUnfinishedMeasure $unfinishedMeasure = null;

    #[Assert\NotNull]
    public null|OnlineMissingPieceRule $missingPieceRule = null;

    public static function fromOnlineRound(OnlineRound $onlineRound): self
    {
        $data = new self();
        $data->detectionMode = $onlineRound->detectionMode;
        $data->unfinishedMeasure = $onlineRound->unfinishedMeasure;
        $data->missingPieceRule = $onlineRound->missingPieceRule;

        return $data;
    }
}
