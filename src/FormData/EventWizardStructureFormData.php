<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStructureTemplate;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The creation wizard's Structure templates (W-creation-wizard-research.md §3.5, EventWizardStructureFormType) - what the
 * rounds are generated from (EventWizardStructurePlanner):
 *
 * - Qualification: the stages (spare rows without a name are not used);
 * - Every round counts: how many solo / pair / team rounds, each category's time limit, the team size, the order and
 *   "add up the times per category" (a standing per category - toolkit only, W7);
 * - Challenges (spws-wizard.md): rows of challenges - who, how many back to back, how many such rounds, the limit - and
 *   "add up the challenges" with the standing rules prefilled with the World Series rules (each its own rules group,
 *   so switching templates never mixes their defaults);
 *
 * No start time: every generated round's day and time are typed by the organiser (EventWizardRoundStartsFormData).
 */
final class EventWizardStructureFormData
{
    public const int STAGE_ROWS = 5;
    public const int BLOCK_ROWS = 6;
    public const int CATEGORY_ROUNDS_MAX = 26;

    public EventWizardStructureTemplate $template = EventWizardStructureTemplate::Qualification;

    /** @var array<int, EventWizardStageFormData> */
    #[Assert\Valid]
    public array $stages = [];

    public null|int $soloRounds = null;
    public null|int $soloMinutes = 60;
    public null|int $pairsRounds = null;
    public null|int $pairsMinutes = 60;
    public null|int $teamsRounds = null;
    public null|int $teamsMinutes = 60;
    public null|int $teamSize = 4;
    public bool $sumPerCategory = true;
    // Solo 1, Pairs 1, Teams 1, Solo 2… - or every solo round first ("by_category")
    public bool $interleaved = true;

    // Every round counts: the rules of "add up the times per category" (toolkit) - untouched = today's standing
    #[Assert\Valid]
    public EventWizardStandingRulesFormData $everyRoundRules;

    /** @var array<int, EventWizardStageFormData> the Challenges rows (spare rows leave `challenges` empty) */
    public array $blocks = [];

    // Challenges: "add up the challenges - one standing per category" and its rules (World Series rules prefilled)
    public bool $challengesSum = true;

    #[Assert\Valid]
    public EventWizardStandingRulesFormData $challengesRules;

    public function __construct()
    {
        for ($index = 0; $index < self::STAGE_ROWS; $index++) {
            $this->stages[$index] = new EventWizardStageFormData();
        }

        for ($index = 0; $index < self::BLOCK_ROWS; $index++) {
            $this->blocks[$index] = new EventWizardStageFormData();
        }

        $this->everyRoundRules = new EventWizardStandingRulesFormData();
        $this->challengesRules = EventWizardStandingRulesFormData::worldSeries();
    }

    /**
     * @return list<EventWizardStageFormData>
     */
    public function usedStages(): array
    {
        return array_values(array_filter($this->stages, static fn (EventWizardStageFormData $stage): bool => $stage->isUsed()));
    }

    /**
     * @return list<EventWizardStageFormData> the Challenges rows in use, in order
     */
    public function usedBlocks(): array
    {
        return array_values(array_filter($this->blocks, static fn (EventWizardStageFormData $block): bool => $block->isBlock()));
    }

    /**
     * @return array<string, int> RoundCategory value => rounds of it (Every round counts)
     */
    public function categoryRounds(): array
    {
        return [
            RoundCategory::Solo->value => $this->soloRounds ?? 0,
            RoundCategory::Duo->value => $this->pairsRounds ?? 0,
            RoundCategory::Team->value => $this->teamsRounds ?? 0,
        ];
    }

    public function categoryMinutes(RoundCategory $category): int
    {
        return match ($category) {
            RoundCategory::Solo => $this->soloMinutes ?? 60,
            RoundCategory::Duo => $this->pairsMinutes ?? 60,
            RoundCategory::Team => $this->teamsMinutes ?? 60,
        };
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->template === EventWizardStructureTemplate::Qualification && $this->usedStages() === []) {
            $context->buildViolation('event_wizard.structure.no_stage')->setTranslationDomain('messages')->atPath('stages')->addViolation();
        }

        if ($this->template === EventWizardStructureTemplate::Challenges) {
            $this->validateBlocks($context);

            return;
        }

