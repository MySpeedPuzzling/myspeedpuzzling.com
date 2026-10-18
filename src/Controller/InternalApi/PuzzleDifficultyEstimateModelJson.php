<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use DateTimeInterface;
use SpeedPuzzling\Web\Results\PuzzleDifficultyEstimateModelResult;

/**
 * A recorded run of the difficulty model as the internal API answers it (POST / GET
 * /internal-api/puzzle-difficulty-estimates/model).
 */
final class PuzzleDifficultyEstimateModelJson
{
    /**
     * @return array<string, mixed>
     */
    public static function of(PuzzleDifficultyEstimateModelResult $model): array
    {
        return [
            'id' => $model->id,
            'model' => $model->model,
            'modelVersion' => $model->modelVersion,
            'trainingSize' => $model->trainingSize,
            'holdoutSpearman' => $model->holdoutSpearman,
            'evaluation' => $model->evaluation,
            'createdAt' => $model->createdAt->format(DateTimeInterface::ATOM),
            'receivedAt' => $model->receivedAt->format(DateTimeInterface::ATOM),
        ];
    }
}
