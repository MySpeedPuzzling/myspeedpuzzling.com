<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One reason of a raised time with its numbers - stored as `{code, params}` in suspicious_time_case.reasons /
 * reasons_shown, and rendered in the player's language by templates/suspicious_time/_reason.html.twig.
 *
 * Params by code (seconds are whole seconds, ratios rounded to 2 decimals, PPM to 1 decimal):
 * - faster_than_predicted: expected, entered, ratio (expected ÷ entered), pieces
 * - faster_than_usual: expected, entered, ratio (expected ÷ entered), pieces, source (baseline|pace)
 * - beyond_known_pace: ppm, p999_ppm (the community's 99.9th percentile of the range), entered, pieces
 * - slower_than_predicted: expected, entered, ratio (entered ÷ expected), pieces
 * - slower_than_usual: expected, entered, ratio (entered ÷ expected), pieces, source (baseline|pace)
 * - below_slow_floor: ppm, floor_ppm (a tenth of the community median of the range and puzzling type), median (the
 *   community median time for the piece count), entered, pieces, puzzling_type (solo|duo|team)
 * - hours_left_out: suggested, hours
 * - teammates_saved_group: time_id, seconds, puzzling_type (duo|team)
 * - comment_mentions_group: word
 * - often_in_group: group_results, group_expected
 * - other_edition: puzzle_id, name, pieces
 * - fastest_on_puzzle: results, fastest
 * - minutes_in_hours_box: suggested
 * - includes_breaks: entered
 * - new_player: results
 * - confirmed_while_saving: expected
 * - prediction_from_slow_attempt: predicted (the stored prediction), previous (the earlier attempt it came from, or
 *   null), raised_slow (an earlier attempt of the puzzle has a pending or marked slow case)
 */
readonly final class SuspiciousTimeReason
{
    /**
     * @param array<string, int|float|string|bool|null> $params
     */
    public function __construct(
        public SuspiciousTimeReasonCode $code,
        public array $params = [],
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $code = $data['code'] ?? null;
        assert(is_string($code));

        /** @var array<string, int|float|string|bool|null> $params */
        $params = is_array($data['params'] ?? null) ? $data['params'] : [];

        return new self(SuspiciousTimeReasonCode::from($code), $params);
    }

    /**
     * @param array<mixed> $list
     * @return list<self>
     */
    public static function listFromArray(array $list): array
    {
        $reasons = [];

        foreach ($list as $item) {
            if (is_array($item)) {
                $reasons[] = self::fromArray($item);
            }
        }

        return $reasons;
    }

    /**
     * @param list<self> $reasons
     * @return list<array{code: string, params: array<string, int|float|string|bool|null>}>
     */
    public static function listToArray(array $reasons): array
    {
        return array_map(static fn (self $reason): array => $reason->toArray(), $reasons);
    }

    /**
     * @return array{code: string, params: array<string, int|float|string|bool|null>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'params' => $this->params,
        ];
    }

    /**
     * The time a reason suggests (hours left out, minutes in the hours box) - only while the time still is the entry
     * the suggestion was made for (its `entered`; reasons stored without it: the trigger's). An edited time makes it
     * stale: "Fix the time" must not bring back a time worked out for another one.
     *
     * @param list<self> $reasons
     */
    public static function suggestionFor(array $reasons, null|int $currentSeconds): null|int
    {
        if ($currentSeconds === null) {
            return null;
        }

        $enteredByAnyReason = null;

        foreach ($reasons as $reason) {
            $enteredByAnyReason ??= $reason->intParam('entered');
        }

        foreach ($reasons as $reason) {
            if ($reason->code !== SuspiciousTimeReasonCode::HoursLeftOut && $reason->code !== SuspiciousTimeReasonCode::MinutesInHoursBox) {
                continue;
            }

            $suggested = $reason->intParam('suggested');
            $entered = $reason->intParam('entered') ?? $enteredByAnyReason;

            if ($suggested !== null && $suggested > 0 && $entered === $currentSeconds) {
                return $suggested;
            }
        }

        return null;
    }

    public function param(string $name): null|int|float|string|bool
    {
        return $this->params[$name] ?? null;
    }

    public function intParam(string $name): null|int
    {
        $value = $this->params[$name] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
