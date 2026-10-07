<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What SuspiciousTimeClassifier says about one solo time.
 *
 * A raised time carries exactly one trigger reason first, then its explanations. Without an expectation (a player
 * without times of their own, a pair/team result) ratio, expectedSeconds and expectedSource are null; the score is
 * then how far the pace is beyond the community's 99.9th percentile or below its slow floor.
 */
readonly final class SuspicionAssessment
{
    /**
     * @param list<SuspiciousTimeReason> $reasons
     */
    public function __construct(
        public SuspicionCheckOutcome $outcome,
        public null|SuspiciousTimeTier $tier = null,
        // expected ÷ entered (below 1 for a slow time)
        public null|float $ratio = null,
        public null|int $expectedSeconds = null,
        public null|ExpectedTimeSource $expectedSource = null,
        public array $reasons = [],
        // hours_left_out: the time with the hours that most likely went missing
        public null|int $suggestedSeconds = null,
        // How far off, for ordering within a tier: the ratio (expected ÷ entered fast, entered ÷ expected slow), or
        // how far beyond the community's 99.9th percentile / below its slow floor
        public null|float $score = null,
    ) {
    }

    public function isRaised(): bool
    {
        return $this->outcome === SuspicionCheckOutcome::Raised;
    }

    /**
     * Fast or slow - the direction of the trigger; null when not raised.
     */
    public function direction(): null|SuspicionDirection
    {
        return $this->reasons === [] ? null : $this->reasons[0]->code->direction();
    }

    public function withReason(SuspiciousTimeReason $reason): self
    {
        return new self(
            outcome: $this->outcome,
            tier: $this->tier,
            ratio: $this->ratio,
            expectedSeconds: $this->expectedSeconds,
            expectedSource: $this->expectedSource,
            reasons: [...$this->reasons, $reason],
            suggestedSeconds: $this->suggestedSeconds,
            score: $this->score,
        );
    }

    public function hasReason(SuspiciousTimeReasonCode $code): bool
    {
        return $this->reason($code) !== null;
    }

    public function reason(SuspiciousTimeReasonCode $code): null|SuspiciousTimeReason
    {
        foreach ($this->reasons as $reason) {
            if ($reason->code === $code) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * What a moderator's "Mark suspicious" ticks by default.
     *
     * @return list<SuspiciousTimeReason>
     */
    public function reasonsShownToPlayer(): array
    {
        return array_values(array_filter(
            $this->reasons,
            static fn (SuspiciousTimeReason $reason): bool => $reason->code->isShownToPlayer(),
        ));
    }

    /**
     * @return list<string>
     */
    public function reasonCodes(): array
    {
        return array_map(static fn (SuspiciousTimeReason $reason): string => $reason->code->value, $this->reasons);
    }
}
