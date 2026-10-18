<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use DateTimeInterface;
use SpeedPuzzling\Web\Results\PuzzleImageAnalysisResult;

/**
 * A stored image analysis as the internal API answers it (PUT …/image-analysis/{analyzer}, GET …/image-analysis).
 */
final class PuzzleImageAnalysisJson
{
    /**
     * @return array<string, mixed>
     */
    public static function of(PuzzleImageAnalysisResult $analysis): array
    {
        return [
            'puzzleId' => $analysis->puzzleId,
            'analyzer' => $analysis->analyzer->value,
            'analyzerVersion' => $analysis->analyzerVersion,
            'imageFingerprint' => $analysis->imageFingerprint,
            // It describes the picture the puzzle has now
            'current' => $analysis->current,
            'analyzedAt' => $analysis->analyzedAt?->format(DateTimeInterface::ATOM),
            'receivedAt' => $analysis->receivedAt->format(DateTimeInterface::ATOM),
            'result' => $analysis->result,
        ];
    }
}
