<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\TasteFitter;

/**
 * The candidate's (v6) picture term in the first-try window of a puzzle without any measured index (k = 0): how much
 * more (or less) players will disagree about it than the window's other terms say, from how alike its pieces look
 * (PictureLookalikes; data-mining F8). Fitted daily (CandidateModelFitter): a ridge regression of the window's squared
 * error less its fitted variance on the standardised numbers over first tries at puzzles with 0-9 indices, centred on
 * the median puzzle (a relative term - the level stays with the other terms), then its window weight with the other
 * terms. term() = weight × (prediction - centre), signed: a puzzle with distinct pieces narrows its window.
 */
readonly final class PictureDispersionModel
{
    /**
     * @param list<float> $means of the numbers (PictureLookalikes::NAMES order) over the fit's rows
     * @param list<float> $scales their standard deviations
     * @param list<float> $coefficients per standardised number
     * @param array<string, int|float|string> $summary
     */
    public function __construct(
        public array $means,
        public array $scales,
        public array $coefficients,
        public float $intercept,
        public float $centre,
        public float $weight,
        public array $summary = [],
    ) {
    }

    /**
     * The ridge regression of the targets on the standardised numbers (the intercept unpenalised), centred on the
     * median prediction over $centreOn (one entry per puzzle of the window), weight 0 until the window fit sets it.
     *
     * @param list<list<float>> $rows the numbers of each row (PictureLookalikes::NAMES order)
     * @param list<float> $targets
     * @param list<PictureLookalikes> $centreOn
     */
    public static function fit(array $rows, array $targets, array $centreOn, float $ridge): self
    {
        $size = count(PictureLookalikes::NAMES);
        $count = count($rows);
        $means = array_fill(0, $size, 0.0);
        $scales = array_fill(0, $size, 0.0);

        foreach ($rows as $row) {
            foreach ($row as $j => $value) {
                $means[$j] += $value / $count;
            }
        }

        foreach ($rows as $row) {
            foreach ($row as $j => $value) {
                $scales[$j] += ($value - $means[$j]) ** 2 / max($count - 1, 1);
            }
        }

        $scales = array_map(static fn (float $variance): float => sqrt($variance), $scales);
        $meanTarget = $count > 0 ? array_sum($targets) / $count : 0.0;
        $gram = array_fill(0, $size, array_fill(0, $size, 0.0));
        $moment = array_fill(0, $size, 0.0);

        foreach ($rows as $r => $row) {
            $z = [];

            foreach ($row as $j => $value) {
                $z[$j] = $scales[$j] > 0.0 ? ($value - $means[$j]) / $scales[$j] : 0.0;
            }

            foreach ($z as $i => $zi) {
                $moment[$i] += $zi * ($targets[$r] - $meanTarget);

                foreach ($z as $j => $zj) {
                    $gram[$i][$j] += $zi * $zj;
                }
            }
        }

        for ($i = 0; $i < $size; $i++) {
            $gram[$i][$i] += $ridge;
        }

        $round = static fn (float $value): float => round($value, 8);
        $model = new self(array_values(array_map($round, $means)), array_values(array_map($round, $scales)), array_map($round, TasteFitter::solve($gram, $moment)), $round($meanTarget), 0.0, 0.0);
        $predictions = array_map($model->relative(...), $centreOn);
        sort($predictions);
        $middle = intdiv(count($predictions), 2);
        $centre = $predictions === [] ? 0.0 : (count($predictions) % 2 === 1 ? $predictions[$middle] : ($predictions[$middle - 1] + $predictions[$middle]) / 2);

        return new self($model->means, $model->scales, $model->coefficients, $model->intercept, $round($centre), 0.0, ['picture_dispersion_rows' => $count]);
    }

    /**
     * The regression's prediction less the centre (log variance, signed) - 0 without the numbers.
     */
    public function relative(null|PictureLookalikes $lookalikes): float
    {
        if ($lookalikes === null || count($lookalikes->values) !== count($this->coefficients)) {
            return 0.0;
        }

        $prediction = $this->intercept;

        foreach ($this->coefficients as $index => $coefficient) {
            $scale = $this->scales[$index] ?? 0.0;
            $prediction += $scale > 0.0 ? $coefficient * ($lookalikes->values[$index] - ($this->means[$index] ?? 0.0)) / $scale : 0.0;
        }

        return $prediction - $this->centre;
    }

    /**
     * The window's variance term (weighted).
     */
    public function term(null|PictureLookalikes $lookalikes): float
    {
        return $this->weight * $this->relative($lookalikes);
    }

    public function with(float $weight): self
    {
        return new self($this->means, $this->scales, $this->coefficients, $this->intercept, $this->centre, $weight, $this->summary);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'means' => $this->means,
            'scales' => $this->scales,
            'coefficients' => $this->coefficients,
            'intercept' => $this->intercept,
            'centre' => $this->centre,
            'weight' => $this->weight,
            'summary' => $this->summary,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): null|self
    {
        $lists = [];

        foreach (['means', 'scales', 'coefficients'] as $key) {
            $values = $data[$key] ?? null;

            if (!is_array($values) || count($values) !== count(PictureLookalikes::NAMES)) {
                return null;
            }

            $lists[$key] = array_map(static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0, array_values($values));
        }

        $summary = [];

        foreach (is_array($data['summary'] ?? null) ? $data['summary'] : [] as $key => $value) {
            if (is_string($key) && (is_int($value) || is_float($value) || is_string($value))) {
                $summary[$key] = $value;
            }
        }

        return new self(
            $lists['means'],
            $lists['scales'],
            $lists['coefficients'],
            is_numeric($data['intercept'] ?? null) ? (float) $data['intercept'] : 0.0,
            is_numeric($data['centre'] ?? null) ? (float) $data['centre'] : 0.0,
            is_numeric($data['weight'] ?? null) ? (float) $data['weight'] : 0.0,
            $summary,
        );
    }
}
