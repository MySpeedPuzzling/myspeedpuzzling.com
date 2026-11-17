<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One generated round on the Structure step (EventWizardRoundStartsFormData): its name (the template's, editable) and
 * its day and time, both typed by the organiser (the day prefilled only when the event lasts one day) - in the zone of
 * the event's country.
 */
final class EventWizardRoundStartFormData
{
    public function __construct(
        public null|string $name = null,
        // "Y-m-d" (a browser's date input)
        public null|string $date = null,
        // "HH:MM" (a browser's time input; "9:30" is accepted too)
        public null|string $time = null,
    ) {
    }

    /**
     * "Y-m-d H:i" - null when the day or the time is missing or no valid one
     */
    public function localStart(): null|string
    {
        if (
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim((string) $this->date), $day) !== 1
            || checkdate((int) $day[2], (int) $day[3], (int) $day[1]) === false
            || preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:00)?$/', trim((string) $this->time), $time) !== 1
        ) {
            return null;
        }

        return sprintf('%s %02d:%s', $day[0], (int) $time[1], $time[2]);
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        $name = trim((string) $this->name);

        if ($name === '') {
            $context->buildViolation('This value should not be blank.')->atPath('name')->addViolation();
        } elseif (mb_strlen($name) > 255) {
            $context->buildViolation('This value is too long. It should have {{ limit }} characters or less.')
                ->setParameter('{{ limit }}', '255')->atPath('name')->addViolation();
        }

        if ($this->localStart() === null) {
            $context->buildViolation('event_wizard.structure.start_invalid')->setTranslationDomain('messages')->addViolation();
        }
    }
}
