<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use SpeedPuzzling\Web\Exceptions\InvalidLocalTime;
use SpeedPuzzling\Web\Message\ChangeCompetitionRegistrationSettings;
use SpeedPuzzling\Web\Results\CompetitionEvent;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The registration settings page (docs/features/competitions-management/registration.md). The window is typed as a
 * local wall clock in $timezone - like a round's start - and saved as the instant it means.
 */
final class CompetitionRegistrationFormData
{
    public bool $registrationManaged = false;

    #[Assert\Range(min: 1, max: ChangeCompetitionRegistrationSettings::CAPACITY_MAX)]
    public null|int $capacity = null;

    // Local wall clocks in $timezone (DateTimeType shows a value in the server's zone - it is handed the wall clock)
    public null|DateTimeImmutable $opensAt = null;

    public null|DateTimeImmutable $closesAt = null;

    #[Assert\NotBlank]
    #[Assert\Timezone]
    public null|string $timezone = null;

    #[Assert\Length(max: ChangeCompetitionRegistrationSettings::ENTRY_FEE_MAX_LENGTH)]
    public null|string $entryFeeText = null;

    #[Assert\Length(max: ChangeCompetitionRegistrationSettings::PAYMENT_INSTRUCTIONS_MAX_LENGTH)]
    public null|string $paymentInstructions = null;

    /**
     * @param string $defaultTimezone the zone of the event's rounds, else its country's - used until settings are saved
     */
    public static function fromCompetition(CompetitionEvent $competition, string $defaultTimezone): self
    {
        $timezone = $competition->registrationTimezone !== null && RoundTimezone::isValid($competition->registrationTimezone)
            ? $competition->registrationTimezone
            : $defaultTimezone;

        $data = new self();
        $data->registrationManaged = $competition->registrationManaged;
        $data->capacity = $competition->capacity;
        $data->timezone = $timezone;
        $data->opensAt = self::wallClock($competition->registrationOpensAt, $timezone);
        $data->closesAt = self::wallClock($competition->registrationClosesAt, $timezone);
        $data->entryFeeText = $competition->entryFeeText;
        $data->paymentInstructions = $competition->paymentInstructions;

        return $data;
    }

    #[Assert\Callback]
    public function validateWindow(ExecutionContextInterface $context): void
    {
        if ($this->opensAt !== null && $this->closesAt !== null && $this->closesAt <= $this->opensAt) {
            $context->buildViolation('competition_registration_closes_before_opens')
                ->atPath('closesAt')
                ->addViolation();
        }
    }

    /**
     * @throws InvalidLocalTime
     */
    public function opensAtInstant(): null|DateTimeImmutable
    {
        return $this->instant($this->opensAt);
    }

    /**
     * @throws InvalidLocalTime
     */
    public function closesAtInstant(): null|DateTimeImmutable
    {
        return $this->instant($this->closesAt);
    }

    public function trimmedEntryFee(): null|string
    {
        return self::trimmed($this->entryFeeText);
    }

    public function trimmedPaymentInstructions(): null|string
    {
        return self::trimmed($this->paymentInstructions);
    }

    /**
     * @throws InvalidLocalTime
     */
    private function instant(null|DateTimeImmutable $wallClock): null|DateTimeImmutable
    {
        if ($wallClock === null) {
            return null;
        }

        assert($this->timezone !== null);

        return RoundTimezone::toInstant($wallClock->format('Y-m-d H:i'), $this->timezone);
    }

    private static function wallClock(null|DateTimeImmutable $instant, string $timezone): null|DateTimeImmutable
    {
        if ($instant === null) {
            return null;
        }

        return new DateTimeImmutable(RoundTimezone::toLocal($instant, $timezone)->format('Y-m-d H:i:s'));
    }

    private static function trimmed(null|string $value): null|string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
