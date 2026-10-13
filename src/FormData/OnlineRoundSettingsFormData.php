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
 * docs/features/online-competitions/). Live video and participants seeing each other are in the form only while a
 * media server is configured (LiveKitSettings, contract phase-1b.md §2) - without one they keep their values. The studio
 * and recording are not in the form and keep theirs.
 */
final class OnlineRoundSettingsFormData
{
    #[Assert\NotNull]
    public null|OnlineDetectionMode $detectionMode = null;

    #[Assert\NotNull]
    public null|OnlineUnfinishedMeasure $unfinishedMeasure = null;

    #[Assert\NotNull]
    public null|OnlineMissingPieceRule $missingPieceRule = null;

    public bool $videoEnabled = false;

    public bool $participantsSeeEachOther = false;

    public static function fromOnlineRound(OnlineRound $onlineRound): self
    {
        $data = new self();
        $data->detectionMode = $onlineRound->detectionMode;
        $data->unfinishedMeasure = $onlineRound->unfinishedMeasure;
        $data->missingPieceRule = $onlineRound->missingPieceRule;
        $data->videoEnabled = $onlineRound->videoEnabled;
        $data->participantsSeeEachOther = $onlineRound->participantsSeeEachOther;

        return $data;
    }
}
