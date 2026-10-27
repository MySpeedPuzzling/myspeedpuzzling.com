<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation;

use SpeedPuzzling\Web\Entity\ImageEstimateEvaluation;

/**
 * How the gate commands print a gate v2 record's numbers (`evaluate-image-estimates`, `evaluate-prediction-candidate`).
 */
final class GateRecordPrinter
{
    public static function json(ImageEstimateEvaluation $evaluation): string
    {
        return json_encode([
            'id' => $evaluation->id->toString(),
            'evaluatedAt' => $evaluation->evaluatedAt->format(DATE_ATOM),
            'subject' => $evaluation->subject,
            'gateVersion' => $evaluation->gateVersion,
            'modelVersion' => $evaluation->modelVersion,
            'newerThan' => $evaluation->newerThan?->format(DATE_ATOM),
            'splitAt' => $evaluation->splitAt?->format(DATE_ATOM),
            'measuredMinIndices' => $evaluation->measuredMinIndices,
            'overall' => $evaluation->overall,
            'byK' => $evaluation->byK,
            'groups' => $evaluation->groups,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public static function honesty(array $metrics): string
    {
        $calibration = is_array($metrics['calibration'] ?? null) ? $metrics['calibration'] : [];

        return sprintf(
            '%s [%s-%s] / %s [%s-%s] n %s%s',
            self::show($calibration['hit50'] ?? null),
            self::show($calibration['hit50Low'] ?? null),
            self::show($calibration['hit50High'] ?? null),
            self::show($calibration['hit80'] ?? null),
            self::show($calibration['hit80Low'] ?? null),
            self::show($calibration['hit80High'] ?? null),
            self::show($calibration['testRows'] ?? null),
            ($calibration['previouslyOpen'] ?? false) === true ? ' (open before)' : '',
        );
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public static function k0Skill(array $metrics): string
    {
        $k0 = PredictionGroupAccumulator::skillBucket($metrics['skill'] ?? null, '0');

        return sprintf(
            '%s (%s / %s), %s vs %s',
            self::show($k0['errorRatio'] ?? null),
            self::show($k0['maeCandidate'] ?? null),
            self::show($k0['maeReference'] ?? null),
            self::show($k0['width80Candidate'] ?? null),
            self::show($k0['width80Reference'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public static function bucketSkill(array $metrics, string $bucket): string
    {
        $numbers = PredictionGroupAccumulator::skillBucket($metrics['skill'] ?? null, $bucket);

        if ($numbers === []) {
            return '-';
        }

        return sprintf('%s ± %s (%s)', self::show($numbers['meanDifference'] ?? null), self::show($numbers['differenceSe'] ?? null), self::show($numbers['rows'] ?? null));
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public static function slice(array $metrics, string $name): string
    {
        $slices = is_array($metrics['slices'] ?? null) ? $metrics['slices'] : [];
        $slice = is_array($slices[$name] ?? null) ? $slices[$name] : null;

        if ($slice === null) {
            return '-';
        }

        return sprintf(
            '%s: %s / %s, %s / %s',
            self::show($slice['rows'] ?? null),
            self::show($slice['maeCandidate'] ?? null),
            self::show($slice['maeReference'] ?? null),
            self::show($slice['window50Hit'] ?? null),
            self::show($slice['window80Hit'] ?? null),
        );
    }

    public static function show(mixed $value): string
    {
        return match (true) {
            is_float($value) => sprintf('%.3f', $value),
            is_int($value), is_string($value) => (string) $value,
            default => '-',
        };
    }
}
