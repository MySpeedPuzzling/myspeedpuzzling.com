<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use DateTimeImmutable;
use SpeedPuzzling\Web\FormData\EventWizardStructureFormData;
use SpeedPuzzling\Web\Results\EventWizardPlannedRound;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStructureTemplate;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * The rounds a Structure template generates (W-creation-wizard-research.md §3.5) - pure, nothing written:
 *
 * - **Names**: a stage of the Qualification template names its rounds after itself - one round keeps the stage's name
 *   ("Final"), several are lettered in the first stage ("Group A", "Group B" - groups) and numbered after it
 *   ("Semifinal 1"); Every round counts names them "Solo 1", "Pairs 1", "Teams 1" (EventWizardDefaults::roundName()).
 * - **Order**: stage by stage; Every round counts interleaved (Solo 1, Pairs 1, Teams 1, Solo 2 …) or category by
 *   category.
 * - **Schedule** (the one rule, W-creation-wizard-research.md §3.5 "As built (W5)"): the rounds are
 *   spread evenly over the event's days - ceil(rounds / days) a day, in order - each day's first round at the start time,
 *   the next ones every $everyMinutes after it. Every name and start stays editable in the schedule table.
 */
readonly final class EventWizardStructurePlanner
{
    public function __construct(
        private EventWizardDefaults $defaults,
    ) {
    }

    /**
     * @param DateTimeImmutable $firstDay the event's first day (its date only is read)
     * @param int $days how many days the event lasts (1 = one day)
     * @return list<EventWizardPlannedRound>
     */
    public function plan(EventWizardStructureFormData $data, DateTimeImmutable $firstDay, int $days): array
    {
        $unscheduled = match ($data->template) {
            EventWizardStructureTemplate::Qualification => $this->qualification($data),
            EventWizardStructureTemplate::EveryRoundCounts => $this->everyRoundCounts($data),
            EventWizardStructureTemplate::Empty => [],
        };

        $count = count($unscheduled);

        if ($count === 0) {
            return [];
        }

        $perDay = (int) ceil($count / max(1, $days));
        [$hours, $minutes] = array_map(intval(...), explode(':', trim((string) $data->startTime)));
        $dayStart = $firstDay->setTime($hours, $minutes);
        $rounds = [];

        foreach ($unscheduled as $index => [$name, $category, $teamSize, $minutesLimit, $stage]) {
            $day = intdiv($index, $perDay);
            $slot = $index % $perDay;
            $start = $dayStart->modify(sprintf('+%d days +%d minutes', $day, $slot * ($data->everyMinutes ?? 0)));

            $rounds[] = new EventWizardPlannedRound(
                name: $name,
                category: $category,
                teamSize: $teamSize,
                minutesLimit: $minutesLimit,
                startsLocal: $start->format('Y-m-d H:i'),
                stage: $stage,
            );
        }

        return $rounds;
    }

    /**
     * @return list<array{string, RoundCategory, null|int, int, null|int}>
     */
    private function qualification(EventWizardStructureFormData $data): array
    {
        $rounds = [];

        foreach ($data->usedStages() as $stageIndex => $stage) {
            $count = $stage->roundsCount ?? 1;
            $name = trim((string) $stage->name);

            for ($number = 1; $number <= $count; $number++) {
                $suffix = $stageIndex === 0 ? self::letter($number) : (string) $number;
                $rounds[] = [
                    $count === 1 ? $name : $name . ' ' . $suffix,
                    $stage->category,
                    $stage->category === RoundCategory::Team ? $stage->teamSize : null,
                    $stage->minutesLimit ?? EventWizardDefaults::MINUTES_LIMIT,
                    $stageIndex,
                ];
            }
        }

        return $rounds;
    }

    /**
     * @return list<array{string, RoundCategory, null|int, int, null|int}>
     */
    private function everyRoundCounts(EventWizardStructureFormData $data): array
    {
        $byCategory = [];

        foreach (RoundCategory::cases() as $category) {
            $count = $data->categoryRounds()[$category->value];

            for ($number = 1; $number <= $count; $number++) {
                $byCategory[$category->value][] = [
                    $this->defaults->roundName($category) . ' ' . $number,
                    $category,
                    $category === RoundCategory::Team ? $data->teamSize : null,
                    $data->categoryMinutes($category),
                    null,
                ];
            }
        }

        if ($data->interleaved === false) {
            return array_merge(...array_values($byCategory));
        }

        $rounds = [];
        $longest = max(array_map(count(...), $byCategory) ?: [0]);

        for ($index = 0; $index < $longest; $index++) {
            foreach ($byCategory as $categoryRounds) {
                if (isset($categoryRounds[$index])) {
                    $rounds[] = $categoryRounds[$index];
                }
            }
        }

        return $rounds;
    }

    /**
     * A, B … Z, then 27, 28 (a stage has at most 26 rounds)
     */
    private static function letter(int $number): string
    {
        return $number >= 1 && $number <= 26 ? chr(64 + $number) : (string) $number;
    }
}
