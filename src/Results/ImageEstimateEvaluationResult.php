<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use Nette\Utils\Json;

/**
 * A stored gate record (ImageEstimateEvaluation) as the gates read it - the picture estimates' (ImageEstimateGate) or a
 * candidate time model's (PredictionCandidateGate). Records from before gate v2 read as gate version 1.
 */
readonly final class ImageEstimateEvaluationResult
{
    public const string COLUMNS = 'id, evaluated_at, model_version, newer_than, measured_min_indices, groups, overall, by_k, gate_version, subject, split_at';

    /**
     * @param list<array<string, mixed>> $groups
     * @param array<string, mixed> $overall
     * @param list<array<string, mixed>> $byK
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $evaluatedAt,
        public int $modelVersion,
        public null|DateTimeImmutable $newerThan,
        public int $measuredMinIndices,
        public array $groups,
        public array $overall,
        public array $byK,
        public int $gateVersion = 1,
        public string $subject = 'image',
        public null|DateTimeImmutable $splitAt = null,
    ) {
    }

    /**
     * @return null|array<string, mixed> the numbers of one size group × image kind
     */
    public function group(string $sizeGroup, string $imageKind): null|array
    {
        foreach ($this->groups as $group) {
            if (($group['sizeGroup'] ?? null) === $sizeGroup && ($group['imageKind'] ?? null) === $imageKind) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row the COLUMNS (gate_version, subject, split_at may be missing: a gate v1 row)
     */
    public static function fromDatabaseRow(array $row): self
    {
        assert(is_string($row['id']));
        assert(is_string($row['evaluated_at']));
        assert(is_int($row['model_version']) || is_string($row['model_version']));
        assert(is_string($row['newer_than']) || $row['newer_than'] === null);
        assert(is_int($row['measured_min_indices']) || is_string($row['measured_min_indices']));
        assert(is_string($row['groups']));
        assert(is_string($row['overall']));
        assert(is_string($row['by_k']));

        /** @var list<array<string, mixed>> $groups */
        $groups = Json::decode($row['groups'], forceArrays: true);
        /** @var array<string, mixed> $overall */
        $overall = Json::decode($row['overall'], forceArrays: true);
        /** @var list<array<string, mixed>> $byK */
        $byK = Json::decode($row['by_k'], forceArrays: true);
        $gateVersion = $row['gate_version'] ?? 1;
        $subject = $row['subject'] ?? 'image';
        $splitAt = $row['split_at'] ?? null;

        return new self(
            id: $row['id'],
            evaluatedAt: new DateTimeImmutable($row['evaluated_at']),
            modelVersion: (int) $row['model_version'],
            newerThan: $row['newer_than'] !== null ? new DateTimeImmutable($row['newer_than']) : null,
            measuredMinIndices: (int) $row['measured_min_indices'],
            groups: $groups,
            overall: $overall,
            byK: $byK,
            gateVersion: is_int($gateVersion) || is_string($gateVersion) ? (int) $gateVersion : 1,
            subject: is_string($subject) ? $subject : 'image',
            splitAt: is_string($splitAt) ? new DateTimeImmutable($splitAt) : null,
        );
    }
}
