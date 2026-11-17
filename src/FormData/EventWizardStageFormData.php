<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One stage of the creation wizard's "Qualification" template (W-creation-wizard-research.md §3.5): its rounds' name,
 * how many rounds, who competes, the time limit and - for whom the competition toolkit is on - how many of each round go
 * on to the next stage (an advancement step, W7). A stage without a name is not used (the form has spare rows).
 */
final class EventWizardStageFormData
{
    public const int ROUNDS_MAX = 26;

    public function __construct(
        public null|string $name = null,
        public null|int $roundsCount = null,
        public RoundCategory $category = RoundCategory::Solo,
        public null|int $teamSize = null,
        public null|int $minutesLimit = null,
        // The best N of each of its rounds go on to the next stage (toolkit only), null = nobody decided
        public null|int $advance = null,
    ) {
    }

    public function isUsed(): bool
    {
        return $this->name !== null && trim($this->name) !== '';
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->isUsed() === false) {
            return;
        }

        if (mb_strlen(trim((string) $this->name)) > 80) {
            $context->buildViolation('This value is too long. It should have {{ limit }} characters or less.')
                ->setParameter('{{ limit }}', '80')->atPath('name')->addViolation();
        }

        if ($this->roundsCount === null || $this->roundsCount < 1 || $this->roundsCount > self::ROUNDS_MAX) {
            $context->buildViolation('event_wizard.structure.rounds_count_invalid')
                ->setParameter('%max%', (string) self::ROUNDS_MAX)->setTranslationDomain('messages')->atPath('roundsCount')->addViolation();
        }

        if ($this->minutesLimit === null || $this->minutesLimit < 1 || $this->minutesLimit > 1440) {
            $context->buildViolation('event_wizard.structure.minutes_invalid')->setTranslationDomain('messages')->atPath('minutesLimit')->addViolation();
        }

        if ($this->category === RoundCategory::Team && $this->teamSize !== null && ($this->teamSize < CompetitionRound::TEAM_SIZE_MIN || $this->teamSize > CompetitionRound::TEAM_SIZE_MAX)) {
            $context->buildViolation('event_wizard.structure.team_size_invalid')
                ->setParameter('%min%', (string) CompetitionRound::TEAM_SIZE_MIN)
                ->setParameter('%max%', (string) CompetitionRound::TEAM_SIZE_MAX)
                ->setTranslationDomain('messages')->atPath('teamSize')->addViolation();
        }

        if ($this->advance !== null && ($this->advance < 1 || $this->advance > AdvancementStepSettings::QUALIFY_MAX)) {
            $context->buildViolation('event_wizard.structure.advance_invalid')->setTranslationDomain('messages')->atPath('advance')->addViolation();
        }
    }
}
