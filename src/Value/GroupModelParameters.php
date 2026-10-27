<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The fitted pair/team model (docs/features/puzzle-intelligence/README.md "Pair and team predictions",
 * Group\GroupPredictionModel), stored as JSON in group_prediction_fit. Every number is in log seconds / log units.
 *
 * - coordination: log c per "size|first|unknown" (size 2 / 3 / 4 = four or more, first 1 / 0, unknown members 0 / 1 / 2 = two or more)
 * - kappaKnown / kappaUnknown: the synergy's pseudo-count (a pair/team without / with unknown members)
 * - halfLifeDays: how fast a pair/team's earlier results fade from its synergy (null = never)
 * - memberKappa: the pseudo-count of a member's offset over all their pairs/teams - the synergy's prior
 * - experienceOffsets / sourceOffsets: shrunk centre offsets per the pair/team's earlier results and the difficulty's source
 * - repeatLogRatio: a repeat after the same pair/team's own earlier time on the puzzle = that time × e^ratio
 * - scaleFactors / quantiles: the window - the residual's scale per factor level (kind, difficulty source, unknown
 *   members, experience, size) and the quantiles (10, 25, 75, 90 %) of the scaled residual per kind
 * - baselineCoordination / baselineQuantiles: the reference (the coordination rule, "size|first"; quantiles per size)
 */
readonly final class GroupModelParameters
{
    /** The parts an evaluation can switch off one at a time (`evaluate-group-predictions --off=`) */
    public const string PART_SYNERGY = 'synergy';
    public const string PART_DRIFT = 'drift';
    public const string PART_MEMBER = 'member';
    public const string PART_DECAY = 'decay';
    public const string PART_OFFSETS = 'offsets';
    public const string PART_OWN_REPEAT = 'own_repeat';
    public const string PART_EVENT = 'event';

    public const array PARTS = [self::PART_SYNERGY, self::PART_DRIFT, self::PART_MEMBER, self::PART_DECAY, self::PART_OFFSETS, self::PART_OWN_REPEAT, self::PART_EVENT];

    /**
     * @param list<string> $off
     * @param array<int|string, float> $coordination
     * @param array<int|string, float> $experienceOffsets
     * @param array<int|string, float> $sourceOffsets
     * @param array<int|string, float> $eventOffsets
     * @param array<int|string, float> $scaleFactors
     * @param array<int|string, list<float>> $quantiles
     * @param array<int|string, float> $baselineCoordination
     * @param array<int|string, list<float>> $baselineQuantiles
     * @param array<string, int|float|string> $summary
     */
    public function __construct(
        public int $modelVersion,
        public float $tau,
        public array $off,
        public array $coordination,
        public float $kappaKnown,
        public float $kappaUnknown,
        public null|float $halfLifeDays,
        public float $memberKappa,
        public array $experienceOffsets,
        public array $sourceOffsets,
        public array $eventOffsets,
        public float $repeatLogRatio,
        public array $scaleFactors,
        public array $quantiles,
        public array $baselineCoordination,
        public array $baselineQuantiles,
        public array $summary = [],
    ) {
    }

    public function isOn(string $part): bool
    {
        return !in_array($part, $this->off, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model_version' => $this->modelVersion,
            'tau' => $this->tau,
            'off' => $this->off,
            'coordination' => $this->coordination,
            'kappa_known' => $this->kappaKnown,
            'kappa_unknown' => $this->kappaUnknown,
            'half_life_days' => $this->halfLifeDays,
            'member_kappa' => $this->memberKappa,
            'experience_offsets' => $this->experienceOffsets,
            'source_offsets' => $this->sourceOffsets,
            'event_offsets' => $this->eventOffsets,
            'repeat_log_ratio' => $this->repeatLogRatio,
            'scale_factors' => $this->scaleFactors,
            'quantiles' => $this->quantiles,
            'baseline_coordination' => $this->baselineCoordination,
            'baseline_quantiles' => $this->baselineQuantiles,
            'summary' => $this->summary,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $halfLife = $data['half_life_days'] ?? null;

        return new self(
            modelVersion: self::int($data['model_version'] ?? 0),
            tau: self::float($data['tau'] ?? 0.5),
            off: array_values(array_filter(is_array($data['off'] ?? null) ? $data['off'] : [], 'is_string')),
            coordination: self::floats($data['coordination'] ?? []),
            kappaKnown: self::float($data['kappa_known'] ?? 3.0),
            kappaUnknown: self::float($data['kappa_unknown'] ?? 1.0),
            halfLifeDays: is_int($halfLife) || is_float($halfLife) ? (float) $halfLife : null,
            memberKappa: self::float($data['member_kappa'] ?? 5.0),
            experienceOffsets: self::floats($data['experience_offsets'] ?? []),
            sourceOffsets: self::floats($data['source_offsets'] ?? []),
            eventOffsets: self::floats($data['event_offsets'] ?? []),
            repeatLogRatio: self::float($data['repeat_log_ratio'] ?? log(0.9)),
            scaleFactors: self::floats($data['scale_factors'] ?? []),
            quantiles: self::quantileLists($data['quantiles'] ?? []),
            baselineCoordination: self::floats($data['baseline_coordination'] ?? []),
            baselineQuantiles: self::quantileLists($data['baseline_quantiles'] ?? []),
            summary: self::summaryOf($data['summary'] ?? []),
        );
    }

    private static function int(mixed $value): int
    {
        return is_int($value) || is_float($value) || is_string($value) ? (int) $value : 0;
    }

    private static function float(mixed $value): float
    {
        return is_int($value) || is_float($value) || is_string($value) ? (float) $value : 0.0;
    }

    /**
     * @return array<int|string, float>
     */
    private static function floats(mixed $values): array
    {
        $floats = [];

        if (is_array($values)) {
            foreach ($values as $key => $value) {
                if (is_int($value) || is_float($value)) {
                    $floats[$key] = (float) $value;
                }
            }
        }

        return $floats;
    }

    /**
     * @return array<int|string, list<float>>
     */
    private static function quantileLists(mixed $values): array
    {
        $lists = [];

        if (is_array($values)) {
            foreach ($values as $key => $list) {
                if (is_array($list) && count($list) === 4) {
                    $lists[$key] = array_values(array_map(static fn (mixed $value): float => self::float($value), $list));
                }
            }
        }

        return $lists;
    }

    /**
     * @return array<string, int|float|string>
     */
    private static function summaryOf(mixed $values): array
    {
        $summary = [];

        if (is_array($values)) {
            foreach ($values as $key => $value) {
                if (is_int($value) || is_float($value) || is_string($value)) {
                    $summary[(string) $key] = $value;
                }
            }
        }

        return $summary;
    }
}
