<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use DateTimeInterface;
use SpeedPuzzling\Web\Results\PuzzleImageForAnalysis;

/**
 * A puzzle of the image analysis work list as the internal API answers it (GET /internal-api/puzzle-images and the
 * claims of POST /internal-api/puzzle-image-claims - the same shape).
 */
final class PuzzleImageForAnalysisJson
{
    /**
     * @param string $imageUrl the full-size original (UploaderHelper::getPublicPath())
     *
     * @return array<string, mixed>
     */
    public static function of(PuzzleImageForAnalysis $puzzle, string $imageUrl): array
    {
        $analyses = [];

        foreach ($puzzle->analyses as $analyzer => $analysis) {
            $analyses[$analyzer] = [
                'analyzerVersion' => $analysis['analyzerVersion'],
                'imageFingerprint' => $analysis['imageFingerprint'],
                'analyzedAt' => $analysis['analyzedAt']?->format(DateTimeInterface::ATOM),
                'receivedAt' => $analysis['receivedAt']->format(DateTimeInterface::ATOM),
            ];
        }

        $claims = [];

        foreach ($puzzle->claims as $analyzer => $claim) {
            $claims[$analyzer] = [
                'worker' => $claim['worker'],
                'expiresAt' => $claim['expiresAt']->format(DateTimeInterface::ATOM),
            ];
        }

        return [
            'puzzleId' => $puzzle->puzzleId,
            'name' => $puzzle->name,
            'piecesCount' => $puzzle->piecesCount,
            'manufacturer' => $puzzle->manufacturerId !== null
                ? ['id' => $puzzle->manufacturerId, 'name' => $puzzle->manufacturerName]
                : null,
            // The full-size original, as stored
            'imageUrl' => $imageUrl,
            'imageFingerprint' => $puzzle->imageFingerprint,
            'imageRatio' => $puzzle->imageRatio,
            // Objects even when empty
            'analyses' => (object) $analyses,
            // Who is working on the image now, per analyzer (claims not expired)
            'claims' => (object) $claims,
        ];
    }
}
