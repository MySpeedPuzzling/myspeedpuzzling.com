<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventWizard;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\FormData\EventWizardStageFormData;
use SpeedPuzzling\Web\FormData\EventWizardStructureFormData;
use SpeedPuzzling\Web\Results\EventWizardPlannedRound;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardDefaults;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardStructurePlanner;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStructureTemplate;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;

/**
 * The Structure step's templates (W5): names, order, categories and the schedule rule (evenly over the event's days).
 */
final class EventWizardStructurePlannerTest extends TestCase
{
    public function testQualificationLettersTheFirstStageAndNumbersTheNextOnes(): void
    {
        $data = new EventWizardStructureFormData();
        $data->stages[0] = new EventWizardStageFormData('Group', 4, RoundCategory::Solo, null, 90, 10);
        $data->stages[1] = new EventWizardStageFormData('Semifinal', 2, RoundCategory::Solo, null, 90, 15);
        $data->stages[2] = new EventWizardStageFormData('Final', 1, RoundCategory::Solo, null, 120);
        // A spare row without a name is not used
        $data->stages[3] = new EventWizardStageFormData('', 3);

        $rounds = self::plan($data, new DateTimeImmutable('2027-06-15'), 1);

        self::assertSame(['Group A', 'Group B', 'Group C', 'Group D', 'Semifinal 1', 'Semifinal 2', 'Final'], self::names($rounds));
        self::assertSame([0, 0, 0, 0, 1, 1, 2], array_map(static fn (EventWizardPlannedRound $round): null|int => $round->stage, $rounds));
        self::assertSame(120, $rounds[6]->minutesLimit);
        // Every 2 hours from 10:00, all on the one day
        self::assertSame('2027-06-15 10:00', $rounds[0]->startsLocal);
        self::assertSame('2027-06-15 12:00', $rounds[1]->startsLocal);
        self::assertSame('2027-06-15 22:00', $rounds[6]->startsLocal);
    }

    public function testTheRoundsAreSpreadEvenlyOverTheEventsDays(): void
    {
        $data = new EventWizardStructureFormData();
        $data->stages[0] = new EventWizardStageFormData('Group', 4, RoundCategory::Solo, null, 90);
        $data->stages[1] = new EventWizardStageFormData('Semifinal', 2, RoundCategory::Solo, null, 90);
        $data->stages[2] = new EventWizardStageFormData('Final', 1, RoundCategory::Solo, null, 120);
        $data->startTime = '9:30';
        $data->everyMinutes = 90;

        // 7 rounds over 2 days: 4 on the first day, 3 on the second, each day from 09:30
        $starts = array_map(
            static fn (EventWizardPlannedRound $round): string => $round->startsLocal,
            self::plan($data, new DateTimeImmutable('2027-06-15'), 2),
        );

        self::assertSame([
            '2027-06-15 09:30', '2027-06-15 11:00', '2027-06-15 12:30', '2027-06-15 14:00',
            '2027-06-16 09:30', '2027-06-16 11:00', '2027-06-16 12:30',
        ], $starts);
    }

    public function testEveryRoundCountsInterleavesOrGoesCategoryByCategory(): void
    {
        $data = new EventWizardStructureFormData();
        $data->template = EventWizardStructureTemplate::EveryRoundCounts;
        $data->soloRounds = 2;
        $data->pairsRounds = 1;
        $data->teamsRounds = 2;
        $data->teamSize = 4;
        $data->teamsMinutes = 45;

        $interleaved = self::plan($data, new DateTimeImmutable('2027-06-15'), 1);
        self::assertSame(['Solo 1', 'Pairs 1', 'Teams 1', 'Solo 2', 'Teams 2'], self::names($interleaved));
        self::assertSame(RoundCategory::Team, $interleaved[2]->category);
        self::assertSame(4, $interleaved[2]->teamSize);
        self::assertSame(45, $interleaved[2]->minutesLimit);
        self::assertNull($interleaved[0]->teamSize);
        self::assertNull($interleaved[0]->stage);

        $data->interleaved = false;
        self::assertSame(['Solo 1', 'Solo 2', 'Pairs 1', 'Teams 1', 'Teams 2'], self::names(self::plan($data, new DateTimeImmutable('2027-06-15'), 1)));
    }

    public function testRoundsThatWouldRunPastMidnightDoNotFit(): void
    {
        $data = new EventWizardStructureFormData();
        $data->template = EventWizardStructureTemplate::EveryRoundCounts;
        $data->soloRounds = 12;
        $data->pairsRounds = 0;
        $data->teamsRounds = 0;
        $data->startTime = '10:00';

        // Left automatic: the largest gap in 5 minutes that ends the day before midnight - 75 minutes, the last at 23:45
        $automatic = self::plan($data, new DateTimeImmutable('2027-06-15'), 1);
        self::assertSame('2027-06-15 11:15', $automatic[1]->startsLocal);
        self::assertSame('2027-06-15 23:45', $automatic[11]->startsLocal);

        // 12 rounds every typed 2 hours from 10:00: the last one would start at 08:00 the next day
        $data->everyMinutes = 120;
        self::assertNull(self::planner()->plan($data, new DateTimeImmutable('2027-06-15'), 1));

        // Automatic, but even 30 minutes is too short a gap for 40 rounds from 10:00 - no fit
        $data->everyMinutes = null;
        $data->soloRounds = 40;
        self::assertNull(self::planner()->plan($data, new DateTimeImmutable('2027-06-15'), 1));
        $data->soloRounds = 12;
        $data->everyMinutes = 120;

        // Over two days: 6 a day, the last at 20:00
        $rounds = self::plan($data, new DateTimeImmutable('2027-06-15'), 2);
        self::assertSame('2027-06-15 20:00', $rounds[5]->startsLocal);
        self::assertSame('2027-06-16 10:00', $rounds[6]->startsLocal);
    }

    public function testNotSureYetGeneratesNoRound(): void
    {
        $data = new EventWizardStructureFormData();
        $data->template = EventWizardStructureTemplate::Empty;

        self::assertSame([], self::plan($data, new DateTimeImmutable('2027-06-15'), 1));
    }

    /**
     * @param list<EventWizardPlannedRound> $rounds
     * @return list<string>
     */
    private static function names(array $rounds): array
    {
        return array_map(static fn (EventWizardPlannedRound $round): string => $round->name, $rounds);
    }

    /**
     * @return list<EventWizardPlannedRound>
     */
    private static function plan(EventWizardStructureFormData $data, DateTimeImmutable $firstDay, int $days): array
    {
        $rounds = self::planner()->plan($data, $firstDay, $days);
        self::assertNotNull($rounds);

        return $rounds;
    }

    private static function planner(): EventWizardStructurePlanner
    {
        $translator = new class () implements TranslatorInterface {
            use TranslatorTrait;

            /**
             * @param array<string, mixed> $parameters
             */
            public function trans(null|string $id, array $parameters = [], null|string $domain = null, null|string $locale = null): string
            {
                return match ($id) {
                    'event_wizard.round_name.solo' => 'Solo',
                    'event_wizard.round_name.duo' => 'Pairs',
                    'event_wizard.round_name.team' => 'Teams',
                    default => (string) $id,
                };
            }
        };

        return new EventWizardStructurePlanner(new EventWizardDefaults($translator));
    }
}
