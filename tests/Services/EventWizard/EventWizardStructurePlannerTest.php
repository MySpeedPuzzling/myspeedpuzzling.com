<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventWizard;

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
 * The Structure step's templates (W5): names, order and categories - never a start (the organiser types every round's,
 * Jan 2026-11-17).
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

        $rounds = self::planner()->plan($data);

        self::assertSame(['Group A', 'Group B', 'Group C', 'Group D', 'Semifinal 1', 'Semifinal 2', 'Final'], self::names($rounds));
        self::assertSame([0, 0, 0, 0, 1, 1, 2], array_map(static fn (EventWizardPlannedRound $round): null|int => $round->stage, $rounds));
        self::assertSame(120, $rounds[6]->minutesLimit);
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

        $interleaved = self::planner()->plan($data);
        self::assertSame(['Solo 1', 'Pairs 1', 'Teams 1', 'Solo 2', 'Teams 2'], self::names($interleaved));
        self::assertSame(RoundCategory::Team, $interleaved[2]->category);
        self::assertSame(4, $interleaved[2]->teamSize);
        self::assertSame(45, $interleaved[2]->minutesLimit);
        self::assertNull($interleaved[0]->teamSize);
        self::assertNull($interleaved[0]->stage);

        $data->interleaved = false;
        self::assertSame(['Solo 1', 'Solo 2', 'Pairs 1', 'Teams 1', 'Teams 2'], self::names(self::planner()->plan($data)));
    }

    public function testManyRoundsAreAlwaysGenerated(): void
    {
        // No day to fit into any more: 40 rounds are 40 rounds, each started when the organiser says
        $data = new EventWizardStructureFormData();
        $data->template = EventWizardStructureTemplate::EveryRoundCounts;
        $data->soloRounds = 26;
        $data->pairsRounds = 14;
        $data->teamsRounds = 0;

        self::assertCount(40, self::planner()->plan($data));
    }

    public function testNotSureYetGeneratesNoRound(): void
    {
        $data = new EventWizardStructureFormData();
        $data->template = EventWizardStructureTemplate::Empty;

        self::assertSame([], self::planner()->plan($data));
    }

    /**
     * @param list<EventWizardPlannedRound> $rounds
     * @return list<string>
     */
    private static function names(array $rounds): array
    {
        return array_map(static fn (EventWizardPlannedRound $round): string => $round->name, $rounds);
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
