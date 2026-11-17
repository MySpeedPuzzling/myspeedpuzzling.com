<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One row of the Structure step's schedule table (EventWizardScheduleFormType): an existing round ($roundId) or a new
 * one (a spare row - used once it has a name), its name, local start (day and time in the round's zone), who competes and
 * the time limit; `remove` deletes an existing round.
 */
final class EventWizardScheduleRowFormData
{
    public function __construct(
        public null|string $roundId = null,
        public null|string $name = null,
        // "Y-m-dTH:i" (a browser's datetime-local input; "Y-m-d H:i" is accepted too)
        public null|string $start = null,
        public RoundCategory $category = RoundCategory::Solo,
        public null|int $teamSize = null,
        public null|int $minutesLimit = null,
        public bool $remove = false,
    ) {
    }

    public function isNew(): bool
    {
        return $this->roundId === null;
    }

    /**
     * A row that becomes or stays a round
     */
    public function isUsed(): bool
    {
        if ($this->isNew()) {
            return $this->name !== null && trim($this->name) !== '';
        }

        return $this->remove === false;
    }

    /**
     * "Y-m-d H:i" - null when the date or the time is no valid one
     */
    public function localStart(): null|string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ]([01]?\d|2[0-3]):([0-5]\d)(:00)?$/', trim((string) $this->start), $matches) !== 1) {
            return null;
        }

        $date = $matches[1];

        [$year, $month, $day] = array_map(intval(...), explode('-', $date));

        if (checkdate($month, $day, $year) === false) {
            return null;
        }

        return sprintf('%s %02d:%s', $date, (int) $matches[2], $matches[3]);
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->isUsed() === false) {
            return;
        }

        $name = trim((string) $this->name);

        if ($name === '') {
            $context->buildViolation('This value should not be blank.')->atPath('name')->addViolation();
        } elseif (mb_strlen($name) > 255) {
            $context->buildViolation('This value is too long. It should have {{ limit }} characters or less.')
                ->setParameter('{{ limit }}', '255')->atPath('name')->addViolation();
        }

        if ($this->localStart() === null) {
            $context->buildViolation('event_wizard.structure.start_invalid')->setTranslationDomain('messages')->atPath('start')->addViolation();
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
    }
}
