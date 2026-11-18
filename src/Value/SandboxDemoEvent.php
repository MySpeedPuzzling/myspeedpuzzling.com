<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A made-up event the sandbox demo command builds (docs/features/sandbox/README.md "Demo events"), found again by its
 * fixed slug - every re-run updates or skips what exists.
 */
readonly final class SandboxDemoEvent
{
    /**
     * @param list<SandboxDemoRound> $rounds in their order
     * @param list<SandboxDemoGroup> $groups
     * @param list<array{name: string, short: string}> $divisions the event's divisions in their order
     */
    public function __construct(
        public string $slug,
        public string $name,
        // Prefix of the participants' external ids
        public string $code,
        public string $location,
        public string $countryCode,
        public string $timezone,
        public string $description,
        public array $rounds,
        public array $groups,
        // One sum standing per category (World Series rules)
        public bool $standings = false,
        // A timer screen and an OBS overlay
        public bool $displays = false,
        public array $divisions = [],
    ) {
    }

    public function round(string $key): SandboxDemoRound
    {
        foreach ($this->rounds as $round) {
            if ($round->key === $key) {
                return $round;
            }
        }

        throw new \InvalidArgumentException(sprintf('No demo round %s', $key));
    }

    /**
     * Days between the first and the last round, relative to the demo day.
     *
     * @return array{int, int}
     */
    public function dayRange(): array
    {
        $offsets = array_map(static fn (SandboxDemoRound $round): int => $round->dayOffset, $this->rounds);

        return [min($offsets), max($offsets)];
    }
}