        if ($this->template !== EventWizardStructureTemplate::EveryRoundCounts) {
            return;
        }

        foreach (['soloRounds' => $this->soloRounds, 'pairsRounds' => $this->pairsRounds, 'teamsRounds' => $this->teamsRounds] as $path => $count) {
            if ($count !== null && ($count < 0 || $count > self::CATEGORY_ROUNDS_MAX)) {
                $context->buildViolation('event_wizard.structure.rounds_count_invalid')
                    ->setParameter('%max%', (string) self::CATEGORY_ROUNDS_MAX)->setTranslationDomain('messages')->atPath($path)->addViolation();
            }
        }

        if (array_sum($this->categoryRounds()) === 0) {
            $context->buildViolation('event_wizard.structure.no_rounds')->setTranslationDomain('messages')->atPath('soloRounds')->addViolation();
        }

        foreach (['soloMinutes' => [$this->soloRounds, $this->soloMinutes], 'pairsMinutes' => [$this->pairsRounds, $this->pairsMinutes], 'teamsMinutes' => [$this->teamsRounds, $this->teamsMinutes]] as $path => [$count, $minutes]) {
            if (($count ?? 0) > 0 && ($minutes === null || $minutes < 1 || $minutes > 1440)) {
                $context->buildViolation('event_wizard.structure.minutes_invalid')->setTranslationDomain('messages')->atPath($path)->addViolation();
            }
        }

        if (($this->teamsRounds ?? 0) > 0 && $this->teamSize !== null && ($this->teamSize < CompetitionRound::TEAM_SIZE_MIN || $this->teamSize > CompetitionRound::TEAM_SIZE_MAX)) {
            $context->buildViolation('event_wizard.structure.team_size_invalid')
                ->setParameter('%min%', (string) CompetitionRound::TEAM_SIZE_MIN)
                ->setParameter('%max%', (string) CompetitionRound::TEAM_SIZE_MAX)
                ->setTranslationDomain('messages')->atPath('teamSize')->addViolation();
        }
    }

    /**
     * The Challenges rows - checked only for that template (its spare rows and the other templates' parts never refuse)
     */
    private function validateBlocks(ExecutionContextInterface $context): void
    {
        if ($this->usedBlocks() === []) {
            $context->buildViolation('event_wizard.structure.challenges.no_rows')->setTranslationDomain('messages')->atPath('blocks')->addViolation();

            return;
        }

        foreach ($this->blocks as $index => $block) {
            if ($block->isBlock() === false) {
                continue;
            }

            $path = 'blocks[' . $index . '].';

            if ($block->challenges > EventWizardStageFormData::CHALLENGES_MAX) {
                $context->buildViolation('event_wizard.structure.challenges.back_to_back_invalid')
                    ->setParameter('%max%', (string) EventWizardStageFormData::CHALLENGES_MAX)->setTranslationDomain('messages')->atPath($path . 'challenges')->addViolation();
            }

            if ($block->roundsCount === null || $block->roundsCount < 1 || $block->roundsCount > EventWizardStageFormData::ROUNDS_MAX) {
                $context->buildViolation('event_wizard.structure.rounds_count_invalid')
                    ->setParameter('%max%', (string) EventWizardStageFormData::ROUNDS_MAX)->setTranslationDomain('messages')->atPath($path . 'roundsCount')->addViolation();
            }

            if ($block->minutesLimit === null || $block->minutesLimit < 1 || $block->minutesLimit > 1440) {
                $context->buildViolation('event_wizard.structure.minutes_invalid')->setTranslationDomain('messages')->atPath($path . 'minutesLimit')->addViolation();
            }

            if ($block->category === RoundCategory::Team && $block->teamSize !== null && ($block->teamSize < CompetitionRound::TEAM_SIZE_MIN || $block->teamSize > CompetitionRound::TEAM_SIZE_MAX)) {
                $context->buildViolation('event_wizard.structure.team_size_invalid')
                    ->setParameter('%min%', (string) CompetitionRound::TEAM_SIZE_MIN)
                    ->setParameter('%max%', (string) CompetitionRound::TEAM_SIZE_MAX)
                    ->setTranslationDomain('messages')->atPath($path . 'teamSize')->addViolation();
            }
        }
    }
}
